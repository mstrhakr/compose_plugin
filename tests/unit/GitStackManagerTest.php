<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use ComposeManager\Tests\Support\FakeDocker;
use GitClone;
use GitCommand;
use GitStackManager;
use GitStackSettings;
use GitStackState;
use PluginTests\TestCase;
use RuntimeException;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackManager.php';
require_once __DIR__ . '/../../source/compose.manager/scripts/compose_git.php';
require_once __DIR__ . '/support/FakeDocker.php';

/**
 * compose-git's add, convert, check, status and reclone, with real git.
 */
final class GitStackManagerTest extends TestCase
{
    private string $mnt;
    private string $upstream;
    private string $author;
    private string $composeRoot;
    private string $clonesRoot;
    private GitStackManager $manager;

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
        $this->author = sys_get_temp_dir() . '/compose_git_manager_author';
        FakeDocker::removeTree($this->author);
        $this->git(['init', '-q', '--bare', '-b', 'main', $this->upstream], null);
        $this->git(['clone', '-q', $this->upstream, $this->author], null);
        $this->git(['checkout', '-q', '-b', 'main'], $this->author);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n"], 'first');

        $this->composeRoot = sys_get_temp_dir() . '/compose_git_manager_projects';
        FakeDocker::removeTree($this->composeRoot);
        mkdir($this->composeRoot);
        FakeDocker::removeTree(COMPOSE_LOCK_DIR);
        $this->clonesRoot = $this->mnt . '/user/appdata/git';
        $this->manager = new GitStackManager($this->composeRoot);
    }

    protected function tearDown(): void
    {
        FakeDocker::removeTree($this->mnt);
        FakeDocker::removeTree($this->author);
        FakeDocker::removeTree($this->composeRoot);
        FakeDocker::removeTree(COMPOSE_LOCK_DIR);
        parent::tearDown();
    }

    // ----- add -----

    public function testAddClonesAndMakesAGitStack(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);

        $stackDir = $this->composeRoot . '/' . $folder;
        $settings = GitStackSettings::load($stackDir);
        $this->assertNotNull($settings);
        $this->assertSame($settings->composeFileInClone(), file_get_contents($stackDir . '/indirect'));
        $this->assertSame('file', file_get_contents($stackDir . '/indirect_mode'));
        $this->assertFileExists($settings->composeFileInClone());
        $this->assertNull(GitStackState::load($stackDir)->deployedCommit);
        $this->assertTrue(\StackInfo::fromProject($this->composeRoot, $folder)->isGitStack());
    }

    public function testAddRefusesAnExistingStackFolderAndClonesNothing(): void
    {
        mkdir($this->composeRoot . '/whoami');

        try {
            $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
            $this->fail('Added over an existing stack folder');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('already exists', $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->clonesRoot);
    }

    public function testAddWithAComposePathNotOnTheBranchLeavesNothingBehind(): void
    {
        try {
            $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yml', $this->clonesRoot);
            $this->fail('Added a stack whose compose file is not in the repository');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('does not exist on branch main', $error->getMessage());
        }
        $this->assertSame([], glob($this->clonesRoot . '/*') ?: []);
        $this->assertDirectoryDoesNotExist($this->composeRoot . '/whoami');
    }

    public function testAddDoesNothingWhileTheArrayIsStopped(): void
    {
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"STOPPED\"\n");
        $this->expectException(RuntimeException::class);
        $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
    }

    public function testAddToAProjectsFolderWhoseMountIsGoneWritesNothing(): void
    {
        // A projects folder that is not on a mount (a pool that did not mount, while the
        // folder is still there, in RAM): neither the clone nor the stack folder may be made.
        // This used to make the stack folder first and fail only when saving its settings.
        $composeRoot = $this->mnt . '/projects';
        mkdir($composeRoot);
        $manager = new GitStackManager($composeRoot);

        try {
            $manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
            $this->fail('The stack was added to a projects folder that is not on a mounted disk.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('not on a mounted disk', $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($composeRoot . '/whoami');
        $this->assertSame([], glob($this->clonesRoot . '/*') ?: []);
    }

    // ----- convert -----

    public function testConvertMovesTheOldComposeFileAsideAndKeepsEnvAndOverride(): void
    {
        $stack = \StackInfo::createNew($this->composeRoot, 'myapp');
        $stackDir = $this->composeRoot . '/' . $stack->projectFolder;
        file_put_contents($stackDir . '/.env', "TZ=Europe/London\n");
        file_put_contents($stackDir . '/compose.override.yaml', "services:\n  whoami:\n    labels:\n      net.unraid.docker.icon: x\n");
        $oldCompose = (string) file_get_contents($stackDir . '/compose.yaml');

        $backup = $this->manager->convert($stack->projectFolder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);

        $this->assertSame($oldCompose, file_get_contents($backup . '/compose.yaml'));
        $this->assertFileDoesNotExist($stackDir . '/compose.yaml');
        $this->assertSame("TZ=Europe/London\n", file_get_contents($stackDir . '/.env'));
        $this->assertStringContainsString('net.unraid.docker.icon', (string) file_get_contents($stackDir . '/compose.override.yaml'));

        \StackInfo::clearCache();
        $converted = \StackInfo::fromProject($this->composeRoot, $stack->projectFolder);
        $this->assertTrue($converted->isGitStack());
        $args = $converted->buildComposeArgs();
        $this->assertSame(GitStackSettings::load($stackDir)->composeFileInClone(), $args['filePaths'][0]);
        $this->assertSame($stackDir . '/compose.override.yaml', $args['filePaths'][1]);
        $this->assertSame($stackDir . '/.env', $args['envFilePath']);
    }

    public function testConvertOfAnIndirectStackCopiesItsEnvIntoTheStackFolder(): void
    {
        $oldFolder = $this->mnt . '/user/appdata/legacy';
        mkdir($oldFolder);
        file_put_contents($oldFolder . '/compose.yaml', "services:\n  whoami:\n    image: traefik/whoami\n");
        file_put_contents($oldFolder . '/.env', "SECRET=1\n");
        $stack = \StackInfo::createNew($this->composeRoot, 'legacy', '', $oldFolder);
        $stackDir = $this->composeRoot . '/' . $stack->projectFolder;

        $backup = $this->manager->convert($stack->projectFolder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);

        $this->assertSame("SECRET=1\n", file_get_contents($stackDir . '/.env'));
        $this->assertSame("SECRET=1\n", file_get_contents($oldFolder . '/.env'));
        $this->assertFileExists($oldFolder . '/compose.yaml');
        $this->assertSame($oldFolder, file_get_contents($backup . '/indirect'));
    }

    public function testConvertOfAStackWithACustomEnvFileCopiesNoEnv(): void
    {
        $oldFolder = $this->mnt . '/user/appdata/legacy';
        mkdir($oldFolder);
        file_put_contents($oldFolder . '/compose.yaml', "services:\n  whoami:\n    image: traefik/whoami\n");
        file_put_contents($oldFolder . '/.env', "NEXT_TO_COMPOSE=1\n");
        $customEnv = $this->mnt . '/user/appdata/custom.env';
        file_put_contents($customEnv, "CUSTOM=1\n");
        $stack = \StackInfo::createNew($this->composeRoot, 'legacy', '', $oldFolder);
        $stackDir = $this->composeRoot . '/' . $stack->projectFolder;
        file_put_contents($stackDir . '/envpath', $customEnv);

        $this->manager->convert($stack->projectFolder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);

        // The stack's own env file setting still decides; nothing is copied next to it.
        $this->assertFileDoesNotExist($stackDir . '/.env');
        \StackInfo::clearCache();
        $this->assertSame($customEnv, \StackInfo::fromProject($this->composeRoot, $stack->projectFolder)->getEffectiveEnvFilePath());
    }

    public function testConvertWithAnEnvPathToAMissingFileCopiesTheEnvInUse(): void
    {
        // An env file setting that names a file which is gone is ignored, and the .env next
        // to the old compose file is used. That is the one to keep using after the convert.
        $oldFolder = $this->mnt . '/user/appdata/legacy';
        mkdir($oldFolder);
        file_put_contents($oldFolder . '/compose.yaml', "services:\n  whoami:\n    image: traefik/whoami\n");
        file_put_contents($oldFolder . '/.env', "NEXT_TO_COMPOSE=1\n");
        $stack = \StackInfo::createNew($this->composeRoot, 'legacy', '', $oldFolder);
        $stackDir = $this->composeRoot . '/' . $stack->projectFolder;
        file_put_contents($stackDir . '/envpath', $this->mnt . '/user/appdata/moved-away.env');

        $this->manager->convert($stack->projectFolder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);

        $this->assertSame("NEXT_TO_COMPOSE=1\n", file_get_contents($stackDir . '/.env'));
        \StackInfo::clearCache();
        $this->assertSame($stackDir . '/.env', \StackInfo::fromProject($this->composeRoot, $stack->projectFolder)->getEffectiveEnvFilePath());
    }

    public function testConvertOfAnIndirectStackCarriesItsOverrideIntoTheStackFolder(): void
    {
        $oldFolder = $this->mnt . '/user/appdata/legacy';
        mkdir($oldFolder);
        file_put_contents($oldFolder . '/compose.yaml', "services:\n  whoami:\n    image: traefik/whoami\n");
        $labels = "services:\n  whoami:\n    labels:\n      net.unraid.docker.icon: x\n";
        file_put_contents($oldFolder . '/compose.override.yaml', $labels);
        $stack = \StackInfo::createNew($this->composeRoot, 'legacy', '', $oldFolder);
        $stackDir = $this->composeRoot . '/' . $stack->projectFolder;

        $this->manager->convert($stack->projectFolder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);

        // A git stack's override lives in the stack folder, not next to the old compose file.
        $this->assertSame($labels, file_get_contents($stackDir . '/compose.override.yaml'));
        $this->assertSame($labels, file_get_contents($oldFolder . '/compose.override.yaml'));
    }

    public function testConvertThatFailsPartWaySaysHowToUndoIt(): void
    {
        $stack = \StackInfo::createNew($this->composeRoot, 'myapp');
        $stackDir = $this->composeRoot . '/' . $stack->projectFolder;
        // A folder where indirect_mode must be written makes that write fail
        // after the old compose file was moved into the backup folder.
        mkdir($stackDir . '/indirect_mode');

        try {
            $this->manager->convert($stack->projectFolder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
            $this->fail('Converted although indirect_mode could not be written');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString("Could not write $stackDir/indirect_mode.", $error->getMessage());
            $this->assertStringContainsString('only partly converted', $error->getMessage());
            $this->assertStringContainsString($stackDir . '/pre-git-', $error->getMessage());
        }
        $backups = glob($stackDir . '/pre-git-*') ?: [];
        $this->assertCount(1, $backups);
        $this->assertFileExists($backups[0] . '/compose.yaml');
    }

    public function testConvertRefusesAGitStack(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already a git stack');
        $this->manager->convert($folder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
    }

    public function testConvertWaitsForNoOneElse(): void
    {
        $stack = \StackInfo::createNew($this->composeRoot, 'myapp');
        mkdir(COMPOSE_LOCK_DIR, 0755, true);
        $lock = fopen(COMPOSE_LOCK_DIR . '/' . $stack->projectName . '.lock', 'c');
        $this->assertNotFalse($lock);
        flock($lock, LOCK_EX);

        try {
            $this->manager->convert($stack->projectFolder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
            $this->fail('Converted while another operation held the lock');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('in progress', $error->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertFileExists($this->composeRoot . '/' . $stack->projectFolder . '/compose.yaml');
    }

    public function testStacksAreNamedExactly(): void
    {
        $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $this->expectException(RuntimeException::class);
        $this->manager->status('Whoami');
    }

    // ----- check and status -----

    public function testCheckComparesTheDeployedCommitWithTheRemote(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->assertFalse($this->manager->check($folder)['upToDate']);

        (new GitStackState($this->upstreamHead(), null))->save($stackDir);
        $this->assertTrue($this->manager->check($folder)['upToDate']);

        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'change');
        $result = $this->manager->check($folder);
        $this->assertFalse($result['upToDate']);
        $this->assertSame($this->upstreamHead(), $result['remoteCommit']);
    }

    public function testStatusShowsLocalChangesWithoutAskingTheRemote(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $settings = GitStackSettings::load($this->composeRoot . '/' . $folder);
        file_put_contents($settings->composeFileInClone(), "edited\n");
        rename($this->upstream, $this->upstream . '.gone');

        $status = $this->manager->status($folder);

        $this->assertSame(['whoami/compose.yaml'], $status['localChanges']);
        $this->assertNull($status['problem']);
        $this->assertSame([$folder], $this->manager->listGitStacks());
    }

    // ----- credentials -----

    public function testGitCredentialIsFoundByNameOrIdAndRegistryCredentialsAreIgnored(): void
    {
        $vault = $this->emptyVault();
        $git = $vault->saveCredential(['name' => 'Forgejo', 'provider' => 'git', 'registry' => 'git.example.com', 'username' => 'bot', 'secret' => 't1']);
        $vault->saveCredential(['name' => 'Docker', 'provider' => 'docker', 'registry' => 'docker.io', 'username' => 'bot', 'secret' => 't2']);

        $this->assertSame($git['id'], $this->manager->findGitCredential('Forgejo'));
        $this->assertSame($git['id'], $this->manager->findGitCredential($git['id']));
        try {
            $this->manager->findGitCredential('Docker');
            $this->fail('Found a registry credential as a git credential');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Git credentials: Forgejo', $error->getMessage());
        }
    }

    public function testADeployKeyIsNotFoundAsAGitCredential(): void
    {
        // A deploy key belongs to the one stack it was made for: no other stack may pick it.
        $vault = $this->emptyVault();
        $token = $vault->saveCredential(['name' => 'Forgejo', 'provider' => 'git', 'registry' => 'git.example.com', 'username' => 'bot', 'secret' => 't1']);
        $key = $vault->saveCredential(['name' => 'other deploy key', 'provider' => 'git-ssh', 'registry' => 'git.example.com', 'username' => 'git', 'secret' => 'k']);

        foreach (['other deploy key', $key['id']] as $nameOrId) {
            try {
                $this->manager->findGitCredential($nameOrId);
                $this->fail('Found a deploy key as a git credential');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Git credentials: Forgejo.', $error->getMessage());
            }
        }
        $this->assertSame($token['id'], $this->manager->findGitCredential('Forgejo'));
    }

    public function testAddingAnSshStackWithACredentialIsRefusedBeforeAnythingIsMade(): void
    {
        $token = $this->emptyVault()->saveCredential(['name' => 'Forgejo', 'provider' => 'git', 'registry' => 'github.com', 'username' => 'bot', 'secret' => 't1']);

        try {
            $this->manager->add('whoami', 'git@github.com:owner/repo.git', 'main', 'whoami/compose.yaml', $this->clonesRoot, '', $token['id']);
            $this->fail('An ssh stack was added with another credential');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('gets a deploy key of its own', $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->composeRoot . '/whoami');
        $this->assertSame([], glob($this->clonesRoot . '/*') ?: []);
        $this->assertCount(1, (new \CredentialVault())->listCredentials());
    }

    public function testTwoGitCredentialsWithTheSameNameAreNotGuessedBetween(): void
    {
        $vault = $this->emptyVault();
        $vault->saveCredential(['name' => 'Bot', 'provider' => 'git', 'registry' => 'github.com', 'username' => 'a', 'secret' => 't1']);
        $vault->saveCredential(['name' => 'Bot', 'provider' => 'git', 'registry' => 'gitlab.com', 'username' => 'b', 'secret' => 't2']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Several git credentials are named 'Bot'");
        $this->manager->findGitCredential('Bot');
    }

    public function testAddThatCannotReachAPrivateRepositoryLeavesNoCloneAndNoCredentialFile(): void
    {
        $credential = $this->emptyVault()->saveCredential(
            ['name' => 'Local', 'provider' => 'git', 'registry' => '127.0.0.1:9', 'username' => 'bot', 'secret' => 'token']
        );

        try {
            $this->manager->add('private', 'https://127.0.0.1:9/team/stacks.git', 'main', 'whoami/compose.yaml', $this->clonesRoot, '', $credential['id']);
            $this->fail('Added a stack whose repository could not be reached');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Could not clone', $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->composeRoot . '/private');
        $this->assertSame([], glob($this->clonesRoot . '/*') ?: []);
        $this->assertSame([], glob(COMPOSE_GIT_CREDENTIAL_DIR . '/*') ?: []);
    }

    public function testCredentialCanBeRemovedAndIsShownInStatus(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $this->assertNull($this->manager->status($folder)['credential']);

        // A repository on this server never takes one.
        $credential = $this->emptyVault()->saveCredential(
            ['name' => 'Forgejo', 'provider' => 'git', 'registry' => 'git.example.com', 'username' => 'bot', 'secret' => 't1']
        );
        try {
            $this->manager->setCredential($folder, $credential['id']);
            $this->fail('Gave a repository on this server a credential');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('needs no credential', $error->getMessage());
        }

        $this->manager->setCredential($folder, null);
        $this->assertNull(GitStackSettings::load($this->composeRoot . '/' . $folder)?->credentialId);
    }

    public function testCredentialTestReachesTheRepositoryOfAStackThatUsesIt(): void
    {
        $credential = $this->emptyVault()->saveCredential(
            ['name' => 'Local', 'provider' => 'git', 'registry' => '127.0.0.1:9', 'username' => 'bot', 'secret' => 'token']
        );
        $this->assertNull($this->manager->testCredential($credential['id']));

        // A stack whose repository cannot be reached (nothing listens on port 9).
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $stackDir = $this->composeRoot . '/' . $folder;
        $data = json_decode((string) file_get_contents($stackDir . '/git.json'), true);
        $data['url'] = 'https://127.0.0.1:9/team/stacks.git';
        $data['credentialId'] = $credential['id'];
        file_put_contents($stackDir . '/git.json', (string) json_encode($data));

        $test = $this->manager->testCredential($credential['id']);

        $this->assertNotNull($test);
        $this->assertFalse($test['valid']);
        $this->assertStringContainsString("Could not reach https://127.0.0.1:9/team/stacks.git (used by '$folder')", $test['message']);
    }

    public function testOnlyAnSshStackHasADeployKeyOrPinnedHostKeys(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);

        try {
            $this->manager->deployKey($folder);
            $this->fail('Showed a deploy key for a stack that does not use ssh');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('has no deploy key', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not reach its repository over ssh');
        $this->manager->trustHost($folder);
    }

    public function testAnSshStackKeepsItsOwnDeployKey(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $stackDir = $this->composeRoot . '/' . $folder;
        GitStackSettings::createNew('git@github.com:owner/repo.git', 'main', 'whoami/compose.yaml', $this->clonesRoot, $folder)
            ->withCredentialId(str_repeat('cd', 16))
            ->withSshKnownHosts("github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl\n")
            ->save($stackDir);

        $token = $this->emptyVault()->saveCredential(['name' => 'Forgejo', 'provider' => 'git', 'registry' => 'github.com', 'username' => 'bot', 'secret' => 't1']);
        foreach ([null, $token['id']] as $credentialId) {
            try {
                $this->manager->setCredential($folder, $credentialId);
                $this->fail('The credential of an ssh stack was changed.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('always uses a deploy key of its own', $error->getMessage());
            }
        }
        $this->assertSame(str_repeat('cd', 16), GitStackSettings::load($stackDir)->credentialId);
    }

    public function testCommandLineCredentialNeedsAStackAndANameOrNone(): void
    {
        $this->assertSame(2, $this->runCli(['compose-git', 'credential', 'whoami']));
        $this->assertSame(2, $this->runCli(['compose-git', 'credential', 'whoami', 'Bot', '--none']));
        $this->assertSame(1, $this->runCli(['compose-git', 'credential', 'whoami', 'No such credential']));
    }

    // ----- reclone -----

    public function testRecloneMovesTheOldCloneAsideAndReturnsToTheDeployedCommit(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $stackDir = $this->composeRoot . '/' . $folder;
        $deployed = $this->upstreamHead();
        (new GitStackState($deployed, null))->save($stackDir);
        $settings = GitStackSettings::load($stackDir);
        file_put_contents($settings->cloneDir . '/whoami/data.db', "keep me\n");
        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'newer');

        $movedTo = $this->manager->reclone($folder);

        $this->assertNotNull($movedTo);
        $this->assertSame("keep me\n", file_get_contents($movedTo . '/whoami/data.db'));
        $this->assertFileDoesNotExist($settings->cloneDir . '/whoami/data.db');
        $this->assertSame($deployed, (new GitClone($settings))->checkedOutCommit());
    }

    public function testRecloneNamesTheUntrackedFilesLeftInTheOldClone(): void
    {
        $messages = [];
        $manager = new GitStackManager($this->composeRoot, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $folder = $manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $settings = GitStackSettings::load($this->composeRoot . '/' . $folder);
        mkdir($settings->cloneDir . '/whoami/data');
        file_put_contents($settings->cloneDir . '/whoami/data/app.db', "keep me\n");

        $manager->reclone($folder);

        $this->assertStringContainsString('whoami/data/', implode("\n", $messages));
    }

    public function testRecloneAfterTheDeployedCommitWasRewrittenAwayStaysAtTheNewHead(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $stackDir = $this->composeRoot . '/' . $folder;
        $this->writeAndPush(['whoami/compose.yaml' => "services: {}\n"], 'to be rewritten');
        (new GitStackState($this->upstreamHead(), null))->save($stackDir);
        $this->git(['reset', '-q', '--hard', 'HEAD~1'], $this->author);
        file_put_contents($this->author . '/whoami/other.txt', "x\n");
        $this->git(['add', '--', 'whoami/other.txt'], $this->author);
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-m', 'rewritten'], $this->author);
        $this->git(['push', '-q', '-f', 'origin', 'main'], $this->author);
        // Until the server prunes it, a rewritten-away commit can still be fetched.
        $this->git(['--git-dir=' . $this->upstream, 'reflog', 'expire', '--expire=now', '--all'], null);
        $this->git(['--git-dir=' . $this->upstream, 'gc', '-q', '--prune=now'], null);

        $rewrittenAway = GitStackState::load($stackDir)->deployedCommit;
        $this->manager->reclone($folder);

        $settings = GitStackSettings::load($stackDir);
        $this->assertSame($this->upstreamHead(), (new GitClone($settings))->checkedOutCommit());
        // Still recorded: the next deploy sees it is gone from the clone and recreates every container.
        $this->assertSame($rewrittenAway, GitStackState::load($stackDir)->deployedCommit);
    }

    public function testConvertNamesTheFoldersNextToTheOldComposeFile(): void
    {
        $messages = [];
        $manager = new GitStackManager($this->composeRoot, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $oldFolder = $this->mnt . '/user/appdata/legacy';
        mkdir($oldFolder . '/db', 0755, true);
        file_put_contents($oldFolder . '/compose.yaml', "services:\n  whoami:\n    image: traefik/whoami\n");
        $stack = \StackInfo::createNew($this->composeRoot, 'legacy', '', $oldFolder);

        $manager->convert($stack->projectFolder, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);

        $this->assertStringContainsString('next to the old compose file: db.', implode("\n", $messages));
    }

    public function testRecloneThatFailsPutsTheOldCloneBack(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $settings = GitStackSettings::load($this->composeRoot . '/' . $folder);
        rename($this->upstream, $this->upstream . '.gone');

        try {
            $this->manager->reclone($folder);
            $this->fail('Recloned from a repository that is gone');
        } catch (RuntimeException) {
        }
        $this->assertFileExists($settings->composeFileInClone());
        (new GitClone($settings))->assertOwned();
        $this->assertSame([], glob($settings->cloneDir . '.replaced-*') ?: []);
    }

    public function testRecloneLeavesASymlinkedCloneFolderAlone(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $settings = GitStackSettings::load($this->composeRoot . '/' . $folder);
        rename($settings->cloneDir, $settings->cloneDir . '.real');
        symlink($settings->cloneDir . '.real', $settings->cloneDir);

        try {
            $this->manager->reclone($folder);
            $this->fail('Recloned through a symlink');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('is a symlink', $error->getMessage());
        }
        $this->assertSame($settings->cloneDir . '.real', readlink($settings->cloneDir));
        $this->assertFileExists($settings->cloneDir . '.real/whoami/compose.yaml');
        $this->assertSame([], glob($settings->cloneDir . '.replaced-*') ?: []);
    }

    public function testRecloneThatCannotPutTheOldCloneBackSaysWhereItIs(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $settings = GitStackSettings::load($this->composeRoot . '/' . $folder);
        $messages = [];
        // Something appears at the clone folder once the old clone is moved
        // aside, so the new clone fails and the old one cannot go back.
        $manager = new GitStackManager($this->composeRoot, static function (string $message) use (&$messages, $settings): void {
            $messages[] = $message;
            if (str_starts_with($message, 'Cloning ')) {
                file_put_contents($settings->cloneDir, 'in the way');
            }
        });

        try {
            $manager->reclone($folder);
            $this->fail('Recloned over a file in the way');
        } catch (RuntimeException) {
        }
        $moved = glob($settings->cloneDir . '.replaced-*') ?: [];
        $this->assertCount(1, $moved);
        $this->assertContains("Could not move the old clone back. It is still at {$moved[0]}.", $messages);
        $this->assertNotContains('Moved the old clone back.', $messages);
    }

    // ----- command line -----

    public function testCommandLineUsageErrorsExitWithTwo(): void
    {
        $this->assertSame(2, $this->runCli(['compose-git', 'frobnicate']));
        $this->assertSame(2, $this->runCli(['compose-git', 'add', 'whoami', '--path', 'whoami/compose.yaml']));
        $this->assertSame(2, $this->runCli(['compose-git', 'status', 'whoami', '--colour']));
    }

    public function testCommandLineAddThenCheckReportsNothingDeployedYet(): void
    {
        $add = ['compose-git', 'add', 'whoami', '--url', $this->upstream, '--path', 'whoami/compose.yaml', '--clones-root', $this->clonesRoot];
        $this->assertSame(0, $this->runCli($add));
        $this->assertSame(3, $this->runCli(['compose-git', 'check', 'whoami']));
        $this->assertSame(1, $this->runCli(['compose-git', 'check', 'nosuchstack']));
    }

    public function testDeployWaitsAsTheStacksSettingSaysUnlessTheCommandLineOverrides(): void
    {
        $on = ['enabled' => true, 'timeout' => '120'];
        $off = ['enabled' => false, 'timeout' => '300'];

        $this->assertSame(['--wait', '--wait-timeout', '120'], compose_git_wait_arguments($on, []));
        $this->assertSame([], compose_git_wait_arguments($off, []));
        $this->assertSame([], compose_git_wait_arguments($on, ['no-wait' => true]));
        $this->assertSame(['--wait', '--wait-timeout', '300'], compose_git_wait_arguments($off, ['wait' => true]));
        $this->assertSame(['--wait', '--wait-timeout', '45'], compose_git_wait_arguments($off, ['wait-timeout' => '45']));
        // A timeout file holding something other than a number is not passed on.
        $this->assertSame(['--wait'], compose_git_wait_arguments(['enabled' => true, 'timeout' => '5m'], []));
    }

    public function testDeployRunsComposeWithTheSameEnvironmentAsTheChecks(): void
    {
        // Exported variables win over the stack's .env in compose, and PWD is the caller's
        // folder: neither may reach the deploy, since the checks never saw them.
        $callersShell = [
            'PATH' => '/root/bin:/usr/bin',
            'HOME' => '/root',
            'PWD' => '/root/somewhere',
            'PORT' => '8080',
            'DOCKER_HOST' => 'tcp://elsewhere:2375',
            'COMPOSE_PROFILES' => 'debug',
            'COMPOSE_LOCK_TIMEOUT' => '5',
            'DOCKER_CONFIG' => '/tmp/registry-login',
        ];

        $this->assertSame([
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => '/root',
            'LC_ALL' => 'C',
            'DOCKER_CONFIG' => '/tmp/registry-login',
            'COMPOSE_LOCK_TIMEOUT' => '5',
        ], compose_git_deploy_environment($callersShell));
    }

    public function testDeployRunsComposeInTheComposeFilesFolder(): void
    {
        $folder = $this->mnt . '/user/appdata/git/whoami/whoami';
        mkdir($folder, 0755, true);
        file_put_contents($folder . '/compose.yaml', "services: {}\n");
        // A compose file that is a symlink: the folder of the file it leads to, as the checks use.
        mkdir($this->mnt . '/user/appdata/git/whoami/linked', 0755, true);
        symlink($folder . '/compose.yaml', $this->mnt . '/user/appdata/git/whoami/linked/compose.yaml');

        $this->assertSame(realpath($folder), compose_git_deploy_directory($folder . '/compose.yaml'));
        $this->assertSame(realpath($folder), compose_git_deploy_directory($this->mnt . '/user/appdata/git/whoami/linked/compose.yaml'));
        $this->assertSame('/', compose_git_deploy_directory($folder . '/missing.yaml'));
        $this->assertSame('/', compose_git_deploy_directory(null));
    }

    public function testDeployRefusesWaitAndNoWaitTogether(): void
    {
        $this->expectException(\ComposeGitUsageError::class);
        compose_git_wait_arguments(['enabled' => false, 'timeout' => '300'], ['wait' => true, 'no-wait' => true]);
    }

    public function testDeployKeepsTheRunningProfilesUnlessTheCommandLineNamesSome(): void
    {
        $folder = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $stackDir = $this->composeRoot . '/' . $folder;
        // StackInfo caches what it reads, so read the stack again after each change.
        $stack = function () use ($folder): \StackInfo {
            \StackInfo::clearCache();
            return $this->manager->gitStack($folder);
        };

        // Nothing running and no defaults: no profiles.
        $this->assertSame([], compose_git_profile_arguments($stack(), []));

        // The default profiles, as the web UI's Update uses on a first run.
        file_put_contents($stackDir . '/default_profile', 'media');
        $this->assertSame(['-gmedia'], compose_git_profile_arguments($stack(), []));

        // The profiles the stack is running with come first.
        file_put_contents($stackDir . '/running_profiles', 'extras,tools');
        $this->assertSame(['-gextras', '-gtools'], compose_git_profile_arguments($stack(), []));

        // --profile replaces both.
        $this->assertSame(['-gdebug'], compose_git_profile_arguments($stack(), ['profile' => ['debug']]));
    }

    public function testCommandLineStatusAsJson(): void
    {
        $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $errors = fopen('php://memory', 'w');
        ob_start();
        $exit = compose_git_main(['compose-git', 'status', '--json'], $this->composeRoot, $errors);
        $output = (string) ob_get_clean();
        fclose($errors);

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame('whoami', $decoded[0]['stack']);
        $this->assertSame('main', $decoded[0]['branch']);
    }

    public function testCommandLineStatusOfAllShowsAStackWhoseSettingsCannotBeRead(): void
    {
        $good = $this->manager->add('whoami', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $bad = $this->manager->add('broken', $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
        $gitJson = $this->composeRoot . '/' . $bad . '/git.json';
        $settings = json_decode((string) file_get_contents($gitJson), true);
        $settings['typo'] = true;
        file_put_contents($gitJson, json_encode($settings));

        [$exit, $output] = $this->runCliCapturingOutput(['compose-git', 'status', '--json']);

        $this->assertSame(0, $exit);
        $rows = array_column(json_decode($output, true), null, 'stack');
        $this->assertSame('main', $rows[$good]['branch']);
        $this->assertNull($rows[$bad]['url']);
        $this->assertNotNull($rows[$bad]['problem']);
        // Named on its own, the stack is still an error.
        $this->assertSame(1, $this->runCli(['compose-git', 'status', $bad]));
    }

    /**
     * @return array<string, array{0: string, 1: string[]}>
     */
    public static function commandsAndTheirOptions(): array
    {
        return [
            'add' => ['add', ['--url', '--path', '--branch', '--clones-root', '--description', '--credential']],
            'convert' => ['convert', ['--url', '--path', '--branch', '--clones-root', '--credential']],
            'credential' => ['credential', ['--none']],
            'deploy-key' => ['deploy-key', []],
            'trust-host' => ['trust-host', []],
            'check' => ['check', ['--all']],
            'deploy' => ['deploy', ['--commit', '--save-local-changes', '--wait', '--no-wait', '--wait-timeout', '--profile']],
            'reclone' => ['reclone', []],
            'status' => ['status', ['--all', '--json']],
        ];
    }

    /**
     * @param string[] $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('commandsAndTheirOptions')]
    public function testEachCommandHasHelpNamingEveryOption(string $command, array $options): void
    {
        [$exit, $output] = $this->runCliCapturingOutput(['compose-git', $command, '--help']);

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith("Usage: compose-git $command", $output);
        foreach ($options as $option) {
            $this->assertStringContainsString($option, $output);
        }
        foreach (explode("\n", $output) as $line) {
            $this->assertLessThanOrEqual(100, strlen($line), "Too long for a terminal: $line");
        }
        // The other ways to ask give the same text.
        $this->assertSame($output, $this->runCliCapturingOutput(['compose-git', $command, '-h'])[1]);
        $this->assertSame($output, $this->runCliCapturingOutput(['compose-git', 'help', $command])[1]);
    }

    public function testHelpWinsOverTheRestOfTheCommandLine(): void
    {
        // Nothing is cloned or checked: --help only prints.
        [$exit, $output] = $this->runCliCapturingOutput(['compose-git', 'deploy', 'nosuchstack', '--help']);

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith('Usage: compose-git deploy', $output);
    }

    public function testAMistakeInACommandShowsThatCommandsHelp(): void
    {
        [$exit, , $errors] = $this->runCliCapturingOutput(['compose-git', 'add', 'whoami', '--path', 'whoami/compose.yaml']);

        $this->assertSame(2, $exit);
        $this->assertStringStartsWith("--url is required.\n\nUsage: compose-git add", $errors);
    }

    public function testTheOverviewPointsToTheCommandHelp(): void
    {
        [$exit, $output] = $this->runCliCapturingOutput(['compose-git', '--help']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("compose-git <command> --help", $output);
        $this->assertSame(2, $this->runCli(['compose-git', 'help', 'frobnicate']));
    }

    // ----- helpers -----

    /**
     * @param string[] $argv
     * @return array{0: int, 1: string, 2: string} The exit status, standard output and the errors
     */
    private function runCliCapturingOutput(array $argv): array
    {
        $errors = fopen('php://memory', 'w+');
        ob_start();
        $exit = compose_git_main($argv, $this->composeRoot, $errors);
        $output = (string) ob_get_clean();
        rewind($errors);
        $errorText = (string) stream_get_contents($errors);
        fclose($errors);
        return [$exit, $output, $errorText];
    }

    private function emptyVault(): \CredentialVault
    {
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
        return new \CredentialVault();
    }

    /** @param string[] $argv */
    private function runCli(array $argv): int
    {
        $errors = fopen('php://memory', 'w');
        ob_start();
        $exit = compose_git_main($argv, $this->composeRoot, $errors);
        ob_end_clean();
        fclose($errors);
        return $exit;
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
}
