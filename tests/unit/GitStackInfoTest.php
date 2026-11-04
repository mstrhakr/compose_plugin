<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

/**
 * How StackInfo treats a git stack: an indirect stack (file mode) pointing
 * into a clone, with git.json in the stack folder.
 */
final class GitStackInfoTest extends TestCase
{
    private string $root;
    private string $stackDir;
    private string $clone;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->root = $this->createTempDir();
        $this->stackDir = $this->root . '/whoami';
        $this->clone = $this->root . '/clones/whoami-0123abcd';
        mkdir($this->stackDir);
        mkdir($this->clone . '/whoami', 0755, true);
        file_put_contents($this->clone . '/whoami/compose.yaml', "services:\n  whoami:\n    image: traefik/whoami\n");
        file_put_contents($this->stackDir . '/indirect', $this->clone . '/whoami/compose.yaml');
        file_put_contents($this->stackDir . '/indirect_mode', 'file');
        file_put_contents($this->stackDir . '/git.json', '{}');
    }

    public function testStackWithGitJsonIsAGitStack(): void
    {
        $this->assertTrue($this->stack()->isGitStack());
        unlink($this->stackDir . '/git.json');
        \StackInfo::clearCache();
        $this->assertFalse($this->stack()->isGitStack());
    }

    // ----- .env -----

    public function testStackFolderEnvWinsOverOneInTheRepository(): void
    {
        file_put_contents($this->clone . '/whoami/.env', "FROM_REPO=1\n");
        file_put_contents($this->stackDir . '/.env', "FROM_HOST=1\n");

        $stack = $this->stack();

        $this->assertSame($this->stackDir . '/.env', $stack->getEnvFilePath());
        $this->assertSame($this->stackDir . '/.env', $stack->getEffectiveEnvFilePath());
        $this->assertStringContainsString($this->stackDir . '/.env', $stack->buildComposeArgs()['envFile']);
    }

    public function testEnvFileKeptInTheRepositoryIsUsedWhenTheStackFolderHasNone(): void
    {
        file_put_contents($this->clone . '/whoami/.env', "FROM_REPO=1\n");

        $stack = $this->stack();

        $this->assertSame($this->clone . '/whoami/.env', $stack->getEffectiveEnvFilePath());
        $this->assertSame($this->clone . '/whoami/.env', $stack->buildComposeArgs()['envFilePath']);
        // The editor opens the one that is used.
        $this->assertSame($this->clone . '/whoami/.env', $stack->getEnvFilePath());
    }

    public function testWithNoEnvFileTheEditorCreatesItInTheStackFolder(): void
    {
        $stack = $this->stack();

        $this->assertNull($stack->getEffectiveEnvFilePath());
        $this->assertSame($this->stackDir . '/.env', $stack->getEnvFilePath());
    }

    public function testExplicitEnvPathStillWins(): void
    {
        $custom = $this->root . '/custom.env';
        file_put_contents($custom, "A=1\n");
        file_put_contents($this->stackDir . '/envpath', $custom);

        $this->assertSame($custom, $this->stack()->getEnvFilePath());
    }

    // ----- overrides -----

    public function testRepositoryOverrideAndManagedOverrideAreBothApplied(): void
    {
        $repoOverride = $this->clone . '/whoami/compose.override.yaml';
        $managedOverride = $this->stackDir . '/compose.override.yaml';
        file_put_contents($repoOverride, "services: {}\n");
        file_put_contents($managedOverride, "services: {}\n");

        $paths = $this->stack()->buildComposeArgs()['filePaths'];

        $this->assertSame([$this->clone . '/whoami/compose.yaml', $repoOverride, $managedOverride], $paths);
    }

    public function testAChangedRepositoryOverrideMakesTheCachesStale(): void
    {
        $repoOverride = $this->clone . '/whoami/compose.override.yaml';
        file_put_contents($repoOverride, "services: {}\n");
        file_put_contents($this->stackDir . '/profiles', json_encode(['cached']));
        file_put_contents($this->stackDir . '/has_build', '1');
        $old = time() - 3600;
        touch($this->clone . '/whoami/compose.yaml', $old);
        touch($repoOverride, $old);

        // The caches are newer than every compose file, so they are used.
        $this->assertSame(['cached'], $this->stack()->getProfiles());
        $this->assertTrue($this->stack()->hasBuildConfig());

        // A commit that changes only the repository's override.
        touch($repoOverride, time() + 60);

        // Read again from compose, which the test image does not have, so the cached answers are gone.
        $this->assertNotSame(['cached'], $this->stack()->getProfiles());
        $this->assertFalse($this->stack()->hasBuildConfig());
    }

    public function testARemovedRepositoryOverrideMakesTheCachesStale(): void
    {
        $repoOverride = $this->clone . '/whoami/compose.override.yaml';
        file_put_contents($repoOverride, "services: {}\n");
        file_put_contents($this->stackDir . '/profiles', json_encode(['cached']));
        file_put_contents($this->stackDir . '/has_build', '1');
        $old = time() - 3600;
        touch($this->clone . '/whoami/compose.yaml', $old);
        touch($repoOverride, $old);
        touch($this->clone . '/whoami', $old);
        touch($this->stackDir . '/profiles', time() - 60);
        touch($this->stackDir . '/has_build', time() - 60);

        $this->assertSame(['cached'], $this->stack()->getProfiles());
        $this->assertTrue($this->stack()->hasBuildConfig());

        // A commit that removes the repository's override leaves no file to compare, but
        // the removal updates its folder.
        unlink($repoOverride);

        $this->assertNotSame(['cached'], $this->stack()->getProfiles());
        $this->assertFalse($this->stack()->hasBuildConfig());
    }

    public function testBuildConfigsIncludeServicesBehindProfiles(): void
    {
        exec('docker compose version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('docker compose is not available.');
        }
        mkdir($this->clone . '/whoami/app');
        file_put_contents($this->clone . '/whoami/app/Dockerfile', "FROM alpine:3.20\n");
        file_put_contents(
            $this->clone . '/whoami/compose.yaml',
            "services:\n  whoami:\n    image: traefik/whoami\n  app:\n    build: ./app\n    platform: linux/arm64\n    profiles: [dev]\n"
        );
        // The default-profile build check found nothing and cached that.
        file_put_contents($this->stackDir . '/has_build', '0');
        touch($this->stackDir . '/has_build', time() + 60);

        $builds = $this->stack()->getBuildConfigs();

        $this->assertSame(['app'], array_keys($builds));
        $this->assertNull($builds['app']['image']);
        $this->assertSame($this->clone . '/whoami/app', $builds['app']['build']['context']);
        $this->assertSame('linux/arm64', $builds['app']['platform']);
    }

    public function testMissingOverridesAreLeftOut(): void
    {
        @unlink($this->stackDir . '/compose.override.yaml');

        $paths = $this->stack()->buildComposeArgs()['filePaths'];

        $this->assertSame([$this->clone . '/whoami/compose.yaml'], $paths);
    }

    public function testBuildConfigFailuresAreNotAnEmptyBuildMap(): void
    {
        $bin = $this->createTempDir();
        $path = getenv('PATH');
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        try {
            foreach ([
                ['echo invalid-compose >&2; exit 7', 'Unable to resolve build configuration'],
                ['echo not-json', 'Invalid Compose configuration'],
                ['echo "{}"', 'Missing Compose services configuration'],
            ] as [$command, $message]) {
                file_put_contents($bin . '/docker', "#!/bin/sh\n$command\n");
                chmod($bin . '/docker', 0755);
                try {
                    $this->stack()->getBuildConfigs();
                    $this->fail('Invalid Compose output must fail explicitly.');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString($message, $e->getMessage());
                }
            }
            file_put_contents($bin . '/docker', "#!/bin/sh\necho warning >&2\necho '{\"services\":{}}'\n");
            $this->assertSame([], $this->stack()->getBuildConfigs(), 'Successful config warnings must not corrupt JSON.');
        } finally {
            putenv('PATH=' . $path);
        }
    }

    public function testOverrideEditsGoToTheStackFolder(): void
    {
        file_put_contents($this->clone . '/whoami/compose.override.yaml', "services: {}\n");
        $stack = $this->stack();

        $this->assertSame($this->stackDir . '/compose.override.yaml', $stack->getPreferredOverridePath());
        $this->assertSame($this->stackDir . '/compose.override.yaml', $stack->getOverridePath());
    }

    public function testOrdinaryIndirectStackKeepsItsOldOverrideRule(): void
    {
        unlink($this->stackDir . '/git.json');
        $repoOverride = $this->clone . '/whoami/compose.override.yaml';
        file_put_contents($repoOverride, "services: {}\n");
        file_put_contents($this->stackDir . '/compose.override.yaml', "services: {}\n");

        $stack = $this->stack();

        $this->assertSame([$this->clone . '/whoami/compose.yaml', $repoOverride], $stack->buildComposeArgs()['filePaths']);
        $this->assertSame($repoOverride, $stack->getPreferredOverridePath());
    }

    // ----- project directory -----

    public function testProjectDirectoryIsTheComposeFileFolderInTheClone(): void
    {
        $this->assertSame($this->clone . '/whoami', $this->stack()->buildComposeArgs()['projectDirectory']);
    }

    private function stack(): \StackInfo
    {
        \StackInfo::clearCache();
        return \StackInfo::fromProject($this->root, 'whoami');
    }
}
