<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackManager.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitSsh.php';

/**
 * What the web UI asks of git stacks. Each method returns the array that
 * Exec.php sends back as JSON: 'result' is 'success' or 'error', and an error
 * has a 'message' to show. The work itself is done by GitStackManager, the
 * same code the compose-git command uses.
 */
final class GitStackWebActions
{
    /**
     * How long Check for Changes waits for the repository to name the branch's latest
     * commit: the browser waits for the answer, as for the Credentials tab's Test. A
     * newer commit is then fetched with the usual fetch time limit; by then the
     * repository has just answered, and the fetch of a few commits takes seconds.
     */
    private const CHECK_TIMEOUT_SECONDS = 15;

    public function __construct(private readonly string $composeRoot)
    {
    }

    /**
     * Create a git stack from the Add Stack dialog: clone the repository and
     * make the stack folder, as compose-git add does. Nothing is deployed.
     *
     * @param array<string, mixed> $input The dialog's fields: stackName, stackDesc,
     *     gitUrl, gitBranch, gitComposePath, gitCredentialId, overrideManagementAutomatic
     * @return array<string, mixed>
     */
    public function add(array $input): array
    {
        $messages = [];
        $manager = new GitStackManager($this->composeRoot, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $branch = trim((string) ($input['gitBranch'] ?? ''));
        $credentialId = trim((string) ($input['gitCredentialId'] ?? ''));
        try {
            $folder = $manager->add(
                trim((string) ($input['stackName'] ?? '')),
                trim((string) ($input['gitUrl'] ?? '')),
                $branch === '' ? GitStackManager::DEFAULT_BRANCH : $branch,
                trim((string) ($input['gitComposePath'] ?? '')),
                null,
                trim((string) ($input['stackDesc'] ?? '')),
                // Only a git credential is accepted, never a registry login.
                $credentialId === '' ? null : $manager->findGitCredential($credentialId)
            );
        } catch (Throwable $error) {
            return self::errorAnswer($error, $messages);
        }

        // The same override choice as the dialog's other sources (see addStack in Exec.php).
        $overrideManagementAutomatic = strtolower(trim((string) ($input['overrideManagementAutomatic'] ?? 'true'))) !== 'false';
        if (!$overrideManagementAutomatic) {
            $labelsViewModeFile = $this->composeRoot . '/' . $folder . '/labels_view_mode';
            if (@file_put_contents($labelsViewModeFile, 'advanced') === false) {
                // The stack is made; only its Labels tab opens in the basic view, where it can be switched.
                composeLogger("Added git stack '$folder', but could not write $labelsViewModeFile", null, 'user', 'warning', 'git');
            }
        }

        StackInfo::clearCache();
        $stack = StackInfo::fromProject($this->composeRoot, $folder);
        return [
            'result' => 'success',
            'project' => $folder,
            'projectName' => $stack->getName(),
            'messages' => $messages,
        ];
    }

    /**
     * Ask the remote for the branch's latest commit and compare it with the
     * deployed one, as compose-git check does. A newer commit is fetched into the
     * clone, under the stack's lock; no container and no checked-out file changes.
     *
     * @return array<string, mixed>
     */
    public function check(string $folder): array
    {
        try {
            return [
                'result' => 'success',
                'check' => (new GitStackManager($this->composeRoot))->check($folder, self::CHECK_TIMEOUT_SECONDS),
            ];
        } catch (Throwable $error) {
            return ['result' => 'error', 'message' => $error->getMessage()];
        }
    }

    /**
     * What deleting a git stack leaves behind, to tell the person.
     *
     * Delete removes only the stack folder. The clone is not deleted (a
     * container may keep data in it), nor moved: once git.json is gone the
     * plugin cannot prove the clone is its own, so it is the person's to
     * remove. An ssh stack's deploy key stays on the Credentials tab.
     *
     * @return array{path: ?string, note: string}|null null for a stack that is not
     *     a git stack. path is the clone, or null when git.json cannot be read; note
     *     says what it is and what else stays.
     */
    public static function leftBehindByDelete(string $stackDir): ?array
    {
        if (!is_file($stackDir . '/' . GitStackSettings::FILE_NAME)) {
            return null;
        }
        try {
            $settings = GitStackSettings::load($stackDir);
        } catch (Throwable) {
            return ['path' => null, 'note' => "It is in the git stack's clone of its repository (its git.json could not be read)."];
        }
        if ($settings === null) {
            // git.json went away since the check above.
            return null;
        }
        $note = "This is the stack's clone of its repository: delete it yourself once nothing in it is needed.";
        if ($settings->isSsh() && $settings->credentialId !== null) {
            $note .= " Its ssh deploy key stays on the Credentials tab of the plugin settings, and in the repository's deploy keys.";
        }
        return ['path' => $settings->cloneDir, 'note' => $note];
    }

    /**
     * What the stack list shows for a git stack. Read from the stack's own
     * files only: no git command is run, so the list stays fast.
     *
     * @return array{branch: ?string, deployedCommit: ?string, failedCommit: ?string, problem: ?string}
     */
    public static function listSummary(string $stackDir): array
    {
        try {
            $settings = GitStackSettings::load($stackDir);
            $state = GitStackState::load($stackDir);
        } catch (Throwable $error) {
            return ['branch' => null, 'deployedCommit' => null, 'failedCommit' => null, 'problem' => $error->getMessage()];
        }
        return [
            'branch' => $settings?->branch,
            'deployedCommit' => $state->deployedCommit,
            'failedCommit' => $state->failedCommit,
            'problem' => null,
        ];
    }

    /**
     * Turn an existing stack into a git stack, from the editor's Sources tab,
     * as compose-git convert does. Its old compose file, when it is in the
     * stack folder, goes into a dated backup folder there (an indirect stack's
     * file stays where it is); its containers change at the next deploy.
     *
     * @param array<string, mixed> $input gitUrl, gitBranch, gitComposePath, gitCredentialId
     * @return array<string, mixed>
     */
    public function convert(string $folder, array $input): array
    {
        $messages = [];
        $manager = new GitStackManager($this->composeRoot, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $branch = trim((string) ($input['gitBranch'] ?? ''));
        $credentialId = trim((string) ($input['gitCredentialId'] ?? ''));
        try {
            $backupDir = $manager->convert(
                $folder,
                trim((string) ($input['gitUrl'] ?? '')),
                $branch === '' ? GitStackManager::DEFAULT_BRANCH : $branch,
                trim((string) ($input['gitComposePath'] ?? '')),
                null,
                // Only a git credential is accepted, never a registry login.
                $credentialId === '' ? null : $manager->findGitCredential($credentialId)
            );
        } catch (Throwable $error) {
            return self::errorAnswer($error, $messages);
        }
        return ['result' => 'success', 'backupDir' => $backupDir, 'messages' => $messages];
    }

    /**
     * A git stack's repository, deployed commit and local changes, for the
     * editor's Sources tab. Asks nothing of the remote.
     *
     * @return array<string, mixed>
     */
    public function status(string $folder): array
    {
        $manager = new GitStackManager($this->composeRoot);
        try {
            $status = $manager->status($folder);
        } catch (Throwable $error) {
            return ['result' => 'error', 'message' => $error->getMessage()];
        }

        // An ssh stack's deploy key, so it can be added to the repository from here.
        $deployKey = null;
        try {
            $deployKey = $manager->deployKey($folder);
        } catch (Throwable $error) {
            // Not an ssh stack: there is nothing to show. An ssh stack whose key cannot be read says why.
            if (GitStackSettings::isSshUrl((string) $status['url']) && $status['problem'] === null) {
                // GitSsh already starts its message this way when ssh-keygen refuses the key.
                $message = $error->getMessage();
                if (!str_starts_with($message, 'Could not read the deploy key')) {
                    $message = 'Could not read the deploy key: ' . $message;
                }
                $status['problem'] = $message;
            }
        }

        return ['result' => 'success', 'git' => $status + ['deployKey' => $deployKey]];
    }

    /**
     * The answer for a failed add or convert. When the repository did not know an ssh
     * stack's deploy key, the key comes apart from the clone's error, so the dialog can
     * show it as the next step rather than inside the error.
     *
     * @param list<string> $messages What the manager said before it failed
     * @return array<string, mixed>
     */
    private static function errorAnswer(Throwable $error, array $messages): array
    {
        if ($error instanceof GitDeployKeyNotAddedException) {
            return [
                'result' => 'error',
                'message' => $error->cloneError,
                'deployKey' => $error->publicKey,
                'messages' => $messages,
            ];
        }
        return ['result' => 'error', 'message' => $error->getMessage(), 'messages' => $messages];
    }
}
