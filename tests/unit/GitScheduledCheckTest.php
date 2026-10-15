<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use ComposeManager\Tests\Support\FakeDocker;
use GitCommand;
use GitDeploy;
use GitScheduledCheck;
use GitStackManager;
use GitStackSettings;
use GitStackState;
use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitScheduledCheck.php';
require_once __DIR__ . '/support/FakeDocker.php';

/**
 * The scheduled check of git stacks, with real git and a stand-in for the
 * deploy and the notifications.
 */
final class GitScheduledCheckTest extends TestCase
{
    private string $mnt;
    private string $upstream;
    private string $author;
    private string $composeRoot;
    private string $clonesRoot;
    private GitStackManager $manager;

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $notifications = [];

    /** @var list<string> */
    private array $deployed = [];

    /** @var list<string> The commit each stand-in deploy was asked for. */
    private array $deployedCommits = [];

    /** @var list<?string> The deployed commit each stand-in deploy was decided from. */
    private array $decidedFrom = [];

    /** The exit status the stand-in deploy returns. */
    private int $deployExitStatus = 0;

    /** Whether the stand-in docker says the stack runs; null when it cannot be asked. */
    private ?bool $running = true;

    /** What the stand-in deploy does besides returning its status, as compose.sh would. */
    private ?\Closure $onDeploy = null;

    /** What happens when the pass asks whether the stack runs, between its comparison and its deploy. */
    private ?\Closure $isRunningHook = null;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->mnt = COMPOSE_GIT_MNT_DIR;
        FakeDocker::removeTree($this->mnt);
        mkdir($this->mnt . '/user/appdata', 0755, true);
        mkdir($this->mnt . '/user/repos', 0755, true);
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"STARTED\"\n");
        file_put_contents(COMPOSE_MOUNTS_FILE, "rootfs {$this->mnt} rootfs rw 0 0\nshfs {$this->mnt}/user fuse.shfs rw 0 0\n");

        $this->upstream = $this->mnt . '/user/repos/stacks.git';
        $this->author = sys_get_temp_dir() . '/compose_git_schedule_author';
        FakeDocker::removeTree($this->author);
        $this->git(['init', '-q', '--bare', '-b', 'main', $this->upstream], null);
        $this->git(['clone', '-q', $this->upstream, $this->author], null);
        $this->git(['checkout', '-q', '-b', 'main'], $this->author);
        $this->writeAndPush([
            'whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n",
            'other/compose.yaml' => "services:\n  other:\n    image: nginx\n",
        ], 'first');

        $this->composeRoot = sys_get_temp_dir() . '/compose_git_schedule_projects';
        FakeDocker::removeTree($this->composeRoot);
        mkdir($this->composeRoot);
        FakeDocker::removeTree(COMPOSE_LOCK_DIR);
        $this->clonesRoot = $this->mnt . '/user/appdata/git';
        $this->manager = new GitStackManager($this->composeRoot);
        $this->notifications = [];
        $this->deployed = [];
        $this->deployedCommits = [];
        $this->decidedFrom = [];
        $this->deployExitStatus = 0;
        $this->onDeploy = null;
        $this->isRunningHook = null;
        $this->running = true;
    }

    protected function tearDown(): void
    {
        FakeDocker::removeTree($this->mnt);
        FakeDocker::removeTree($this->author);
        FakeDocker::removeTree($this->composeRoot);
        FakeDocker::removeTree(COMPOSE_LOCK_DIR);
        parent::tearDown();
    }

    public function testAStackWithAutomaticDeploysOffIsNotChecked(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_OFF);
        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'change');

        $this->assertSame(0, $this->check()->run());

        $this->assertSame([], $this->notifications);
        $this->assertFileDoesNotExist($this->composeRoot . '/' . $folder . '/' . GitScheduledCheck::RECORD_FILE);
    }

    public function testAnUpToDateStackIsLeftAlone(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);

        $this->assertSame(1, $this->check()->run());

        $this->assertSame([], $this->notifications);
        $this->assertSame([], $this->deployed);
        $this->assertFalse(GitScheduledCheck::loadRecord($this->composeRoot . '/' . $folder)['stackChanged']);
    }

    public function testNotifyTellsOnceAboutACommitThatChangesTheStack(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_NOTIFY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');

        $this->check()->run();
        $this->check()->run();

        $this->assertCount(1, $this->notifications);
        $this->assertSame("Git stack update: $folder", $this->notifications[0][0]);
        $this->assertStringContainsString(substr($this->upstreamHead(), 0, 12), $this->notifications[0][1]);
        $this->assertSame([], $this->deployed);
        $record = GitScheduledCheck::loadRecord($this->composeRoot . '/' . $folder);
        $this->assertTrue($record['stackChanged']);
        $this->assertSame($this->upstreamHead(), $record['remoteCommit']);
    }

    public function testWhileBehindACommitToAnotherStacksFolderBringsNoSecondNotification(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_NOTIFY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->check()->run();
        $this->writeAndPush(['other/compose.yaml' => "services: {}\n"], 'other stack');
        $this->check()->run();
        $this->assertCount(1, $this->notifications);
        $this->assertTrue(GitScheduledCheck::loadRecord($this->composeRoot . '/' . $folder)['stackChanged']);

        // A further change to the stack's own folder is news again.
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.11\n"], 'bump');
        $this->check()->run();
        $this->assertCount(2, $this->notifications);
    }

    public function testACommitToAnotherStacksFolderChangesNothing(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->writeAndPush(['other/compose.yaml' => "services: {}\n"], 'other stack');

        $this->check()->run();

        $this->assertSame([], $this->notifications);
        $this->assertSame([], $this->deployed);
        $record = GitScheduledCheck::loadRecord($this->composeRoot . '/' . $folder);
        $this->assertFalse($record['stackChanged']);
        $this->assertSame($this->upstreamHead(), $record['remoteCommit']);
    }

    public function testDeployDeploysEachNewCommitOnce(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');

        $this->check()->run();
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);

        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.11\n"], 'bump');
        $this->check()->run();
        $this->assertSame([$folder, $folder], $this->deployed);
    }

    public function testTheCommitComparedIsTheCommitDeployedWhenTheBranchMovesOnMeanwhile(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $compared = $this->upstreamHead();
        $deployedBefore = GitStackState::load($stackDir)->deployedCommit;

        // The branch moves on after the pass compared its commit and before the deploy starts.
        $this->isRunningHook = function (): void {
            $this->isRunningHook = null;
            $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.11\n"], 'bump');
        };
        $this->check()->run();

        $this->assertSame([$compared], $this->deployedCommits);
        // The deploy is told what the pass compared from, so it can tell a deploy by hand meanwhile.
        $this->assertSame([$deployedBefore], $this->decidedFrom);
        $this->assertSame($compared, GitScheduledCheck::loadRecord($stackDir)['handledCommit']);

        // The newer commit is compared and deployed by the next pass, once.
        (new GitStackState($compared, null))->save($stackDir);
        $this->check()->run();
        $this->check()->run();
        $this->assertSame([$compared, $this->upstreamHead()], $this->deployedCommits);
        $this->assertSame([$folder, $folder], $this->deployed);
    }

    public function testADeploySkippedUnderTheStacksLockIsDecidedAgainByTheNextPass(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        // The stack is stopped while the deploy waits for its lock: the deploy changes nothing.
        $this->deployExitStatus = GitDeploy::EXIT_SKIPPED;
        $this->onDeploy = function (): void {
            $this->running = false;
        };

        $this->check()->run();

        $this->assertSame([$folder], $this->deployed);
        $this->assertNull(GitScheduledCheck::loadRecord($stackDir)['handledBy']);
        $this->assertNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));
        $this->assertSame([], $this->notifications);

        // The next pass sees the stack stopped, and says so once, as for any stopped stack.
        $this->onDeploy = null;
        $this->check()->run();
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);
        $this->assertCount(1, $this->notifications);
        $this->assertStringContainsString('is not running', $this->notifications[0][1]);
    }

    public function testADeploySkippedUnderTheStacksLockKeepsHowTheCommitWasHandledBefore(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_NOTIFY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->check()->run();
        $notified = GitScheduledCheck::loadRecord($stackDir);
        $this->assertSame('notified', $notified['handledBy']);

        // Switched to Deploy; the deploy finds, under the lock, that it no longer applies.
        GitStackSettings::load($stackDir)->withAutoDeploy(GitStackSettings::AUTO_DEPLOY_DEPLOY)->save($stackDir);
        $this->deployExitStatus = GitDeploy::EXIT_SKIPPED;
        $this->check()->run();

        // The record still says the commit was notified about, so no second notification is sent,
        // and the next pass tries the deploy again.
        $this->assertSame([$folder], $this->deployed);
        $this->assertSame($notified, GitScheduledCheck::loadRecord($stackDir));
        $this->deployExitStatus = 0;
        $this->check()->run();
        $this->assertSame([$folder, $folder], $this->deployed);
        $this->assertCount(1, $this->notifications);
    }

    public function testTheDeployRunsGitdeployAtTheComparedCommitAsAnAutomaticDeploy(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $commit = $this->upstreamHead();

        $deployed = str_repeat('a', 40);

        $command = GitScheduledCheck::automaticDeployCommand(\StackInfo::fromProject($this->composeRoot, $folder), $commit, $deployed);

        $this->assertSame('/bin/sh', $command[0]);
        $this->assertStringEndsWith('/scripts/compose_background.sh', $command[1]);
        $this->assertContains('-cgitdeploy', $command);
        $position = array_search('--git-commit', $command, true);
        $this->assertNotFalse($position);
        $this->assertSame($commit, $command[$position + 1]);
        $position = array_search('--automatic-deploy-from', $command, true);
        $this->assertNotFalse($position);
        $this->assertSame($deployed, $command[$position + 1]);

        // Nothing deployed yet: said as "none".
        $command = GitScheduledCheck::automaticDeployCommand(\StackInfo::fromProject($this->composeRoot, $folder), $commit, null);
        $position = array_search('--automatic-deploy-from', $command, true);
        $this->assertNotFalse($position);
        $this->assertSame('none', $command[$position + 1]);
    }

    public function testAStackWhoseLastDeployFailedIsNotDeployedAndSaysWhy(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $state = GitStackState::load($stackDir);
        (new GitStackState($state->deployedCommit, str_repeat('b', 40)))->save($stackDir);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');

        $this->check()->run();
        $this->check()->run();

        $this->assertSame([], $this->deployed);
        $this->assertCount(1, $this->notifications);
        $this->assertStringContainsString('paused', $this->notifications[0][1]);
        $this->assertStringContainsString('compose-git resume', $this->notifications[0][1]);
        $this->assertSame('warning', $this->notifications[0][2]);

        // Once the failure is cleared, the waiting commit is deployed.
        $this->manager->resume($folder);
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);
        $this->assertCount(1, $this->notifications);
    }

    public function testADeployThatFailsBeforeUpIsRecordedAndResumeTriesItAgain(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        // A pull that fails, say: compose.sh exits 1 and records nothing in git_state.json.
        $this->deployExitStatus = 1;

        $this->check()->run();
        $this->check()->run();

        // Tried once, and recorded as failed rather than deployed.
        $this->assertSame([$folder], $this->deployed);
        $this->assertSame($this->upstreamHead(), GitScheduledCheck::failedAutomaticDeploy($stackDir));
        $this->assertNull(GitStackState::load($stackDir)->failedCommit);

        // Resume clears it, and the next check tries the commit again.
        $this->deployExitStatus = 0;
        $this->assertTrue($this->manager->resume($folder));
        $this->assertNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));
        $this->check()->run();
        $this->assertSame([$folder, $folder], $this->deployed);
        $this->assertFalse($this->manager->resume($folder));
    }

    public function testAFailedAutomaticDeployIsForgottenOnceACommitUndoesTheChange(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->deployExitStatus = 1;
        $this->check()->run();
        $this->assertNotNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));

        // The folder back as deployed: nothing to deploy, and nothing failed any more.
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n"], 'revert');
        $this->check()->run();

        $this->assertSame([$folder], $this->deployed);
        $this->assertNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));
        $this->assertFalse(GitScheduledCheck::loadRecord($stackDir)['stackChanged']);
    }

    public function testAFailedAutomaticDeployIsForgottenOnceTheCommitIsDeployed(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->deployExitStatus = 1;
        $this->check()->run();
        $this->assertNotNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));

        // Deployed by hand, with Pull and Redeploy.
        (new GitStackState($this->upstreamHead(), null))->save($stackDir);
        $this->assertNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));
    }

    public function testAfterAFailedAutomaticDeployACommitToAnotherStackKeepsTheFailedCommitNamed(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $failed = $this->upstreamHead();
        $this->deployExitStatus = 1;
        $this->check()->run();

        // A commit to another stack's folder: not deployed, and the commit that failed is still the one named.
        $this->writeAndPush(['other/compose.yaml' => "services:\n  other:\n    image: traefik/whoami\n"], 'other');
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);
        $this->assertSame($failed, GitScheduledCheck::failedAutomaticDeploy($stackDir));

        // Resume: the next check deploys the branch's latest commit.
        $this->deployExitStatus = 0;
        $this->assertTrue($this->manager->resume($folder));
        $this->check()->run();
        $this->assertSame([$folder, $folder], $this->deployed);
    }

    public function testAFailedAutomaticDeployIsForgottenOnceANewerCommitIsDeployedByHand(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->deployExitStatus = 1;
        $this->check()->run();
        $this->writeAndPush(['other/compose.yaml' => "services:\n  other:\n    image: traefik/whoami\n"], 'other');
        $this->check()->run();
        $this->assertNotNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));

        // The branch's latest commit, deployed with Pull and Redeploy.
        (new GitStackState($this->upstreamHead(), null))->save($stackDir);
        $this->assertNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));
    }

    public function testAStackThatIsNotRunningIsNotDeployedUntilItRuns(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->running = false;

        $this->check()->run();
        $this->check()->run();

        // Stopped (or never deployed): told once, not started.
        $this->assertSame([], $this->deployed);
        $this->assertCount(1, $this->notifications);
        $this->assertStringContainsString('is not running', $this->notifications[0][1]);

        // Started again: the waiting commit is deployed at the next check.
        $this->running = true;
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);
        $this->assertCount(1, $this->notifications);
    }

    public function testWhenDockerCannotBeAskedNothingIsDeployedAndTheNextPassAsksAgain(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->running = null;

        $this->check()->run();

        // Not knowing is not "stopped": no deploy, no notification, nothing recorded as handled.
        $this->assertSame([], $this->deployed);
        $this->assertSame([], $this->notifications);
        $this->running = true;
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);
    }

    public function testAStackWhoseProjectIdentityIsUnresolvedIsLeftAlone(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        // An older project name and docker that cannot be asked: the identity fails closed.
        @unlink($stackDir . '/' . \ProjectIdentity::METADATA_FILE);
        file_put_contents($stackDir . '/name', 'older_name');
        \ProjectIdentity::setProbe(static fn() => null);
        try {
            $this->assertSame(0, $this->check()->run());
        } finally {
            \ProjectIdentity::setProbe(static fn(): array => []);
        }

        $this->assertSame([], $this->deployed);
        $this->assertSame([], $this->notifications);
    }

    public function testAFailedUpThenResumeDeploysTheCommitAgainAtTheNextCheck(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $deployed = $this->upstreamHead();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        // The deploy fails during up: compose.sh records the commit as failed, and exits 1.
        $this->deployExitStatus = 1;
        $this->onDeploy = function () use ($stackDir, $deployed): void {
            (new GitStackState($deployed, $this->upstreamHead()))->save($stackDir);
        };
        $this->check()->run();
        // The failure is the stack's failed commit; it is not shown a second time as an automatic
        // deploy that failed before up.
        $this->assertSame($this->upstreamHead(), GitStackState::load($stackDir)->failedCommit);
        $this->assertNull(GitScheduledCheck::failedAutomaticDeploy($stackDir));
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);

        // Resumed after the next pass, and fixed outside git: the next check tries the commit again.
        $this->deployExitStatus = 0;
        $this->onDeploy = null;
        $this->manager->resume($folder);
        $this->check()->run();
        $this->assertSame([$folder, $folder], $this->deployed);
    }

    public function testAStackTakenBackToAnOlderCommitIsBroughtForward(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $older = $this->upstreamHead();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->onDeploy = function () use ($stackDir): void {
            (new GitStackState($this->upstreamHead(), null))->save($stackDir);
        };
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);

        // Deploy Commit back to the older commit, by hand.
        (new GitStackState($older, null))->save($stackDir);
        $this->check()->run();

        $this->assertSame([$folder, $folder], $this->deployed);
    }

    public function testACommitMadeByHandInTheCloneHoldsTheDeployBack(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $cloneDir = GitStackSettings::load($this->composeRoot . '/' . $folder)->cloneDir;
        file_put_contents($cloneDir . '/whoami/compose.yaml', "services:\n  whoami:\n    image: traefik/whoami:by-hand\n");
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-am', 'by hand'], $cloneDir);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');

        $this->check()->run();

        $this->assertSame([], $this->deployed);
        $this->assertCount(1, $this->notifications);
        $this->assertStringContainsString('a commit made in the clone', $this->notifications[0][1]);
    }

    public function testStatusShowsAScheduledDeployThatFailedBeforeUp(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->deployExitStatus = 1;
        $this->check()->run();

        $this->assertSame($this->upstreamHead(), $this->manager->status($folder)['failedAutomaticCommit']);
    }

    public function testAfterAFailedDeployACommitIsComparedWithTheCommitThatFailed(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $deployed = $this->upstreamHead();
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:broken\n"], 'break');
        (new GitStackState($deployed, $this->upstreamHead()))->save($stackDir);
        // The fix puts the folder back exactly as the deployed commit had it.
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n"], 'fix');

        $this->check()->run();

        // Still a change from what the containers were last started from: paused, so it says so.
        $this->assertTrue(GitScheduledCheck::loadRecord($stackDir)['stackChanged']);
        $this->assertCount(1, $this->notifications);
        $this->assertStringContainsString('paused', $this->notifications[0][1]);

        $this->manager->resume($folder);
        $this->check()->run();
        // Compared with the deployed commit again: nothing changed, so nothing is deployed.
        $this->assertFalse(GitScheduledCheck::loadRecord($stackDir)['stackChanged']);
        $this->assertSame([], $this->deployed);
    }

    public function testACommitOnlyNotifiedAboutIsDeployedOnceTheStackIsSwitchedToDeploy(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_NOTIFY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->check()->run();
        $this->assertCount(1, $this->notifications);

        GitStackSettings::load($stackDir)->withAutoDeploy(GitStackSettings::AUTO_DEPLOY_DEPLOY)->save($stackDir);
        $this->check()->run();
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);

        // Switching back to notify after a deploy sends nothing for that commit.
        GitStackSettings::load($stackDir)->withAutoDeploy(GitStackSettings::AUTO_DEPLOY_NOTIFY)->save($stackDir);
        $this->check()->run();
        $this->assertCount(1, $this->notifications);
    }

    public function testAStackWithLocalChangesIsNotDeployedAndSaysWhy(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        file_put_contents(GitStackSettings::load($this->composeRoot . '/' . $folder)->composeFileInClone(), "services: {}\n");
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');

        $this->check()->run();
        $this->check()->run();

        $this->assertSame([], $this->deployed);
        $this->assertCount(1, $this->notifications);
        $this->assertStringContainsString('whoami/compose.yaml', $this->notifications[0][1]);

        // Once the local change is undone, the waiting commit is deployed.
        $this->git(['checkout', '--', 'whoami/compose.yaml'], GitStackSettings::load($this->composeRoot . '/' . $folder)->cloneDir);
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);
    }

    public function testABusyStackIsCheckedAgainNextTime(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $stack = \StackInfo::fromProject($this->composeRoot, $folder);
        mkdir(COMPOSE_LOCK_DIR, 0755, true);
        $lock = fopen(COMPOSE_LOCK_DIR . '/' . $stack->projectName . '.lock', 'c');
        $this->assertNotFalse($lock);
        flock($lock, LOCK_EX);

        $this->check()->run();
        $this->assertSame([], $this->deployed);

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);
    }

    public function testAStackIsBusyWhileAnotherOperationRunsAlsoWhenNothingNeedsFetching(): void
    {
        // A commit already fetched and notified about: the next pass needs no fetch to act on it.
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_NOTIFY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        $this->check()->run();
        GitStackSettings::load($stackDir)->withAutoDeploy(GitStackSettings::AUTO_DEPLOY_DEPLOY)->save($stackDir);
        $stack = \StackInfo::fromProject($this->composeRoot, $folder);
        mkdir(COMPOSE_LOCK_DIR, 0755, true);
        $lock = fopen(COMPOSE_LOCK_DIR . '/' . $stack->projectName . '.lock', 'c');
        $this->assertNotFalse($lock);
        flock($lock, LOCK_EX);

        // A deploy by hand holds the stack and may be changing what it runs: the pass leaves it.
        $this->check()->run();
        $this->assertSame([], $this->deployed);

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->check()->run();
        $this->assertSame([$folder], $this->deployed);
    }

    public function testAPassWhileAnotherIsStillRunningChecksNothing(): void
    {
        $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        if (!is_dir(COMPOSE_LOCK_DIR)) {
            mkdir(COMPOSE_LOCK_DIR, 0755, true);
        }
        $held = fopen(COMPOSE_LOCK_DIR . '/.git-scheduled-check.lock', 'c');
        $this->assertNotFalse($held);
        flock($held, LOCK_EX);
        try {
            $this->assertSame(0, $this->check()->run());
            $this->assertSame([], $this->deployed);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        $this->assertSame(1, $this->check()->run());
    }

    public function testAStackNamedLikeThePassIsNotHeldByThePasssOwnLock(): void
    {
        // The pass's lock and the stacks' locks share a folder: a stack whose project name
        // matched the pass's lock file would always look busy, and never be checked.
        $folder = $this->deployedStack('git-scheduled-check', GitStackSettings::AUTO_DEPLOY_DEPLOY);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');

        $this->check()->run();

        $this->assertSame([$folder], $this->deployed);
    }

    public function testAMalformedRecordIsWorkedOutAgain(): void
    {
        $folder = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_NOTIFY);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami:v1.10\n"], 'pin');
        file_put_contents($stackDir . '/' . GitScheduledCheck::RECORD_FILE, '{"version": 1, "remoteCommit": "not a commit", "stackChanged": "yes"');

        $this->check()->run();

        // Read as empty, so the commit is compared again and notified about once.
        $this->assertCount(1, $this->notifications);
        $this->assertTrue(GitScheduledCheck::loadRecord($stackDir)['stackChanged']);
    }

    public function testNothingIsCheckedWhileTheArrayIsStopped(): void
    {
        $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_NOTIFY);
        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'change');
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"STOPPED\"\n");

        $this->assertSame(0, $this->check()->run());
        $this->assertSame([], $this->notifications);
    }

    public function testAnUnreachableRepositoryIsSkippedAndTheOtherStacksAreStillChecked(): void
    {
        $broken = $this->deployedStack('whoami', GitStackSettings::AUTO_DEPLOY_NOTIFY);
        $working = $this->deployedStack('other', GitStackSettings::AUTO_DEPLOY_NOTIFY, 'other/compose.yaml');
        rename($this->upstream, $this->upstream . '.moved');
        // Only the first stack's repository is gone: point the second at a copy.
        $copy = $this->mnt . '/user/repos/copy.git';
        $this->git(['clone', '-q', '--bare', $this->upstream . '.moved', $copy], null);
        $otherDir = $this->composeRoot . '/' . $working;
        $settings = json_decode((string) file_get_contents($otherDir . '/git.json'), true);
        $settings['url'] = $copy;
        file_put_contents($otherDir . '/git.json', (string) json_encode($settings));

        $this->assertSame(1, $this->check()->run());
        $this->assertFileDoesNotExist($this->composeRoot . '/' . $broken . '/' . GitScheduledCheck::RECORD_FILE);
    }

    // ----- helpers -----

    private function check(): GitScheduledCheck
    {
        return new GitScheduledCheck(
            $this->composeRoot,
            function (string $subject, string $message, string $icon): void {
                $this->notifications[] = [$subject, $message, $icon];
            },
            function (\StackInfo $stack, string $commit, ?string $deployedWhenDecided): int {
                $this->deployed[] = $stack->projectFolder;
                $this->deployedCommits[] = $commit;
                $this->decidedFrom[] = $deployedWhenDecided;
                if ($this->onDeploy !== null) {
                    ($this->onDeploy)();
                }
                return $this->deployExitStatus;
            },
            null,
            function (\StackInfo $stack): bool {
                if ($this->isRunningHook !== null) {
                    ($this->isRunningHook)();
                }
                if ($this->running === null) {
                    throw new \RuntimeException('Cannot connect to the Docker daemon.');
                }
                return $this->running;
            }
        );
    }

    /**
     * A git stack that has deployed the branch's latest commit.
     */
    private function deployedStack(string $name, string $autoDeploy, string $composePath = 'whoami/compose.yaml'): string
    {
        $folder = $this->manager->add($name, $this->upstream, 'main', $composePath, $this->clonesRoot);
        $stackDir = $this->composeRoot . '/' . $folder;
        GitStackSettings::load($stackDir)->withAutoDeploy($autoDeploy)->save($stackDir);
        (new GitStackState($this->upstreamHead(), null))->save($stackDir);
        return $folder;
    }

    private function upstreamHead(): string
    {
        $path = file_exists($this->upstream) ? $this->upstream : $this->upstream . '.moved';
        $result = GitCommand::run(['rev-parse', 'refs/heads/main'], $path);
        return trim($result->stdout);
    }

    /**
     * @param array<string, string> $files
     */
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
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-m', $message], $this->author);
        $this->git(['push', '-q', 'origin', 'main'], $this->author);
    }

    /**
     * @param string[] $args
     */
    private function git(array $args, ?string $dir): void
    {
        $result = GitCommand::run($args, $dir);
        $this->assertTrue($result->succeeded(), 'git ' . implode(' ', $args) . ': ' . $result->stderr);
    }
}
