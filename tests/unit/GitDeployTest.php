<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use ComposeManager\Tests\Support\FakeDocker;
use GitClone;
use GitCommand;
use GitDeploy;
use GitDeploySkipped;
use GitStackSettings;
use GitStackState;
use PHPUnit\Framework\Attributes\DataProvider;
use PluginTests\Mocks\FunctionMocks;
use PluginTests\TestCase;
use RuntimeException;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitDeploy.php';
require_once __DIR__ . '/support/FakeDocker.php';

/**
 * The git half of a deploy, with real git and a scripted docker.
 */
final class GitDeployTest extends TestCase
{
    private FakeDocker $docker;
    private string $mnt;
    private string $upstream;
    private string $author;
    private string $composeRoot;
    private string $stackDir;
    private GitClone $clone;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->docker = new FakeDocker();
        $this->docker->setConfig(['name' => 'whoami', 'services' => ['whoami' => ['image' => 'busybox']]]);

        $this->mnt = COMPOSE_GIT_MNT_DIR;
        FakeDocker::removeTree($this->mnt);
        mkdir($this->mnt . '/user/appdata', 0755, true);
        mkdir($this->mnt . '/user/repos', 0755, true);
        $this->setArrayState('STARTED');
        file_put_contents(COMPOSE_MOUNTS_FILE, "rootfs {$this->mnt} rootfs rw 0 0\nshfs {$this->mnt}/user fuse.shfs rw 0 0\n");

        $this->upstream = $this->mnt . '/user/repos/upstream.git';
        $this->author = sys_get_temp_dir() . '/compose_git_deploy_author';
        FakeDocker::removeTree($this->author);
        $this->git(['init', '-q', '--bare', '-b', 'main', $this->upstream], null);
        $this->git(['clone', '-q', $this->upstream, $this->author], null);
        $this->git(['checkout', '-q', '-b', 'main'], $this->author);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n"], 'first');

        // A git stack as compose-git add would leave it.
        $this->composeRoot = sys_get_temp_dir() . '/compose_git_deploy_projects';
        FakeDocker::removeTree($this->composeRoot);
        $this->stackDir = $this->composeRoot . '/whoami';
        mkdir($this->stackDir, 0755, true);
        $settings = GitStackSettings::createNew($this->upstream, 'main', 'whoami/compose.yaml', $this->mnt . '/user/appdata/git', 'whoami');
        $settings->save($this->stackDir);
        $this->clone = new GitClone($settings);
        $first = $this->clone->create();
        file_put_contents($this->stackDir . '/indirect', $settings->composeFileInClone());
        file_put_contents($this->stackDir . '/indirect_mode', 'file');
        file_put_contents($this->stackDir . '/project_name', 'whoami');
        (new GitStackState($first, null))->save($this->stackDir);
    }

    protected function tearDown(): void
    {
        $this->docker->cleanUp();
        FakeDocker::removeTree($this->mnt);
        FakeDocker::removeTree($this->author);
        FakeDocker::removeTree($this->composeRoot);
        parent::tearDown();
    }

    public function testPrepareChecksOutTheLatestCommitAndReturnsThePreviousOne(): void
    {
        $before = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');

        $previous = $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame($before, $previous);
        $this->assertSame($this->upstreamHead(), $this->clone->checkedOutCommit());
    }

    public function testAnAutomaticDeployStillAppliesWhileNothingChanged(): void
    {
        $this->setAutoDeploy(GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->docker->setRunning([['name' => 'whoami-whoami-1', 'project' => 'whoami', 'ports' => []]]);

        $this->deploy()->assertAutomaticDeployStillApplies('whoami', $this->deployedCommit());

        $this->assertContains('ps -q --filter label=com.docker.compose.project=whoami', $this->docker->calls());
    }

    /**
     * @return array<string, array{0: \Closure(self): void, 1: string}>
     */
    public static function automaticDeploysThatNoLongerApply(): array
    {
        return [
            'switched to notify' => [static function (self $test): void {
                $test->setAutoDeploy(GitStackSettings::AUTO_DEPLOY_NOTIFY);
            }, 'no longer set to Deploy'],
            'switched off' => [static function (self $test): void {
                $test->setAutoDeploy(GitStackSettings::AUTO_DEPLOY_OFF);
            }, 'no longer set to Deploy'],
            'a deploy failed' => [static function (self $test): void {
                $state = GitStackState::load($test->stackDir);
                (new GitStackState($state->deployedCommit, str_repeat('b', 40)))->save($test->stackDir);
            }, 'automatic deploys of this stack are paused'],
            'another commit deployed meanwhile' => [static function (self $test): void {
                // Pull and Redeploy, or a rollback, ran while the automatic deploy waited for the lock.
                (new GitStackState(str_repeat('c', 40), null))->save($test->stackDir);
            }, 'The deployed commit changed since the scheduled check decided'],
            'local changes' => [static function (self $test): void {
                file_put_contents(GitStackSettings::load($test->stackDir)->composeFileInClone(), "services: {}\n");
            }, 'The clone has local changes'],
            'stopped' => [static function (self $test): void {
                $test->docker->setRunning([]);
            }, 'The stack is not running'],
            'git cannot be asked' => [static function (self $test): void {
                file_put_contents(GitStackSettings::load($test->stackDir)->cloneDir . '/.git/HEAD', "not a ref\n");
            }, "Could not read the clone's local changes"],
            'docker cannot be asked' => [static function (self $test): void {
                $test->docker->fail('ps', 'Cannot connect to the Docker daemon');
            }, 'Could not ask docker'],
        ];
    }

    /**
     * @param \Closure(self): void $change What changed while the deploy waited for the stack's lock
     */
    #[DataProvider('automaticDeploysThatNoLongerApply')]
    public function testAnAutomaticDeployThatNoLongerAppliesIsSkipped(\Closure $change, string $why): void
    {
        $this->setAutoDeploy(GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->docker->setRunning([['name' => 'whoami-whoami-1', 'project' => 'whoami', 'ports' => []]]);
        $deployedWhenDecided = $this->deployedCommit();
        $change($this);

        try {
            $this->deploy()->assertAutomaticDeployStillApplies('whoami', $deployedWhenDecided);
            $this->fail('An automatic deploy went ahead that no longer applies');
        } catch (GitDeploySkipped $skipped) {
            $this->assertStringContainsString($why, $skipped->getMessage());
        }
    }

    public function testFailedCheckPutsThePreviousCommitBack(): void
    {
        $before = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n\timage: broken\n"], 'broken');
        $this->docker->setConfig([], "yaml: found character that cannot start any token\n", 1);

        try {
            $this->deploy()->prepare('whoami', [], null, false);
            $this->fail('Deployed a broken compose file');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot start any token', $error->getMessage());
        }
        $this->assertSame($before, $this->clone->checkedOutCommit());
    }

    public function testMissingExternalNetworkIsLeftToCreateMissingExternalNetworks(): void
    {
        $this->docker->setConfig([
            'name' => 'whoami',
            'services' => ['whoami' => ['image' => 'busybox']],
            'networks' => ['proxy' => ['name' => 'zz-proxy', 'external' => true]],
        ]);

        try {
            $this->deploy()->prepare('whoami', [], null, false);
            $this->fail('Deployed with a missing external network and the setting off');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString("network 'zz-proxy' does not exist", $error->getMessage());
        }

        // With the setting on, compose.sh creates the network before up, so the check lets it through.
        FunctionMocks::setPluginConfig('compose.manager', ['CREATE_MISSING_EXTERNAL_NETWORKS' => 'true']);
        $this->deploy()->prepare('whoami', [], null, false);
        $this->assertSame($this->upstreamHead(), $this->clone->checkedOutCommit());
    }

    public function testComposeFileRemovedUpstreamIsNotDeployed(): void
    {
        $before = $this->clone->checkedOutCommit();
        $this->git(['rm', '-q', 'whoami/compose.yaml'], $this->author);
        $this->commitAndPush('remove');

        try {
            $this->deploy()->prepare('whoami', [], null, false);
            $this->fail('Deployed a commit without the compose file');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('does not exist at commit', $error->getMessage());
        }
        $this->assertSame($before, $this->clone->checkedOutCommit());
        $this->assertSame([], array_filter($this->docker->calls(), static fn(string $c): bool => str_contains($c, 'config')));
    }

    public function testTheCheckRunsComposeWithTheStacksFilesEnvAndProfiles(): void
    {
        file_put_contents($this->stackDir . '/.env', "TZ=Europe/London\n");
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');

        $this->deploy()->prepare('whoami', ['media'], null, false);

        $cloneDir = $this->clone->settings()->cloneDir;
        $this->assertContains(
            "compose -f $cloneDir/whoami/compose.yaml --env-file {$this->stackDir}/.env --profile media -p whoami config --format json",
            $this->docker->calls()
        );
    }

    public function testLocalChangesStopTheDeployAndAreKept(): void
    {
        $file = $this->clone->settings()->cloneDir . '/whoami/compose.yaml';
        file_put_contents($file, "hotfix\n");
        $this->writeAndPush(['whoami/other.txt' => "x\n"], 'upstream change');

        try {
            $this->deploy()->prepare('whoami', [], null, false);
            $this->fail('Deployed over local changes');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('whoami/compose.yaml', $error->getMessage());
        }
        $this->assertSame("hotfix\n", file_get_contents($file));
    }

    public function testLocalChangesCanBeSavedAsAPatchAndThenDeployed(): void
    {
        file_put_contents($this->clone->settings()->cloneDir . '/whoami/compose.yaml', "hotfix\n");
        $this->writeAndPush(['whoami/other.txt' => "x\n"], 'upstream change');

        $this->deploy()->prepare('whoami', [], null, true);

        $patches = glob($this->stackDir . '/git-changes/*.patch') ?: [];
        $this->assertCount(1, $patches);
        $this->assertStringContainsString('+hotfix', (string) file_get_contents($patches[0]));
        $this->assertSame($this->upstreamHead(), $this->clone->checkedOutCommit());
    }

    public function testCommitMadeByHandInTheCloneStopsTheDeploy(): void
    {
        $dir = $this->clone->settings()->cloneDir;
        file_put_contents($dir . '/whoami/compose.yaml', "by hand\n");
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-am', 'by hand'], $dir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('by hand');
        $this->deploy()->prepare('whoami', [], null, false);
    }

    public function testCommitMadeByHandIsSavedInThePatchWithTheOption(): void
    {
        $dir = $this->clone->settings()->cloneDir;
        file_put_contents($dir . '/whoami/compose.yaml', "by hand\n");
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-am', 'by hand'], $dir);
        $this->writeAndPush(['whoami/other.txt' => "x\n"], 'upstream change');

        $this->deploy()->prepare('whoami', [], null, true);

        $patches = glob($this->stackDir . '/git-changes/*.patch') ?: [];
        $this->assertCount(1, $patches);
        $this->assertStringContainsString('+by hand', (string) file_get_contents($patches[0]));
        $this->assertSame($this->upstreamHead(), $this->clone->checkedOutCommit());
    }

    public function testCloneLeftAtANewerCommitOfTheBranchIsNotACommitMadeByHand(): void
    {
        // A deploy interrupted after the checkout (Ctrl-C, or a put-back that
        // failed) leaves the clone at a branch commit that was never deployed.
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');
        $this->clone->checkOut($this->clone->fetch());

        $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame($this->upstreamHead(), $this->clone->checkedOutCommit());
        $this->assertDirectoryDoesNotExist($this->stackDir . '/git-changes');
    }

    public function testLocalChangesAreKeptWhenTheFetchFails(): void
    {
        $file = $this->clone->settings()->cloneDir . '/whoami/compose.yaml';
        file_put_contents($file, "hotfix\n");
        rename($this->upstream, $this->upstream . '.away');

        try {
            $this->deploy()->prepare('whoami', [], null, true);
            $this->fail('Deployed without reaching the repository');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Could not fetch', $error->getMessage());
        } finally {
            rename($this->upstream . '.away', $this->upstream);
        }
        $this->assertSame("hotfix\n", file_get_contents($file));
        $this->assertDirectoryDoesNotExist($this->stackDir . '/git-changes');
    }

    public function testComposePathEditedInGitJsonButNotInTheIndirectFileIsRefused(): void
    {
        $this->writeAndPush(['other/compose.yaml' => "services:\n  other:\n    image: busybox\n"], 'second stack');
        $json = $this->stackDir . '/git.json';
        file_put_contents($json, str_replace('whoami/compose.yaml', 'other/compose.yaml', (string) file_get_contents($json)));

        try {
            $this->deploy()->prepare('whoami', [], null, false);
            $this->fail('Checked one compose file and would have deployed another');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('name different compose files', $error->getMessage());
        }
    }

    public function testASpecificOlderCommitCanBeDeployed(): void
    {
        $first = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');
        $this->deploy()->prepare('whoami', [], null, false);
        $this->deploy()->finish(true);

        $this->deploy()->prepare('whoami', [], $first, false);

        $this->assertSame($first, $this->clone->checkedOutCommit());
    }

    public function testCommitOptionMustBeAFullCommitId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->deploy()->prepare('whoami', [], '--upload-pack=touch /tmp/x', false);
    }

    public function testNothingHappensWhileTheArrayIsStopped(): void
    {
        $this->setArrayState('STOPPED');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('array is not started');
        $this->deploy()->prepare('whoami', [], null, false);
    }

    public function testRestorePutsTheOldCommitBack(): void
    {
        $previous = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'change');
        $this->deploy()->prepare('whoami', [], null, false);

        $this->deploy()->restore($previous);

        $this->assertSame($previous, $this->clone->checkedOutCommit());
    }

    public function testFinishRecordsSuccessAndFailure(): void
    {
        $first = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'change');
        $this->deploy()->prepare('whoami', [], null, false);

        $this->deploy()->finish(false);
        $state = GitStackState::load($this->stackDir);
        $this->assertSame($first, $state->deployedCommit);
        $this->assertSame($this->upstreamHead(), $state->failedCommit);

        $this->deploy()->finish(true);
        $state = GitStackState::load($this->stackDir);
        $this->assertSame($this->upstreamHead(), $state->deployedCommit);
        $this->assertNull($state->failedCommit);
    }

    // ----- recreate on any change in the stack's folder -----

    public function testConfigOnlyChangeInTheStackFolderRecreatesEveryContainer(): void
    {
        $this->writeAndPush(['whoami/config.yml' => "level: debug\n"], 'config only');
        $previous = $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame(['--force-recreate'], $this->deploy()->upArguments($previous));
    }

    public function testChangeOutsideTheStackFolderRecreatesNothing(): void
    {
        $this->writeAndPush(['other/compose.yaml' => "services: {}\n"], 'another stack');
        $previous = $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame([], $this->deploy()->upArguments($previous));
    }

    public function testRedeployOfTheSameCommitRecreatesNothing(): void
    {
        $previous = $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame([], $this->deploy()->upArguments($previous));
    }

    public function testDiscardedLocalChangesInTheStackFolderRecreateEveryContainer(): void
    {
        // A config file edited in the clone, then discarded at the same commit: no commit
        // differs, but a container with a single-file mount of it still holds the edit.
        $this->writeAndPush(['whoami/app.conf' => "from-repo\n"], 'config');
        $previous = $this->deploy()->prepare('whoami', [], null, false);
        $this->deploy()->finish(true);
        file_put_contents($this->clone->settings()->cloneDir . '/whoami/app.conf', "edited-locally\n");

        $previous = $this->deploy()->prepare('whoami', [], null, true);
        $this->assertSame(['--force-recreate'], $this->deploy()->upArguments($previous));

        // Until a deploy succeeds, every deploy recreates: the containers may still hold the edit.
        $this->deploy()->finish(false);
        $this->assertSame(['--force-recreate'], $this->deploy()->upArguments($previous));
        $this->deploy()->finish(true);
        $this->assertFileDoesNotExist($this->stackDir . '/' . GitDeploy::DISCARDED_CHANGES_FILE);
        $previous = $this->deploy()->prepare('whoami', [], null, false);
        $this->assertSame([], $this->deploy()->upArguments($previous));
    }

    public function testDiscardedLocalChangesOutsideTheStackFolderRecreateNothing(): void
    {
        $this->writeAndPush(['other/readme.txt' => "from-repo\n"], 'another folder');
        $previous = $this->deploy()->prepare('whoami', [], null, false);
        $this->deploy()->finish(true);
        file_put_contents($this->clone->settings()->cloneDir . '/other/readme.txt', "edited-locally\n");

        $previous = $this->deploy()->prepare('whoami', [], null, true);

        $this->assertSame([], $this->deploy()->upArguments($previous));
    }

    public function testWithTheSettingOffNothingIsRecreated(): void
    {
        GitStackSettings::load($this->stackDir)->withRecreateOnFolderChange(false)->save($this->stackDir);
        $this->writeAndPush(['whoami/config.yml' => "level: debug\n"], 'config only');
        $previous = $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame([], $this->deploy()->upArguments($previous));
    }

    public function testFolderChangeSinceTheDeployedCommitRecreatesAfterAFailedUp(): void
    {
        // A deployed; B edits a mounted config file and its up fails; C changes
        // nothing in the folder. The containers may still have A's file.
        $this->writeAndPush(['whoami/config.yml' => "level: debug\n"], 'B: config');
        $this->deploy()->prepare('whoami', [], null, false);
        $this->deploy()->finish(false);
        $this->writeAndPush(['other/compose.yaml' => "services: {}\n"], 'C: another stack');

        $previous = $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame(['--force-recreate'], $this->deploy()->upArguments($previous));
    }

    public function testDeployedCommitNoLongerInTheCloneRecreatesInsteadOfFailing(): void
    {
        // The branch was rewritten and git pruned the deployed commit.
        (new GitStackState(str_repeat('a', 40), null))->save($this->stackDir);
        $this->writeAndPush(['other/compose.yaml' => "services: {}\n"], 'another stack');

        $previous = $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame(['--force-recreate'], $this->deploy()->upArguments($previous));
    }

    public function testDeployAfterAFailedUpIsNotMistakenForACommitMadeByHand(): void
    {
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');
        $this->deploy()->prepare('whoami', [], null, false);
        $this->deploy()->finish(false);

        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v3\n"], 'fix');
        $this->deploy()->prepare('whoami', [], null, false);

        $this->assertSame($this->upstreamHead(), $this->clone->checkedOutCommit());
        $this->assertDirectoryDoesNotExist($this->stackDir . '/git-changes');
    }

    public function testEnvKeptInTheRepositoryIsUsedAndChecked(): void
    {
        $this->writeAndPush(['whoami/.env' => "X=from-repo\n", 'whoami/.env.example' => "X=\n"], 'repo env');
        $this->deploy()->prepare('whoami', [], null, false);

        $args = $this->deploy()->composeArgs();

        $envFile = $args[array_search('--env-file', $args, true) + 1];
        $this->assertSame(realpath($this->clone->settings()->cloneDir . '/whoami/.env'), realpath($envFile));
    }

    public function testComposeArgsUseTheCloneAndTheStackFoldersEnv(): void
    {
        file_put_contents($this->stackDir . '/.env', "A=1\n");
        $args = $this->deploy()->composeArgs();
        $this->assertSame(
            ['-f', $this->clone->settings()->composeFileInClone(), '--env-file', realpath($this->stackDir . '/.env')],
            $args
        );
    }

    public function testComposeFolderIsTheFolderTheChecksRunIn(): void
    {
        $this->assertSame(
            dirname((string) realpath($this->clone->settings()->composeFileInClone())),
            $this->deploy()->composeFolder()
        );
    }

    public function testComposeFolderRefusesAMissingComposeFile(): void
    {
        unlink($this->clone->settings()->composeFileInClone());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');
        $this->deploy()->composeFolder();
    }

    public function testComposeFileReachedThroughASymlinkOutsideTheCloneIsRefused(): void
    {
        $outside = $this->mnt . '/user/appdata/outside.yaml';
        file_put_contents($outside, "services: {}\n");
        $this->git(['rm', '-q', 'whoami/compose.yaml'], $this->author);
        @mkdir($this->author . '/whoami');
        symlink($outside, $this->author . '/whoami/compose.yaml');
        $this->git(['add', 'whoami/compose.yaml'], $this->author);
        $this->commitAndPush('symlink out');
        $before = $this->clone->checkedOutCommit();

        try {
            $this->deploy()->prepare('whoami', [], null, false);
            $this->fail('Used a compose file outside the clone');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('leads outside the repository', $error->getMessage());
        }
        $this->assertSame($before, $this->clone->checkedOutCommit());
    }

    public function testOverrideEntryForAServiceTheCommitRemovedIsPrunedBeforeTheChecks(): void
    {
        // The override has UI labels for 'gone', which the new commit renames to 'whoami2'.
        $override = (string) \StackInfo::fromProject($this->composeRoot, 'whoami')->getOverridePath();
        $this->assertStringStartsWith($this->stackDir . '/', $override);
        file_put_contents(
            $override,
            "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://x\n"
            . "  gone:\n    labels:\n      net.unraid.docker.webui: http://y\n"
        );
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n  whoami2:\n    image: busybox\n"], 'rename gone');
        $this->docker->setServices(['whoami', 'whoami2']);
        $said = [];
        $deploy = new GitDeploy($this->stackDir, static function (string $message) use (&$said): void {
            $said[] = $message;
        });

        // StackInfo runs "docker" from the PATH, so put the scripted one first.
        $originalPath = (string) getenv('PATH');
        putenv('PATH=' . $this->docker->dir . ':' . $originalPath);
        try {
            $deploy->prepare('whoami', [], null, false);
        } finally {
            putenv('PATH=' . $originalPath);
        }

        $content = (string) file_get_contents($override);
        $this->assertStringNotContainsString('gone', $content);
        $this->assertStringContainsString('whoami', $content);
        $this->assertContains("Removed 'gone' from the plugin's override: the compose file no longer has that service.", $said);
    }

    public function testDryRunChecksTheNewCommitShowsTheChangeAndPutsThePreviousOneBack(): void
    {
        $before = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');

        $result = $this->deploy()->dryRun('whoami', [], null);

        $this->assertSame($before, $result['previousCommit']);
        $this->assertSame($this->upstreamHead(), $result['targetCommit']);
        $this->assertSame([], $result['problems']);
        $this->assertTrue($result['recreatesEveryContainer']);
        $this->assertNull($result['configProblem']);
        $this->assertStringContainsString("-    image: traefik/whoami\n", (string) $result['configDiff']);
        $this->assertStringContainsString("+    image: traefik/whoami:v2\n", (string) $result['configDiff']);
        // The checks ran on the new commit, and nothing of the stack changed.
        $this->assertContains('compose -f ' . $this->clone->settings()->cloneDir . '/whoami/compose.yaml -p whoami config --format json', $this->docker->calls());
        $this->assertSame($before, $this->clone->checkedOutCommit());
        $this->assertSame($before, $result['deployedCommit']);
        $this->assertSame($before, GitStackState::load($this->stackDir)->deployedCommit);
        $this->assertNull(GitStackState::load($this->stackDir)->failedCommit);
        $this->assertFileDoesNotExist($this->stackDir . '/' . GitDeploy::DISCARDED_CHANGES_FILE);
        $this->assertFileDoesNotExist($this->stackDir . '/git_check.json');
        // The stack had no override, and the dry run made none.
        $this->assertFileDoesNotExist((string) \StackInfo::fromProject($this->composeRoot, 'whoami')->getOverridePath());
        $this->assertSame([], array_filter($this->docker->calls(), static fn(string $c): bool => preg_match('/\b(up|pull|build)\b/', $c) === 1));
    }

    public function testDryRunNamesTheDeployedCommitAfterAFailedUp(): void
    {
        $deployed = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');
        $this->deploy()->prepare('whoami', [], null, false);
        $this->deploy()->finish(false);

        $result = $this->deploy()->dryRun('whoami', [], null);

        // The clone is at the commit whose up failed; the last one that worked is named too.
        $this->assertSame($this->upstreamHead(), $result['previousCommit']);
        $this->assertSame($this->upstreamHead(), $result['targetCommit']);
        $this->assertSame($deployed, $result['deployedCommit']);
    }

    public function testDryRunPutsThePreviousCommitBackWhenACheckStopsIt(): void
    {
        $outside = $this->mnt . '/user/appdata/outside.yaml';
        file_put_contents($outside, "services: {}\n");
        $this->git(['rm', '-q', 'whoami/compose.yaml'], $this->author);
        @mkdir($this->author . '/whoami');
        symlink($outside, $this->author . '/whoami/compose.yaml');
        $this->git(['add', 'whoami/compose.yaml'], $this->author);
        $this->commitAndPush('symlink out');
        $before = $this->clone->checkedOutCommit();

        try {
            $this->deploy()->dryRun('whoami', [], null);
            $this->fail('Tried a compose file outside the clone');
        } catch (RuntimeException $error) {
            // The check's own error, not one about putting the commit back.
            $this->assertStringContainsString('leads outside the repository', $error->getMessage());
            $this->assertStringNotContainsString('could not be checked out again', $error->getMessage());
        }
        $this->assertSame($before, $this->clone->checkedOutCommit());
    }

    public function testDryRunListsWhatWouldStopTheDeploy(): void
    {
        $before = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');
        $this->docker->setConfig([
            'name' => 'whoami',
            'services' => ['whoami' => ['image' => 'busybox']],
            'networks' => ['proxy' => ['name' => 'zz-proxy', 'external' => true]],
        ]);

        $result = $this->deploy()->dryRun('whoami', [], null);

        $this->assertCount(1, $result['problems']);
        $this->assertStringContainsString("network 'zz-proxy' does not exist", $result['problems'][0]);
        // Up would not run, so whether it would recreate the containers is not worked out.
        $this->assertFalse($result['recreatesEveryContainer']);
        $this->assertNotSame('', $result['configDiff']);
        $this->assertSame($before, $this->clone->checkedOutCommit());
    }

    public function testDryRunOfTheCheckedOutCommitShowsNoChange(): void
    {
        $result = $this->deploy()->dryRun('whoami', [], null);

        $this->assertSame($result['previousCommit'], $result['targetCommit']);
        $this->assertSame([], $result['problems']);
        $this->assertFalse($result['recreatesEveryContainer']);
        $this->assertSame('', $result['configDiff']);
    }

    public function testDryRunOfAnOlderCommit(): void
    {
        $first = $this->clone->checkedOutCommit();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');
        $this->deploy()->prepare('whoami', [], null, false);
        $this->deploy()->finish(true);

        $result = $this->deploy()->dryRun('whoami', [], $first);

        $this->assertSame($first, $result['targetCommit']);
        $this->assertStringContainsString("+    image: traefik/whoami\n", (string) $result['configDiff']);
        $this->assertSame($this->upstreamHead(), $this->clone->checkedOutCommit());
    }

    public function testDryRunStopsOnLocalChangesAndKeepsThem(): void
    {
        $file = $this->clone->settings()->cloneDir . '/whoami/compose.yaml';
        file_put_contents($file, "hotfix\n");
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');

        try {
            $this->deploy()->dryRun('whoami', [], null);
            $this->fail('Tried a commit over local changes');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('local changes to whoami/compose.yaml', $error->getMessage());
        }
        $this->assertSame("hotfix\n", file_get_contents($file));
    }

    public function testDryRunSaysWhenTheConfigurationCannotBeRead(): void
    {
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v2\n"], 'bump');
        $this->docker->failConfigAsWritten("unknown flag: --no-env-resolution\n");

        $result = $this->deploy()->dryRun('whoami', [], null);

        $this->assertNull($result['configDiff']);
        $this->assertStringContainsString('unknown flag: --no-env-resolution', (string) $result['configProblem']);
        // The checks still ran and said the deploy would go ahead.
        $this->assertSame([], $result['problems']);
    }

    public function testDryRunPutsTheOverrideBackAfterTheChecksPruneIt(): void
    {
        $override = (string) \StackInfo::fromProject($this->composeRoot, 'whoami')->getOverridePath();
        $content = "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://x\n"
            . "  gone:\n    labels:\n      net.unraid.docker.webui: http://y\n";
        file_put_contents($override, $content);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n  whoami2:\n    image: busybox\n"], 'rename gone');
        $this->docker->setServices(['whoami', 'whoami2']);
        $said = [];
        $deploy = new GitDeploy($this->stackDir, static function (string $message) use (&$said): void {
            $said[] = $message;
        });

        // StackInfo runs "docker" from the PATH, so put the scripted one first.
        $originalPath = (string) getenv('PATH');
        putenv('PATH=' . $this->docker->dir . ':' . $originalPath);
        try {
            $deploy->dryRun('whoami', [], null);
        } finally {
            putenv('PATH=' . $originalPath);
        }

        // The checks ran on the pruned override, as a deploy's would, and the dry run put it back.
        $this->assertContains("Removed 'gone' from the plugin's override: the compose file no longer has that service.", $said);
        $this->assertContains("Put the plugin's override back as it was before the dry run.", $said);
        $this->assertSame($content, file_get_contents($override));
    }

    public function testDryRunKeepsAnOverrideSavedBeforeThePruneThatChangedNothing(): void
    {
        $override = (string) \StackInfo::fromProject($this->composeRoot, 'whoami')->getOverridePath();
        file_put_contents($override, "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://x\n");
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->docker->setServices(['whoami']);
        // The Labels tab saves the override after the dry run started, before the checks prune it.
        $savedMeanwhile = "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://z\n";
        $deploy = new GitDeploy($this->stackDir, static function (string $message) use ($override, $savedMeanwhile): void {
            if (str_starts_with($message, 'Checking out')) {
                file_put_contents($override, $savedMeanwhile);
            }
        });

        $originalPath = (string) getenv('PATH');
        putenv('PATH=' . $this->docker->dir . ':' . $originalPath);
        try {
            $deploy->dryRun('whoami', [], null);
        } finally {
            putenv('PATH=' . $originalPath);
        }

        $this->assertSame($savedMeanwhile, file_get_contents($override));
    }

    public function testDryRunPutsBackAnOverrideSavedBeforeThePruneThatChangedIt(): void
    {
        $override = (string) \StackInfo::fromProject($this->composeRoot, 'whoami')->getOverridePath();
        file_put_contents(
            $override,
            "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://x\n"
            . "  gone:\n    labels:\n      net.unraid.docker.webui: http://y\n"
        );
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n  whoami2:\n    image: busybox\n"], 'rename gone');
        $this->docker->setServices(['whoami', 'whoami2']);
        // The Labels tab saves the override after the dry run started, before the checks prune it.
        $savedMeanwhile = "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://z\n"
            . "  gone:\n    labels:\n      net.unraid.docker.webui: http://y\n";
        $deploy = new GitDeploy($this->stackDir, static function (string $message) use ($override, $savedMeanwhile): void {
            if (str_starts_with($message, 'Checking out')) {
                file_put_contents($override, $savedMeanwhile);
            }
        });

        $originalPath = (string) getenv('PATH');
        putenv('PATH=' . $this->docker->dir . ':' . $originalPath);
        try {
            $deploy->dryRun('whoami', [], null);
        } finally {
            putenv('PATH=' . $originalPath);
        }

        // What the prune read was the newer save, so that is what is put back.
        $this->assertSame($savedMeanwhile, file_get_contents($override));
    }

    public function testDryRunStoppedBeforeThePruneLeavesAnOverrideSavedWhileItRan(): void
    {
        $override = (string) \StackInfo::fromProject($this->composeRoot, 'whoami')->getOverridePath();
        file_put_contents($override, "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://x\n");
        // A commit whose compose file leads out of the clone: the checks stop before the prune.
        $outside = $this->mnt . '/user/appdata/outside.yaml';
        file_put_contents($outside, "services: {}\n");
        $this->git(['rm', '-q', 'whoami/compose.yaml'], $this->author);
        @mkdir($this->author . '/whoami');
        symlink($outside, $this->author . '/whoami/compose.yaml');
        $this->git(['add', 'whoami/compose.yaml'], $this->author);
        $this->commitAndPush('symlink out');
        // The Labels tab saves the override as the checks start.
        $savedMeanwhile = "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://z\n";
        $deploy = new GitDeploy($this->stackDir, static function (string $message) use ($override, $savedMeanwhile): void {
            if (str_starts_with($message, 'Checking the stack')) {
                file_put_contents($override, $savedMeanwhile);
            }
        });

        try {
            $deploy->dryRun('whoami', [], null);
            $this->fail('Tried a compose file outside the clone');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('leads outside the repository', $error->getMessage());
        }
        $this->assertSame($savedMeanwhile, file_get_contents($override));
    }

    public function testDryRunLeavesAnOverrideSavedWhileItRan(): void
    {
        $override = (string) \StackInfo::fromProject($this->composeRoot, 'whoami')->getOverridePath();
        file_put_contents(
            $override,
            "services:\n  whoami:\n    labels:\n      net.unraid.docker.webui: http://x\n"
            . "  gone:\n    labels:\n      net.unraid.docker.webui: http://y\n"
        );
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n  whoami2:\n    image: busybox\n"], 'rename gone');
        $this->docker->setServices(['whoami', 'whoami2']);
        // The Labels tab saves the override right after the checks pruned it.
        $savedMeanwhile = "services:\n  whoami2:\n    labels:\n      net.unraid.docker.webui: http://z\n";
        $said = [];
        $deploy = new GitDeploy($this->stackDir, static function (string $message) use (&$said, $override, $savedMeanwhile): void {
            $said[] = $message;
            if (str_starts_with($message, "Removed 'gone'")) {
                file_put_contents($override, $savedMeanwhile);
            }
        });

        $originalPath = (string) getenv('PATH');
        putenv('PATH=' . $this->docker->dir . ':' . $originalPath);
        try {
            $deploy->dryRun('whoami', [], null);
        } finally {
            putenv('PATH=' . $originalPath);
        }

        $this->assertSame($savedMeanwhile, file_get_contents($override));
        $this->assertContains("The plugin's override was changed during the dry run, so it was left as it is now.", $said);
    }

    public function testDryRunWhileTheArrayIsStoppedTriesNothing(): void
    {
        $this->setArrayState('STOPPED');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The array is not started');
        $this->deploy()->dryRun('whoami', [], null);
    }

    // ----- helpers -----

    private function deploy(): GitDeploy
    {
        return new GitDeploy($this->stackDir);
    }

    private function setAutoDeploy(string $autoDeploy): void
    {
        GitStackSettings::load($this->stackDir)->withAutoDeploy($autoDeploy)->save($this->stackDir);
    }

    private function deployedCommit(): ?string
    {
        return GitStackState::load($this->stackDir)->deployedCommit;
    }

    /** @param array<string, string> $files */
    private function writeAndPush(array $files, string $message): void
    {
        foreach ($files as $path => $content) {
            $full = $this->author . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0755, true);
            }
            file_put_contents($full, $content);
            $this->git(['add', '--', $path], $this->author);
        }
        $this->commitAndPush($message);
    }

    private function commitAndPush(string $message): void
    {
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-m', $message], $this->author);
        $this->git(['push', '-q', 'origin', 'main'], $this->author);
    }

    private function upstreamHead(): string
    {
        return trim(GitCommand::run(['--git-dir=' . $this->upstream, 'rev-parse', 'refs/heads/main'])->stdout);
    }

    /** @param string[] $args */
    private function git(array $args, ?string $dir): void
    {
        $result = GitCommand::run($args, $dir);
        $this->assertTrue($result->succeeded(), 'git ' . implode(' ', $args) . ': ' . $result->stderr);
    }

    private function setArrayState(string $state): void
    {
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"$state\"\n");
    }
}
