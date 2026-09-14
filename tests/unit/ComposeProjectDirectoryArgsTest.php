<?php

/**
 * Regression coverage for issue #149.
 *
 * `-w` (compose --project-directory) must only be emitted for default discovery.
 * Adding it alongside explicit `-f` args breaks `extends.file`, which compose
 * resolves against the project directory.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Helpers.php';

class ComposeProjectDirectoryArgsTest extends TestCase
{
    private string $stackRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stackRoot = sys_get_temp_dir() . '/compose_projdir_test_' . getmypid();
        @mkdir($this->stackRoot . '/docker', 0755, true);
        file_put_contents($this->stackRoot . '/docker/docker-compose.yml', "services: {}\n");
        file_put_contents($this->stackRoot . '/docker/docker-compose-ipinfo.yml', "services: {}\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->stackRoot . '/build_on_update');
        @unlink($this->stackRoot . '/docker/docker-compose.yml');
        @unlink($this->stackRoot . '/docker/docker-compose-ipinfo.yml');
        @rmdir($this->stackRoot . '/docker');
        @rmdir($this->stackRoot);

        parent::tearDown();
    }

    public function testExplicitComposeFilesDoNotEmitProjectDirectory(): void
    {
        $composeCommand = [];
        \appendComposeFileArgs($composeCommand, [
            'projectDirectory' => $this->stackRoot,
            'useDefaultFileDiscovery' => false,
            'filePaths' => [
                $this->stackRoot . '/docker/docker-compose.yml',
                $this->stackRoot . '/docker/docker-compose-ipinfo.yml',
            ],
        ]);

        $this->assertSame([
            '-f' . $this->stackRoot . '/docker/docker-compose.yml',
            '-f' . $this->stackRoot . '/docker/docker-compose-ipinfo.yml',
        ], $composeCommand);
    }

    public function testDefaultDiscoveryEmitsOnlyProjectDirectory(): void
    {
        $composeCommand = [];
        \appendComposeFileArgs($composeCommand, [
            'projectDirectory' => $this->stackRoot,
            'useDefaultFileDiscovery' => true,
            'filePaths' => [$this->stackRoot . '/docker/docker-compose.yml'],
        ]);

        $this->assertSame(['-w' . $this->stackRoot], $composeCommand);
    }

    public function testDefaultDiscoveryWithoutProjectDirectoryEmitsNothing(): void
    {
        $composeCommand = [];
        \appendComposeFileArgs($composeCommand, [
            'projectDirectory' => '',
            'useDefaultFileDiscovery' => true,
            'filePaths' => [$this->stackRoot . '/docker/docker-compose.yml'],
        ]);

        $this->assertSame([], $composeCommand);
    }

    public function testNonexistentComposeFilesAreSkipped(): void
    {
        $composeCommand = [];
        \appendComposeFileArgs($composeCommand, [
            'projectDirectory' => $this->stackRoot,
            'useDefaultFileDiscovery' => false,
            'filePaths' => [$this->stackRoot . '/docker/missing.yml'],
        ]);

        $this->assertSame([], $composeCommand);
    }

    public function testBuildOnUpdateDefaultsToGlobalSetting(): void
    {
        $this->assertFalse(\resolveStackBuildOnUpdate($this->stackRoot, []));
        $this->assertFalse(\resolveStackBuildOnUpdate($this->stackRoot, ['BUILD_ON_UPDATE_DEFAULT' => 'false']));
        $this->assertTrue(\resolveStackBuildOnUpdate($this->stackRoot, ['BUILD_ON_UPDATE_DEFAULT' => 'true']));
    }

    public function testBuildOnUpdateStackOverrideWinsOverGlobal(): void
    {
        $overrideFile = $this->stackRoot . '/build_on_update';

        file_put_contents($overrideFile, "true\n");
        $this->assertTrue(\resolveStackBuildOnUpdate($this->stackRoot, ['BUILD_ON_UPDATE_DEFAULT' => 'false']));

        file_put_contents($overrideFile, "false\n");
        $this->assertFalse(\resolveStackBuildOnUpdate($this->stackRoot, ['BUILD_ON_UPDATE_DEFAULT' => 'true']));

        file_put_contents($overrideFile, "");
        $this->assertTrue(\resolveStackBuildOnUpdate($this->stackRoot, ['BUILD_ON_UPDATE_DEFAULT' => 'true']));

        @unlink($overrideFile);
    }
}
