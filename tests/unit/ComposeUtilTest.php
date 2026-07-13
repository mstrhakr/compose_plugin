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
