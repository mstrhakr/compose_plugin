<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use ComposeManager\Tests\Support\FakeDocker;
use GitClone;
use GitCommand;
use GitDeploy;
use GitStackSettings;
use GitStackState;
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

    // ----- helpers -----

    private function deploy(): GitDeploy
    {
        return new GitDeploy($this->stackDir);
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
