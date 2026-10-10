<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCommand.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitCredentials.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitSsh.php';

/**
 * Thrown when a clone folder is not one this plugin can prove it made for this stack.
 */
final class GitCloneNotOwnedException extends RuntimeException
{
}

/**
 * Thrown when git clone itself failed, rather than a check before or after it. It keeps
 * everything git said: a refused key and an unreachable host end in the same "fatal:"
 * line, and only the lines before it tell them apart.
 */
final class GitCloneFailedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $gitOutput)
    {
        parent::__construct($message);
    }

    /**
     * Whether the server answered and refused access, which over ssh usually means it
     * does not know the key. False for anything else: an unreachable host, a wrong host
     * key, a missing branch. Each host words a refusal its own way:
     *  - "Permission denied (publickey)": ssh itself, when no account has the key
     *  - "Repository not found": GitHub, when the key belongs to another repository
     *  - "you don't have permission to view it": GitLab
     *  - "User permission denied": Gitea and Forgejo
     *  - "repository access denied": Bitbucket
     */
    public function serverRefusedAccess(): bool
    {
        $output = strtolower($this->gitOutput);
        foreach (['permission denied', 'repository not found', "you don't have permission", 'access denied'] as $wording) {
            if (str_contains($output, $wording)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * The plugin-owned git clone behind one git-backed stack.
 *
 * The clone is always checked out detached at a specific commit, and the
 * plugin never commits to it. The whole repository is checked out: a sparse
 * checkout of just the stack's folder would be smaller, but git then
 * overwrites untracked files without warning, while a plain checkout refuses
 * to. Never switch to a sparse or forced checkout: that refusal is what keeps
 * untracked files safe. Ignored files count as untracked here: by default git
 * overwrites them, so every checkout passes --no-overwrite-ignore. Rules this
 * class keeps (see the tests):
 *  - it only changes a folder that carries this stack's marker and points at
 *    this stack's repository (assertOwned()),
 *  - it only clones into a folder that does not exist yet, and on failure
 *    removes only the folder it just created,
 *  - it never runs git clean and never forces a checkout, so untracked files,
 *    ignored or not, such as data a container wrote through a relative bind
 *    mount, are never removed or overwritten,
 *  - local changes are saved as a patch before they are ever discarded.
 */
final class GitClone
{
    /** File inside .git that marks a clone as made by this plugin for one stack. */
    public const MARKER_FILE = 'compose-manager-clone';

    private const CLONE_TIMEOUT_SECONDS = 900;
    private const FETCH_TIMEOUT_SECONDS = 300;
    private const REMOTE_TIMEOUT_SECONDS = 60;

    public function __construct(private readonly GitStackSettings $settings)
    {
    }

    public function settings(): GitStackSettings
    {
        return $this->settings;
    }

    /**
     * Whether something exists at the clone folder path (a folder, file or symlink).
     */
    public function exists(): bool
    {
        return file_exists($this->settings->cloneDir) || @readlink($this->settings->cloneDir) !== false;
    }

    /**
     * Ask the remote which commit the branch points at, without changing anything.
     *
     * @param int $timeoutSeconds How long to wait for the remote
     * @throws RuntimeException if the remote cannot be reached or the branch does not exist
     */
    public function remoteBranchCommit(int $timeoutSeconds = self::REMOTE_TIMEOUT_SECONDS): string
    {
        $ref = 'refs/heads/' . $this->settings->branch;
        $result = $this->git(
            ['ls-remote', '--heads', '--', $this->settings->url, $ref],
            null,
            $timeoutSeconds
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not reach the repository: ' . $result->errorSummary());
        }
        foreach (explode("\n", trim($result->stdout)) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (is_array($fields) && count($fields) === 2 && $fields[1] === $ref && self::isCommitId($fields[0])) {
                return $fields[0];
            }
        }
        throw new RuntimeException("The branch '{$this->settings->branch}' does not exist in the repository.");
    }

    /**
     * Clone the repository into the stack's clone folder, checked out at the
     * branch's current commit.
     *
     * The folder must not exist yet. If anything fails, the folder this call
     * created is removed again, and nothing else is touched.
     *
     * @return string the commit checked out
     * @throws GitCloneFailedException when git clone itself failed
     * @throws RuntimeException naming any other problem
     */
    public function create(): string
    {
        $cloneDir = $this->settings->cloneDir;
        $clonesRoot = dirname($cloneDir);
        GitPathGuard::assertValidClonesRoot($clonesRoot);
        GitPathGuard::createDirectory($clonesRoot);

        GitPathGuard::assertSafeToWrite($cloneDir);
        if ($this->exists()) {
            throw new RuntimeException("$cloneDir already exists, so nothing was cloned into it.");
        }

        try {
            $args = ['clone', '--no-checkout', '--single-branch', '--no-tags', '--branch', $this->settings->branch];
            if ($this->isLocalRepository()) {
                // --no-local makes git copy objects the normal way instead of
                // hard-linking them, and lets the blob filter below apply.
                $args[] = '--no-local';
            }
            $args[] = '--filter=blob:none';
            $args[] = '--';
            $args[] = $this->settings->url;
            $args[] = $cloneDir;

            $result = $this->git($args, null, self::CLONE_TIMEOUT_SECONDS);
            if (!$result->succeeded()) {
                throw new GitCloneFailedException('Could not clone the repository: ' . $result->errorSummary(), $result->stderr);
            }
            if (!is_dir($cloneDir . '/.git')) {
                throw new RuntimeException("git reported success but $cloneDir/.git is missing.");
            }

            GitPathGuard::assertSafeToWrite($cloneDir . '/.git');
            if (file_put_contents($cloneDir . '/.git/' . self::MARKER_FILE, $this->settings->cloneId . "\n") === false) {
                throw new RuntimeException("Could not write the clone marker in $cloneDir.");
            }

            $commit = $this->resolveCommit('refs/remotes/origin/' . $this->settings->branch);
            if (!$this->composeFileExistsAt($commit)) {
                throw new RuntimeException(
                    "{$this->settings->composePath} does not exist on branch {$this->settings->branch}, so no clone was made."
                );
            }
            $this->runOrThrow(['checkout', '--detach', $commit], 'Could not check out the files');
            return $commit;
        } catch (Throwable $error) {
            $this->removeFolderCreatedByThisRun();
            throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
        }
    }

    /**
     * Throw unless the clone folder is provably this stack's plugin-made clone.
     *
     * Checks that it is a real folder (not a symlink), that it is the top of a
     * git work tree with its own .git folder, that the marker names this
     * stack's clone id, and that its remote is still this stack's repository.
     *
     * @throws GitCloneNotOwnedException naming the mismatch
     */
    public function assertOwned(): void
    {
        $cloneDir = $this->settings->cloneDir;
        if (@readlink($cloneDir) !== false) {
            throw new GitCloneNotOwnedException("$cloneDir is a symlink, so it was not used.");
        }
        if (!is_dir($cloneDir)) {
            throw new GitCloneNotOwnedException("The clone folder $cloneDir is missing. (Is the array started?)");
        }
        $gitDir = $cloneDir . '/.git';
        if (@readlink($gitDir) !== false || !is_dir($gitDir)) {
            throw new GitCloneNotOwnedException("$cloneDir is not a git clone made by this plugin (no .git folder).");
        }

        $marker = @file_get_contents($gitDir . '/' . self::MARKER_FILE);
        if ($marker === false || trim($marker) !== $this->settings->cloneId) {
            throw new GitCloneNotOwnedException(
                "$cloneDir was not made by this plugin for this stack (its marker is missing or names another stack), so it was not changed."
            );
        }

        $topLevel = $this->git(['rev-parse', '--show-toplevel'], $cloneDir, 30);
        if (!$topLevel->succeeded() || trim($topLevel->stdout) !== realpath($cloneDir)) {
            throw new GitCloneNotOwnedException("$cloneDir is not the top of its own git clone.");
        }

        $remote = $this->git(['config', '--get', 'remote.origin.url'], $cloneDir, 30);
        if (!$remote->succeeded() || trim($remote->stdout) !== $this->settings->url) {
            throw new GitCloneNotOwnedException(
                "$cloneDir is a clone of a different repository than this stack's ("
                . trim($remote->stdout) . '). Clone it again to change the repository.'
            );
        }
    }

    /**
     * Fetch the stack's branch from the remote. Changes only .git, never the files.
     *
     * @return string the commit the branch now points at
     * @throws RuntimeException naming the problem
     */
    public function fetch(): string
    {
        $this->assertOwned();
        GitPathGuard::assertSafeToWrite($this->settings->cloneDir . '/.git');

        $branch = $this->settings->branch;
        $result = $this->git(
            ['fetch', '--no-tags', '--prune', 'origin', '--', "+refs/heads/$branch:refs/remotes/origin/$branch"],
            $this->settings->cloneDir,
            self::FETCH_TIMEOUT_SECONDS
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not fetch from the repository: ' . $result->errorSummary());
        }
        return $this->resolveCommit('refs/remotes/origin/' . $branch);
    }

    /**
     * The commit the files are checked out at.
     *
     * @throws RuntimeException if it cannot be read
     */
    public function checkedOutCommit(): string
    {
        $this->assertOwned();
        return $this->resolveCommit('HEAD');
    }

    /**
     * Tracked files that differ from the checked-out commit, as paths relative to the clone.
     *
     * Untracked files (including data a container wrote into the clone) are
     * not local changes.
     *
     * @return string[]
     * @throws RuntimeException if git status fails
     */
    public function locallyChangedFiles(): array
    {
        $this->assertOwned();
        $result = $this->git(
            ['status', '--porcelain=v1', '-z', '--untracked-files=no'],
            $this->settings->cloneDir,
            60
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not read the clone status: ' . $result->errorSummary());
        }

        $paths = [];
        $entries = explode("\0", $result->stdout);
        for ($i = 0; $i < count($entries); $i++) {
            $entry = $entries[$i];
            if (strlen($entry) < 4) {
                continue;
            }
            $paths[] = substr($entry, 3);
            // A rename or copy is followed by its original path as a separate entry.
            if ($entry[0] === 'R' || $entry[0] === 'C') {
                $i++;
            }
        }
        return $paths;
    }

    /**
     * Whether the stack's files would change between two commits: the folder
     * holding the compose file, or anything at all when it is at the top level.
     *
     * @throws RuntimeException if either commit is unknown
     */
    public function stackFilesChanged(string $fromCommit, string $toCommit): bool
    {
        $this->assertOwned();
        $args = ['diff', '--name-only', '-z', $this->commitArgument($fromCommit), $this->commitArgument($toCommit), '--'];
        $stackFolder = $this->stackFolderInRepo();
        if ($stackFolder !== '') {
            $args[] = $stackFolder;
        }
        $result = $this->git($args, $this->settings->cloneDir, 60);
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not compare commits: ' . $result->errorSummary());
        }
        return trim($result->stdout, "\0") !== '';
    }

    /**
     * Whether the compose file exists at a commit.
     *
     * @throws RuntimeException if the commit is unknown
     */
    public function composeFileExistsAt(string $commit): bool
    {
        $this->assertOwned();
        $result = $this->git(
            ['ls-tree', '--name-only', '-z', $this->commitArgument($commit), '--', $this->settings->composePath],
            $this->settings->cloneDir,
            60
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not read the commit: ' . $result->errorSummary());
        }
        return trim($result->stdout, "\0") === $this->settings->composePath;
    }

    /**
     * Check out a commit. Refuses when tracked files have local changes, and
     * stops (changing nothing) if it would overwrite an untracked file,
     * ignored or not.
     *
     * @throws RuntimeException naming the problem
     */
    public function checkOut(string $commit): void
    {
        $this->assertOwned();
        $changed = $this->locallyChangedFiles();
        if ($changed !== []) {
            throw new RuntimeException(
                'The clone has local changes (' . implode(', ', array_slice($changed, 0, 5))
                . (count($changed) > 5 ? ', ...' : '') . '). Save or discard them first.'
            );
        }
        GitPathGuard::assertSafeToWrite($this->settings->cloneDir);

        // A plain checkout (never sparse, never forced) refuses by itself to
        // overwrite an untracked file, to replace one with a folder, or to
        // write through an untracked symlink, and then changes nothing.
        // Without --no-overwrite-ignore it silently overwrites ignored files
        // (such as a data/ folder listed in .gitignore), so that is always given.
        $result = $this->git(
            ['checkout', '--detach', '--no-overwrite-ignore', $this->commitArgument($commit)],
            $this->settings->cloneDir
        );
        if ($result->succeeded()) {
            return;
        }
        $inTheWay = self::untrackedPathsGitRefusedToOverwrite($result->stderr);
        if ($inTheWay !== []) {
            throw new RuntimeException(
                'The new commit adds files where untracked local files already are, so nothing was changed: '
                . implode(', ', array_slice($inTheWay, 0, 5)) . (count($inTheWay) > 5 ? ', ...' : '')
                . '. Move them out of the way, then deploy again.'
            );
        }
        throw new RuntimeException("Could not check out $commit: " . $result->errorSummary());
    }

    /**
     * The paths git lists when it refuses a checkout over untracked files.
     *
     * git prints "error: The following untracked working tree files would be
     * overwritten by checkout:" (or "removed"), or, for a folder the new commit
     * replaces with a file, "error: Updating the following directories would
     * lose untracked files in them:", then one tab-indented path per line.
     *
     * @return string[]
     */
    private static function untrackedPathsGitRefusedToOverwrite(string $stderr): array
    {
        $paths = [];
        $inList = false;
        foreach (explode("\n", $stderr) as $line) {
            if (str_contains($line, 'untracked working tree files would be')
                || str_contains($line, 'would lose untracked files in them')) {
                $inList = true;
                continue;
            }
            if ($inList && str_starts_with($line, "\t")) {
                $paths[] = trim($line);
                continue;
            }
            $inList = false;
        }
        return $paths;
    }

    /**
     * Save everything that differs from a commit (local edits, and any commits
     * made by hand in the clone) as a patch file in the stack folder.
     *
     * @return string|null the patch file written, or null when there was nothing to save
     * @throws RuntimeException if the patch cannot be written
     */
    public function saveChangesAsPatch(string $againstCommit, string $stackDir): ?string
    {
        $this->assertOwned();
        $result = $this->git(
            ['diff', '--binary', $this->commitArgument($againstCommit), '--'],
            $this->settings->cloneDir,
            120
        );
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not collect the local changes: ' . $result->errorSummary());
        }
        if ($result->stdout === '') {
            return null;
        }

        $patchDir = rtrim($stackDir, '/') . '/git-changes';
        if (str_starts_with($patchDir, rtrim(COMPOSE_GIT_MNT_DIR, '/') . '/')) {
            GitPathGuard::assertSafeToWrite($patchDir);
        }
        if (!is_dir($patchDir) && !@mkdir($patchDir, 0755) && !is_dir($patchDir)) {
            throw new RuntimeException("Could not create $patchDir.");
        }

        $base = $patchDir . '/' . date('Y-m-d_His') . '-' . substr($againstCommit, 0, 12);
        $patchFile = $base . '.patch';
        for ($n = 2; file_exists($patchFile); $n++) {
            $patchFile = "$base-$n.patch";
        }
        // The patch is the only copy of the changes once they are discarded,
        // so a short write (a full flash) must stop the discard.
        if (file_put_contents($patchFile, $result->stdout) !== strlen($result->stdout)) {
            // This run created the file (the loop above picked an unused name).
            @unlink($patchFile);
            throw new RuntimeException("Could not write all of $patchFile, so the local changes were kept.");
        }
        return $patchFile;
    }

    /**
     * Take every staged change out of the index, leaving the files as they are.
     *
     * A new file that was staged (for example by "git add -A", which also
     * sweeps up a container's ./data folder) is in the index, so git does not
     * list it as untracked, and "git reset --hard" would delete it. Unstaged,
     * it is an untracked file again: assertDiscardKeepsUntrackedFiles()
     * protects it and the discard leaves it alone. Call this first, before
     * the check and before saving the patch.
     *
     * @throws RuntimeException naming the problem
     */
    public function unstageAll(): void
    {
        $this->assertOwned();
        GitPathGuard::assertSafeToWrite($this->settings->cloneDir . '/.git');
        $this->runOrThrow(['reset', '--quiet', 'HEAD'], 'Could not unstage the staged changes');
    }

    /**
     * Put the tracked files back to the checked-out commit. Untracked files are
     * left alone, and so are staged new files (see unstageAll()). Call only
     * after saveChangesAsPatch() has saved the changes.
     *
     * @throws RuntimeException naming the problem
     */
    public function discardLocalChanges(): void
    {
        $this->assertOwned();
        $this->unstageAll();
        $this->assertDiscardKeepsUntrackedFiles();
        GitPathGuard::assertSafeToWrite($this->settings->cloneDir);
        $this->runOrThrow(['reset', '--hard', '--quiet', 'HEAD'], 'Could not discard the local changes');
    }

    /**
     * Throw if discarding the local changes would delete or overwrite an untracked file.
     *
     * "git reset --hard HEAD" puts back every file tracked at HEAD, and it
     * replaces whatever untracked file or folder is at that path: a tracked
     * file "logs" that was deleted and replaced by a folder of logs, or the
     * other way round, a tracked folder "logs" that was deleted and replaced
     * by a file. The patch from saveChangesAsPatch() does not hold those
     * untracked contents, so they would be lost. Call this before saving the
     * patch, so a refusal changes nothing.
     *
     * @throws RuntimeException listing the files in the way
     */
    public function assertDiscardKeepsUntrackedFiles(): void
    {
        $tracked = $this->git(['ls-tree', '-r', '--name-only', '-z', 'HEAD'], $this->settings->cloneDir, 60);
        // No --exclude-standard: ignored files are listed too, and are just as much at risk.
        $untracked = $this->git(['ls-files', '-z', '--others'], $this->settings->cloneDir, 120);
        if (!$tracked->succeeded() || !$untracked->succeeded()) {
            throw new RuntimeException(
                'Could not list the files in the clone: '
                . (!$tracked->succeeded() ? $tracked->errorSummary() : $untracked->errorSummary())
            );
        }

        $trackedFiles = [];
        // Every folder that holds a tracked file, at any depth.
        $trackedFolders = [];
        foreach (explode("\0", $tracked->stdout) as $path) {
            if ($path === '') {
                continue;
            }
            $trackedFiles[$path] = true;
            $folder = dirname($path);
            while ($folder !== '.' && !isset($trackedFolders[$folder])) {
                $trackedFolders[$folder] = true;
                $folder = dirname($folder);
            }
        }

        $atRisk = [];
        foreach (explode("\0", $untracked->stdout) as $path) {
            if ($path === '') {
                continue;
            }
            // At risk: an untracked file where a tracked folder is. The reset
            // would delete the file to put the folder back.
            if (isset($trackedFolders[$path])) {
                $atRisk[$path] = true;
                continue;
            }
            // At risk: an untracked file at a tracked path, or inside a folder
            // that sits where a tracked file is.
            $candidate = $path;
            while ($candidate !== '.' && $candidate !== '') {
                if (isset($trackedFiles[$candidate])) {
                    $atRisk[$candidate] = true;
                    break;
                }
                $candidate = dirname($candidate);
            }
        }

        if ($atRisk !== []) {
            $paths = array_keys($atRisk);
            throw new RuntimeException(
                'Discarding the local changes would delete or overwrite untracked files at '
                . implode(', ', array_slice($paths, 0, 5)) . (count($paths) > 5 ? ', ...' : '')
                . ', because the checked-out commit has a tracked file or folder there. Nothing was changed. '
                . 'Move them out of the clone, then deploy again.'
            );
        }
    }

    /**
     * The folder in the repository that holds the compose file, or '' for the top level.
     */
    public function stackFolderInRepo(): string
    {
        $folder = dirname($this->settings->composePath);
        return $folder === '.' ? '' : $folder;
    }

    /**
     * Whether a commit is on the stack's branch as last fetched (it, or a later
     * commit of the branch, is what the branch points at).
     *
     * @throws RuntimeException if git cannot tell
     */
    public function isOnBranch(string $commit): bool
    {
        $this->assertOwned();
        $result = $this->git(
            ['merge-base', '--is-ancestor', $this->commitArgument($commit), 'refs/remotes/origin/' . $this->settings->branch],
            $this->settings->cloneDir,
            60
        );
        // Exit code 1 means "not an ancestor"; anything else non-zero is an error.
        if ($result->exitCode === 1 && !$result->timedOut) {
            return false;
        }
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not compare the commit with the branch: ' . $result->errorSummary());
        }
        return true;
    }

    /**
     * Whether the checked-out commit was committed in the clone by hand.
     *
     * A checked-out commit that is on the branch came from the repository: the
     * deployed one, one whose "up" failed, or one left checked out when a
     * deploy was interrupted. The deployed and failed commits also count when a
     * rewritten branch no longer holds them. Anything else was committed in the
     * clone by hand. The deploy and the stack's status both ask this, so the
     * web UI offers to save such a commit when the deploy would refuse it. One
     * rare case differs: the deploy asks after a fetch and the status without
     * one, so a commit left by an interrupted deploy on a branch that was then
     * force-pushed is fine to the status and made by hand to the deploy, which
     * refuses it with its own message.
     *
     * @throws RuntimeException if git cannot tell
     */
    public function isMadeByHand(string $checkedOut, ?string $deployedCommit, ?string $failedCommit): bool
    {
        return $checkedOut !== $deployedCommit
            && $checkedOut !== $failedCommit
            && !$this->isOnBranch($checkedOut);
    }

    /**
     * The last commit a commit shares with the stack's branch as last fetched,
     * or null when they share none.
     *
     * @throws RuntimeException if git fails
     */
    public function lastCommitSharedWithBranch(string $commit): ?string
    {
        $this->assertOwned();
        $result = $this->git(
            ['merge-base', $this->commitArgument($commit), 'refs/remotes/origin/' . $this->settings->branch],
            $this->settings->cloneDir,
            60
        );
        if ($result->exitCode === 1 && !$result->timedOut) {
            return null;
        }
        $shared = trim($result->stdout);
        if (!$result->succeeded() || !self::isCommitId($shared)) {
            throw new RuntimeException('Could not compare the commit with the branch: ' . $result->errorSummary());
        }
        return $shared;
    }

    /**
     * Whether the clone has a commit (after a rewritten branch, an old commit may be gone).
     */
    public function hasCommit(string $commit): bool
    {
        $this->assertOwned();
        $result = $this->git(
            ['cat-file', '-e', $this->commitArgument($commit) . '^{commit}'],
            $this->settings->cloneDir,
            30
        );
        return $result->succeeded();
    }

    /**
     * The untracked files and folders in the clone, as git lists them (a
     * folder holding only untracked files is one entry, ending in /).
     * Ignored files are listed too.
     *
     * @return string[]
     * @throws RuntimeException if git fails
     */
    public function untrackedEntries(): array
    {
        $this->assertOwned();
        $result = $this->git(['ls-files', '-z', '--others', '--directory'], $this->settings->cloneDir, 120);
        if (!$result->succeeded()) {
            throw new RuntimeException('Could not list the untracked files: ' . $result->errorSummary());
        }
        return array_values(array_filter(explode("\0", $result->stdout), static fn(string $path): bool => $path !== ''));
    }

    /**
     * Whether a string looks like a full commit id (SHA-1 or SHA-256).
     */
    public static function isCommitId(string $value): bool
    {
        return preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $value) === 1;
    }

    /**
     * Resolve a reference to a full commit id.
     *
     * @throws RuntimeException if it does not name a commit
     */
    private function resolveCommit(string $reference): string
    {
        $result = $this->git(
            ['rev-parse', '--verify', '--quiet', '--end-of-options', $reference . '^{commit}'],
            $this->settings->cloneDir,
            30
        );
        $commit = trim($result->stdout);
        if (!$result->succeeded() || !self::isCommitId($commit)) {
            throw new RuntimeException("Could not find commit $reference in the clone.");
        }
        return $commit;
    }

    /**
     * A commit id checked to be safe to pass to git as an argument.
     *
     * @throws InvalidArgumentException
     */
    private function commitArgument(string $commit): string
    {
        if (!self::isCommitId($commit)) {
            throw new InvalidArgumentException("Not a full commit id: $commit");
        }
        return $commit;
    }

    private function isLocalRepository(): bool
    {
        return str_starts_with($this->settings->url, '/');
    }

    /**
     * Run git for this clone.
     *
     * A repository on this server (such as one a Gitea container keeps under
     * appdata) is often owned by another user, and git refuses to read from
     * it ("dubious ownership"). The stack's own repository path is trusted
     * for the run, and nothing else (see GitCommand).
     *
     * A stack with a credential gets it for every run, not only clone and
     * fetch: the clone is partial (--filter=blob:none), so a checkout or diff
     * can fetch file contents from the repository too. An ssh stack gets its
     * deploy key and pinned host keys the same way (see GitSsh).
     *
     * @param string[] $args
     */
    private function git(array $args, ?string $workingDirectory, int $timeoutSeconds = GitCommand::DEFAULT_TIMEOUT_SECONDS): ProcessResult
    {
        $trusted = $this->isLocalRepository() ? [$this->settings->url] : [];
        if ($this->settings->isSsh()) {
            return $this->gitOverSsh($args, $workingDirectory, $timeoutSeconds);
        }
        if ($this->settings->credentialId === null) {
            $result = GitCommand::run($args, $workingDirectory, $timeoutSeconds, [], $trusted);
        } else {
            $credentialFile = GitCredentials::writeFile($this->settings->credentialId, $this->settings->url);
            try {
                $result = GitCommand::run(
                    $args,
                    $workingDirectory,
                    $timeoutSeconds,
                    [],
                    $trusted,
                    GitCredentials::settingsFor($credentialFile)
                );
            } finally {
                GitCredentials::remove($credentialFile);
            }
        }
        if (!$result->succeeded()) {
            $hint = self::httpsFailureHint($result->stderr, $this->settings->credentialId !== null);
            if ($hint !== null) {
                throw new RuntimeException($result->errorSummary() . "\n" . $hint);
            }
        }
        return $result;
    }

    /**
     * What to tell the person when git's own message for a failed https run
     * points the wrong way, or null when it is clear enough.
     */
    public static function httpsFailureHint(string $stderr, bool $hasCredential): ?string
    {
        // The token is offered only for the exact address the stack uses (credential.useHttpPath),
        // so after a redirect git asks for the new address and gets nothing.
        if ($hasCredential && preg_match('/^warning: redirecting to (\S+)/m', $stderr, $match) === 1) {
            return "The server redirected to {$match[1]}, and the stack's credential is offered only for the address "
                . 'the stack uses. Use the address the server redirects to (usually the one ending in .git).';
        }
        if (!$hasCredential && str_contains($stderr, 'terminal prompts disabled')) {
            return 'The repository asks for a user name and password, so it needs a credential: add a git token on the '
                . 'Credentials tab, then name it with --credential when adding the stack, or with compose-git credential '
                . '<stack> <name> for a stack that exists.';
        }
        return null;
    }

    /**
     * Run git for an ssh stack, with its deploy key and its pinned host keys only.
     *
     * @param string[] $args
     * @throws RuntimeException if the stack has no deploy key or no pinned host keys
     */
    private function gitOverSsh(array $args, ?string $workingDirectory, int $timeoutSeconds): ProcessResult
    {
        if ($this->settings->credentialId === null || $this->settings->sshKnownHosts === null) {
            throw new RuntimeException(
                'This ssh stack has no deploy key or no pinned host keys in its git.json. Set it up again with compose-git.'
            );
        }
        $files = GitSsh::writeRunFiles($this->settings->credentialId, $this->settings->sshKnownHosts);
        try {
            $result = GitCommand::run(
                $args,
                $workingDirectory,
                $timeoutSeconds,
                GitSsh::environmentFor($files),
                [],
                GitSsh::settings()
            );
            // git's last line is only "Could not read from remote repository", so say what ssh said.
            if (!$result->succeeded() && str_contains($result->stderr, 'Host key verification failed')) {
                throw new RuntimeException(
                    'The repository server\'s ssh host key does not match the one pinned for this stack, so nothing was fetched. '
                    . 'If the server was rebuilt or its keys were changed on purpose, run compose-git trust-host and compare '
                    . 'the new fingerprints with the ones the server publishes.'
                );
            }
            return $result;
        } finally {
            GitCredentials::remove($files['key']);
            GitCredentials::remove($files['knownHosts']);
        }
    }

    /**
     * @param string[] $args
     * @throws RuntimeException
     */
    private function runOrThrow(array $args, string $failureMessage): ProcessResult
    {
        $result = $this->git($args, $this->settings->cloneDir);
        if (!$result->succeeded()) {
            throw new RuntimeException("$failureMessage: " . $result->errorSummary());
        }
        return $result;
    }

    /**
     * Remove the clone folder after a failed create().
     *
     * Only called by create(), which checked the folder did not exist before
     * it started. Deletes without following symlinks, and only a folder that
     * is directly inside the clones root.
     */
    private function removeFolderCreatedByThisRun(): void
    {
        $cloneDir = $this->settings->cloneDir;
        if (!$this->exists()) {
            return;
        }
        $parent = realpath(dirname($cloneDir));
        if ($parent === false || @readlink($cloneDir) !== false || dirname((string) realpath($cloneDir)) !== $parent) {
            return;
        }
        self::removeTreeWithoutFollowingLinks($cloneDir);
    }

    private static function removeTreeWithoutFollowingLinks(string $path): void
    {
        if (@readlink($path) !== false || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTreeWithoutFollowingLinks($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
