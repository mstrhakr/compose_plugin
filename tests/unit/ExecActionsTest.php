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
use PluginTests\StreamWrapper\UnraidStreamWrapper;


require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class ExecActionsTest extends TestCase
{
    private string $testComposeRoot;
    /** @var string[] */
    private array $externalCleanupPaths = [];

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
        foreach ($this->externalCleanupPaths as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }
        $this->externalCleanupPaths = [];
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
     * Prepare a symlink path under an allowed root (/mnt or /boot/config).
     *
     * @return array{symlink:string,target:string}
     */
    private function createAllowedSymlinkedPathFixture(bool $createComposeFile = false): array
    {
        $bases = ['/mnt', '/boot/config'];
        $base = null;
        foreach ($bases as $candidate) {
            if (!is_dir($candidate) && !@mkdir($candidate, 0755, true)) {
                continue;
            }
            if (is_writable($candidate)) {
                $base = $candidate;
                break;
            }
        }

        if ($base === null) {
            $this->markTestSkipped('Requires writable /mnt or /boot/config for allowed-path fixture.');
        }

        $suffix = 'compose_exec_fixture_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $target = $base . '/' . $suffix . '_target';
        $symlink = $base . '/' . $suffix . '_link';

        if (!@mkdir($target, 0755, true) && !is_dir($target)) {
            $this->markTestSkipped('Unable to create allowed-path fixture directory.');
        }

        if ($createComposeFile) {
            file_put_contents($target . '/compose.yaml', "services:\n  app:\n    image: redis\n");
        }

        if (!@symlink($target, $symlink)) {
            $this->recursiveDelete($target);
            $this->markTestSkipped('Symlink creation is unavailable in this environment.');
        }

        $this->externalCleanupPaths[] = $symlink;
        $this->externalCleanupPaths[] = $target;

        return ['symlink' => $symlink, 'target' => $target];
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
        $this->assertTrue($result['exists']);
        $this->assertSame($stackPath . '/.env', $result['fileName']);
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
        $this->assertTrue($result['exists']);
        $this->assertSame($customEnvPath, $result['fileName']);
    }

    /**
     * Test getEnv returns blank content and exists=false when no env file
     */
    public function testGetEnvReturnsBlankWhenNoFile(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        @unlink($stackPath . '/.env');

        $output = $this->executeAction('getEnv', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertSame('', $result['content']);
        $this->assertFalse($result['exists']);
        $this->assertSame($stackPath . '/.env', $result['fileName']);
    }

    // ===========================================
    // createEnvTemplate Action Tests
    // ===========================================

    /**
     * Test createEnvTemplate writes default .env template in stack path
     */
    public function testCreateEnvTemplateWritesDefaultPath(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        @unlink($stackPath . '/.env');

        $output = $this->executeAction('createEnvTemplate', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertTrue($result['exists']);
        $this->assertSame($stackPath . '/.env', $result['fileName']);
        $this->assertFileExists($stackPath . '/.env');
        $this->assertStringContainsString('IMAGE_TAG=latest', $result['content']);
    }

    /**
     * Test createEnvTemplate writes .env in indirect folder when stack is indirect
     */
    public function testCreateEnvTemplateUsesIndirectFolder(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $indirectDir = $this->testComposeRoot . '/external-env';
        mkdir($indirectDir, 0755, true);
        file_put_contents($indirectDir . '/compose.yaml', "services:\n  app:\n    image: nginx:alpine\n");
        @unlink($indirectDir . '/.env');

        file_put_contents($stackPath . '/indirect', $indirectDir);
        file_put_contents($stackPath . '/indirect_mode', 'folder');

        $output = $this->executeAction('createEnvTemplate', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertTrue($result['exists']);
        $this->assertSame($indirectDir . '/.env', $result['fileName']);
        $this->assertFileExists($indirectDir . '/.env');
    }

    /**
     * Test createEnvTemplate returns error when target path is a directory
     */
    public function testCreateEnvTemplateFailsWhenTargetIsDirectory(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $dirTarget = $this->testComposeRoot . '/env-dir-target';
        mkdir($dirTarget, 0755, true);
        file_put_contents($stackPath . '/envpath', $dirTarget);

        $output = $this->executeAction('createEnvTemplate', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('directory', $result['message']);
        $this->assertSame($dirTarget, file_get_contents($stackPath . '/envpath'));
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
        $this->assertEquals($stackPath . '/compose.override.yaml', $result['fileName']);
        $this->assertArrayNotHasKey('readingFromIndirect', $result);
        $this->assertArrayNotHasKey('projectOverridePath', $result);
    }

    /**
     * Test getOverride returns empty when no file
     */
    public function testGetOverrideReturnsBlankWhenNoFile(): void
    {
        $stackPath = $this->createTestStack('test-stack');

        $output = $this->executeAction('getOverride', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertSame('', $result['content']);
        $this->assertSame($stackPath . '/compose.override.yaml', $result['fileName']);
        $this->assertFalse($result['exists']);
        $this->assertFileDoesNotExist($stackPath . '/compose.override.yaml');
        $this->assertArrayNotHasKey('readingFromIndirect', $result);
        $this->assertArrayNotHasKey('projectOverridePath', $result);
    }

    // ===========================================
    // createOverrideTemplate Action Tests
    // ===========================================

    /**
     * Test createOverrideTemplate writes the default template
     */
    public function testCreateOverrideTemplateWritesTemplate(): void
    {
        $stackPath = $this->createTestStack('test-stack');

        $output = $this->executeAction('createOverrideTemplate', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertSame($stackPath . '/compose.override.yaml', $result['fileName']);
        $this->assertTrue($result['exists']);
        $this->assertStringContainsString('Manual example:', $result['content']);
        $this->assertStringContainsString('net.unraid.docker.webui', $result['content']);
        $this->assertFileExists($stackPath . '/compose.override.yaml');
        $this->assertStringContainsString('services: {}', file_get_contents($stackPath . '/compose.override.yaml'));
    }

    /**
     * Test createOverrideTemplate returns error when target path is a directory
     */
    public function testCreateOverrideTemplateFailsWhenTargetIsDirectory(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        @unlink($stackPath . '/compose.override.yaml');
        mkdir($stackPath . '/compose.override.yaml', 0755, true);

        $output = $this->executeAction('createOverrideTemplate', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('directory', $result['message']);
        $this->assertTrue(is_dir($stackPath . '/compose.override.yaml'));
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
     * Test deleteStack returns success response and removes the stack folder
     */
    public function testDeleteStackReturnsSuccess(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        
        $output = $this->executeAction('deleteStack', [
            'stackName' => 'test-stack',
        ]);
        
        $result = json_decode($output, true);
        // Will be 'success' if no indirect file, or 'warning' if indirect exists
        $this->assertContains($result['result'], ['success', 'warning']);
        $this->assertDirectoryDoesNotExist($stackPath);
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

    /**
     * Test deleteStack rejects traversal-like stack names
     */
    public function testDeleteStackRejectsTraversalName(): void
    {
        $output = $this->executeAction('deleteStack', [
            'stackName' => '..',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('Failed to delete stack folder', $result['message']);
        $this->assertStringContainsString('Invalid stack name', $result['message']);
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

    public function testGetStackSettingsReturnsExternalComposeFileForFileMode(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $externalDir = $this->testComposeRoot . '/external';
        $externalFile = $externalDir . '/custom.compose.yaml';
        mkdir($externalDir, 0755, true);
        file_put_contents($externalFile, "services:\n  app:\n    image: redis\n");
        file_put_contents($stackPath . '/indirect', $externalFile);
        file_put_contents($stackPath . '/indirect_mode', 'file');

        $output = $this->executeAction('getStackSettings', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertSame('file', $result['indirectMode']);
        $this->assertSame($externalFile, $result['externalComposeFilePath']);
        $this->assertSame('', $result['externalComposePath']);
    }

    public function testGetStackSettingsPreservesBrokenIndirectFileModePath(): void
    {
        $stackPath = $this->createTestStack('test-stack', [COMPOSE_FILE_NAMES[0] => null]);
        $missingFile = $this->testComposeRoot . '/external/missing.compose.yaml';
        file_put_contents($stackPath . '/indirect', $missingFile);
        file_put_contents($stackPath . '/indirect_mode', 'file');
        @unlink($stackPath . '/' . COMPOSE_FILE_NAMES[0]);

        $output = $this->executeAction('getStackSettings', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertSame('file', $result['indirectMode']);
        $this->assertSame($missingFile, $result['invalidIndirectPath']);
        $this->assertSame('', $result['externalComposeFilePath']);
    }

    public function testGetStackSettingsReturnsEffectiveDefaultDiscoveryFalseForFileMode(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $externalDir = $this->testComposeRoot . '/external-discovery';
        $externalFile = $externalDir . '/compose.yaml';
        mkdir($externalDir, 0755, true);
        file_put_contents($externalFile, "services:\n  app:\n    image: redis\n");
        file_put_contents($stackPath . '/indirect', $externalFile);
        file_put_contents($stackPath . '/indirect_mode', 'file');
        file_put_contents($stackPath . '/use_default_compose_files', 'true');

        $output = $this->executeAction('getStackSettings', [
            'script' => 'test-stack',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertFalse($result['useDefaultComposeFiles']);
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

    public function testSetStackSettingsPreservesExtraComposeFilesWhenFieldMissing(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/extra_compose_files', "compose.gpu.yaml\n");

        // A stale UI session that predates the field posts without it
        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'defaultProfile' => 'staging',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertFileExists($stackPath . '/extra_compose_files');
        $this->assertEquals("compose.gpu.yaml\n", file_get_contents($stackPath . '/extra_compose_files'));
    }

    public function testSetStackSettingsClearsExtraComposeFilesWhenFieldEmpty(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/extra_compose_files', "compose.gpu.yaml\n");

        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'extraComposeFiles' => '',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertFileDoesNotExist($stackPath . '/extra_compose_files');
    }

    public function testSetStackSettingsWritesExtraComposeFiles(): void
    {
        $stackPath = $this->createTestStack('test-stack');

        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'extraComposeFiles' => "compose.gpu.yaml\ncompose.debug.yaml",
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals("compose.gpu.yaml\ncompose.debug.yaml\n", file_get_contents($stackPath . '/extra_compose_files'));
    }

    public function testSetStackSettingsRejectsBothExternalComposePathAndFile(): void
    {
        $this->createTestStack('test-stack');

        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'externalComposePath' => '/mnt/user/appdata/example',
            'externalComposeFilePath' => '/mnt/user/appdata/example/compose.yaml',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('Set either External Compose Path or External Compose File', $result['message']);
    }

    public function testAddStackRejectsBothIndirectPathAndFile(): void
    {
        $output = $this->executeAction('addStack', [
            'stackName' => 'My Stack',
            'stackPath' => '/mnt/user/appdata/example',
            'stackFilePath' => '/mnt/user/appdata/example/compose.yaml',
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('error', $result['result']);
        $this->assertStringContainsString('Set either Indirect Path or Indirect Compose File', $result['message']);
    }

    public function testAddStackPreservesUserProvidedSymlinkIndirectFolderPath(): void
    {
        $fixture = $this->createAllowedSymlinkedPathFixture();

        $output = $this->executeAction('addStack', [
            'stackName' => 'Symlink Preserve Folder',
            'stackPath' => $fixture['symlink'],
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);

        $project = $result['project'] ?? '';
        $this->assertNotSame('', $project);
        $indirectFile = $this->testComposeRoot . '/' . $project . '/indirect';
        $this->assertFileExists($indirectFile);
        $this->assertSame($fixture['symlink'], trim((string) file_get_contents($indirectFile)));
    }

    public function testSetStackSettingsPreservesUserProvidedSymlinkExternalComposeFilePath(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $fixture = $this->createAllowedSymlinkedPathFixture(true);
        $symlinkedComposeFile = $fixture['symlink'] . '/compose.yaml';

        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'externalComposeFilePath' => $symlinkedComposeFile,
        ]);

        $result = json_decode($output, true);
        $this->assertEquals('success', $result['result']);

        $indirectFile = $stackPath . '/indirect';
        $this->assertFileExists($indirectFile);
        $this->assertSame($symlinkedComposeFile, trim((string) file_get_contents($indirectFile)));
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
    // getSavedUpdateStatus Action Tests
    // ===========================================

    /**
     * Returns dockerVersionsInstalled=false when the plugin directory is absent.
     */
    public function testGetSavedUpdateStatusDockerVersionsNotInstalled(): void
    {
        @unlink(COMPOSE_UPDATE_STATUS_FILE);

        // Make the test deterministic even when running on a host that has docker.versions installed.
        $fakeDir = sys_get_temp_dir() . '/fake_docker_versions_absent_' . getmypid();
        UnraidStreamWrapper::addMapping('/usr/local/emhttp/plugins/docker.versions', $fakeDir);

        $output = $this->executeAction('getSavedUpdateStatus');
        $result = json_decode($output, true);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['result']);
        $this->assertSame([], $result['stacks']);
        $this->assertFalse($result['dockerVersionsInstalled']);
    }



    /**
     * Falls back to empty stacks when the status file contains invalid JSON.
     */
    public function testGetSavedUpdateStatusHandlesInvalidStatusFile(): void
    {
        file_put_contents(COMPOSE_UPDATE_STATUS_FILE, 'not-valid-json');

        $output = $this->executeAction('getSavedUpdateStatus');
        $result = json_decode($output, true);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['result']);
        $this->assertSame([], $result['stacks']);

        @unlink(COMPOSE_UPDATE_STATUS_FILE);
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
}
