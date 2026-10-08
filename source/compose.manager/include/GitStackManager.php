<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackState.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitClone.php';

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
        string $description = ''
    ): string {
        $this->assertArrayStarted();
        $folder = StackInfo::sanitizeProjectString($stackName);
        if ($folder === '') {
            throw new InvalidArgumentException("'$stackName' cannot be used as a stack name.");
        }
        $stackDir = $this->composeRoot . '/' . $folder;
        if (file_exists($stackDir) || @readlink($stackDir) !== false) {
            throw new RuntimeException("A stack folder named '$folder' already exists. Choose another name, or use convert for that stack.");
        }

        $settings = GitStackSettings::createNew($url, $branch, $composePath, $clonesRoot ?? COMPOSE_GIT_DEFAULT_CLONES_ROOT, $folder);
        ($this->say)("Cloning $url ($branch) into {$settings->cloneDir}...");
        (new GitClone($settings))->create();

        try {
            $stack = StackInfo::createNew($this->composeRoot, $stackName, $description, $settings->composeFileInClone());
            $stackDir = $this->composeRoot . '/' . $stack->projectFolder;
            $settings->save($stackDir);
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
    public function convert(string $folder, string $url, string $branch, string $composePath, ?string $clonesRoot): string
    {
        $this->assertArrayStarted();
        $stack = $this->stack($folder);
        if ($stack->isGitStack()) {
            throw new RuntimeException("'$folder' is already a git stack.");
        }
        $stackDir = $stack->path;

        return $this->withStackLock($stack, function () use ($stack, $stackDir, $folder, $url, $branch, $composePath, $clonesRoot): string {
            $oldOverride = $stack->getOverridePath();
            $oldEnv = $stack->getEffectiveEnvFilePath();
            $hasExplicitEnvPath = trim((string) @file_get_contents($stackDir . '/envpath')) !== '';
            $oldComposeFile = $stack->isIndirect ? null : $stack->composeFilePath;
            $foldersNextToOldComposeFile = $this->foldersIn($stack->composeSource);

            $settings = GitStackSettings::createNew($url, $branch, $composePath, $clonesRoot ?? COMPOSE_GIT_DEFAULT_CLONES_ROOT, $folder);
            ($this->say)("Cloning $url ($branch) into {$settings->cloneDir}...");
            (new GitClone($settings))->create();

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
                if (!$hasExplicitEnvPath && $oldEnv !== null && $oldEnv !== $stackEnv && !is_file($stackEnv)) {
                    if (!@copy($oldEnv, $stackEnv)) {
                        throw new RuntimeException("Could not copy $oldEnv to $stackEnv.");
                    }
                    ($this->say)("Copied $oldEnv to $stackEnv.");
                }

                $settings->save($stackDir);
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
     * Compare the deployed commit with the branch on the remote, changing nothing.
     *
     * @return array{stack: string, branch: string, remoteCommit: string, deployedCommit: ?string, failedCommit: ?string, upToDate: bool}
     * @throws RuntimeException if the stack or the remote cannot be read
     */
    public function check(string $folder): array
    {
        $stack = $this->stack($folder);
        $settings = $this->settings($stack);
        $state = GitStackState::load($stack->path);
        $remote = (new GitClone($settings))->remoteBranchCommit();
        return [
            'stack' => $folder,
            'branch' => $settings->branch,
            'remoteCommit' => $remote,
            'deployedCommit' => $state->deployedCommit,
            'failedCommit' => $state->failedCommit,
            'upToDate' => $state->deployedCommit === $remote,
        ];
    }

    /**
     * What is known about a git stack locally, without asking the remote.
     *
     * @return array{stack: string, url: string, branch: string, composePath: string, cloneDir: string, recreateOnFolderChange: bool, deployedCommit: ?string, failedCommit: ?string, checkedOutCommit: ?string, localChanges: list<string>, problem: ?string}
     */
    public function status(string $folder): array
    {
        $stack = $this->stack($folder);
        $settings = $this->settings($stack);
        $state = GitStackState::load($stack->path);
        $checkedOut = null;
        $changes = [];
        $problem = null;
        try {
            $clone = new GitClone($settings);
            $checkedOut = $clone->checkedOutCommit();
            $changes = $clone->locallyChangedFiles();
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
            'deployedCommit' => $state->deployedCommit,
            'failedCommit' => $state->failedCommit,
            'checkedOutCommit' => $checkedOut,
            'localChanges' => $changes,
            'problem' => $problem,
        ];
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
