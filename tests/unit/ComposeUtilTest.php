<?php

/**
 * Unit Tests for Compose Utility Functions (REAL SOURCE)
 * 
 * Tests the actual source: source/compose.manager/include/Helpers.php
 * Functions are now in a separate file for testability.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use OverrideInfo;
use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

// Load the functions file directly (no switch statement)
require_once '/usr/local/emhttp/plugins/compose.manager/include/Helpers.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';

/**
 * Tests for compose_util.php functions
 * 
 * Note: ComposeUtil.php contains these functions:
 * - logger($string) - calls system logger
 * - execComposeCommandInTTY($cmd, $debug) - runs ttyd
 * - echoComposeCommand($action) - echoes compose command
 * - echoComposeCommandMultiple($action, $paths) - echoes multiple compose commands
 * 
 * These functions mostly echo output and execute external commands, so we test
 * with output capturing and mock filesystem setup.
 */
class ComposeUtilTest extends TestCase
{
    public static function configurationActions(): array
    {
        return [['up'], ['update'], ['down']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configurationActions')]
    public function testMissingConfiguredEnvIsExplicitAndBulkDispatchContinues(string $action): void
    {
        global $compose_root;
        $compose_root = $this->createTempDir();
        $paths = [];
        foreach (['bad-input', 'healthy-input'] as $project) {
            $path = $compose_root . '/' . $project;
            mkdir($path);
            file_put_contents($path . '/compose.yaml', "services:\n  app:\n    image: nginx\n");
            file_put_contents($path . '/project_name', $project);
            file_put_contents($path . '/project_identity.json', json_encode([
                'version' => \ProjectIdentity::MIGRATION_VERSION, 'projectName' => $project,
                'source' => 'owner', 'origin' => 'dcflachs',
                'composeFiles' => [$path . '/compose.yaml'], 'composeFilesSource' => 'runtime-labels',
            ]));
            $paths[] = $path;
        }
        file_put_contents($paths[0] . '/envpath', $paths[0] . '/missing.env');
        $varFile = $this->createTempDir() . '/var.ini';
        file_put_contents($varFile, "mdState=STARTED\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', $varFile);
        $_POST['path'] = $paths[0];
        $before = glob('/tmp/compose_multi_*.sh') ?: [];
        try {
            ob_start();
            echoComposeCommand($action);
            $response = json_decode((string) ob_get_clean(), true);
            $this->assertSame('configuration', $response['error']);
            $this->assertStringContainsString('env file is unavailable', $response['message']);
            $this->assertTrue(\StackInfo::fromProject($compose_root, 'bad-input')->hasResolvedIdentity());

            ob_start();
            echoComposeCommandMultiple($action, ['paths' => [$paths[0]]]);
            $response = json_decode((string) ob_get_clean(), true);
            $this->assertSame('configuration', $response['error']);
            $this->assertArrayHasKey('bad-input', $response['failures']);

            ob_start();
            echoComposeCommandMultiple($action, ['paths' => $paths]);
            $response = (string) ob_get_clean();
            $this->assertStringContainsString('ShowTtyd.php', $response);
            $scripts = array_values(array_diff(glob('/tmp/compose_multi_*.sh') ?: [], $before));
            $this->assertCount(1, $scripts);
            $script = file_get_contents($scripts[0]);
            $this->assertStringContainsString('! Skipped bad-input:', $script);
            $this->assertStringContainsString('healthy-input', $script);
            $this->assertStringNotContainsString("'-pbad-input'", $script);
        } finally {
            foreach (array_diff(glob('/tmp/compose_multi_*.sh') ?: [], $before) as $file) {
                unlink($file);
            }
            unset($_POST['path']);
        }
    }

    public function testTrackedBulkDispatchQueuesEveryAcceptedStackBeforeTerminalConnection(): void
    {
        global $compose_root;
        $compose_root = $this->createTempDir();
        $paths = [];
        foreach (['receipt-first', 'receipt-second'] as $project) {
            $path = $compose_root . '/' . $project;
            mkdir($path, 0755, true);
            file_put_contents($path . '/compose.yaml', "services:\n  app:\n    image: nginx\n");
            $paths[] = $path;
        }
        $varFile = $this->createTempDir() . '/var.ini';
        file_put_contents($varFile, "mdState=STARTED\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', $varFile);
        $before = glob('/tmp/compose_multi_*.sh') ?: [];
        $_POST['trackOperation'] = '1';
        $files = [];
        $sessionFiles = [];
        $scripts = [];
        try {
            ob_start();
            echoComposeCommandMultiple('up', ['paths' => $paths]);
            $response = json_decode((string) ob_get_clean(), true);
            $this->assertStringStartsWith('/plugins/compose.manager/include/ShowTtyd.php?done=1&socket=compose_action_', $response['url']);
            parse_str(parse_url($response['url'], PHP_URL_QUERY), $query);
            $this->assertSame('up', $query['command']);
            $this->assertSame('1', $query['bulk']);
            $sessionFiles[] = \composeUiOperationFile('terminal-session', $query['session']) . '.log';
            $sessionFiles[] = \composeUiOperationFile('terminal-session', $query['session']);
            $this->assertSame(['receipt-first', 'receipt-second'], array_keys($response['operations']));
            foreach ($response['operations'] as $project => $id) {
                $file = \composeUiOperationFile($project, $id);
                $files[] = $file;
                $this->assertSame(['state' => 'queued'], json_decode((string) file_get_contents($file), true));
            }
            $scripts = array_values(array_filter(
                array_diff(glob('/tmp/compose_multi_*.sh') ?: [], $before),
                static fn(string $script): bool => str_contains((string) file_get_contents($script), $files[0])
            ));
            $this->assertCount(1, $scripts);
            $script = (string) file_get_contents($scripts[0]);
            foreach ($files as $file) {
                $this->assertStringContainsString(escapeshellarg($file), $script);
            }
        } finally {
            foreach (array_merge($files, $sessionFiles, $scripts) as $file) {
                @unlink($file);
            }
            unset($_POST['trackOperation']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up required globals for compose_util functions
        global $plugin_root, $sName, $socket_name;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';
        $socket_name = 'compose_test';
        
        // Set up plugin config
        FunctionMocks::setPluginConfig('compose.manager', [
            'DEBUG_TO_LOG' => 'false',
            'XDEBUG_MODE' => 'coverage',
        ]);
    }

    // ===========================================
    // echoComposeCommand() Tests
    // ===========================================

    /**
     * Test echoComposeCommand when array is not started
     */
    public function testEchoComposeCommandArrayNotStarted(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create mock var.ini with stopped array
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STOPPED\nfsState=Stopped\n");
        
        // Update the stream wrapper mapping
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = $tempDir . '/test-stack';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should return arrayNotStarted script path
        $this->assertStringContainsString('arrayNotStarted.sh', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path']);
    }

    /**
     * Test echoComposeCommand returns ttyd viewer URL.
     */
    public function testEchoComposeCommandReturnsTtydViewerUrl(): void
    {
        global $compose_root, $plugin_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory with compose file
        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", $stackName);
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = $stackDir;
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should return ttyd viewer URL for non-log actions
        $this->assertSame('/plugins/compose.manager/include/ShowTtyd.php?done=1', $output);

        $_POST['trackOperation'] = '1';
        ob_start();
        echoComposeCommand('up');
        $tracked = json_decode((string) ob_get_clean(), true);
        $this->assertStringStartsWith('/plugins/compose.manager/include/ShowTtyd.php?done=1&socket=compose_action_', $tracked['url']);
        parse_str(parse_url($tracked['url'], PHP_URL_QUERY), $query);
        $this->assertSame('up', $query['command']);
        $this->assertSame('0', $query['bulk']);
        $sessionFile = \composeUiOperationFile('terminal-session', $query['session']);
        unlink($sessionFile . '.log');
        unlink($sessionFile);
        $receipt = \composeUiOperationFile($stackName, $tracked['operations'][$stackName]);
        $this->assertSame(['state' => 'queued'], json_decode((string) file_get_contents($receipt), true));
        unlink($receipt);
        unset($_POST['trackOperation']);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand no longer returns compose.sh query URLs.
     */
    public function testEchoComposeCommandDoesNotReturnLegacyComposeScriptUrl(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;

        // Create stack directory with compose file
        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", $stackName);

        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");

        $_POST['path'] = $stackDir;
        $_POST['profile'] = '';

        ob_start();
        echoComposeCommand('logs');
        $output = ob_get_clean();

        $this->assertStringContainsString('/plugins/compose.manager/include/ShowTtyd.php?socket=', $output);
        $this->assertStringNotContainsString('compose.sh', $output);

        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand can launch a live follow-logs session without detach.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('followWaitModes')]
    public function testEchoComposeCommandFollowLogsUpUsesLiveLogSocket(bool $globalWait, ?string $stackWait, ?string $requestWait): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;

        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", $stackName);
        FunctionMocks::setPluginConfig('compose.manager', [
            'DEBUG_TO_LOG' => 'false', 'WAIT_FOR_HEALTHY_DEFAULT' => $globalWait ? 'true' : 'false',
            'WAIT_FOR_HEALTHY_TIMEOUT_DEFAULT' => '45',
        ]);
        if ($stackWait !== null) file_put_contents("$stackDir/wait_for_healthy", $stackWait);
        file_put_contents("$stackDir/wait_timeout", '17');

        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");

        $_POST['path'] = $stackDir;
        $_POST['profile'] = '';
        if ($requestWait !== null) $_POST['waitForHealthy'] = $requestWait;
        $GLOBALS['composeLoggerMessages'] = [];

        ob_start();
        echoComposeCommand('up', ['followLogs' => true, 'background' => true]);
        $output = ob_get_clean();

        $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('session=', $response['url']);
        $this->assertStringContainsString('&follow=1', $response['url']);
        $this->assertArrayNotHasKey('background', $response);
        $commands = implode("\n", $GLOBALS['composeLoggerMessages']);
        $this->assertStringContainsString('--follow-logs', $commands);
        $this->assertStringNotContainsString('--wait', $commands);
        $this->assertSame($stackWait, is_file("$stackDir/wait_for_healthy") ? file_get_contents("$stackDir/wait_for_healthy") : null);
        $this->assertSame('17', file_get_contents("$stackDir/wait_timeout"));

        unset($_POST['waitForHealthy']);
        $GLOBALS['composeLoggerMessages'] = [];
        ob_start();
        echoComposeCommand('up');
        ob_end_clean();
        $commands = implode("\n", $GLOBALS['composeLoggerMessages']);
        $expectedWait = $stackWait !== null ? $stackWait === 'true' : $globalWait;
        if ($expectedWait) {
            $this->assertStringContainsString("'--wait' '--wait-timeout' '17'", $commands);
        } else {
            $this->assertStringNotContainsString("'--wait'", $commands);
        }
        $this->assertStringNotContainsString("'--follow-logs'", $commands);

        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    public static function followWaitModes(): array
    {
        return [
            'global wait' => [true, null, null],
            'stack wait' => [false, 'true', null],
            'explicit request wait' => [false, null, '1'],
            'stack overrides global off' => [true, 'false', null],
            'request cannot force wait with follow' => [true, 'true', 'true'],
        ];
    }

    /**
     * Test echoComposeCommand with profile
     */
    public function testEchoComposeCommandWithProfile(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory
        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", $stackName);
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data with profile
        $_POST['path'] = $stackDir;
        $_POST['profile'] = 'dev';
        $GLOBALS['composeLoggerMessages'] = [];
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should return ttyd viewer URL
        $this->assertSame('/plugins/compose.manager/include/ShowTtyd.php?done=1', $output);
        $this->assertStringNotContainsString('--prepare-images', implode("\n", $GLOBALS['composeLoggerMessages']));

        $GLOBALS['composeLoggerMessages'] = [];
        ob_start();
        echoComposeCommand('up', ['prepareImages' => true]);
        $preparedOutput = ob_get_clean();
        $this->assertSame($output, $preparedOutput);
        $commands = implode("\n", $GLOBALS['composeLoggerMessages']);
        $this->assertStringContainsString("'--prepare-images'", $commands);
        $this->assertStringContainsString("'-gdev'", $commands);
        $this->assertStringContainsString("'-cup'", $commands);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with multiple profiles
     */
    public function testEchoComposeCommandWithMultipleProfiles(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory
        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", $stackName);
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data with multiple profiles
        $_POST['path'] = $stackDir;
        $_POST['profile'] = 'dev,prod';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should return ttyd viewer URL
        $this->assertSame('/plugins/compose.manager/include/ShowTtyd.php?done=1', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with indirect stack
     */
    public function testEchoComposeCommandWithIndirect(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create indirect target directory
        $indirectDir = $this->createTempDir();
        file_put_contents("$indirectDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        
        // Create stack directory with indirect pointer
        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/indirect", $indirectDir);
        file_put_contents("$stackDir/name", $stackName);
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = $stackDir;
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should return ttyd viewer URL
        $this->assertSame('/plugins/compose.manager/include/ShowTtyd.php?done=1', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with override file
     */
    public function testEchoComposeCommandWithOverrideFile(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        // Create stack directory with compose and override files
        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        
        $overrideInfo = \StackInfo::fromProject($tempDir, $stackName)->overrideInfo;
        file_put_contents($overrideInfo->getOverridePath(), "services:\n  web:\n    ports:\n      - 80:80\n");
                
        $sanitizedStackName = \StackInfo::sanitizeProjectString($stackName);
        file_put_contents("$stackDir/name", $sanitizedStackName);
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = $stackDir;
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should return ttyd viewer URL
        $this->assertSame('/plugins/compose.manager/include/ShowTtyd.php?done=1', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * Test echoComposeCommand with custom env path
     */
    public function testEchoComposeCommandWithEnvPath(): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory with envpath file
        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", $stackName);
        $customEnvPath = "$tempDir/custom.env";
        file_put_contents($customEnvPath, "TEST_VAR=1\n");
        file_put_contents("$stackDir/envpath", $customEnvPath);
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = $stackDir;
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand('up');
        $output = ob_get_clean();
        
        // Should return ttyd viewer URL
        $this->assertSame('/plugins/compose.manager/include/ShowTtyd.php?done=1', $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    public function testWaitForTtydSocketTimeout(): void
    {
        $socketName = 'compose_test_wait_timeout_' . uniqid();
        $tmpDir = sys_get_temp_dir();
        $socketPath = "$tmpDir/$socketName.sock";
        @unlink($socketPath);

        $this->assertFalse(waitForTtydSocket($socketName, 500, 50, $tmpDir));
    }

    public function testWaitForTtydSocketSuccess(): void
    {
        $socketName = 'compose_test_wait_success_' . uniqid();
        $tmpDir = sys_get_temp_dir();
        $socketPath = "$tmpDir/$socketName.sock";
        @unlink($socketPath);
        touch($socketPath);

        $this->assertTrue(waitForTtydSocket($socketName, 500, 50, $tmpDir));

        @unlink($socketPath);
    }

    public function testGetLastCmdLogFileForComposeAction(): void
    {
        $path = '/test-stack';
        $this->assertSame('/test-stack/last_cmd.log', getLastCmdLogFileForComposeAction('up', $path));
        $this->assertSame('', getLastCmdLogFileForComposeAction('logs', $path));
        $this->assertSame('/test-stack/last_cmd.log', getLastCmdLogFileForComposeAction('down', $path));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('actionsProvider')]
    /**
     * Test various compose actions
     */
    public function testEchoComposeCommandActions(string $action, string $expectedArg): void
    {
        global $compose_root;
        $tempDir = $this->createTempDir();
        $compose_root = $tempDir;
        
        // Create stack directory
        $stackName = 'test-stack';
        $stackDir = "$tempDir/$stackName";
        mkdir($stackDir, 0755, true);
        file_put_contents("$stackDir/" . COMPOSE_FILE_NAMES[0], "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/name", $stackName);
        
        // Ensure array is started
        $varIniDir = sys_get_temp_dir() . '/emhttp_test_' . uniqid();
        mkdir($varIniDir, 0755, true);
        file_put_contents("$varIniDir/var.ini", "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', "$varIniDir/var.ini");
        
        // Set POST data
        $_POST['path'] = $stackDir;
        $_POST['profile'] = '';
        
        // Capture output
        ob_start();
        echoComposeCommand($action);
        $output = ob_get_clean();
        
        // Should return expected ttyd viewer URL pattern for each action
        $this->assertStringContainsString($expectedArg, $output);
        
        // Cleanup
        unlink("$varIniDir/var.ini");
        rmdir($varIniDir);
        unset($_POST['path'], $_POST['profile']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function actionsProvider(): array
    {
        return [
            'up action' => ['up', 'ShowTtyd.php?done=1'],
            'down action' => ['down', 'ShowTtyd.php?done=1'],
            'pull action' => ['pull', 'ShowTtyd.php?done=1'],
            'stop action' => ['stop', 'ShowTtyd.php?done=1'],
            'logs action' => ['logs', 'ShowTtyd.php?socket='],
            'update action' => ['update', 'ShowTtyd.php?done=1'],
        ];
    }
}
