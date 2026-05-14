<?php

/**
 * Unit Tests for exec.php Actions (REAL SOURCE)
 * 
 * Tests the AJAX action handlers in source/compose.manager/include/Exec.php
 * Now that functions are in ExecHelpers.php, we can include Exec.php
 * multiple times to test different actions.
 * 
 * Note: Some tests use Linux commands (rm) and are skipped on Windows.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;
use PluginTests\Mocks\DockerUtilMock;


require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class ExecActionsTest extends TestCase
{
    private string $testComposeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        
        // Create test compose root
        $this->testComposeRoot = sys_get_temp_dir() . '/compose_exec_test_' . getmypid();
        if (!is_dir($this->testComposeRoot)) {
            mkdir($this->testComposeRoot, 0755, true);
        }
        
        // Set the global compose_root
        global $compose_root, $plugin_root, $sName;
        $compose_root = $this->testComposeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';
        
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->testComposeRoot,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testComposeRoot)) {
            $this->recursiveDelete($this->testComposeRoot);
        }
        $_POST = [];
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Execute an action by including the real exec.php with POST data.
     */
    private function executeAction(string $action, array $postData = []): string
    {
        global $compose_root, $plugin_root, $sName;
        $compose_root = $this->testComposeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';
        
        $_POST = array_merge(['action' => $action], $postData);
        
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/Exec.php';
        $output = ob_get_clean();
        
        $_POST = [];
        
        return $output ?: '';
    }

    /**
     * Create a test stack directory
     */
    private function createTestStack(string $name, array $files = []): string
    {
        $stackPath = $this->testComposeRoot . '/' . $name;
        mkdir($stackPath, 0755, true);
        
        // Create compose.yaml by default
        if (!isset($files[COMPOSE_FILE_NAMES[0]])) {
            file_put_contents($stackPath . '/' . COMPOSE_FILE_NAMES[0], "services:\n");
        }
        
        foreach ($files as $filename => $content) {
            file_put_contents($stackPath . '/' . $filename, $content);
        }
        
        return $stackPath;
    }

    /**
     * Build a minimal Docker inspect-style container array suitable for
     * DockerUtilMock and dockerContainerToComposeService().
     */
    private function makeContainer(string $id, string $name, string $image, array $extra = []): array
    {
        return array_replace_recursive([
            'Id' => $id,
            'Name' => '/' . $name,
            'Image' => $image,
            'Manager' => 'dockerman',
            'Running' => true,
            'Status' => 'Up 2 hours',
            'Config' => [
                'Image' => $image,
                'Env' => [],
                'Labels' => [],
                'ExposedPorts' => [],
            ],
            'HostConfig' => [
                'PortBindings' => [],
                'Binds' => [],
                'RestartPolicy' => ['Name' => ''],
                'NetworkMode' => 'bridge',
            ],
            'Mounts' => [],
            'NetworkSettings' => ['Networks' => []],
        ], $extra);
    }

    // ===========================================
    // changeName Action Tests
    // ===========================================

    /**
     * Test changeName action saves name to file
     */
    public function testChangeNameSavesNameFile(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $output = $this->executeAction('changeName', [
            'script' => 'test-stack',
            'newName' => 'My Custom Name',
        ]);
        
        $this->assertFileExists($stackPath . '/name');
        $this->assertEquals('My Custom Name', file_get_contents($stackPath . '/name'));
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test changeName trims whitespace from name
     */
    public function testChangeNameTrimsWhitespace(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $this->executeAction('changeName', [
            'script' => 'test-stack',
            'newName' => '  Trimmed Name  ',
        ]);
        
        $this->assertEquals('Trimmed Name', file_get_contents($stackPath . '/name'));
    }

    // ===========================================
    // changeDesc Action Tests
    // ===========================================

    /**
     * Test changeDesc saves description to file
     */
    public function testChangeDescSavesDescription(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $output = $this->executeAction('changeDesc', [
            'script' => 'test-stack',
            'newDesc' => 'This is a test description',
        ]);
        
        $this->assertFileExists($stackPath . '/description');
        $this->assertEquals('This is a test description', file_get_contents($stackPath . '/description'));
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
    }

    // ===========================================
    // getDescription Action Tests
    // ===========================================

    /**
     * Test getDescription returns file content
     */
    public function testGetDescriptionReturnsContent(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/description', 'Test description content');
        
        $output = $this->executeAction('getDescription', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals('Test description content', $result['content']);
    }

    /**
     * Test getDescription returns empty when no description file
     */
    public function testGetDescriptionEmptyWhenNoFile(): void
    {
        $this->createTestStack('test-stack');
        
        $output = $this->executeAction('getDescription', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals('', $result['content']);
    }

    /**
     * Test getDescription error when stack not specified
     */
    public function testGetDescriptionErrorWhenNoStack(): void
    {
        $output = $this->executeAction('getDescription', [
            'script' => '',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('not specified', $result['message']);
    }

    // ===========================================
    // updateAutostart Action Tests
    // ===========================================

    /**
     * Test updateAutostart creates autostart file
     */
    public function testUpdateAutostartCreatesFile(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $output = $this->executeAction('updateAutostart', [
            'script' => 'test-stack',
            'autostart' => 'true',
        ]);
        
        $this->assertFileExists($stackPath . '/autostart');
        $this->assertEquals('true', file_get_contents($stackPath . '/autostart'));
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test updateAutostart replaces existing file
     */
    public function testUpdateAutostartReplacesExisting(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/autostart', 'true');
        
        $this->executeAction('updateAutostart', [
            'script' => 'test-stack',
            'autostart' => 'false',
        ]);
        
        $this->assertEquals('false', file_get_contents($stackPath . '/autostart'));
    }

    // ===========================================
    // getYml Action Tests
    // ===========================================

    /**
     * Test getYml returns compose file content
     */
    public function testGetYmlReturnsContent(): void
    {
        $content = "services:\n  web:\n    image: nginx";
        $this->createTestStack('test-stack', [
            COMPOSE_FILE_NAMES[0] => $content,
        ]);
        
        $output = $this->executeAction('getYml', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals($content, $result['content']);
    }

    // ===========================================
    // saveYml Action Tests
    // ===========================================

    /**
     * Test saveYml writes compose file
     */
    public function testSaveYmlWritesFile(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $newContent = "services:\n  app:\n    image: alpine";
        $output = $this->executeAction('saveYml', [
            'script' => 'test-stack',
            'scriptContents' => $newContent,
        ]);
        
        $this->assertEquals($newContent, file_get_contents($stackPath . '/' . COMPOSE_FILE_NAMES[0]));
        $this->assertStringContainsString('saved', $output);
    }

    // ===========================================
    // getEnv Action Tests
    // ===========================================

    /**
     * Test getEnv returns .env file content
     */
    public function testGetEnvReturnsContent(): void
    {
        $envContent = "VAR1=value1\nVAR2=value2";
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/.env', $envContent);
        
        $output = $this->executeAction('getEnv', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals($envContent, $result['content']);
    }

    /**
     * Test getEnv uses custom envpath if set
     */
    public function testGetEnvUsesCustomEnvPath(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $customEnvPath = $this->testComposeRoot . '/custom.env';
        file_put_contents($customEnvPath, "CUSTOM_VAR=custom_value");
        file_put_contents($stackPath . '/envpath', $customEnvPath);
        
        $output = $this->executeAction('getEnv', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals("CUSTOM_VAR=custom_value", $result['content']);
    }

    // ===========================================
    // saveEnv Action Tests
    // ===========================================

    /**
     * Test saveEnv writes .env file
     */
    public function testSaveEnvWritesFile(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $envContent = "NEW_VAR=new_value";
        $output = $this->executeAction('saveEnv', [
            'script' => 'test-stack',
            'scriptContents' => $envContent,
        ]);
        
        $this->assertEquals($envContent, file_get_contents($stackPath . '/.env'));
        $this->assertStringContainsString('saved', $output);
    }

    // ===========================================
    // getOverride Action Tests
    // ===========================================

    /**
     * Test getOverride returns override file content
     */
    public function testGetOverrideReturnsContent(): void
    {
        $overrideContent = "services:\n  web:\n    ports:\n      - '8080:80'";
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/compose.override.yaml', $overrideContent);
        
        $output = $this->executeAction('getOverride', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals($overrideContent, $result['content']);
    }

    /**
     * Test getOverride returns empty when no file
     */
    public function testGetOverrideCreatesWhenNoFile(): void
    {
        $this->createTestStack('test-stack');
        
        $output = $this->executeAction('getOverride', [
            'script' => 'test-stack',
        ]);
        $overrideContent = "# Override file for UI labels (icon, webui, shell)\n";
        $overrideContent .= "# This file is managed by Compose Manager\n";
        $overrideContent .= "services: {}\n";
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals($overrideContent, $result['content']);
    }

    // ===========================================
    // saveOverride Action Tests
    // ===========================================

    /**
     * Test saveOverride writes override file
     */
    public function testSaveOverrideWritesFile(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $overrideContent = "services:\n  web:\n    volumes:\n      - ./data:/data";
        $this->executeAction('saveOverride', [
            'script' => 'test-stack',
            'scriptContents' => $overrideContent,
        ]);
        
        $this->assertEquals($overrideContent, file_get_contents($stackPath . '/compose.override.yaml'));
    }

    // ===========================================
    // setEnvPath Action Tests  
    // ===========================================

    /**
     * Test setEnvPath creates envpath file
     * Uses a path under compose_root which is always writable and allowed.
     */
    public function testSetEnvPathCreatesFile(): void
    {
        $stackPath = $this->createTestStack('test-stack');

        // Use a path under compose_root — always writable and passes validation
        $envDir = $this->testComposeRoot . '/envfiles';
        mkdir($envDir, 0755, true);
        $customPath = $envDir . '/custom.env';

        $output = $this->executeAction('setEnvPath', [
            'script' => 'test-stack',
            'envPath' => $customPath,
        ]);
        
        $this->assertFileExists($stackPath . '/envpath');
        $this->assertEquals($customPath, file_get_contents($stackPath . '/envpath'));
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test setEnvPath with empty value returns success
     * Note: File deletion uses shell 'rm' command which we can't reliably test
     */
    public function testSetEnvPathEmptyReturnsSuccess(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/envpath', '/some/path.env');
        
        $output = $this->executeAction('setEnvPath', [
            'script' => 'test-stack',
            'envPath' => '',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
    }

    // ===========================================
    // getEnvPath Action Tests
    // ===========================================

    /**
     * Test getEnvPath returns envpath content
     */
    public function testGetEnvPathReturnsContent(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $customPath = '/mnt/user/appdata/custom.env';
        file_put_contents($stackPath . '/envpath', $customPath);
        
        $output = $this->executeAction('getEnvPath', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals($customPath, $result['content']);
    }

    // ===========================================
    // deleteStack Action Tests
    // ===========================================

    /**
     * Test deleteStack returns success response
     * Note: Directory deletion uses shell 'rm -rf' command which we can't reliably test
     */
    public function testDeleteStackReturnsSuccess(): void
    {
        $this->createTestStack('test-stack');
        
        $output = $this->executeAction('deleteStack', [
            'stackName' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        // Will be 'success' if no indirect file, or 'warning' if indirect exists
        $this->assertContains($result['result'], ['success', 'warning']);
    }

    /**
     * Test deleteStack returns warning for indirect stacks
     */
    public function testDeleteStackWarningForIndirect(): void
    {
        $this->createTestStack('test-stack', [
            'indirect' => '/mnt/user/compose/mystack',
        ]);
        
        $output = $this->executeAction('deleteStack', [
            'stackName' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('warning', $result['result']);
        $this->assertStringContainsString('/mnt/user/compose/mystack', $result['message']);
    }

    /**
     * Test deleteStack error when stack not specified
     */
    public function testDeleteStackErrorWhenNoStack(): void
    {
        $output = $this->executeAction('deleteStack', [
            'stackName' => '',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('not specified', $result['message']);
    }

    // ===========================================
    // getStackSettings Action Tests
    // ===========================================

    /**
     * Test getStackSettings returns settings data
     */
    public function testGetStackSettingsReturnsData(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/envpath', '/custom/path.env');
        file_put_contents($stackPath . '/default_profile', 'production');
        
        $output = $this->executeAction('getStackSettings', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertArrayHasKey('envPath', $result);
        $this->assertArrayHasKey('defaultProfile', $result);
        $this->assertEquals('/custom/path.env', $result['envPath']);
        $this->assertEquals('production', $result['defaultProfile']);
    }

    /**
     * Test getStackSettings error when stack not specified
     */
    public function testGetStackSettingsErrorWhenNoStack(): void
    {
        $output = $this->executeAction('getStackSettings', [
            'script' => '',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
    }

    // ===========================================
    // setStackSettings Action Tests
    // ===========================================

    /**
     * Test setStackSettings saves settings
     */
    public function testSetStackSettingsSavesSettings(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'envPath' => '/new/env/path.env',
            'defaultProfile' => 'staging',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test setStackSettings accepts exact SVG data URL icon
     */
    public function testSetStackSettingsAcceptsExactSvgDataUrlIcon(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $iconDataUrl = "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='20 10 90 90'><text y='1em' font-size='90'>%F0%9F%94%A7</text></svg>";

        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'iconUrl' => $iconDataUrl,
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertFileExists($stackPath . '/icon_url');
        $this->assertSame($iconDataUrl, file_get_contents($stackPath . '/icon_url'));

        $getOutput = $this->executeAction('getStackSettings', [
            'script' => 'test-stack',
        ]);
        $getResult = json_decode($getOutput, true);
        $this->assertEquals('success', $getResult['result']);
        $this->assertSame($iconDataUrl, $getResult['iconUrl']);
    }

    /**
     * Test setStackSettings accepts HTTP and HTTPS icon URLs
     */
    public function testSetStackSettingsAcceptsHttpAndHttpsIconUrls(): void
    {
        $stackPath = $this->createTestStack('test-stack');

        $httpsIconUrl = 'https://example.com/icon.svg';
        $httpsOutput = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'iconUrl' => $httpsIconUrl,
        ]);
        $httpsResult = json_decode($httpsOutput, true);
        $this->assertEquals('success', $httpsResult['result']);
        $this->assertSame($httpsIconUrl, file_get_contents($stackPath . '/icon_url'));

        $httpIconUrl = 'http://example.com/icon.png';
        $httpOutput = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'iconUrl' => $httpIconUrl,
        ]);
        $httpResult = json_decode($httpOutput, true);
        $this->assertEquals('success', $httpResult['result']);
        $this->assertSame($httpIconUrl, file_get_contents($stackPath . '/icon_url'));
    }

    /**
     * Test setStackSettings accepts local icon paths under allowed roots
     */
    public function testSetStackSettingsAcceptsAllowedLocalIconPaths(): void
    {
        $stackPath = $this->createTestStack('test-stack');

        $mntPath = '/mnt/user/icons/custom.png';
        $mntOutput = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'iconUrl' => $mntPath,
        ]);
        $mntResult = json_decode($mntOutput, true);
        $this->assertEquals('success', $mntResult['result']);
        $this->assertSame($mntPath, file_get_contents($stackPath . '/icon_url'));

        $projectPath = '/boot/config/plugins/compose.manager/projects/test-stack/icon.svg';
        $projectOutput = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'iconUrl' => $projectPath,
        ]);
        $projectResult = json_decode($projectOutput, true);
        $this->assertEquals('success', $projectResult['result']);
        $this->assertSame($projectPath, file_get_contents($stackPath . '/icon_url'));
    }

    /**
     * Test setStackSettings accepts base64 encoded image data URL icon
     */
    public function testSetStackSettingsAcceptsBase64ImageDataUrlIcon(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $iconDataUrl = 'data:image/png;base64,iVBORw0KGgo=';

        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'iconUrl' => $iconDataUrl,
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertSame($iconDataUrl, file_get_contents($stackPath . '/icon_url'));
    }

    /**
     * Test setStackSettings rejects unsupported icon URL types
     */
    public function testSetStackSettingsRejectsUnsupportedIconUrlTypes(): void
    {
        $this->createTestStack('test-stack');

        $badCases = [
            'ftp://example.com/icon.png',
            '/etc/passwd',
            'data:text/plain;base64,SGVsbG8=',
        ];

        foreach ($badCases as $iconUrl) {
            $output = $this->executeAction('setStackSettings', [
                'script' => 'test-stack',
                'iconUrl' => $iconUrl,
            ]);
            $result = json_decode($output, true);
            $this->assertEquals('error', $result['result']);
            $this->assertStringContainsString('Invalid icon', $result['message']);
        }
    }

    // ===========================================
    // checkStackLock Action Tests
    // ===========================================

    /**
     * Test checkStackLock returns false when no lock
     */
    public function testCheckStackLockReturnsFalseWhenNoLock(): void
    {
        $this->createTestStack('test-stack');
        
        $output = $this->executeAction('checkStackLock', [
            'script' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertFalse($result['locked']);
    }

    // ===========================================
    // performImportTransfer Action Tests
    // ===========================================

    public function testPerformImportTransferMissingStackName(): void
    {
        $output = $this->executeAction('performImportTransfer', [
            'stackName' => '',
            'composeYml' => "services:\n  web:\n    image: nginx\n",
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('Stack name is required', $result['message']);
    }

    public function testPerformImportTransferEmptyCompose(): void
    {
        $output = $this->executeAction('performImportTransfer', [
            'stackName' => 'import-test',
            'composeYml' => '',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('Compose content is required', $result['message']);

        // Stack folder should NOT have been created
        $this->assertDirectoryDoesNotExist($this->testComposeRoot . '/import-test');
    }

    public function testPerformImportTransferSuccess(): void
    {
        $yaml = "services:\n  web:\n    image: nginx\n";

        $output = $this->executeAction('performImportTransfer', [
            'stackName' => 'import-ok',
            'stackDesc' => 'My import',
            'composeYml' => $yaml,
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertStringContainsString('import-ok', $result['projectPath']);

        // compose file written (trim() in handler strips trailing newline)
        $composePath = $result['projectPath'] . '/compose.yaml';
        $this->assertFileExists($composePath);
        $this->assertEquals(trim($yaml), file_get_contents($composePath));
    }

    public function testPerformImportTransferWritesEnvAndOverride(): void
    {
        $yaml = "services:\n  web:\n    image: nginx\n";
        $env = "FOO=bar\n";
        $override = "services:\n  web:\n    labels:\n      - test=1\n";

        $output = $this->executeAction('performImportTransfer', [
            'stackName' => 'import-extras',
            'composeYml' => $yaml,
            'env' => $env,
            'override' => $override,
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);

        $basePath = $result['projectPath'];
        $this->assertEquals(trim($env), file_get_contents($basePath . '/.env'));
        $this->assertEquals(trim($override), file_get_contents($basePath . '/compose.override.yaml'));
    }

    // ===========================================
    // getDockerContainersForImport Action Tests
    // ===========================================

    public function testGetDockerContainersForImportReturnsDockerManOnly(): void
    {
        DockerUtilMock::setContainers([
            'nginx' => $this->makeContainer('abc123', 'nginx', 'nginx:latest'),
            'compose-svc' => $this->makeContainer('def456', 'compose-svc', 'redis:7', [
                'Manager' => 'compose',
            ]),
        ]);

        $output = $this->executeAction('getDockerContainersForImport');
        $result = json_decode($output, true);

        $this->assertEquals('success', $result['result']);
        $this->assertCount(1, $result['containers']);
        $this->assertEquals('abc123', $result['containers'][0]['Id']);
    }

    public function testGetDockerContainersForImportEmptyWhenNoCandidates(): void
    {
        DockerUtilMock::setContainers([]);

        $output = $this->executeAction('getDockerContainersForImport');
        $result = json_decode($output, true);

        $this->assertEquals('success', $result['result']);
        $this->assertEmpty($result['containers']);
    }

    // ===========================================
    // generateImportData Action Tests
    // ===========================================

    public function testGenerateImportDataErrorWhenNoContainers(): void
    {
        $output = $this->executeAction('generateImportData', [
            'containerIds' => json_encode([]),
        ]);
        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
    }

    public function testGenerateImportDataReturnsServiceMeta(): void
    {
        DockerUtilMock::setContainers([
            'web' => $this->makeContainer('aaa111', 'web', 'nginx:latest', [
                'Config' => [
                    'Image' => 'nginx:latest',
                    'Env' => ['MY_VAR=hello'],
                    'Labels' => [
                        'net.unraid.docker.icon' => 'https://example.com/icon.png',
                    ],
                    'ExposedPorts' => ['80/tcp' => (object) []],
                ],
                'HostConfig' => [
                    'PortBindings' => [
                        '80/tcp' => [['HostIp' => '', 'HostPort' => '8080']],
                    ],
                    'Binds' => [],
                    'RestartPolicy' => ['Name' => 'unless-stopped'],
                    'NetworkMode' => 'bridge',
                ],
            ]),
        ]);

        $output = $this->executeAction('generateImportData', [
            'containerIds' => json_encode(['aaa111']),
        ]);
        $result = json_decode($output, true);

        $this->assertEquals('success', $result['result']);
        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('portConflicts', $result);
        $this->assertArrayHasKey('networks', $result);

        // Should have exactly one service
        $this->assertCount(1, $result['services']);
        $svc = reset($result['services']);
        $this->assertEquals('nginx:latest', $svc['image']);
        $this->assertEquals('https://example.com/icon.png', $svc['icon']);
    }

    // ===========================================
    // finalizeImportCompose Action Tests
    // ===========================================

    public function testFinalizeImportComposeErrorWhenNoContainers(): void
    {
        $output = $this->executeAction('finalizeImportCompose', [
            'containerIds' => json_encode([]),
        ]);
        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
    }

    public function testFinalizeImportComposeReturnsYaml(): void
    {
        DockerUtilMock::setContainers([
            'redis' => $this->makeContainer('bbb222', 'redis', 'redis:7', [
                'Config' => [
                    'Image' => 'redis:7',
                    'Env' => [],
                    'Labels' => [],
                    'ExposedPorts' => [],
                ],
                'HostConfig' => [
                    'PortBindings' => [],
                    'Binds' => [],
                    'RestartPolicy' => ['Name' => ''],
                    'NetworkMode' => 'bridge',
                ],
            ]),
        ]);

        $output = $this->executeAction('finalizeImportCompose', [
            'containerIds' => json_encode(['bbb222']),
        ]);
        $result = json_decode($output, true);

        $this->assertEquals('success', $result['result']);
        $this->assertArrayHasKey('composeYml', $result);
        $this->assertStringContainsString('redis:7', $result['composeYml']);
        $this->assertArrayHasKey('validation', $result);
    }
}
