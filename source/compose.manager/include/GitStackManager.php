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
        $state = GitStackState::load($stack->path);
        $clone = new GitClone($settings);
        $remote = $timeoutSeconds === null ? $clone->remoteBranchCommit() : $clone->remoteBranchCommit($timeoutSeconds);
        $deployed = $state->deployedCommit;

        $changesStack = null;
        $deployedCommitMissing = false;
        if ($deployed === null) {
            // Nothing deployed yet: whatever the branch has is new to the stack.
            $changesStack = true;
        } elseif ($deployed !== $remote) {
            [$changesStack, $deployedCommitMissing] = $this->withStackLock(
                $stack,
                /** @return array{bool, bool} changesStack, deployedCommitMissing */
                static function () use ($clone, $deployed, $remote): array {
                    $clone->fetch();
                    if (!$clone->hasCommit($deployed)) {
                        // Recloned since, after a force-push for one. Nothing to compare with.
                        return [true, true];
                    }
                    return [$clone->stackFilesChanged($deployed, $remote), false];
                }
            );
        }

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
     * What is known about a git stack locally, without asking the remote.
     *
     * @return array{stack: string, url: string, branch: string, composePath: string, cloneDir: string, recreateOnFolderChange: bool, credential: ?string, deployedCommit: ?string, failedCommit: ?string, checkedOutCommit: ?string, localChanges: list<string>, commitMadeByHand: bool, problem: ?string}
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
            'credential' => $settings->credentialId === null ? null : $this->credentialName($settings->credentialId),
            'deployedCommit' => $state->deployedCommit,
            'failedCommit' => $state->failedCommit,
            'checkedOutCommit' => $checkedOut,
            'localChanges' => $changes,
            'commitMadeByHand' => $madeByHand,
            'problem' => $problem,
        ];
    }

    /**
     * Change the credential a git stack uses to reach its repository, or remove it.
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
        if ($settings->isSsh()) {
            throw new InvalidArgumentException(
                "An ssh stack always uses a deploy key of its own, so the credential of '$folder' cannot be changed. "
                . 'compose-git deploy-key shows its public key.'
            );
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
            // HTTPS tokens only: a deploy key belongs to the one stack it was made for.
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
     * reuses) the stack's deploy key and pins the host's keys.
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
            throw new InvalidArgumentException('An ssh stack gets a deploy key of its own, so it cannot be given another credential.');
        }
        // The host first: a mistyped host then fails before a deploy key is made for it.
        $knownHosts = GitSsh::scanHostKeys($address['host'], $address['port']);
        $credentialId = $this->deployKeyFor($folder, $address);
        ($this->say)("Pinned the ssh host keys of {$address['host']}: " . implode(', ', GitSsh::fingerprints($knownHosts)));
        ($this->say)('Compare them with the fingerprints your git host publishes.');
        return $settings->withCredentialId($credentialId)->withSshKnownHosts($knownHosts);
    }

    /**
     * The stack's deploy key: the one made for it by an earlier attempt, or a new one.
     *
     * The key is found by the stack's name and host. The vault does not record the repository,
     * so a key left behind by a deleted stack of the same name is reused too; the message says
     * what to do when that key belongs to another repository.
     *
     * @param array{user: string, host: string, port: int, path: string} $address
     */
    private function deployKeyFor(string $folder, array $address): string
    {
        $name = "$folder deploy key";
        $host = $address['host'] . ($address['port'] === 22 ? '' : ':' . $address['port']);
        foreach ((new CredentialVault())->listCredentials() as $credential) {
            if ($credential['name'] === $name && $credential['provider'] === 'git-ssh' && $credential['registry'] === $host) {
                ($this->say)(
                    "Using the deploy key made for '$folder' earlier ('$name' on the Credentials tab). If it was "
                    . "made for another repository, by a stack of the same name that has since been deleted, "
                    . "delete '$name' first: GitHub, for one, refuses a deploy key that another repository has."
                );
                return $credential['id'];
            }
        }
        $key = GitSsh::generateKey("compose-manager $folder");
        ($this->say)("Made a new deploy key for '$folder'.");
        $id = (new CredentialVault())->saveCredential([
            'name' => $name,
            'provider' => 'git-ssh',
            'registry' => $host,
            'username' => $address['user'],
            'secret' => $key['private'],
        ])['id'];
        composeLogger("Made a deploy key for git stack '$folder'", ['id' => $id, 'host' => $host], 'user', 'info', 'credentials');
        return $id;
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
        if (!is_dir(COMPOSE_LOCK_DIR)) {
            @mkdir(COMPOSE_LOCK_DIR, 0755, true);
        }
        $lockFile = COMPOSE_LOCK_DIR . '/' . $stack->projectName . '.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            throw new RuntimeException("Could not open the lock file $lockFile.");
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException("Another operation is in progress for '{$stack->projectFolder}'. Try again when it has finished.");
            }
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
