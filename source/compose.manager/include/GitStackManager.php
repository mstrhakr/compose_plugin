<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackState.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitClone.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/CredentialVault.php';

/**
 * The server refused a new ssh stack's clone, and the usual reason is that the repository
 * does not know the stack's deploy key yet. The message, which compose-git prints, ends with
 * the key; the web UI shows the clone's error and the key apart.
 */
final class GitDeployKeyNotAddedException extends RuntimeException
{
    public function __construct(
        public readonly string $cloneError,
        public readonly string $publicKey,
        Throwable $previous
    ) {
        parent::__construct(self::messageWithKey($cloneError, $publicKey), 0, $previous);
    }

    /**
     * The clone's error, followed by the deploy key and what to do with it.
     */
    public static function messageWithKey(string $cloneError, string $publicKey): string
    {
        return $cloneError . "\n\nIf the repository does not know this stack's deploy key yet, add it as a "
            . "read-only deploy key in the repository's settings, then try again:\n"
            . $publicKey;
    }
}

/**
 * Creates and looks after git stacks: what the compose-git command does,
 * apart from deploying (compose.sh gitdeploy does that).
 *
 * Stacks are named by their exact folder in the projects folder; nothing is
 * matched loosely. Nothing here deletes a file: a replaced compose file or
 * clone is moved aside, so every step can be undone by hand.
 */
final class GitStackManager
{
    public const DEFAULT_BRANCH = 'main';

    /**
     * How long the Credentials tab's Test waits for a repository: the browser waits for the
     * answer, and the registry test waits at most 15 seconds too.
     */
    private const CREDENTIAL_TEST_TIMEOUT_SECONDS = 15;

    /** @var callable(string): void */
    private $say;

    /**
     * @param string $composeRoot The plugin's projects folder
     * @param callable(string): void|null $say Receives progress messages
     */
    public function __construct(private readonly string $composeRoot, ?callable $say = null)
    {
        $this->say = $say ?? static function (string $message): void {
        };
    }

    /**
     * Create a new git stack: clone the repository, then make the stack folder.
     *
     * @return string The new stack's folder name
     * @throws RuntimeException|InvalidArgumentException naming why nothing was created
     */
    public function add(
        string $stackName,
        string $url,
        string $branch,
        string $composePath,
        ?string $clonesRoot,
        string $description = '',
        ?string $credentialId = null
    ): string {
        $this->assertArrayStarted();
        $folder = StackInfo::sanitizeProjectString($stackName);
        if ($folder === '') {
            throw new InvalidArgumentException("'$stackName' cannot be used as a stack name.");
        }
        $stackDir = $this->composeRoot . '/' . $folder;
        if (file_exists($stackDir) || @readlink($stackDir) !== false) {
            throw new RuntimeException("A stack folder named '$folder' already exists. Choose another name, or move that stack into git (compose-git convert, or the button on its Sources tab).");
        }
        // Before the clone, so a projects folder whose mount is gone leaves nothing behind.
        $this->assertSafeToWriteStackFolder($stackDir);

        $settings = $this->newSettings($url, $branch, $composePath, $clonesRoot, $folder, $credentialId);
        ($this->say)("Cloning $url ($branch) into {$settings->cloneDir}...");
        $this->createClone($settings);

        try {
            $stack = StackInfo::createNew($this->composeRoot, $stackName, $description, $settings->composeFileInClone());
            $stackDir = $this->composeRoot . '/' . $stack->projectFolder;
            $settings->saveCheckingCredential($stackDir);
            (new GitStackState(null, null))->save($stackDir);
        } catch (Throwable $error) {
            // Nothing here deletes files, so name what was left: a retry makes a new clone,
            // and stops at a stack folder that already exists.
            if (is_dir($stackDir)) {
                $leftBehind = "The clone at {$settings->cloneDir} was made, and the stack folder $stackDir was"
                    . ' only partly made. Remove both folders before trying again.';
            } else {
                $leftBehind = "The clone at {$settings->cloneDir} was made, but the stack was not."
                    . ' Remove that folder before trying again.';
            }
            throw new RuntimeException($error->getMessage() . "\n" . $leftBehind, 0, $error);
        }
        ($this->say)("Created stack '{$stack->projectFolder}'. It is not deployed yet.");
        if ($settings->credentialId !== null) {
            $this->logCredentialChange($stack->projectFolder, $settings->credentialId);
        }
        return $stack->projectFolder;
    }

    /**
     * Turn an existing stack into a git stack.
     *
     * The stack keeps its folder, name, project name, .env and plugin-managed
     * override. Its old compose file (for a stack kept in the projects folder)
     * and its old indirect settings are moved into a dated backup folder in
     * the stack folder, so the change can be undone by moving them back.
     * Running containers are left alone until the next deploy.
     *
     * @return string The backup folder
     * @throws RuntimeException|InvalidArgumentException naming why nothing was changed
     */
    public function convert(
        string $folder,
        string $url,
        string $branch,
        string $composePath,
        ?string $clonesRoot,
        ?string $credentialId = null
    ): string {
        $this->assertArrayStarted();
        $stack = $this->stack($folder);
        if ($stack->isGitStack()) {
            throw new RuntimeException("'$folder' is already a git stack.");
        }
        $stackDir = $stack->path;

        return $this->withStackLock($stack, function () use ($stack, $stackDir, $folder, $url, $branch, $composePath, $clonesRoot, $credentialId): string {
            $oldOverride = $stack->getOverridePath();
            $oldEnv = $stack->getEffectiveEnvFilePath();
            // Whether the .env in use is the one next to the old compose file. That one stops
            // being used once the compose file is in the clone. An envpath setting is not enough
            // to tell: one that names a missing file is ignored, and that .env is used instead.
            $usesEnvNextToComposeFile = $oldEnv !== null && Path::refersToSamePath($oldEnv, $stack->composeSource . '/.env');
            $oldComposeFile = $stack->isIndirect ? null : $stack->composeFilePath;
            $foldersNextToOldComposeFile = $this->foldersIn($stack->composeSource);

            $settings = $this->newSettings($url, $branch, $composePath, $clonesRoot, $folder, $credentialId);
            ($this->say)("Cloning $url ($branch) into {$settings->cloneDir}...");
            $this->createClone($settings);

            // From here the stack folder changes; everything replaced goes into the backup.
            $backupDir = $stackDir . '/pre-git-' . date('Y-m-d_His');
            $this->assertSafeToWriteStackFolder($backupDir);
            if (!@mkdir($backupDir, 0755)) {
                throw new RuntimeException("Could not create $backupDir. The clone at {$settings->cloneDir} was made; nothing else changed.");
            }
            foreach (['indirect', 'indirect_mode', 'use_default_compose_files'] as $metadataFile) {
                $source = $stackDir . '/' . $metadataFile;
                if (is_file($source) && !@copy($source, $backupDir . '/' . $metadataFile)) {
                    throw new RuntimeException(
                        "Could not copy $source into $backupDir. Nothing else in the stack was changed; "
                        . "the clone at {$settings->cloneDir} was made."
                    );
                }
            }
            if ($oldComposeFile !== null && is_file($oldComposeFile) && dirname($oldComposeFile) === $stackDir) {
                if (!rename($oldComposeFile, $backupDir . '/' . basename($oldComposeFile))) {
                    throw new RuntimeException(
                        "Could not move $oldComposeFile into $backupDir. Nothing else in the stack was changed; "
                        . "the clone at {$settings->cloneDir} was made."
                    );
                }
                ($this->say)('Moved the old ' . basename($oldComposeFile) . " to $backupDir.");
            }

            try {
                $indirect = $settings->composeFileInClone();
                if (@file_put_contents($stackDir . '/indirect', $indirect) !== strlen($indirect)) {
                    throw new RuntimeException("Could not write $stackDir/indirect.");
                }
                if (@file_put_contents($stackDir . '/indirect_mode', 'file') !== strlen('file')) {
                    throw new RuntimeException("Could not write $stackDir/indirect_mode.");
                }

                // An indirect stack may have used a .env next to its old compose
                // file. Keep using it: copy it into the stack folder.
                $stackEnv = $stackDir . '/.env';
                if ($usesEnvNextToComposeFile && $oldEnv !== null && $oldEnv !== $stackEnv && !is_file($stackEnv)) {
                    if (!@copy($oldEnv, $stackEnv)) {
                        throw new RuntimeException("Could not copy $oldEnv to $stackEnv.");
                    }
                    ($this->say)("Copied $oldEnv to $stackEnv.");
                }

                $settings->saveCheckingCredential($stackDir);
                (new GitStackState(null, null))->save($stackDir);

                // The managed override's name follows the compose file's name, so
                // it can change. Carry the old one over if the new one is missing.
                StackInfo::clearCache();
                $newOverride = StackInfo::fromProject($this->composeRoot, $folder)->getOverridePath();
                if ($oldOverride !== null && is_file($oldOverride) && $newOverride !== null
                    && $newOverride !== $oldOverride && !$this->hasContent($newOverride)) {
                    if (!@copy($oldOverride, $newOverride)) {
                        throw new RuntimeException("Could not copy the plugin's override $oldOverride to $newOverride.");
                    }
                    ($this->say)("Copied the plugin's override $oldOverride to $newOverride.");
                }
            } catch (Throwable $error) {
                // The old compose file may already be in the backup folder, so say how to undo.
                throw new RuntimeException(
                    $error->getMessage() . "\nThe stack was only partly converted. To undo it, delete whichever of"
                    . " git.json, git_state.json, indirect and indirect_mode are in $stackDir, then copy the files in"
                    . " $backupDir back into it. The clone at {$settings->cloneDir} was made.",
                    0,
                    $error
                );
            }

            ($this->say)("'$folder' is now a git stack. Its containers change at the next deploy.");
            if ($settings->credentialId !== null) {
                $this->logCredentialChange($folder, $settings->credentialId);
            }
            if ($foldersNextToOldComposeFile !== []) {
                // The compose file is not parsed here; this is only a reminder.
                ($this->say)(
                    'Note: these folders are next to the old compose file: ' . implode(', ', $foldersNextToOldComposeFile)
                    . '. If the compose file mounts any of them with a relative path (such as ./data), that path now'
                    . ' points inside the clone, and the container would start on an empty folder. Use an absolute'
                    . ' path, or move the data, before deploying.'
                );
            }
            return $backupDir;
        });
    }

    /**
     * The names of the folders in a folder, leaving out the ones the plugin makes.
     *
     * @return string[]
     */
    private function foldersIn(string $folder): array
    {
        $names = [];
        foreach (@scandir($folder) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'git-changes' || str_starts_with($entry, 'pre-git-')) {
                continue;
            }
            if (is_dir($folder . '/' . $entry)) {
                $names[] = $entry;
            }
        }
        return $names;
    }

    /**
     * Compare the deployed commit with the branch on the remote. Changes no container and
     * no file of the stack; when the branch has a newer commit, it is fetched into the
     * clone (under the stack's lock, as a deploy would fetch it) to see what it changes.
     * The deployed commit is read under that lock too, so the check is refused while
     * another operation on the stack is running.
     *
     * changesStack is null when the stack is up to date. Otherwise it says whether the new
     * commit changes anything in the stack's folder in the repository (the folder holding
     * its compose file, or the whole repository for a compose file at the top). It is true
     * when that cannot be identified: nothing deployed yet, or the clone no longer has the
     * deployed commit (deployedCommitMissing says which, so the answer can say so). It is
     * false when every change is outside the stack's folder, as a commit to another stack
     * in a shared repository is. Files the stack uses from outside its folder (a ../shared
     * bind mount, a build context of .., an env_file, extends or include in another folder)
     * are not looked at, so false does not prove the new commit changes nothing the stack
     * uses.
     *
     * stackFolder is that folder, relative to the top of the repository ('' for the top).
     *
     * @param int|null $timeoutSeconds How long to wait for the remote; null for git's usual limit
     * @return array{stack: string, branch: string, remoteCommit: string, deployedCommit: ?string, failedCommit: ?string, upToDate: bool, changesStack: ?bool, deployedCommitMissing: bool, stackFolder: string}
     * @throws RuntimeException if the stack or the remote cannot be read, or another operation on the stack is running
     */
    public function check(string $folder, ?int $timeoutSeconds = null): array
    {
        $stack = $this->stack($folder);
        $settings = $this->settings($stack);
        $clone = new GitClone($settings);
        // Asking the remote can take a while, so it is done before taking the lock: a deploy
        // started meanwhile is not refused.
        $remote = $timeoutSeconds === null ? $clone->remoteBranchCommit() : $clone->remoteBranchCommit($timeoutSeconds);

        // What is deployed is read under the lock, after the remote has answered. A deploy
        // or rollback that finished while the remote was asked is then counted, and none
        // can finish while the deployed commit is compared with the remote's.
        [$state, $changesStack, $deployedCommitMissing] = $this->withStackLock(
            $stack,
            /** @return array{GitStackState, ?bool, bool} state, changesStack, deployedCommitMissing */
            static function () use ($stack, $clone, $remote): array {
                $state = GitStackState::load($stack->path);
                $deployed = $state->deployedCommit;
                if ($deployed === null) {
                    // Nothing deployed yet: whatever the branch has is new to the stack.
                    return [$state, true, false];
                }
                if ($deployed === $remote) {
                    return [$state, null, false];
                }
                $clone->fetch();
                if (!$clone->hasCommit($deployed)) {
                    // Recloned since, after a force-push for one. Nothing to compare with.
                    return [$state, true, true];
                }
                return [$state, $clone->stackFilesChanged($deployed, $remote), false];
            }
        );
        $deployed = $state->deployedCommit;

        return [
            'stack' => $folder,
            'branch' => $settings->branch,
            'remoteCommit' => $remote,
            'deployedCommit' => $deployed,
            'failedCommit' => $state->failedCommit,
            'upToDate' => $deployed === $remote,
            'changesStack' => $changesStack,
            'deployedCommitMissing' => $deployedCommitMissing,
            'stackFolder' => dirname($settings->composePath) === '.' ? '' : dirname($settings->composePath),
        ];
    }

    /**
     * Try a deploy of a git stack without deploying it (compose-git deploy --dry-run): see
     * GitDeploy::dryRun(). Refused while another operation on the stack is running, since
     * the dry run checks other commits out in the clone for a moment.
     *
     * @param string|null $commit A specific commit to try, or null for the branch's latest
     * @param string[] $profiles Compose profiles the deploy would use
     * @return array{previousCommit: string, deployedCommit: ?string, targetCommit: string, problems: string[], recreatesEveryContainer: bool, configDiff: ?string, configProblem: ?string}
     */
    public function dryRun(string $folder, ?string $commit, array $profiles): array
    {
        require_once '/usr/local/emhttp/plugins/compose.manager/include/GitDeploy.php';
        $stack = $this->gitStack($folder);
        if (!$stack->hasResolvedIdentity()) {
            throw new RuntimeException((string) $stack->getIdentityBlockReason());
        }
        $deploy = new GitDeploy($stack->path, $this->say);
        return $this->withStackLock(
            $stack,
            /** @return array{previousCommit: string, deployedCommit: ?string, targetCommit: string, problems: string[], recreatesEveryContainer: bool, configDiff: ?string, configProblem: ?string} */
            static fn(): array => $deploy->dryRun($stack->projectName, $profiles, $commit)
        );
    }

    /**
     * What is known about a git stack locally, without asking the remote.
     *
     * @return array{stack: string, url: string, branch: string, composePath: string, cloneDir: string, recreateOnFolderChange: bool, autoDeploy: string, credential: ?string, deployedCommit: ?string, failedCommit: ?string, checkedOutCommit: ?string, localChanges: list<string>, commitMadeByHand: bool, failedAutomaticCommit: ?string, problem: ?string}
     */
    public function status(string $folder): array
    {
        $stack = $this->stack($folder);
        $settings = $this->settings($stack);
        $state = GitStackState::load($stack->path);
        $checkedOut = null;
        $changes = [];
        $madeByHand = false;
        $problem = null;
        try {
            $clone = new GitClone($settings);
            $checkedOut = $clone->checkedOutCommit();
            $changes = $clone->locallyChangedFiles();
            $madeByHand = $clone->isMadeByHand($checkedOut, $state->deployedCommit, $state->failedCommit);
        } catch (Throwable $error) {
            $problem = $error->getMessage();
        }
        return [
            'stack' => $folder,
            'url' => $settings->url,
            'branch' => $settings->branch,
            'composePath' => $settings->composePath,
            'cloneDir' => $settings->cloneDir,
            'recreateOnFolderChange' => $settings->recreateOnFolderChange,
            'autoDeploy' => $settings->autoDeploy,
            'credential' => $settings->credentialId === null ? null : $this->credentialName($settings->credentialId),
            'deployedCommit' => $state->deployedCommit,
            'failedCommit' => $state->failedCommit,
            'checkedOutCommit' => $checkedOut,
            'localChanges' => $changes,
            'commitMadeByHand' => $madeByHand,
            'failedAutomaticCommit' => self::failedAutomaticCommit($stack->path),
            'problem' => $problem,
        ];
    }

    /**
     * The commit whose scheduled deploy failed before up, if any (see GitScheduledCheck).
     */
    private static function failedAutomaticCommit(string $stackDir): ?string
    {
        require_once '/usr/local/emhttp/plugins/compose.manager/include/GitScheduledCheck.php';
        return GitScheduledCheck::failedAutomaticDeploy($stackDir);
    }

    /**
     * Change the credential a git stack uses to reach its repository, or remove it.
     *
     * An ssh stack always keeps a deploy key, and can only be given another key of its own
     * repository (see repositoryDeployKeys()): that is how stacks that each got a key of their
     * own, before keys were shared, move onto one.
     *
     * The repository is reached with the new setting first, and nothing is
     * saved unless that works.
     *
     * @throws RuntimeException|InvalidArgumentException naming why nothing was changed
     */
    public function setCredential(string $folder, ?string $credentialId): void
    {
        $this->assertArrayStarted();
        $stack = $this->stack($folder);
        $settings = $this->settings($stack);
        $oldCredentialId = $settings->credentialId;
        if ($settings->isSsh()) {
            if ($credentialId !== null && $credentialId === $oldCredentialId) {
                ($this->say)("'$folder' already uses the deploy key '" . $this->credentialName($credentialId) . "'. Nothing was changed.");
                return;
            }
            $this->assertRepositoryDeployKey($folder, $settings, $credentialId);
        }

        $this->withStackLock($stack, function () use ($stack, $settings, $credentialId): void {
            $changed = $settings->withCredentialId($credentialId);
            ($this->say)("Checking that {$changed->url} can be reached with the new setting...");
            (new GitClone($changed))->remoteBranchCommit();
            $changed->saveCheckingCredential($stack->path);
        });
        $this->logCredentialChange($folder, $credentialId);
        ($this->say)($credentialId === null
            ? "'$folder' now reaches its repository without a credential."
            : "'$folder' now uses the credential '" . $this->credentialName($credentialId) . "'.");
        if ($settings->isSsh() && $oldCredentialId !== null && $oldCredentialId !== $credentialId) {
            ($this->say)(
                "Its old deploy key '" . $this->credentialName($oldCredentialId) . "' stays on the Credentials tab, which "
                . "shows whether another stack still uses it. If none does, delete it there and from the repository's deploy keys."
            );
        }
    }

    /**
     * Refuse any credential for an ssh stack but a deploy key of its own repository.
     *
     * @throws InvalidArgumentException naming the keys it could be given
     */
    private function assertRepositoryDeployKey(string $folder, GitStackSettings $settings, ?string $credentialId): void
    {
        $address = GitStackSettings::sshAddress($settings->url);
        $keys = $address === null ? [] : $this->repositoryDeployKeys($folder, $address);
        if ($credentialId !== null && isset($keys[$credentialId])) {
            return;
        }
        throw $this->notARepositoryDeployKey($folder, $keys);
    }

    /**
     * Why an ssh stack cannot be given a credential, naming the deploy keys it could be given.
     *
     * @param array<string, string> $keys The repository's deploy keys, by id
     */
    private function notARepositoryDeployKey(string $folder, array $keys): InvalidArgumentException
    {
        $choices = array_map(static fn(string $name): string => "'$name'", array_values($keys));
        return new InvalidArgumentException(
            "An ssh stack reaches its repository with a deploy key, so '$folder' can only be given a deploy key of the "
            . 'same repository that another stack uses, or one made for the repository'
            . ($choices === [] ? ', and there is none.' : ': ' . implode(', ', $choices) . '.')
            . ' compose-git deploy-key shows the key it uses now.'
        );
    }

    /**
     * The deploy keys of an ssh stack's repository, other than its own: the keys other ssh stacks
     * of the same repository use, and a key made for the repository, by id, with their names.
     *
     * @param array{user: string, host: string, port: int, path: string} $address
     * @return array<string, string>
     */
    public function repositoryDeployKeys(string $folder, array $address): array
    {
        $deployKeys = $this->deployKeysOnHost($address);
        $keys = [];
        foreach ($this->stacksOfRepository($folder, $address) as $credentialId) {
            if (isset($deployKeys[$credentialId])) {
                $keys[$credentialId] = $deployKeys[$credentialId];
            }
        }
        $repositoryKeyName = 'deploy key for ' . self::sshRepositoryName($address);
        foreach ($deployKeys as $id => $name) {
            if ($name === $repositoryKeyName) {
                $keys[$id] = $name;
            }
        }
        return $keys;
    }

    /**
     * The id of the credential named $nameOrId (or with that id) that the stack $folder may be given:
     * for an ssh stack a deploy key of its repository, otherwise an HTTPS token.
     *
     * @throws RuntimeException if there is none, or several with that name
     */
    public function findCredentialFor(string $folder, string $nameOrId): string
    {
        $settings = $this->settings($this->stack($folder));
        $address = GitStackSettings::sshAddress($settings->url);
        if ($address === null) {
            return $this->findGitCredential($nameOrId);
        }
        // The key it uses now: setCredential() then says so and changes nothing.
        $current = $settings->credentialId;
        if ($current !== null && ($current === $nameOrId || $this->credentialName($current) === $nameOrId)) {
            return $current;
        }
        $keys = $this->repositoryDeployKeys($folder, $address);
        $matches = [];
        foreach ($keys as $id => $name) {
            if ($id === $nameOrId || $name === $nameOrId) {
                $matches[] = $id;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new RuntimeException("Several deploy keys are named '$nameOrId'. Use the key's id instead.");
        }
        throw $this->notARepositoryDeployKey($folder, $keys);
    }

    /**
     * Choose what the scheduled check does when a new commit changes a git
     * stack: nothing (off), send a notification (notify), or deploy it (deploy).
     *
     * @throws RuntimeException|InvalidArgumentException naming why nothing was changed
     */
    public function setAutoDeploy(string $folder, string $autoDeploy): void
    {
        $this->assertArrayStarted();
        $stack = $this->stack($folder);
        $settings = $this->settings($stack);

        $this->withStackLock($stack, function () use ($stack, $settings, $autoDeploy): void {
            $settings->withAutoDeploy($autoDeploy)->save($stack->path);
        });
        ($this->say)(match ($autoDeploy) {
            GitStackSettings::AUTO_DEPLOY_NOTIFY => "'$folder' sends a notification when a new commit changes it.",
            GitStackSettings::AUTO_DEPLOY_DEPLOY => "'$folder' is deployed when a new commit changes it.",
            default => "'$folder' is no longer checked on a schedule.",
        });
    }

    /**
     * Clear a git stack's failed deploy, so the scheduled check deploys it again.
     *
     * After a failed deploy the stack is paused: the scheduled check leaves it
     * alone until a deploy works. Someone who fixed it another way (by hand, or
     * with the web UI's Update) clears the failure with this. The deployed
     * commit stays as it was. It also clears a scheduled deploy that failed
     * before any container was started, which is otherwise not tried again.
     *
     * @return bool false if the stack had no failed deploy to clear
     * @throws RuntimeException naming why nothing was changed
     */
    public function resume(string $folder): bool
    {
        $this->assertArrayStarted();
        $stack = $this->stack($folder);
        $checked = $this->settings($stack)->autoDeploy !== GitStackSettings::AUTO_DEPLOY_OFF;

        return $this->withStackLock($stack, function () use ($stack, $folder, $checked): bool {
            $cleared = false;
            $state = GitStackState::load($stack->path);
            if ($state->failedCommit !== null) {
                (new GitStackState($state->deployedCommit, null))->save($stack->path);
                ($this->say)("Cleared the failed deploy of " . substr($state->failedCommit, 0, 12) . '.'
                    . ($checked ? " Automatic deploys of '$folder' resume." : ''));
                $cleared = true;
            }
            // A scheduled deploy that failed before up (a pull, say) is kept in the
            // scheduled check's record instead; clearing it lets the next check try again.
            require_once '/usr/local/emhttp/plugins/compose.manager/include/GitScheduledCheck.php';
            $failedAutomatic = GitScheduledCheck::failedAutomaticDeploy($stack->path);
            if ($failedAutomatic !== null) {
                GitScheduledCheck::clearFailedAutomaticDeploy($stack->path);
                ($this->say)("Cleared the failed automatic deploy of " . substr($failedAutomatic, 0, 12) . '.'
                    . ($checked ? " The next scheduled check of '$folder' tries again." : ''));
                $cleared = true;
            }
            if (!$cleared) {
                ($this->say)("'$folder' has no failed deploy, so there is nothing to resume.");
            }
            return $cleared;
        });
    }

    /**
     * The id of a git repository credential, given its exact name or its id.
     *
     * @throws RuntimeException if there is none, or several with that name
     */
    public function findGitCredential(string $nameOrId): string
    {
        $gitCredentials = array_values(array_filter(
            (new CredentialVault())->listCredentials(),
            // HTTPS tokens only: a deploy key is chosen by the repository it reaches, never by name.
            static fn(array $credential): bool => ($credential['provider'] ?? '') === 'git'
        ));
        $matches = array_values(array_filter(
            $gitCredentials,
            static fn(array $credential): bool => $credential['id'] === $nameOrId || $credential['name'] === $nameOrId
        ));
        if (count($matches) === 1) {
            return $matches[0]['id'];
        }
        if (count($matches) > 1) {
            throw new RuntimeException("Several git credentials are named '$nameOrId'. Use the credential's id instead.");
        }
        $names = array_map(static fn(array $credential): string => $credential['name'], $gitCredentials);
        throw new RuntimeException(
            "There is no git credential named '$nameOrId'. "
            . ($names === [] ? 'Add one on the Credentials tab of the plugin settings first.' : 'Git credentials: ' . implode(', ', $names) . '.')
        );
    }

    /**
     * Test a git credential by reaching the repository of a git stack that uses it.
     *
     * @return array{id: string, name: string, registry: string, valid: bool, message: string}|null
     *     null when no git stack uses the credential, so there is no repository to try
     */
    public function testCredential(string $credentialId): ?array
    {
        foreach ($this->listGitStacks() as $folder) {
            try {
                $settings = $this->settings($this->stack($folder));
            } catch (Throwable) {
                continue;
            }
            if ($settings->credentialId !== $credentialId) {
                continue;
            }
            $summary = (new CredentialVault())->getCredentialSummary($credentialId);
            $result = ['id' => $credentialId, 'name' => $summary['name'], 'registry' => $summary['registry']];
            try {
                (new GitClone($settings))->remoteBranchCommit(self::CREDENTIAL_TEST_TIMEOUT_SECONDS);
                return $result + ['valid' => true, 'message' => "Reached {$settings->url} (used by '$folder')."];
            } catch (Throwable $error) {
                return $result + ['valid' => false, 'message' => "{$settings->url} (used by '$folder'): " . $error->getMessage()];
            }
        }
        return null;
    }

    /**
     * The public half of an ssh stack's deploy key, to add to the repository's settings.
     *
     * @throws RuntimeException if the stack is not an ssh stack
     */
    public function deployKey(string $folder): string
    {
        $settings = $this->settings($this->stack($folder));
        if (!$settings->isSsh() || $settings->credentialId === null) {
            throw new RuntimeException("'$folder' does not reach its repository over ssh, so it has no deploy key.");
        }
        return GitSsh::publicKey($settings->credentialId);
    }

    /**
     * Pin an ssh stack's repository host keys again, after the server's keys changed.
     *
     * The old and new fingerprints are shown, and the new keys are saved only
     * after the repository has been reached with them.
     *
     * @throws RuntimeException naming why nothing was changed
     */
    public function trustHost(string $folder): void
    {
        $this->assertArrayStarted();
        $stack = $this->stack($folder);
        $settings = $this->settings($stack);
        $address = GitStackSettings::sshAddress($settings->url);
        if ($address === null) {
            throw new RuntimeException("'$folder' does not reach its repository over ssh.");
        }

        $this->withStackLock($stack, function () use ($stack, $settings, $address): void {
            $knownHosts = GitSsh::scanHostKeys($address['host'], $address['port']);
            ($this->say)('Pinned now:   ' . implode(', ', GitSsh::fingerprints((string) $settings->sshKnownHosts)));
            ($this->say)('Host offers:  ' . implode(', ', GitSsh::fingerprints($knownHosts)));
            if ($knownHosts === $settings->sshKnownHosts) {
                ($this->say)('The host keys have not changed.');
                return;
            }
            ($this->say)('Compare the new fingerprints with the ones your git host publishes before you deploy.');
            $changed = $settings->withSshKnownHosts($knownHosts);
            (new GitClone($changed))->remoteBranchCommit();
            $changed->save($stack->path);
            ($this->say)('The new host keys are pinned.');
        });
    }

    /**
     * Settings for a new git stack. For an ssh repository this also makes (or
     * reuses) the repository's deploy key and pins the host's keys.
     */
    private function newSettings(
        string $url,
        string $branch,
        string $composePath,
        ?string $clonesRoot,
        string $folder,
        ?string $credentialId
    ): GitStackSettings {
        $settings = GitStackSettings::createNew($url, $branch, $composePath, $clonesRoot ?? COMPOSE_GIT_DEFAULT_CLONES_ROOT, $folder);
        if (!$settings->isSsh()) {
            return $settings->withCredentialId($credentialId);
        }

        $address = GitStackSettings::sshAddress($url);
        if ($address === null) {
            throw new InvalidArgumentException("The ssh repository address is not valid: $url");
        }
        if ($credentialId !== null) {
            throw new InvalidArgumentException('An ssh stack uses the deploy key the plugin keeps for its repository, so it cannot be given another credential.');
        }
        // The host first: a mistyped host then fails before a deploy key is made for it.
        $knownHosts = GitSsh::scanHostKeys($address['host'], $address['port']);
        $credentialId = $this->deployKeyFor($folder, $address);
        ($this->say)("Pinned the ssh host keys of {$address['host']}: " . implode(', ', GitSsh::fingerprints($knownHosts)));
        ($this->say)('Compare them with the fingerprints your git host publishes.');
        return $settings->withCredentialId($credentialId)->withSshKnownHosts($knownHosts);
    }

    /**
     * The deploy key for a new ssh stack. One key serves every stack of a repository: GitHub
     * refuses one key on two repositories, but not one key for several stacks of the same one,
     * and every such key would have the same read-only access anyway.
     *
     * An existing key is used if there is one (see existingDeployKey()); otherwise a new key is
     * made, named after the repository so that later stacks of the repository find it. Nothing
     * locks this choice: two stacks of a new repository added at the same moment can each make a
     * key. Both keys work; the Credentials tab then lists two keys of the same name.
     *
     * @param array{user: string, host: string, port: int, path: string} $address
     */
    private function deployKeyFor(string $folder, array $address): string
    {
        $existing = $this->existingDeployKey($folder, $address);
        if ($existing !== null) {
            ($this->say)($existing['message']);
            return $existing['id'];
        }
        $repository = self::sshRepositoryName($address);
        $name = "deploy key for $repository";
        $key = GitSsh::generateKey("compose-manager $repository");
        ($this->say)("Made a new deploy key for $repository ('$name' on the Credentials tab). Other stacks of this repository will use it too.");
        $id = (new CredentialVault())->saveCredential([
            'name' => $name,
            'provider' => 'git-ssh',
            'registry' => self::sshRegistry($address),
            'username' => $address['user'],
            'secret' => $key['private'],
        ])['id'];
        composeLogger(
            "Made a deploy key for $repository, for git stack '$folder'",
            ['id' => $id, 'host' => self::sshRegistry($address)],
            'user',
            'info',
            'credentials'
        );
        return $id;
    }

    /**
     * The deploy key a new ssh stack would use without making one, and the message that says
     * why. In this order:
     *  1. the key another ssh stack of the same repository uses: the repository knows it already;
     *  2. a key made for this repository earlier, such as by an attempt whose clone failed
     *     because the key had not been added to the repository yet;
     *  3. a key named after this stack by an earlier attempt of an older version of the plugin,
     *     as long as no git stack uses it: such a key may belong to another repository, and
     *     one that a stack uses could not be deleted to make way for a new one.
     * The same repository means the same user, host, port and path in the address.
     *
     * @param array{user: string, host: string, port: int, path: string} $address
     * @return array{id: string, message: string}|null
     */
    public function existingDeployKey(string $folder, array $address): ?array
    {
        $deployKeys = $this->deployKeysOnHost($address);

        foreach ($this->stacksOfRepository($folder, $address) as $otherFolder => $credentialId) {
            if (!isset($deployKeys[$credentialId])) {
                continue;
            }
            $name = $deployKeys[$credentialId];
            return [
                'id' => $credentialId,
                'message' => "Using the deploy key of '$otherFolder' ('$name' on the Credentials tab), which "
                    . 'reaches the same repository: the repository knows it already.',
            ];
        }

        $repositoryKeyName = 'deploy key for ' . self::sshRepositoryName($address);
        foreach ($deployKeys as $id => $name) {
            if ($name === $repositoryKeyName) {
                return [
                    'id' => $id,
                    'message' => "Using the deploy key made for this repository earlier ('$name' on the Credentials tab).",
                ];
            }
        }

        $stackKeyName = "$folder deploy key";
        $keysInUse = array_values($this->gitStackCredentials());
        foreach ($deployKeys as $id => $name) {
            if ($name === $stackKeyName && !in_array($id, $keysInUse, true)) {
                return [
                    'id' => $id,
                    'message' => "Using the deploy key made for '$folder' earlier ('$name' on the Credentials tab). If it was "
                        . 'made for another repository, by a stack of the same name that has since been deleted, '
                        . "delete '$name' first: GitHub, for one, refuses a deploy key that another repository has.",
                ];
            }
        }
        return null;
    }

    /**
     * The deploy keys in the credential vault for the host (and port) of an ssh address: names by id.
     *
     * @param array{user: string, host: string, port: int, path: string} $address
     * @return array<string, string>
     */
    private function deployKeysOnHost(array $address): array
    {
        $keys = [];
        foreach ((new CredentialVault())->listCredentials() as $credential) {
            if ($credential['provider'] === 'git-ssh' && $credential['registry'] === self::sshRegistry($address)) {
                $keys[$credential['id']] = $credential['name'];
            }
        }
        return $keys;
    }

    /**
     * The other ssh stacks of a repository and the credential each uses: the git stacks, other
     * than $exceptFolder, whose address has the same user, host, port and path.
     *
     * @param array{user: string, host: string, port: int, path: string} $address
     * @return array<string, string> Credential ids by stack folder
     */
    private function stacksOfRepository(string $exceptFolder, array $address): array
    {
        $stacks = [];
        foreach ($this->gitStackCredentials() as $folder => $credentialId) {
            if ($folder === $exceptFolder) {
                continue;
            }
            $url = $this->gitStackUrl($folder);
            if ($url !== null && GitStackSettings::sshAddress($url) === $address) {
                $stacks[$folder] = $credentialId;
            }
        }
        return $stacks;
    }

    /**
     * The credential every git stack uses, by stack folder; a stack with none is left out.
     *
     * git.json is read as plain JSON, as the Credentials tab reads it: a stack whose settings fail
     * a check still uses its credential. One that cannot be read at all is named in a message.
     *
     * @return array<string, string>
     */
    private function gitStackCredentials(): array
    {
        $credentials = [];
        foreach ($this->listGitStacks() as $folder) {
            $data = $this->readGitJson($folder);
            if ($data === null) {
                ($this->say)("Could not read the git settings of '$folder', so its deploy key is not looked at.");
                continue;
            }
            $credentialId = $data['credentialId'] ?? null;
            if (is_string($credentialId) && $credentialId !== '') {
                $credentials[$folder] = $credentialId;
            }
        }
        return $credentials;
    }

    /**
     * A git stack's repository address from its git.json, read as plain JSON; null if unreadable.
     */
    private function gitStackUrl(string $folder): ?string
    {
        $url = ($this->readGitJson($folder) ?? [])['url'] ?? null;
        return is_string($url) ? $url : null;
    }

    /**
     * A git stack's git.json as plain JSON, or null if it cannot be read or is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    private function readGitJson(string $folder): ?array
    {
        $data = json_decode((string) @file_get_contents($this->composeRoot . '/' . $folder . '/' . GitStackSettings::FILE_NAME), true);
        return is_array($data) ? $data : null;
    }

    /**
     * An ssh repository's address as a deploy key's name shows it: user@host:path, or
     * ssh://user@host:port/path for a port other than 22.
     *
     * @param array{user: string, host: string, port: int, path: string} $address
     */
    private static function sshRepositoryName(array $address): string
    {
        if ($address['port'] === 22) {
            return "{$address['user']}@{$address['host']}:{$address['path']}";
        }
        return "ssh://{$address['user']}@{$address['host']}:{$address['port']}/{$address['path']}";
    }

    /**
     * The host a deploy key is kept under in the credential vault: host, or host:port for a
     * port other than 22.
     *
     * @param array{user: string, host: string, port: int, path: string} $address
     */
    private static function sshRegistry(array $address): string
    {
        return $address['host'] . ($address['port'] === 22 ? '' : ':' . $address['port']);
    }

    /**
     * Clone a new stack's repository. When an ssh clone fails, show the deploy
     * key: the usual reason is that it has not been added to the repository yet.
     *
     * Only a failure of git clone itself can be the key. A check before or after
     * it (the folder already exists, the compose file is not on the branch) is
     * passed on as it is.
     */
    private function createClone(GitStackSettings $settings): void
    {
        try {
            (new GitClone($settings))->create();
        } catch (GitCloneFailedException $error) {
            if (!$settings->isSsh() || $settings->credentialId === null) {
                throw $error;
            }
            try {
                $publicKey = GitSsh::publicKey($settings->credentialId);
            } catch (RuntimeException) {
                // Not a deploy key at all (the wrong --credential): the clone's own message says so.
                throw $error;
            }
            if ($error->serverRefusedAccess()) {
                throw new GitDeployKeyNotAddedException($error->getMessage(), $publicKey, $error);
            }
            // Most likely something else (an unreachable host, a wrong branch), but a host may
            // word a refusal in a way not recognised here, so the key still comes with the error.
            throw new RuntimeException(
                GitDeployKeyNotAddedException::messageWithKey($error->getMessage(), $publicKey),
                0,
                $error
            );
        }
    }

    /**
     * A credential's name for messages, or a note that it is gone.
     */
    private function credentialName(string $credentialId): string
    {
        try {
            return (new CredentialVault())->getCredentialSummary($credentialId)['name'];
        } catch (Throwable) {
            return "$credentialId (no longer in the credential vault)";
        }
    }

    /**
     * Record in the syslog which credential a git stack now uses, as the web UI's credential actions do.
     */
    private function logCredentialChange(string $folder, ?string $credentialId): void
    {
        if ($credentialId === null) {
            composeLogger("Git stack '$folder' no longer uses a credential", null, 'user', 'info', 'credentials');
            return;
        }
        composeLogger(
            "Git stack '$folder' now uses the credential '" . $this->credentialName($credentialId) . "'",
            ['id' => $credentialId],
            'user',
            'info',
            'credentials'
        );
    }

    /**
     * Replace a stack's clone with a fresh one at the deployed commit.
     *
     * The old clone is moved aside, never deleted: untracked data such as a
     * relative ./data bind mount lives in it, and stays there to be copied
     * back by hand. If the new clone fails, the old one is moved back.
     *
     * @return string|null Where the old clone was moved, or null if there was none
     * @throws RuntimeException naming the problem
     */
    public function reclone(string $folder): ?string
    {
        $this->assertArrayStarted();
        $stack = $this->stack($folder);
        $settings = $this->settings($stack);

        return $this->withStackLock($stack, function () use ($stack, $settings): ?string {
            $state = GitStackState::load($stack->path);
            $cloneDir = $settings->cloneDir;
            $movedTo = null;

            $untrackedInOldClone = [];
            if (file_exists($cloneDir) || @readlink($cloneDir) !== false) {
                if (@readlink($cloneDir) !== false) {
                    throw new RuntimeException("$cloneDir is a symlink, so it was left alone.");
                }
                // Data a container wrote through a relative bind mount stays in
                // the old clone; list it so the person knows what to copy back.
                try {
                    $untrackedInOldClone = (new GitClone($settings))->untrackedEntries();
                } catch (Throwable) {
                    // A clone that is not provably ours is not read.
                }
                $movedTo = $cloneDir . '.replaced-' . date('Y-m-d_His');
                GitPathGuard::assertSafeToWrite($movedTo);
                if (file_exists($movedTo) || !@rename($cloneDir, $movedTo)) {
                    throw new RuntimeException("Could not move $cloneDir aside to $movedTo. Nothing was changed.");
                }
                ($this->say)("Moved the old clone to $movedTo.");
            }

            try {
                $clone = new GitClone($settings);
                ($this->say)("Cloning {$settings->url} ({$settings->branch})...");
                $head = $clone->create();
            } catch (Throwable $error) {
                if ($movedTo !== null) {
                    if (!file_exists($cloneDir) && @rename($movedTo, $cloneDir)) {
                        ($this->say)('Moved the old clone back.');
                    } else {
                        ($this->say)("Could not move the old clone back. It is still at $movedTo.");
                    }
                }
                throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
            }

            // Put the files back at the commit that is running, so the next
            // deploy compares against what is really deployed.
            $deployed = $state->deployedCommit;
            if ($deployed !== null && $deployed !== $head) {
                if (!$clone->hasCommit($deployed)) {
                    // The branch was rewritten and no longer holds it. The
                    // deployed commit stays recorded: the next deploy finds it
                    // gone from the clone and recreates every container, since
                    // what changed cannot be measured (see GitDeploy::upArguments).
                    ($this->say)('The deployed commit ' . substr($deployed, 0, 12) . ' is not on the branch any more, so the clone was left at '
                        . substr($head, 0, 12) . '. Deploy to bring the stack up to date; that deploy recreates every container.');
                } else {
                    try {
                        $clone->checkOut($deployed);
                    } catch (Throwable $error) {
                        throw new RuntimeException(
                            'The new clone could not be put at the deployed commit ' . substr($deployed, 0, 12) . ': '
                            . $error->getMessage() . "\nIt is at " . substr($head, 0, 12) . '.'
                            . ($movedTo !== null ? " The old clone is at $movedTo." : ''),
                            0,
                            $error
                        );
                    }
                }
            }
            if ($movedTo !== null && $untrackedInOldClone !== []) {
                ($this->say)(
                    'The old clone holds files that are not in the repository, and not in the new clone: '
                    . implode(', ', array_slice($untrackedInOldClone, 0, 10)) . (count($untrackedInOldClone) > 10 ? ', ...' : '')
                    . '. If a container keeps data there (a relative bind mount such as ./data), copy it back before deploying.'
                );
            }
            return $movedTo;
        });
    }

    /**
     * The folder names of all git stacks, in the projects folder's order.
     *
     * @return string[]
     */
    public function listGitStacks(): array
    {
        $folders = [];
        foreach (StackInfo::listProjectFolders($this->composeRoot) as $folder) {
            if (is_file($this->composeRoot . '/' . $folder . '/' . GitStackSettings::FILE_NAME)) {
                $folders[] = $folder;
            }
        }
        return $folders;
    }

    /**
     * A git stack by its exact folder name.
     *
     * @throws RuntimeException if there is no such stack, or it is not a git stack
     */
    public function gitStack(string $folder): StackInfo
    {
        $stack = $this->stack($folder);
        if (!$stack->isGitStack()) {
            throw new RuntimeException("'$folder' is not a git stack.");
        }
        return $stack;
    }

    /**
     * A stack by its exact folder name.
     *
     * @throws RuntimeException if there is no such stack folder
     */
    private function stack(string $folder): StackInfo
    {
        if ($folder === '' || $folder === '.' || $folder === '..' || str_contains($folder, '/')) {
            throw new RuntimeException("'$folder' is not a stack folder name.");
        }
        if (!is_dir($this->composeRoot . '/' . $folder) || @readlink($this->composeRoot . '/' . $folder) !== false) {
            throw new RuntimeException("There is no stack folder named exactly '$folder' in {$this->composeRoot}.");
        }
        StackInfo::clearCache();
        return StackInfo::fromProject($this->composeRoot, $folder);
    }

    private function settings(StackInfo $stack): GitStackSettings
    {
        $settings = GitStackSettings::load($stack->path);
        if ($settings === null) {
            throw new RuntimeException("'{$stack->projectFolder}' is not a git stack.");
        }
        return $settings;
    }

    private function assertArrayStarted(): void
    {
        if (!GitPathGuard::isArrayStarted()) {
            throw new RuntimeException('The array is not started, so nothing was changed.');
        }
    }

    /**
     * The projects folder is usually on the flash, but may be on the array.
     */
    private function assertSafeToWriteStackFolder(string $path): void
    {
        if (str_starts_with($path, rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/')) {
            GitPathGuard::assertSafeToWrite($path);
        }
    }

    private function hasContent(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        // The plugin writes an empty template override on its own; that does not count.
        return trim((string) file_get_contents($file)) !== trim(OverrideInfo::buildTemplateContent());
    }

    /**
     * Run under compose.sh's lock for the stack, so a deploy cannot run at the same time.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function withStackLock(StackInfo $stack, callable $work): mixed
    {
        $handle = self::tryStackLock($stack);
        if ($handle === null) {
            throw new RuntimeException("Another operation is in progress for '{$stack->projectFolder}'. Try again when it has finished.");
        }
        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Take compose.sh's lock for the stack without waiting. The caller releases it
     * with flock($handle, LOCK_UN) and fclose($handle).
     *
     * @return resource|null null if another operation holds the lock
     * @throws RuntimeException if the lock file cannot be opened
     */
    public static function tryStackLock(StackInfo $stack)
    {
        if (!is_dir(COMPOSE_LOCK_DIR)) {
            @mkdir(COMPOSE_LOCK_DIR, 0755, true);
        }
        $lockFile = COMPOSE_LOCK_DIR . '/' . $stack->projectName . '.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            throw new RuntimeException("Could not open the lock file $lockFile.");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }
}
