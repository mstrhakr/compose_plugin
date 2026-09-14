<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/ComposeCommandBuilder.php';

class ComposeCommandBuilderTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();

        $this->tempRoot = sys_get_temp_dir() . '/compose_builder_test_' . getmypid() . '_' . uniqid();
        mkdir($this->tempRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        \StackInfo::clearCache();
        $this->recursiveDelete($this->tempRoot);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    public function testBuildForActionUsesDefaultProfilesForUp(): void
    {
        $stack = 'profiles-up';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");
        file_put_contents($stackDir . '/default_profile', 'dev,prod');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $spec = \ComposeCommandBuilder::buildForAction($info, 'up');

        $this->assertSame('up', $spec['action']);
        $this->assertSame(['dev', 'prod'], $spec['profiles']);
        $this->assertSame($info->projectName, $spec['projectName']);
        $this->assertSame($stackDir, $spec['stackPath']);
    }

    public function testBuildForActionUsesRunningProfilesForUpdate(): void
    {
        $stack = 'profiles-update-running';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");
        file_put_contents($stackDir . '/default_profile', 'dev,prod');
        file_put_contents($stackDir . '/running_profiles', 'hotfix,metrics');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $spec = \ComposeCommandBuilder::buildForAction($info, 'update');

        $this->assertSame(['hotfix', 'metrics'], $spec['profiles']);
    }

    public function testBuildIncludesCredentialOnlyForRegistryActions(): void
    {
        $stack = 'credential-action';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");
        file_put_contents($stackDir . '/credential_id', 'credential-123');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $this->assertSame('credential-123', \ComposeCommandBuilder::buildForAction($info, 'pull')['credentialId']);
        $this->assertSame('credential-123', \ComposeCommandBuilder::buildForAction($info, 'up')['credentialId']);
        $this->assertSame('', \ComposeCommandBuilder::buildForAction($info, 'down')['credentialId']);
        $this->assertSame('', \ComposeCommandBuilder::buildForAction($info, 'logs')['credentialId']);
    }

    public function testBuildForActionFallsBackToDefaultProfilesForUpdate(): void
    {
        $stack = 'profiles-update-default';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");
        file_put_contents($stackDir . '/default_profile', 'dev,prod');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $spec = \ComposeCommandBuilder::buildForAction($info, 'update');

        $this->assertSame(['dev', 'prod'], $spec['profiles']);
    }

    public function testBuildForActionUsesWildcardForDown(): void
    {
        $stack = 'profiles-down';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");
        file_put_contents($stackDir . '/default_profile', 'dev,prod');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $spec = \ComposeCommandBuilder::buildForAction($info, 'down');

        $this->assertSame(['*'], $spec['profiles']);
    }

    public function testBuildForActionIncludesEffectiveEnvPathFallback(): void
    {
        $stack = 'env-fallback';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");
        file_put_contents($stackDir . '/.env', "KEY=value\n");
        file_put_contents($stackDir . '/envpath', '/not/real/path.env');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $spec = \ComposeCommandBuilder::buildForAction($info, 'up');

        $this->assertSame($stackDir . '/.env', $spec['envFilePath']);
    }

    public function testFromProjectUsesProvidedStackPathOverride(): void
    {
        $stack = 'stack-path-override';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");

        $customStackPath = '/custom/stack/path';
        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, $stack, 'up', $customStackPath);

        $this->assertSame($customStackPath, $spec['stackPath']);
    }
}
