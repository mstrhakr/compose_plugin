<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitPathGuard.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackState.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitClone.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/GitDeployCheck.php';

/**
 * The git half of deploying a git stack. compose.sh calls this under the
 * stack's lock (scripts/git_stack.php), then pulls and starts the containers.
 *
 * prepare() fetches, checks out the new commit and runs every check. If
 * anything fails, it puts the previous commit back before it returns, so
 * nothing has changed. Only after it succeeds does compose.sh touch a
 * container.
 */
final class GitDeploy
{
    /** @var callable(string): void */
    private $say;

    /**
     * @param string $stackDir The stack folder (holds git.json)
     * @param callable(string): void|null $say Receives progress messages for the person deploying
     */
    public function __construct(private readonly string $stackDir, ?callable $say = null)
    {
        $this->say = $say ?? static function (string $message): void {
        };
    }

    /**
     * Fetch, check out and check the commit to deploy.
     *
     * @param string $projectName The stack's compose project name
     * @param string[] $profiles Compose profiles the deploy will use
     * @param string|null $commit A specific commit to deploy, or null for the branch's latest
     * @param bool $saveAndDiscardLocalChanges Save local changes as a patch, then discard them, instead of refusing
     * @return string The commit that was checked out before, to put back if the pull fails
     * @throws RuntimeException naming why nothing was deployed
     */
    public function prepare(string $projectName, array $profiles, ?string $commit, bool $saveAndDiscardLocalChanges): string
    {
        if (!GitPathGuard::isArrayStarted()) {
            throw new RuntimeException('The array is not started, so nothing was deployed.');
        }
        $settings = $this->loadSettings();
        $clone = new GitClone($settings);
        $clone->assertOwned();
        $state = GitStackState::load($this->stackDir);

        $previous = $clone->checkedOutCommit();

        // Fetch and check the target first: fetching changes only .git, so a
        // network failure or a removed compose file stops the deploy before
        // any local change is discarded.
        ($this->say)("Fetching {$settings->branch} from {$settings->url}...");
        $latest = $clone->fetch();
        $target = $commit ?? $latest;
        if (!GitClone::isCommitId($target)) {
            throw new RuntimeException("Not a full commit id: $target");
        }
        if (!$clone->composeFileExistsAt($target)) {
            throw new RuntimeException(
                "{$settings->composePath} does not exist at commit " . substr($target, 0, 12)
                . '. It may have been moved or removed in the repository. Nothing was deployed, and the stack was left as it is.'
            );
        }

        $this->dealWithLocalChanges($clone, $state, $previous, $saveAndDiscardLocalChanges);

        try {
            if ($target !== $previous) {
                ($this->say)('Checking out ' . substr($target, 0, 12) . '...');
                $clone->checkOut($target);
            }
            $this->runChecks($settings, $projectName, $profiles);
        } catch (Throwable $error) {
            throw $this->putBack($clone, $previous, $error);
        }

        return $previous;
    }

    /**
     * Extra arguments for "up" after prepare().
     *
     * Plain "up -d" recreates a container only when its compose definition
     * changed, so a commit that only edits a bind-mounted config file would
     * leave the containers on the old file. When the stack's setting is on and
     * anything in the stack's folder changed, the whole stack is recreated
     * (not restarted: a restart keeps a single-file mount on the old copy of
     * the file).
     *
     * "Changed" is measured from both the previously checked-out commit and
     * the last successfully deployed one. After a failed "up" the two differ,
     * and the running containers may hold either commit's files.
     *
     * @param string $previousCommit The commit prepare() returned
     * @return string[] ['--force-recreate'] or []
     */
    public function upArguments(string $previousCommit): array
    {
        $settings = $this->loadSettings();
        if (!$settings->recreateOnFolderChange) {
            return [];
        }
        $clone = new GitClone($settings);
        $current = $clone->checkedOutCommit();

        $compareFrom = [$previousCommit];
        $deployed = GitStackState::load($this->stackDir)->deployedCommit;
        if ($deployed !== null && $deployed !== $previousCommit) {
            $compareFrom[] = $deployed;
        }
        foreach ($compareFrom as $from) {
            if ($from !== $current && !$clone->hasCommit($from)) {
                // The branch was rewritten and git has since pruned the old
                // commit, so the change cannot be measured: assume one.
                ($this->say)('The commit ' . substr($from, 0, 12) . ' is no longer in the clone, so every container is recreated.');
                return ['--force-recreate'];
            }
            if ($from !== $current && $clone->stackFilesChanged($from, $current)) {
                ($this->say)("Files in the stack's folder changed, so every container is recreated.");
                return ['--force-recreate'];
            }
        }
        return [];
    }

    /**
     * Put the clone back at a commit (after a failed pull, before any container changed).
     */
    public function restore(string $commit): void
    {
        $clone = new GitClone($this->loadSettings());
        if ($clone->checkedOutCommit() !== $commit) {
            $clone->checkOut($commit);
        }
    }

    /**
     * Record how the deploy of the checked-out commit ended.
     */
    public function finish(bool $succeeded): void
    {
        $clone = new GitClone($this->loadSettings());
        $commit = $clone->checkedOutCommit();
        $state = GitStackState::load($this->stackDir);
        $newState = $succeeded
            ? new GitStackState($commit, null)
            : new GitStackState($state->deployedCommit, $commit);
        $newState->save($this->stackDir);
    }

    /**
     * The -f and --env-file arguments for the stack as it is checked out now.
     *
     * @return string[]
     */
    public function composeArgs(): array
    {
        $composeRoot = dirname(rtrim($this->stackDir, '/'));
        $folder = basename(rtrim($this->stackDir, '/'));
        StackInfo::clearCache();
        $stack = StackInfo::fromProject($composeRoot, $folder);
        $built = $stack->buildComposeArgs();

        $args = [];
        foreach ($built['filePaths'] as $file) {
            $args[] = '-f';
            $args[] = $file;
        }
        if ($args === []) {
            throw new RuntimeException("No compose file was found for the stack in {$this->stackDir}.");
        }
        if (isset($built['envFilePath'])) {
            $args[] = '--env-file';
            $args[] = $built['envFilePath'];
        }
        return $args;
    }

    /**
     * After a failed check or checkout, check the previous commit out again.
     * Returns the error to throw: the original problem, or, if putting the
     * commit back failed too, both, so nobody is told the clone is where it
     * was when it is not.
     */
    private function putBack(GitClone $clone, string $previous, Throwable $error): RuntimeException
    {
        try {
            if ($clone->checkedOutCommit() !== $previous) {
                ($this->say)('Putting back ' . substr($previous, 0, 12) . '...');
                $clone->checkOut($previous);
            }
        } catch (Throwable $putBackError) {
            return new RuntimeException(
                $error->getMessage() . "\nThe previous commit " . substr($previous, 0, 12)
                . ' could not be checked out again (' . $putBackError->getMessage() . '). '
                . 'No container was changed, but the clone is not at the deployed commit.',
                0,
                $error
            );
        }
        return $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
    }

    private function loadSettings(): GitStackSettings
    {
        $settings = GitStackSettings::load($this->stackDir);
        if ($settings === null) {
            throw new RuntimeException("{$this->stackDir} is not a git stack (it has no git.json).");
        }

        // compose is run with the file named in the stack's indirect file,
        // while the checks use git.json's compose path. If git.json was edited
        // by hand, the two can disagree, and the checks would pass for a file
        // that is not the one deployed.
        $indirectFile = rtrim($this->stackDir, '/') . '/indirect';
        $indirect = trim((string) @file_get_contents($indirectFile));
        if ($indirect !== $settings->composeFileInClone()) {
            throw new RuntimeException(
                "git.json and the stack's indirect file name different compose files ("
                . $settings->composeFileInClone() . " and " . ($indirect === '' ? 'none' : $indirect) . "). "
                . "Make $indirectFile hold the first, then deploy again."
            );
        }
        return $settings;
    }

    /**
     * Refuse when the clone has local changes, or save them as a patch and discard them.
     *
     * A commit made by hand in the clone counts as a local change too. Call
     * after fetch(), so the branch it is compared with is up to date.
     */
    private function dealWithLocalChanges(GitClone $clone, GitStackState $state, string $checkedOut, bool $saveAndDiscard): void
    {
        $changedFiles = $clone->locallyChangedFiles();
        // A checked-out commit that is on the branch came from the repository:
        // the deployed one, one whose "up" failed, or one left checked out when
        // a deploy was interrupted. The deployed and failed commits also count
        // when a rewritten branch no longer holds them. Anything else was
        // committed in the clone by hand.
        $madeByHand = $checkedOut !== $state->deployedCommit
            && $checkedOut !== $state->failedCommit
            && !$clone->isOnBranch($checkedOut);
        if ($changedFiles === [] && !$madeByHand) {
            return;
        }

        $base = $checkedOut;
        if ($madeByHand) {
            // Save the hand-made commits too, from where they left the branch.
            $base = $clone->lastCommitSharedWithBranch($checkedOut) ?? $state->deployedCommit;
            if ($base === null) {
                throw new RuntimeException(
                    'The clone is at ' . substr($checkedOut, 0, 12) . ', which shares no history with the branch '
                    . "{$clone->settings()->branch}, so nothing was deployed. Clone it again with compose-git reclone."
                );
            }
        }

        if (!$saveAndDiscard) {
            $what = $changedFiles !== []
                ? 'local changes to ' . implode(', ', array_slice($changedFiles, 0, 5)) . (count($changedFiles) > 5 ? ', ...' : '')
                : 'a commit made in the clone by hand (' . substr($checkedOut, 0, 12) . ')';
            throw new RuntimeException(
                "The clone has $what, so nothing was deployed. "
                . 'Deploy again with the option to save them as a patch and discard them, or undo them by hand.'
            );
        }

        $clone->unstageAll();
        $clone->assertDiscardKeepsUntrackedFiles();
        $patch = $clone->saveChangesAsPatch($base, $this->stackDir);
        if ($patch !== null) {
            ($this->say)("Saved the local changes to $patch");
        }
        $clone->discardLocalChanges();
    }

    /**
     * @param string[] $profiles
     * @throws RuntimeException listing every problem found
     */
    private function runChecks(GitStackSettings $settings, string $projectName, array $profiles): void
    {
        ($this->say)('Checking the stack before deploying...');

        // The compose file must stay inside the clone, even through a symlink in the repository.
        $composeFile = realpath($settings->composeFileInClone());
        $cloneReal = realpath($settings->cloneDir);
        if ($composeFile === false || $cloneReal === false || !str_starts_with($composeFile, $cloneReal . '/')) {
            throw new RuntimeException("{$settings->composePath} leads outside the repository (through a symlink), so it was not used.");
        }

        $this->pruneOverrideServicesTheComposeFileNoLongerHas();

        $args = $this->composeArgs();
        foreach ($profiles as $profile) {
            $args[] = '--profile';
            $args[] = $profile;
        }
        $envFile = null;
        $envIndex = array_search('--env-file', $args, true);
        if ($envIndex !== false) {
            $envFile = $args[$envIndex + 1];
            // Say it: the stack folder's .env wins over the repository's, so
            // which one is used can change between two deploys.
            ($this->say)("Using the .env at $envFile");
        } else {
            $envFile = rtrim($this->stackDir, '/') . '/.env';
        }

        $pluginSettings = parse_plugin_cfg('compose.manager');
        $missingNetworksWillBeCreated = ($pluginSettings['CREATE_MISSING_EXTERNAL_NETWORKS'] ?? 'false') === 'true';

        $check = new GitDeployCheck(
            $projectName,
            $args,
            $settings->cloneDir,
            dirname($composeFile),
            $envFile,
            $missingNetworksWillBeCreated
        );
        $problems = $check->run();
        if ($problems !== []) {
            throw new RuntimeException("The stack was not deployed:\n- " . implode("\n- ", $problems));
        }
        foreach ($check->foldersDockerWillCreate() as $folder) {
            ($this->say)("Docker will create the missing folder $folder");
        }
    }

    /**
     * Remove services from the plugin-managed override that the new commit's
     * compose file no longer has, as the web UI does before every "up".
     *
     * An override entry for a renamed or removed service defines a service
     * with no image, and compose would refuse the whole stack over a file the
     * user did not write. The entries only hold the plugin's UI labels. If the
     * checks then fail and the previous commit is put back, the labels of a
     * removed service are not restored; they are only labels.
     */
    private function pruneOverrideServicesTheComposeFileNoLongerHas(): void
    {
        StackInfo::clearCache();
        $stack = StackInfo::fromProject(dirname(rtrim($this->stackDir, '/')), basename(rtrim($this->stackDir, '/')));
        $pruned = $stack->pruneOrphanOverrideServices();
        foreach ($pruned['removed'] as $service) {
            ($this->say)("Removed '$service' from the plugin's override: the compose file no longer has that service.");
        }
    }
}
