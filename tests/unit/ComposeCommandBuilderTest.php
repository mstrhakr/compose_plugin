<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/ComposeCommandBuilder.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/StackUpdateService.php';

class ComposeCommandBuilderTest extends TestCase
{
    private string $tempRoot;

    public function testUpdateVariantsShareAllResolvedArguments(): void
    {
        $dir = $this->tempRoot . '/shared-update';
        mkdir($dir);
        file_put_contents($dir . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        file_put_contents($dir . '/running_profiles', 'dev');
        file_put_contents($dir . '/default_profile', 'default');
        file_put_contents($dir . '/credential_id', 'registry-login');
        file_put_contents($dir . '/.env', "TEST=1\n");
        $stack = \StackInfo::fromProject($this->tempRoot, 'shared-update');
        $cfg = ['BUILD_ON_UPDATE_DEFAULT' => 'true'];

        $normal = \StackUpdateService::buildUpdateCommand($stack, $cfg);
        $force = \StackUpdateService::buildUpdateCommand($stack, $cfg, ['recreate' => true]);
        $automatic = \StackUpdateService::buildUpdateCommand($stack, $cfg, ['automatic' => true]);

        $this->assertSame([...$normal, '--recreate'], $force);
        $this->assertSame([...$normal, '--automatic-update'], $automatic);
        $this->assertContains('--build', $normal);
        $this->assertNotContains('-gdev', $normal);
        $this->assertNotContains('-gdefault', $normal);
        $this->assertContains('registry-login', $normal);
        $this->assertContains('-e' . $dir . '/.env', $normal);
        $this->assertContains('-s' . $dir, $normal);

        file_put_contents($dir . '/build_on_update', 'false');
        $disabled = \StackUpdateService::buildUpdateCommand($stack, $cfg, ['automatic' => true]);
        $this->assertNotContains('--build', $disabled);
        $this->assertSame([], array_values(array_filter($normal, static fn(string $arg): bool => str_starts_with($arg, '-g'))));
    }

    public function testCliUpdateSpecLeavesRuntimeScopeToTheEngine(): void
    {
        $dir = $this->tempRoot . '/cli-update';
        mkdir($dir);
        file_put_contents($dir . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        file_put_contents($dir . '/running_profiles', 'active');
        file_put_contents($dir . '/default_profile', 'backup');
        $command = [
            PHP_BINARY, '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/bootstrap.php',
            dirname(__DIR__, 2) . '/source/compose.manager/scripts/compose_args.php',
            '--compose-root', $this->tempRoot, '--project', 'cli-update', '--action', 'UPDATE', '--format', 'json',
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));
        $response = json_decode(implode("\n", $output), true);
        $this->assertSame('success', $response['result']);
        $this->assertSame([], $response['data']['profiles']);
        $this->assertSame(
            \StackUpdateService::resolveUpdateSpec(\StackInfo::fromProject($this->tempRoot, 'cli-update')),
            $response['data']
        );
    }

    public function testManualStoppedDefaultCanBeOverriddenButNeverEnablesAutomaticInclusion(): void
    {
        $dir = $this->tempRoot . '/scope-options';
        mkdir($dir);
        file_put_contents($dir . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        $stack = \StackInfo::fromProject($this->tempRoot, 'scope-options');
        foreach ([false, true] as $default) {
            $cfg = ['UPDATE_INCLUDE_STOPPED_DEFAULT' => $default ? 'true' : 'false'];
            $command = \StackUpdateService::buildUpdateCommand($stack, $cfg);
            $this->assertSame($default, in_array('--include-stopped', $command, true));
            foreach ([false, true] as $include) {
                foreach ([false, true] as $automatic) {
                    $command = \StackUpdateService::buildUpdateCommand($stack, $cfg, [
                        'includeStopped' => $include, 'automatic' => $automatic,
                    ]);
                    $this->assertSame($include && !$automatic, in_array('--include-stopped', $command, true));
                }
            }
        }
        $this->assertSame('false', parse_ini_file(dirname(__DIR__, 2) . '/source/compose.manager/default.cfg')['UPDATE_INCLUDE_STOPPED_DEFAULT']);
    }

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

    public function testBuildForUpAndDownOmitsMissingOptionalOverride(): void
    {
        $stack = 'missing-override';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $overridePath = $info->getOverridePath();

        $this->assertNotNull($overridePath);
        $this->assertFileDoesNotExist($overridePath);
        $upSpec = \ComposeCommandBuilder::buildForAction($info, 'up');
        $downSpec = \ComposeCommandBuilder::buildForAction($info, 'down');

        $this->assertSame([$stackDir . '/compose.yaml'], $upSpec['composeFiles']);
        $this->assertSame([$stackDir . '/compose.yaml'], $downSpec['composeFiles']);
        $this->assertSame($overridePath, $info->getOverridePath(), 'The preferred write target must remain available.');
    }

    public function testUpdateSpecDoesNotUseHistoricalRunningProfiles(): void
    {
        $stack = 'profiles-update-running';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");
        file_put_contents($stackDir . '/default_profile', 'dev,prod');
        file_put_contents($stackDir . '/running_profiles', 'hotfix,metrics');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $spec = \StackUpdateService::resolveUpdateSpec($info);

        $this->assertSame([], $spec['profiles']);
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

    public function testUpdateSpecDoesNotFallBackToDefaultProfiles(): void
    {
        $stack = 'profiles-update-default';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents($stackDir . '/compose.yaml', "services:\n");
        file_put_contents($stackDir . '/default_profile', 'dev,prod');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $spec = \StackUpdateService::resolveUpdateSpec($info);

        $this->assertSame([], $spec['profiles']);
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
