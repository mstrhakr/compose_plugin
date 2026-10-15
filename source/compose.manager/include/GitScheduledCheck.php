<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackState.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitClone.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackManager.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitDeploy.php';

/**
 * The scheduled check of git stacks (compose-git scheduled-check, run from cron).
 *
 * For each git stack whose autoDeploy setting is not "off", one pass:
 *  1. asks the remote for the branch's latest commit (git ls-remote, once per
 *     repository, branch and credential in a pass);
 *  2. when that commit is new, fetches it and decides whether it changes the
 *     stack: its own folder in the repository, not just any file, compared with
 *     the deployed commit (or, after a failed deploy, the commit that failed:
 *     that is what the containers were last started from). In a shared
 *     repository a commit to another stack's folder changes nothing here;
 *  3. handles each new commit that changes the stack once: "notify" sends a
 *     notification, "deploy" deploys it. A stack whose last deploy failed, whose
 *     clone has local changes, or that is not running (stopped, or never
 *     deployed; the image auto-update leaves those alone too) is not deployed;
 *     one notification says why. A commit held back that way is deployed once the
 *     failure is cleared, the local changes are gone or the stack runs, and one
 *     only notified about is deployed once the stack is switched to "deploy". The deploy
 *     checks all of that again under the stack's lock, and changes nothing if it no longer
 *     holds (the stack was stopped while the deploy waited for the lock, say); the next
 *     pass then decides again. A deploy that fails before any container
 *     is started (a pull, the lock, the checkout) is not retried either; it is
 *     recorded as failed until Resume clears it or a later deploy works.
 *
 * What a pass learned is kept in the stack folder (git_check.json), written only
 * when it changes, so a pass with nothing new writes nothing to the flash.
 */
final class GitScheduledCheck
{
    public const RECORD_FILE = 'git_check.json';
    private const RECORD_VERSION = 1;
    /**
     * The pass's lock file, in the folder that holds each stack's <project name>.lock. A project
     * name never starts with a dot, so no stack's lock can be this file.
     */
    private const LOCK_NAME = '.git-scheduled-check.lock';

    /** How a commit was handled (handledBy in the record). */
    private const HANDLED_NOTIFIED = 'notified';
    private const HANDLED_DEPLOYED = 'deployed';
    private const HANDLED_PAUSED = 'paused';
    private const HANDLED_LOCAL_CHANGES = 'local-changes';
    private const HANDLED_NOT_RUNNING = 'not-running';
    /**
     * The deploy was started and failed. Before up (a pull, the lock, the checkout) it is shown as a
     * failed automatic deploy; during up, compose.sh records the stack's failed commit instead.
     */
    private const HANDLED_FAILED = 'failed';

    /** @var callable(string, string, string): void */
    private $notify;

    /** @var callable(StackInfo, string, ?string): int */
    private $deploy;

    /** @var callable(string): void */
    private $say;

    /** @var callable(StackInfo): bool */
    private $isRunning;

    /**
     * @param string $composeRoot The plugin's projects folder
     * @param callable(string, string, string): void|null $notify Sends a notification:
     *     subject, message, icon ('' or 'warning'). Unraid's notify by default.
     * @param callable(StackInfo, string, ?string): int|null $deploy Deploys a stack at the given commit,
     *     as decided from the given deployed commit (null if none), and returns the exit status.
     *     compose.sh gitdeploy through compose_background.sh by default.
     * @param callable(string): void|null $say Receives progress messages
     * @param callable(StackInfo): bool|null $isRunning Whether any of the stack's containers
     *     run; throws RuntimeException when that cannot be found out. docker ps by default.
     */
    public function __construct(
        private readonly string $composeRoot,
        ?callable $notify = null,
        ?callable $deploy = null,
        ?callable $say = null,
        ?callable $isRunning = null
    ) {
        $this->notify = $notify ?? static function (string $subject, string $message, string $icon): void {
            composeSendNotification($subject, $message, $icon);
        };
        $this->deploy = $deploy ?? static function (StackInfo $stack, string $commit, ?string $deployedWhenDecided): int {
            return self::deployInBackgroundRunner($stack, $commit, $deployedWhenDecided);
        };
        $this->say = $say ?? static function (string $message): void {
        };
        $this->isRunning = $isRunning ?? static function (StackInfo $stack): bool {
            return self::hasRunningContainers($stack);
        };
    }

    /**
     * One pass over every git stack. Does nothing while the array is stopped,
     * or while another pass is still running.
     *
     * @return int How many stacks were checked
     */
    public function run(): int
    {
        if (!GitPathGuard::isArrayStarted()) {
            ($this->say)('The array is not started, so nothing was checked.');
            return 0;
        }
        if (!is_dir(COMPOSE_LOCK_DIR)) {
            @mkdir(COMPOSE_LOCK_DIR, 0755, true);
        }
        $lockFile = COMPOSE_LOCK_DIR . '/' . self::LOCK_NAME;
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            ($this->say)("Could not open the lock file $lockFile, so nothing was checked.");
            composeLogger("Scheduled git check skipped: could not open the lock file $lockFile.", null, 'daemon', 'warning', 'git');
            return 0;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            ($this->say)('Another scheduled check is still running.');
            // To the syslog too: cron drops what is said, and a pass stuck in a deploy
            // would otherwise stop every stack's checks with nobody told.
            composeLogger("Scheduled git check skipped: the last one is still running ($lockFile).", null, 'daemon', 'warning', 'git');
            fclose($lock);
            return 0;
        }

        try {
            $checked = 0;
            /** @var array<string, string|RuntimeException> $remoteHeads */
            $remoteHeads = [];
            foreach ((new GitStackManager($this->composeRoot))->listGitStacks() as $folder) {
                try {
                    if ($this->checkStack($folder, $remoteHeads)) {
                        $checked++;
                    }
                } catch (Throwable $error) {
                    ($this->say)("$folder: " . $error->getMessage());
                    composeLogger("Scheduled git check of '$folder' failed: " . $error->getMessage(), null, 'daemon', 'warning', 'git');
                }
            }
            return $checked;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Check one stack.
     *
     * @param array<string, string|RuntimeException> $remoteHeads Remote heads already asked for in this pass
     * @return bool false if the stack is not checked on a schedule
     */
    private function checkStack(string $folder, array &$remoteHeads): bool
    {
        StackInfo::clearCache();
        $stack = StackInfo::fromProject($this->composeRoot, $folder);
        $settings = GitStackSettings::load($stack->path);
        if ($settings === null || $settings->autoDeploy === GitStackSettings::AUTO_DEPLOY_OFF) {
            return false;
        }
        // As the image auto-update does: a stack whose project name is not settled is left alone,
        // so a deploy cannot start it under the wrong name.
        if (!$stack->hasResolvedIdentity()) {
            ($this->say)("$folder: skipped, its compose project identity is unresolved.");
            composeLogger("Skipped scheduled git check of '$folder': compose project identity is unresolved", null, 'daemon', 'warning', 'git');
            return false;
        }
        $clone = new GitClone($settings);

        // 1. The branch's latest commit, asked once per repository, branch and credential.
        $key = $settings->url . "\n" . $settings->branch . "\n" . ($settings->credentialId ?? '');
        if (!isset($remoteHeads[$key])) {
            try {
                $remoteHeads[$key] = $clone->remoteBranchCommit();
            } catch (RuntimeException $error) {
                $remoteHeads[$key] = $error;
            }
        }
        if ($remoteHeads[$key] instanceof RuntimeException) {
            throw $remoteHeads[$key];
        }
        $remote = $remoteHeads[$key];

        $record = self::loadRecord($stack->path);
        $newRecord = $this->compare($stack, $clone, $remote, $record);
        if ($newRecord === null) {
            // Another operation holds the stack; the next pass tries again.
            ($this->say)("$folder: busy, checked next time.");
            return true;
        }

        // 3. Handle a new commit that changes the stack, once.
        $sameAsTheHandledCommit = $this->changesNothingSinceTheHandledCommit($newRecord, $clone, $remote);
        $waitsForResume = $sameAsTheHandledCommit && $newRecord['handledBy'] === self::HANDLED_FAILED;
        if ($sameAsTheHandledCommit && !$waitsForResume) {
            $newRecord['handledCommit'] = $remote;
        }
        // After a failed deploy, the record keeps the commit that failed, so the list and
        // status name it; a newer commit that changes nothing more waits for Resume with it.
        if ($newRecord['stackChanged'] && !$waitsForResume && !$this->alreadyHandled($newRecord, $remote, $settings, $stack, $clone)) {
            $handledBy = $this->handleNewCommit($stack, $settings, $clone, $remote, $newRecord['baseCommit']);
            if ($handledBy !== null) {
                $newRecord['handledBy'] = $handledBy;
                $newRecord['handledCommit'] = $remote;
                // A deploy moves what the containers run from: record that, so a later change to it
                // (a rollback by hand, Resume) is seen as one.
                $state = GitStackState::load($stack->path);
                $newRecord['baseCommit'] = $state->failedCommit ?? $state->deployedCommit;
            }
        }
        if ($newRecord !== $record) {
            self::saveRecord($stack->path, $newRecord);
        }
        ($this->say)("$folder: " . ($newRecord['stackChanged'] ? 'behind ' : 'up to date with ') . substr($remote, 0, 12));
        return true;
    }

    /**
     * Whether the remote commit changes nothing in the stack's folder since the
     * commit already handled. While a stack is behind, a commit to another
     * stack in the same repository then counts as handled too, so it brings no
     * second notification.
     *
     * @param array{remoteCommit: ?string, stackChanged: bool, handledCommit: ?string, handledBy: ?string, baseCommit: ?string} $record
     */
    private function changesNothingSinceTheHandledCommit(array $record, GitClone $clone, string $remote): bool
    {
        $handled = $record['handledCommit'];
        if (!$record['stackChanged'] || $handled === null || $handled === $remote) {
            return false;
        }
        return $clone->hasCommit($handled) && !$clone->stackFilesChanged($handled, $remote);
    }

    /**
     * Whether a commit was handled already and needs nothing more. A
     * notification is never sent twice for a commit, but a commit that was only
     * notified about is deployed once the stack is switched to "deploy", and one
     * held back by a failed deploy or local changes is deployed once that is
     * cleared.
     *
     * @param array{remoteCommit: ?string, stackChanged: bool, handledCommit: ?string, handledBy: ?string, baseCommit: ?string} $record
     */
    private function alreadyHandled(array $record, string $remote, GitStackSettings $settings, StackInfo $stack, GitClone $clone): bool
    {
        if ($record['handledCommit'] !== $remote) {
            return false;
        }
        $deploying = $settings->autoDeploy === GitStackSettings::AUTO_DEPLOY_DEPLOY;
        return match ($record['handledBy']) {
            self::HANDLED_NOTIFIED => !$deploying,
            self::HANDLED_PAUSED => !$deploying || GitStackState::load($stack->path)->failedCommit !== null,
            self::HANDLED_LOCAL_CHANGES => !$deploying || self::localChanges($clone, $stack) !== [],
            self::HANDLED_NOT_RUNNING => !$deploying || !($this->isRunning)($stack),
            default => true,
        };
    }

    /**
     * 2. Whether the remote commit changes the stack, fetching it when it is new.
     *
     * What the containers run from is read under the stack's lock, so the stack counts as
     * busy while another operation on it runs, also when nothing needs fetching: a deploy
     * or rollback that is running may be about to change it.
     *
     * @param array{remoteCommit: ?string, stackChanged: bool, handledCommit: ?string, handledBy: ?string, baseCommit: ?string} $record
     * @return array{remoteCommit: ?string, stackChanged: bool, handledCommit: ?string, handledBy: ?string, baseCommit: ?string}|null
     *     null if another operation holds the stack's lock
     */
    private function compare(StackInfo $stack, GitClone $clone, string $remote, array $record): ?array
    {
        $lock = GitStackManager::tryStackLock($stack);
        if ($lock === null) {
            return null;
        }
        try {
            $state = GitStackState::load($stack->path);
            $base = $state->failedCommit ?? $state->deployedCommit;
            if ($remote === $base) {
                return ['remoteCommit' => $remote, 'stackChanged' => false, 'handledCommit' => $remote, 'handledBy' => self::HANDLED_DEPLOYED, 'baseCommit' => $base];
            }
            if ($remote === $record['remoteCommit'] && $base === $record['baseCommit']) {
                return $record;
            }

            // A new commit: fetch it while holding the lock, so no deploy runs at the same time.
            $clone->fetch();
            // A base the clone no longer has (after a reclone, or a hand-edited git_state.json) counts as a change.
            $changed = $base === null || !$clone->hasCommit($base) || $clone->stackFilesChanged($base, $remote);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (!$changed) {
            // The stack's folder is as the base has it (a commit to another stack, or one that
            // undoes the change): nothing is waiting, so what an earlier commit left in the
            // record (a failed automatic deploy, say) no longer applies.
            return ['remoteCommit' => $remote, 'stackChanged' => false, 'handledCommit' => $remote, 'handledBy' => self::HANDLED_DEPLOYED, 'baseCommit' => $base];
        }
        if ($base !== $record['baseCommit']) {
            // What the containers run from changed since the record was written (a deploy of
            // another commit by hand, a rollback, Resume after a failed up): how the commit was
            // handled then says nothing about now, so it is handled again.
            return ['remoteCommit' => $remote, 'stackChanged' => true, 'handledCommit' => null, 'handledBy' => null, 'baseCommit' => $base];
        }
        return ['remoteCommit' => $remote, 'stackChanged' => true, 'handledCommit' => $record['handledCommit'], 'handledBy' => $record['handledBy'], 'baseCommit' => $base];
    }

    /**
     * @param string|null $comparedFrom The commit the containers ran from when the remote commit was
     *     compared (compare()'s base); the deploy checks under the stack's lock that it still is
     * @return string|null How it was handled (one of the HANDLED_ constants), or null when the
     *     deploy found, under the stack's lock, that it no longer applied: the next pass decides again
     */
    private function handleNewCommit(StackInfo $stack, GitStackSettings $settings, GitClone $clone, string $remote, ?string $comparedFrom): ?string
    {
        $name = $stack->projectFolder;
        $short = substr($remote, 0, 12);

        if ($settings->autoDeploy === GitStackSettings::AUTO_DEPLOY_NOTIFY) {
            ($this->notify)(
                "Git stack update: $name",
                "Commit $short on {$settings->branch} changes '$name'. Deploy it with Pull and Redeploy, or compose-git deploy $name.",
                ''
            );
            return self::HANDLED_NOTIFIED;
        }

        $state = GitStackState::load($stack->path);
        if ($state->failedCommit !== null) {
            ($this->notify)(
                "Git stack not deployed: $name",
                "Commit $short on {$settings->branch} was not deployed: the deploy of " . substr($state->failedCommit, 0, 12)
                . " failed, so automatic deploys of '$name' are paused. Fix it and deploy it, or resume automatic deploys"
                . " (stack menu, or compose-git resume $name).",
                'warning'
            );
            return self::HANDLED_PAUSED;
        }
        $localChanges = self::localChanges($clone, $stack);
        if ($localChanges !== []) {
            ($this->notify)(
                "Git stack not deployed: $name",
                "Commit $short on {$settings->branch} was not deployed: files in the clone of '$name' were changed by hand ("
                . implode(', ', array_slice($localChanges, 0, 5)) . (count($localChanges) > 5 ? ', ...' : '')
                . '). Deploy it with Pull and Redeploy, which offers to save them as a patch first.',
                'warning'
            );
            return self::HANDLED_LOCAL_CHANGES;
        }
        // As the image auto-update does: a stack someone stopped, or never started, is not
        // started by a commit. It is deployed at the first check after it runs again.
        if (!($this->isRunning)($stack)) {
            ($this->notify)(
                "Git stack not deployed: $name",
                "Commit $short on {$settings->branch} was not deployed: '$name' is not running. Deploy it with Pull and "
                . 'Redeploy, or start the stack and the next check deploys it. To stop these notifications for a stack'
                . ' stopped on purpose, set its automatic deploys to Off.',
                ''
            );
            return self::HANDLED_NOT_RUNNING;
        }

        ($this->say)("$name: deploying $short...");
        composeLogger("Scheduled git deploy of '$name' at $short", null, 'daemon', 'info', 'git');
        // The background runner logs to the stack's last_cmd.log and notifies whether it worked.
        // The commit compared is the one deployed, even if the branch has moved on since: a newer
        // commit is compared and handled by the next pass, and this one is recorded as handled.
        $exitStatus = ($this->deploy)($stack, $remote, $comparedFrom);
        if ($exitStatus === GitDeploy::EXIT_SKIPPED) {
            // Something changed while the deploy waited for the stack's lock (the stack was
            // stopped, or another commit deployed by hand, say). Nothing was changed; the next
            // pass sees what changed and acts on it.
            composeLogger("Scheduled git deploy of '$name' at $short skipped: it no longer applied", null, 'daemon', 'info', 'git');
            return null;
        }
        if ($exitStatus !== 0) {
            // Tried once, like every commit, but recorded as failed: the stack list shows it,
            // and Resume Automatic Deploys clears it so the next pass tries again. A failure
            // during up is recorded by compose.sh itself, as failedCommit, and pauses the stack.
            composeLogger("Scheduled git deploy of '$name' at $short failed (exit status $exitStatus)", null, 'daemon', 'warning', 'git');
            return self::HANDLED_FAILED;
        }
        return self::HANDLED_DEPLOYED;
    }

    /**
     * The commit whose scheduled deploy failed before up, if the last pass
     * recorded one and neither it nor the newer commit the last pass saw on the
     * branch has been deployed since.
     *
     * A scheduled deploy that failed during up is recorded the same way by the
     * pass, but compose.sh has recorded it as the stack's failed commit too;
     * that one is the failure to show, so this returns null for it.
     */
    public static function failedAutomaticDeploy(string $stackDir): ?string
    {
        $record = self::loadRecord($stackDir);
        if ($record['handledBy'] !== self::HANDLED_FAILED || $record['handledCommit'] === null) {
            return null;
        }
        $state = GitStackState::load($stackDir);
        if ($state->deployedCommit === $record['handledCommit'] || $state->deployedCommit === $record['remoteCommit']) {
            return null;
        }
        if ($state->failedCommit === $record['handledCommit']) {
            return null;
        }
        return $record['handledCommit'];
    }

    /**
     * Forget a scheduled deploy that failed before up, so the next pass
     * deploys the branch's latest commit again (compose-git resume).
     *
     * @return bool false if there was none
     */
    public static function clearFailedAutomaticDeploy(string $stackDir): bool
    {
        if (self::failedAutomaticDeploy($stackDir) === null) {
            return false;
        }
        $record = self::loadRecord($stackDir);
        $record['handledCommit'] = null;
        $record['handledBy'] = null;
        self::saveRecord($stackDir, $record);
        return true;
    }

    /**
     * What in the clone a deploy would stop on without the option to save it
     * (GitDeploy::localChanges(), which the deploy checks again under the stack's lock).
     *
     * @return string[] Changed files, and a line naming a hand-made commit
     */
    private static function localChanges(GitClone $clone, StackInfo $stack): array
    {
        return GitDeploy::localChanges($clone, GitStackState::load($stack->path));
    }

    /**
     * Whether any of the stack's containers is running (GitDeploy::hasRunningContainers()).
     *
     * @throws RuntimeException when docker cannot be asked: not knowing is not "stopped",
     *     so the pass leaves the stack and asks again next time
     */
    private static function hasRunningContainers(StackInfo $stack): bool
    {
        return GitDeploy::hasRunningContainers($stack->projectName);
    }

    /**
     * The command that deploys a stack at a commit for the pass: compose.sh gitdeploy through
     * compose_background.sh, as the web UI's background deploy runs it, as an automatic deploy
     * (--automatic-deploy-from), so the deploy checks again under the stack's lock that it applies.
     *
     * @param string|null $deployedWhenDecided The deployed commit the pass decided from (null if none)
     * @return string[]
     */
    public static function automaticDeployCommand(StackInfo $stack, string $commit, ?string $deployedWhenDecided): array
    {
        require_once '/usr/local/emhttp/plugins/compose.manager/include/Helpers.php';
        $waitSettings = resolveStackWaitSettings($stack->path, parse_plugin_cfg('compose.manager'));
        // The profiles the stack runs with, as Pull and Redeploy uses them: without them, profile
        // services would stay on the old commit while the deploy reports success.
        $command = buildGitDeployCommand($stack, $commit, false, gitDeployProfiles($stack, []), gitDeployWaitArguments($waitSettings));
        $command[] = '--automatic-deploy-from';
        $command[] = $deployedWhenDecided ?? 'none';
        // compose_background.sh takes compose.sh's arguments, without compose.sh itself. It is
        // run with sh, as AutoUpdateRunner runs its script, so it needs no execute bit.
        array_shift($command);
        array_unshift($command, '/bin/sh', dirname(__DIR__) . '/scripts/compose_background.sh');
        return $command;
    }

    /**
     * Deploy a stack at a commit (automaticDeployCommand()), waiting for it to finish.
     */
    private static function deployInBackgroundRunner(StackInfo $stack, string $commit, ?string $deployedWhenDecided): int
    {
        $command = self::automaticDeployCommand($stack, $commit, $deployedWhenDecided);

        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            return 1;
        }
        return proc_close($process);
    }

    /**
     * What earlier passes learned about a stack. A missing or unreadable file
     * means nothing is known yet: the next pass works it out again.
     *
     * @return array{remoteCommit: ?string, stackChanged: bool, handledCommit: ?string, handledBy: ?string, baseCommit: ?string}
     */
    public static function loadRecord(string $stackDir): array
    {
        $empty = ['remoteCommit' => null, 'stackChanged' => false, 'handledCommit' => null, 'handledBy' => null, 'baseCommit' => null];
        $data = json_decode((string) @file_get_contents(rtrim($stackDir, '/') . '/' . self::RECORD_FILE), true);
        if (!is_array($data) || ($data['version'] ?? null) !== self::RECORD_VERSION) {
            return $empty;
        }
        $commitOrNull = static fn(mixed $value): ?string => is_string($value) && GitClone::isCommitId($value) ? $value : null;
        return [
            'remoteCommit' => $commitOrNull($data['remoteCommit'] ?? null),
            'stackChanged' => ($data['stackChanged'] ?? false) === true,
            'handledCommit' => $commitOrNull($data['handledCommit'] ?? null),
            'handledBy' => in_array($data['handledBy'] ?? null, [self::HANDLED_NOTIFIED, self::HANDLED_DEPLOYED, self::HANDLED_PAUSED, self::HANDLED_LOCAL_CHANGES, self::HANDLED_FAILED, self::HANDLED_NOT_RUNNING], true)
                ? $data['handledBy']
                : null,
            'baseCommit' => $commitOrNull($data['baseCommit'] ?? null),
        ];
    }

    /**
     * @param array{remoteCommit: ?string, stackChanged: bool, handledCommit: ?string, handledBy: ?string, baseCommit: ?string} $record
     */
    private static function saveRecord(string $stackDir, array $record): void
    {
        $stackDir = rtrim($stackDir, '/');
        if (str_starts_with($stackDir, rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/')) {
            GitPathGuard::assertSafeToWrite($stackDir);
        }
        $json = json_encode(['version' => self::RECORD_VERSION] + $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $file = $stackDir . '/' . self::RECORD_FILE;
        $tmpFile = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmpFile, $json) !== strlen($json) || !rename($tmpFile, $file)) {
            @unlink($tmpFile);
            throw new RuntimeException("Could not write $file.");
        }
    }
}
