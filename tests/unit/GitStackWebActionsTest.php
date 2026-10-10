<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use ComposeManager\Tests\Support\FakeDocker;
use GitCommand;
use GitStackManager;
use GitStackSettings;
use GitStackWebActions;
use PluginTests\Mocks\FunctionMocks;
use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitStackWebActions.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Helpers.php';
require_once __DIR__ . '/support/FakeDocker.php';

/**
 * What the web UI asks of git stacks (GitStackWebActions and its Exec.php
 * actions), with real git.
 */
final class GitStackWebActionsTest extends TestCase
{
    private string $mnt;
    private string $upstream;
    private string $author;
    private string $composeRoot;
    private string $clonesRoot;

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
        $this->author = sys_get_temp_dir() . '/compose_git_web_author';
        FakeDocker::removeTree($this->author);
        $this->git(['init', '-q', '--bare', '-b', 'main', $this->upstream], null);
        $this->git(['clone', '-q', $this->upstream, $this->author], null);
        $this->git(['checkout', '-q', '-b', 'main'], $this->author);
        $this->writeAndPush(['whoami/compose.yaml' => "services:\n  whoami:\n    image: traefik/whoami\n"], 'first');

        $this->composeRoot = sys_get_temp_dir() . '/compose_git_web_projects';
        FakeDocker::removeTree($this->composeRoot);
        mkdir($this->composeRoot);
        FakeDocker::removeTree(COMPOSE_LOCK_DIR);
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
        $this->clonesRoot = $this->mnt . '/user/appdata/git';

        global $compose_root, $plugin_root, $sName;
        $compose_root = $this->composeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';
        FunctionMocks::setPluginConfig('compose.manager', ['PROJECTS_FOLDER' => $this->composeRoot]);
    }

    protected function tearDown(): void
    {
        FakeDocker::removeTree($this->mnt);
        FakeDocker::removeTree($this->author);
        FakeDocker::removeTree($this->composeRoot);
        FakeDocker::removeTree(COMPOSE_LOCK_DIR);
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
        $_POST = [];
        \ProjectIdentity::setProbe(null);
        parent::tearDown();
    }

    // ----- add -----

    public function testAddMakesAGitStackThatIsNotDeployed(): void
    {
        $result = $this->executeAction('addGitStack', [
            'stackName' => 'Who Am I',
            'stackDesc' => 'From git',
            'gitUrl' => $this->upstream,
            'gitBranch' => '',
            'gitComposePath' => 'whoami/compose.yaml',
        ]);

        $this->assertSame('success', $result['result'], $result['message'] ?? '');
        $this->assertSame('Who Am I', $result['projectName']);
        $stackDir = $this->composeRoot . '/' . $result['project'];
        $settings = GitStackSettings::load($stackDir);
        $this->assertNotNull($settings);
        $this->assertSame('main', $settings->branch);
        $this->assertFileExists($settings->composeFileInClone());
        $this->assertFileDoesNotExist($stackDir . '/labels_view_mode');
        $this->assertNotEmpty($result['messages']);
    }

    public function testAddKeepsTheManualOverrideChoice(): void
    {
        $result = (new GitStackWebActions($this->composeRoot))->add([
            'stackName' => 'whoami',
            'gitUrl' => $this->upstream,
            'gitComposePath' => 'whoami/compose.yaml',
            'overrideManagementAutomatic' => 'false',
        ]);

        $this->assertSame('success', $result['result']);
        $this->assertSame('advanced', file_get_contents($this->composeRoot . '/whoami/labels_view_mode'));
    }

    public function testAddExplainsWhyNothingWasCreated(): void
    {
        $result = (new GitStackWebActions($this->composeRoot))->add([
            'stackName' => 'whoami',
            'gitUrl' => $this->upstream,
            'gitComposePath' => 'whoami/compose.yml',
        ]);

        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('does not exist on branch main', $result['message']);
        $this->assertDirectoryDoesNotExist($this->composeRoot . '/whoami');
    }

    public function testAddRefusesARegistryLoginAsTheRepositoryCredential(): void
    {
        $registryLogin = (new \CredentialVault())->saveCredential([
            'name' => 'Docker Hub', 'provider' => 'docker', 'registry' => 'docker.io',
            'username' => 'me', 'secret' => 'token',
        ])['id'];

        $result = (new GitStackWebActions($this->composeRoot))->add([
            'stackName' => 'whoami',
            'gitUrl' => $this->upstream,
            'gitComposePath' => 'whoami/compose.yaml',
            'gitCredentialId' => $registryLogin,
        ]);

        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('no git credential', $result['message']);
        $this->assertDirectoryDoesNotExist($this->composeRoot . '/whoami');
    }

    // ----- convert -----

    public function testConvertTurnsAStackIntoAGitStackAndKeepsTheOldComposeFile(): void
    {
        $stack = \StackInfo::createNew($this->composeRoot, 'myapp');
        $stackDir = $this->composeRoot . '/' . $stack->projectFolder;

        $result = $this->executeAction('convertToGitStack', [
            'script' => $stack->projectFolder,
            'gitUrl' => $this->upstream,
            'gitComposePath' => 'whoami/compose.yaml',
        ]);

        $this->assertSame('success', $result['result'], $result['message'] ?? '');
        $this->assertFileExists($result['backupDir'] . '/compose.yaml');
        $this->assertNotNull(GitStackSettings::load($stackDir));
        $this->assertNotEmpty($result['messages']);
    }

    public function testConvertThatFailsChangesNothing(): void
    {
        $stack = \StackInfo::createNew($this->composeRoot, 'myapp');
        $stackDir = $this->composeRoot . '/' . $stack->projectFolder;

        $result = (new GitStackWebActions($this->composeRoot))->convert($stack->projectFolder, [
            'gitUrl' => $this->upstream,
            'gitComposePath' => 'whoami/missing.yaml',
        ]);

        $this->assertSame('error', $result['result']);
        $this->assertFileExists($stackDir . '/compose.yaml');
        $this->assertFileDoesNotExist($stackDir . '/git.json');
    }

    // ----- status -----

    public function testStatusShowsTheRepositoryAndWhatIsDeployed(): void
    {
        $folder = $this->addGitStack('whoami');

        $result = (new GitStackWebActions($this->composeRoot))->status($folder);

        $this->assertSame('success', $result['result']);
        $this->assertSame($this->upstream, $result['git']['url']);
        $this->assertSame('main', $result['git']['branch']);
        $this->assertSame('whoami/compose.yaml', $result['git']['composePath']);
        $this->assertNull($result['git']['deployedCommit']);
        $this->assertSame([], $result['git']['localChanges']);
        $this->assertNull($result['git']['deployKey']);
    }

    public function testStatusSaysWhyAnSshStacksDeployKeyCannotBeShown(): void
    {
        if (trim((string) shell_exec('command -v ssh-keygen')) === '') {
            $this->markTestSkipped('ssh-keygen is not installed.');
        }
        $folder = $this->addGitStack('whoami');
        $stackDir = $this->composeRoot . '/' . $folder;
        // An ssh stack whose deploy key in the vault is not a key ssh-keygen can read.
        $credentialId = (new \CredentialVault())->saveCredential([
            'name' => 'whoami deploy key', 'provider' => 'git-ssh', 'registry' => 'git.example.com',
            'username' => 'git', 'secret' => 'not a key',
        ])['id'];
        $hostKey = \GitSsh::generateKey('host key')['public'];
        $url = 'git@git.example.com:team/stacks.git';
        $data = json_decode((string) file_get_contents($stackDir . '/git.json'), true);
        $data['url'] = $url;
        $data['credentialId'] = $credentialId;
        $data['sshKnownHosts'] = 'git.example.com ' . implode(' ', array_slice(explode(' ', $hostKey), 0, 2)) . "\n";
        file_put_contents($stackDir . '/git.json', (string) json_encode($data));
        $this->git(['remote', 'set-url', 'origin', $url], GitStackSettings::load($stackDir)->cloneDir);

        $result = (new GitStackWebActions($this->composeRoot))->status($folder);

        $this->assertSame('success', $result['result']);
        $this->assertNull($result['git']['deployKey']);
        $this->assertStringStartsWith('Could not read the deploy key: ', (string) $result['git']['problem']);
        $this->assertStringNotContainsString('Could not read the deploy key: Could not read', (string) $result['git']['problem']);
    }

    public function testStatusListsFilesChangedInTheClone(): void
    {
        $folder = $this->addGitStack('whoami');
        $settings = GitStackSettings::load($this->composeRoot . '/' . $folder);
        file_put_contents($settings->composeFileInClone(), "services: {}\n");

        $result = (new GitStackWebActions($this->composeRoot))->status($folder);

        $this->assertSame(['whoami/compose.yaml'], $result['git']['localChanges']);
    }

    public function testStatusSaysWhenTheCloneHasACommitMadeByHand(): void
    {
        $folder = $this->addGitStack('whoami');
        $settings = GitStackSettings::load($this->composeRoot . '/' . $folder);
        $actions = new GitStackWebActions($this->composeRoot);
        $this->assertFalse($actions->status($folder)['git']['commitMadeByHand']);

        file_put_contents($settings->composeFileInClone(), "services: {}\n");
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.invalid', 'commit', '-q', '-am', 'by hand'], $settings->cloneDir);

        // The same test the deploy uses, so the UI offers to save it exactly when the deploy would refuse.
        $result = $actions->status($folder);
        $this->assertTrue($result['git']['commitMadeByHand']);
        $this->assertSame([], $result['git']['localChanges']);
    }

    public function testStatusOfAStackThatIsNotAGitStackIsAnError(): void
    {
        \StackInfo::createNew($this->composeRoot, 'plain');

        $result = (new GitStackWebActions($this->composeRoot))->status('plain');

        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('not a git stack', $result['message']);
    }

    public function testGetGitStackStatusAction(): void
    {
        $folder = $this->addGitStack('whoami');

        $result = $this->executeAction('getGitStackStatus', ['script' => $folder]);

        $this->assertSame('success', $result['result']);
        $this->assertSame('main', $result['git']['branch']);
    }

    // ----- check for changes -----

    public function testCheckSaysWhetherANewerCommitIsWaiting(): void
    {
        $folder = $this->addGitStack('whoami');

        $result = $this->executeAction('checkGitStack', ['script' => $folder]);

        $this->assertSame('success', $result['result']);
        $this->assertFalse($result['check']['upToDate']);
        $this->assertNull($result['check']['deployedCommit']);
        $this->assertSame('main', $result['check']['branch']);
        $this->assertTrue($result['check']['changesStack']);
        $this->assertSame('whoami', $result['check']['stackFolder']);
    }

    public function testCheckWaitsForNoDeployButSaysOneIsRunning(): void
    {
        $folder = $this->addGitStack('whoami');
        (new \GitStackState($this->upstreamHead(), null))->save($this->composeRoot . '/' . $folder);
        $this->writeAndPush(['whoami/config.txt' => "setting=1\n"], 'newer');
        // A deploy holds the stack's lock (compose.sh takes the same one).
        @mkdir(COMPOSE_LOCK_DIR, 0755, true);
        $lock = fopen(COMPOSE_LOCK_DIR . '/whoami.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX));

        try {
            $result = $this->executeAction('checkGitStack', ['script' => $folder]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('Another operation is in progress', $result['message']);
    }

    public function testCheckOfAMissingStackIsAnError(): void
    {
        $result = (new GitStackWebActions($this->composeRoot))->check('nothing-here');

        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('no stack folder', $result['message']);
    }

    // ----- delete -----

    public function testDeleteLeavesTheCloneAndSaysWhereItIs(): void
    {
        $folder = $this->addGitStack('whoami');
        $cloneDir = GitStackSettings::load($this->composeRoot . '/' . $folder)->cloneDir;

        $result = $this->executeAction('deleteStack', ['stackName' => $folder]);

        $this->assertSame('warning', $result['result']);
        $this->assertStringStartsWith($cloneDir . '. This is the stack\'s clone', $result['message']);
        $this->assertDirectoryDoesNotExist($this->composeRoot . '/' . $folder);
        $this->assertDirectoryExists($cloneDir);
    }

    public function testNothingIsLeftBehindByDeletingAStackThatIsNotAGitStack(): void
    {
        $stack = \StackInfo::createNew($this->composeRoot, 'plain');

        $this->assertNull(GitStackWebActions::leftBehindByDelete($this->composeRoot . '/' . $stack->projectFolder));
    }

    // ----- the stack list -----

    public function testListSummaryReadsTheBranchAndCommitsFromTheStackFolder(): void
    {
        $folder = $this->addGitStack('whoami');
        $stackDir = $this->composeRoot . '/' . $folder;
        $deployed = str_repeat('a', 40);
        $failed = str_repeat('b', 40);
        (new \GitStackState($deployed, $failed))->save($stackDir);

        $this->assertSame(
            ['branch' => 'main', 'deployedCommit' => $deployed, 'failedCommit' => $failed, 'problem' => null],
            GitStackWebActions::listSummary($stackDir)
        );
    }

    public function testListSummaryNamesAProblemInsteadOfFailing(): void
    {
        $folder = $this->addGitStack('whoami');
        file_put_contents($this->composeRoot . '/' . $folder . '/git.json', '{"version": 99}');

        $summary = GitStackWebActions::listSummary($this->composeRoot . '/' . $folder);

        $this->assertNull($summary['branch']);
        $this->assertNotNull($summary['problem']);
    }

    public function testTheStackListShowsTheBranchDeployedCommitAndAFailedDeploy(): void
    {
        $folder = $this->addGitStack('whoami');
        $deployed = '1234567' . str_repeat('a', 33);
        (new \GitStackState($deployed, str_repeat('b', 40)))->save($this->composeRoot . '/' . $folder);

        $html = $this->stackListHtml();

        $this->assertStringContainsString("main @ <span title='Deployed commit $deployed'>1234567</span>", $html);
        $this->assertStringContainsString('>failed</span>', $html);
    }

    public function testTheStackListSaysWhenAGitStacksSettingsCannotBeRead(): void
    {
        $folder = $this->addGitStack('whoami');
        file_put_contents($this->composeRoot . '/' . $folder . '/git.json', '{"version": 99}');

        $this->assertStringContainsString('git settings cannot be read', $this->stackListHtml());
    }

    // ----- the editor's settings -----

    public function testGetStackSettingsSaysWhetherTheStackIsAGitStack(): void
    {
        $folder = $this->addGitStack('whoami');
        \StackInfo::createNew($this->composeRoot, 'plain');

        $this->assertTrue($this->executeAction('getStackSettings', ['script' => $folder])['isGitStack']);
        $this->assertFalse($this->executeAction('getStackSettings', ['script' => 'plain'])['isGitStack']);
    }

    public function testSavingSettingsNeverChangesAGitStacksComposeFile(): void
    {
        $folder = $this->addGitStack('whoami');
        $stackDir = $this->composeRoot . '/' . $folder;
        $indirect = (string) file_get_contents($stackDir . '/indirect');

        // A form that sends no compose source would otherwise turn it back into a project-folder stack.
        $result = $this->executeAction('setStackSettings', ['script' => $folder, 'externalComposePath' => '', 'externalComposeFilePath' => '']);

        $this->assertSame('success', $result['result']);
        $this->assertSame($indirect, file_get_contents($stackDir . '/indirect'));
        $this->assertSame('file', file_get_contents($stackDir . '/indirect_mode'));
        foreach (COMPOSE_FILE_NAMES as $composeFileName) {
            $this->assertFileDoesNotExist($stackDir . '/' . $composeFileName);
        }
    }

    public function testSavingSettingsIgnoresAnotherComposeFileForAGitStack(): void
    {
        $folder = $this->addGitStack('whoami');
        $stackDir = $this->composeRoot . '/' . $folder;
        $indirect = (string) file_get_contents($stackDir . '/indirect');
        $other = $this->mnt . '/user/appdata/other.compose.yaml';
        file_put_contents($other, "services: {}\n");

        $result = $this->executeAction('setStackSettings', ['script' => $folder, 'externalComposeFilePath' => $other]);

        $this->assertSame('success', $result['result']);
        $this->assertSame($indirect, file_get_contents($stackDir . '/indirect'));
    }

    // ----- deploy from the stack menu -----

    public function testDeployOpensATerminalForAGitStack(): void
    {
        $folder = $this->addGitStack('whoami');

        $output = $this->deploy(['path' => $this->composeRoot . '/' . $folder]);

        $this->assertSame('/plugins/compose.manager/include/ShowTtyd.php?done=1', $output);
        $this->assertSame(
            "'-cgitdeploy' '-pwhoami' '-s{$this->composeRoot}/{$folder}'",
            $this->terminalCommand()
        );
    }

    public function testTheWebDeployPassesOnTheCommitLocalChangesProfilesWaitAndDebug(): void
    {
        $folder = $this->addGitStack('whoami');
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->composeRoot,
            'DEBUG_TO_LOG' => 'true',
            'WAIT_FOR_HEALTHY_DEFAULT' => 'true',
            'WAIT_FOR_HEALTHY_TIMEOUT_DEFAULT' => '60',
        ]);
        $commit = str_repeat('a', 40);

        $output = $this->deploy([
            'path' => $this->composeRoot . '/' . $folder,
            'commit' => strtoupper($commit),
            'saveLocalChanges' => '1',
            'profile' => 'gpu,tools',
            'profileChosen' => '1',
        ]);

        $this->assertSame('/plugins/compose.manager/include/ShowTtyd.php?done=1', $output);
        $this->assertSame(
            "'-cgitdeploy' '-pwhoami' '-s{$this->composeRoot}/{$folder}'"
            . " '-ggpu' '-gtools' '--git-commit' '$commit' '--save-local-changes' '--wait' '--wait-timeout' '60' '--debug'",
            $this->terminalCommand()
        );
    }

    public function testTheWebDeployUsesTheRunningProfilesWhenNoneWereChosen(): void
    {
        $folder = $this->addGitStack('whoami');
        file_put_contents($this->composeRoot . '/' . $folder . '/running_profiles', 'gpu');

        $this->deploy(['path' => $this->composeRoot . '/' . $folder, 'profile' => '']);

        $this->assertStringEndsWith(" '-ggpu'", $this->terminalCommand());
    }

    public function testDeployRefusesAStackWhoseProjectNameIsNotSettled(): void
    {
        $folder = $this->addGitStack('whoami');
        $stackDir = $this->composeRoot . '/' . $folder;
        // An imported stack whose name differs from its folder, while Docker cannot be asked
        // which of the two project names its containers use.
        @unlink($stackDir . '/' . \ProjectIdentity::METADATA_FILE);
        file_put_contents($stackDir . '/name', 'Legacy Name');
        \ProjectIdentity::setProbe(static fn() => null);

        $answer = json_decode($this->deploy(['path' => $stackDir]), true);

        $this->assertSame('identity', $answer['error']);
        $this->assertSame([], $this->terminalCommands());
    }

    public function testTheWebDeployUsesTheChosenProfilesOrElseTheRunningOnes(): void
    {
        $folder = $this->addGitStack('whoami');
        $stackDir = $this->composeRoot . '/' . $folder;
        file_put_contents($stackDir . '/running_profiles', 'gpu');
        \StackInfo::clearCache();
        $stack = \StackInfo::fromProject($this->composeRoot, $folder);

        // Nothing chosen (no profiles in the compose file, or an older page): as compose-git deploy.
        $this->assertSame(['gpu'], gitDeployProfilesFromRequest($stack, ['profile' => '']));
        // Chosen in the profile dialog.
        $this->assertSame(['gpu', 'tools'], gitDeployProfilesFromRequest($stack, ['profile' => 'gpu, tools', 'profileChosen' => '1']));
        // Chosen: the default services only.
        $this->assertSame([], gitDeployProfilesFromRequest($stack, ['profile' => '', 'profileChosen' => '1']));
    }

    public function testDeployRefusesAStackThatIsNotAGitStack(): void
    {
        \StackInfo::createNew($this->composeRoot, 'plain');

        $answer = json_decode($this->deploy(['path' => $this->composeRoot . '/plain']), true);

        $this->assertSame('git', $answer['error']);
        $this->assertStringContainsString('not a git stack', $answer['message']);
    }

    public function testDeployAsksForAFullCommitId(): void
    {
        $folder = $this->addGitStack('whoami');

        $answer = json_decode($this->deploy(['path' => $this->composeRoot . '/' . $folder, 'commit' => 'abc1234']), true);

        $this->assertSame('git', $answer['error']);
        $this->assertStringContainsString('full commit id', $answer['message']);
    }

    public function testDeployDoesNothingWhileTheArrayIsStopped(): void
    {
        $folder = $this->addGitStack('whoami');
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"STOPPED\"\n");

        $output = $this->deploy(['path' => $this->composeRoot . '/' . $folder]);

        $this->assertStringContainsString('arrayNotStarted.sh', $output);
    }

    // ----- helpers -----

    /**
     * Run the web UI's git deploy (ComposeUtil.php composeGitDeploy) and return what it echoes.
     *
     * @param array<string, string> $post
     */
    private function deploy(array $post): string
    {
        global $compose_root, $plugin_root, $sName;
        $compose_root = $this->composeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager/';
        $sName = 'compose.manager';
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', COMPOSE_UNRAID_VAR_INI);
        \StackInfo::clearCache();

        $_POST = $post;
        $GLOBALS['composeLoggerMessages'] = [];
        ob_start();
        echoGitDeployCommand();
        $output = (string) ob_get_clean();
        $_POST = [];
        return $output;
    }

    /**
     * The commands the last deploy() would have run in the terminal window. Tests
     * do not start ttyd; execComposeCommandInTTY() logs the command instead.
     *
     * @return list<string>
     */
    private function terminalCommands(): array
    {
        $prefix = 'Skipping ttyd execution in test mode: ';
        $commands = [];
        foreach ($GLOBALS['composeLoggerMessages'] ?? [] as $message) {
            if (str_starts_with($message, $prefix)) {
                $commands[] = substr($message, strlen($prefix));
            }
        }
        return $commands;
    }

    /**
     * compose.sh's arguments in the one command the last deploy() ran in the terminal.
     */
    private function terminalCommand(): string
    {
        $commands = $this->terminalCommands();
        $this->assertCount(1, $commands);
        $this->assertMatchesRegularExpression("#^'[^']*/scripts/compose\\.sh' #", $commands[0]);
        return substr($commands[0], strpos($commands[0], "' ") + 2);
    }

    /**
     * The Compose page's stack list (ComposeList.php), as the page loads it.
     */
    private function stackListHtml(): string
    {
        global $compose_root, $plugin_root, $sName;
        $compose_root = $this->composeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';
        \StackInfo::clearCache();

        $_GET = [];
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/ComposeList.php';
        return (string) ob_get_clean();
    }

    private function addGitStack(string $name): string
    {
        return (new GitStackManager($this->composeRoot))->add($name, $this->upstream, 'main', 'whoami/compose.yaml', $this->clonesRoot);
    }

    /**
     * Run an Exec.php action and decode its JSON answer.
     *
     * @param array<string, string> $post
     * @return array<string, mixed>
     */
    private function executeAction(string $action, array $post): array
    {
        global $compose_root, $plugin_root, $sName;
        $compose_root = $this->composeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';
        \StackInfo::clearCache();

        $_POST = array_merge(['action' => $action], $post);
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/Exec.php';
        $output = (string) ob_get_clean();
        $_POST = [];

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "Exec.php $action answered: $output");
        return $decoded;
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

    private function upstreamHead(): string
    {
        return trim(GitCommand::run(['--git-dir=' . $this->upstream, 'rev-parse', 'refs/heads/main'])->stdout);
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
