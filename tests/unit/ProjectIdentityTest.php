<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/ComposeCommandBuilder.php';

/**
 * Regression coverage for the fail-closed legacy project-identity migration.
 *
 * The original compose plugin used the per-stack `name` file as the
 * `docker compose -p` value; Plus derives it from the folder. These tests pin
 * the behaviour that decides which one wins.
 */
class ProjectIdentityTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        \ProjectIdentity::setProbe(static fn(): array => []);
        $this->tempRoot = $this->createTempDir();
    }

    protected function tearDown(): void
    {
        \ProjectIdentity::setProbe(static fn(): array => []);
        parent::tearDown();
    }

    /**
     * Create a legacy-style stack: folder name and `name` file deliberately differ.
     */
    private function makeStack(string $folder, ?string $name = null): string
    {
        $path = $this->tempRoot . '/' . $folder;
        mkdir($path, 0755, true);
        file_put_contents($path . '/compose.yaml', "services:\n");
        if ($name !== null) {
            file_put_contents($path . '/name', $name);
        }
        return $path;
    }

    /**
     * @param array<string, int> $containerCountsByProject
     * @return array<string, array{containers: int, volumes: int, networks: int}>
     */
    private function containers(array $containerCountsByProject): array
    {
        $probe = [];
        foreach ($containerCountsByProject as $project => $count) {
            $probe[$project] = ['containers' => $count, 'volumes' => 0, 'networks' => 0];
        }
        return $probe;
    }

    public function testLegacyRuntimeIdentityIsPreservedWhenItOwnsRunningContainers(): void
    {
        $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['legacy_runtime' => 2]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertSame('legacy_runtime', $stack->projectName);
        $this->assertSame(\ProjectIdentity::SOURCE_LEGACY_RUNTIME, $stack->identity->source);
        $this->assertTrue($stack->hasResolvedIdentity());
        $this->assertSame('legacy-folder', $stack->projectFolder);
        $this->assertSame('legacy_runtime', $stack->displayName);
    }

    public function testLegacyRuntimeIdentityIsPreservedForStoppedContainers(): void
    {
        // `docker ps -a` reports stopped containers too — a stack that is merely
        // down must still keep its identity.
        $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['legacy_runtime' => 1]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertSame('legacy_runtime', $stack->projectName);
        $this->assertTrue($stack->hasResolvedIdentity());
    }

    public function testLegacyIdentityIsPreservedWhenOnlyVolumesRemain(): void
    {
        $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(static fn(): array => [
            'legacy_runtime' => ['containers' => 0, 'volumes' => 3, 'networks' => 0],
        ]);

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertSame('legacy_runtime', $stack->projectName);
        $this->assertSame(\ProjectIdentity::SOURCE_LEGACY_RUNTIME, $stack->identity->source);
    }

    public function testFolderIdentityWinsWhenItOwnsTheContainers(): void
    {
        $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['legacy-folder' => 1]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertSame('legacy-folder', $stack->projectName);
        $this->assertSame(\ProjectIdentity::SOURCE_FOLDER_RUNTIME, $stack->identity->source);
        $this->assertTrue($stack->hasResolvedIdentity());
    }

    public function testBothCandidatesPresentFailsClosed(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers([
            'legacy-folder' => 1,
            'legacy_runtime' => 1,
        ]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertFalse($stack->hasResolvedIdentity());
        $this->assertSame(\ProjectIdentity::CONFLICT_AMBIGUOUS, $stack->identity->conflictReason);
        $this->assertNotNull($stack->getIdentityBlockReason());
        $this->assertFileDoesNotExist($path . '/' . \ProjectIdentity::METADATA_FILE);
    }

    public function testNeitherCandidatePresentUsesFolderIdentity(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['someone-else' => 4]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertSame('legacy-folder', $stack->projectName);
        $this->assertSame(\ProjectIdentity::SOURCE_UNUSED, $stack->identity->source);
        $this->assertTrue($stack->hasResolvedIdentity());
        $this->assertSame('legacy-folder', trim((string) file_get_contents($path . '/' . \ProjectIdentity::METADATA_FILE)));
    }

    public function testUnreachableDockerFailsClosed(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(static fn() => null);

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertFalse($stack->hasResolvedIdentity());
        $this->assertSame(\ProjectIdentity::CONFLICT_PROBE_FAILED, $stack->identity->conflictReason);
        $this->assertFileDoesNotExist($path . '/' . \ProjectIdentity::METADATA_FILE);
    }

    public function testMatchingCandidatesNeverProbeDocker(): void
    {
        $this->makeStack('mystack', 'mystack');
        \ProjectIdentity::setProbe(function (): array {
            $this->fail('Docker must not be probed when the candidates already agree');
        });

        $stack = \StackInfo::fromProject($this->tempRoot, 'mystack');

        $this->assertSame('mystack', $stack->projectName);
        $this->assertSame(\ProjectIdentity::SOURCE_CANONICAL, $stack->identity->source);
    }

    public function testPinnedIdentityIsReusedWithoutProbing(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        file_put_contents($path . '/' . \ProjectIdentity::METADATA_FILE, 'legacy_runtime');
        \ProjectIdentity::setProbe(function (): array {
            $this->fail('Docker must not be probed once the identity is pinned');
        });

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertSame('legacy_runtime', $stack->projectName);
        $this->assertSame(\ProjectIdentity::SOURCE_PERSISTED, $stack->identity->source);
        $this->assertTrue($stack->hasResolvedIdentity());
    }

    public function testResolutionIsPinnedSoLaterRenamesDoNotMoveIdentity(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['legacy_runtime' => 1]));
        \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        // Owner renames the stack in the WebGUI; identity must not follow.
        file_put_contents($path . '/name', 'Totally Different');
        \StackInfo::clearCache();
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['totally_different' => 9]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertSame('legacy_runtime', $stack->projectName);
        $this->assertSame('Totally Different', $stack->displayName);
    }

    public function testNewPlusStackPinsFolderDerivedIdentity(): void
    {
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['my-stack' => 1]));

        $stack = \StackInfo::createNew($this->tempRoot, 'My-Stack');

        $this->assertSame('my-stack', $stack->projectName);
        $this->assertTrue($stack->hasResolvedIdentity());
        $this->assertSame('my-stack', trim((string) file_get_contents($stack->path . '/' . \ProjectIdentity::METADATA_FILE)));
    }

    public function testCollisionSuffixedStackDoesNotAdoptExistingProjectIdentity(): void
    {
        $this->makeStack('my-stack', 'my-stack');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['my-stack' => 3]));

        $stack = \StackInfo::createNew($this->tempRoot, 'my-stack');

        $this->assertSame('my-stack-001', $stack->projectFolder);
        $this->assertSame('my-stack-001', $stack->projectName);
    }

    public function testOwnerCanPinAmbiguousIdentity(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers([
            'legacy-folder' => 1,
            'legacy_runtime' => 1,
        ]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');
        $this->assertFalse($stack->hasResolvedIdentity());

        $stack->applyIdentityChoice('legacy_runtime');

        $this->assertTrue($stack->hasResolvedIdentity());
        $this->assertSame('legacy_runtime', $stack->projectName);
        $this->assertSame('legacy_runtime', trim((string) file_get_contents($path . '/' . \ProjectIdentity::METADATA_FILE)));
    }

    public function testOwnerCannotPinAnArbitraryIdentity(): void
    {
        $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers([
            'legacy-folder' => 1,
            'legacy_runtime' => 1,
        ]));
        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->expectException(\RuntimeException::class);
        $stack->applyIdentityChoice('some-other-project');
    }

    public function testMutatingComposeArgsAreRefusedWhileUnresolved(): void
    {
        $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers([
            'legacy-folder' => 1,
            'legacy_runtime' => 1,
        ]));
        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->expectException(\RuntimeException::class);
        \ComposeCommandBuilder::buildForAction($stack, 'up');
    }

    public function testReadOnlyLogsAreAllowedWhileUnresolved(): void
    {
        $this->makeStack('legacy-folder', 'legacy_runtime');
        \ProjectIdentity::setProbe(fn(): array => $this->containers([
            'legacy-folder' => 1,
            'legacy_runtime' => 1,
        ]));
        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $args = \ComposeCommandBuilder::buildForAction($stack, 'logs');

        $this->assertSame('logs', $args['action']);
    }

    public function testProbeParsesDockerLabelOutputPerResourceType(): void
    {
        $output = implode("\n", [
            'containers com.docker.compose.project=legacy_runtime,com.docker.compose.service=web',
            'containers com.docker.compose.project=legacy_runtime,com.docker.compose.service=db',
            'volumes com.docker.compose.project=legacy_runtime,com.docker.compose.volume=data',
            'networks com.docker.compose.project=other-stack',
            'containers net.unraid.docker.managed=composeman',
        ]) . "\n__rc=0";

        $counts = \ProjectIdentity::parseProbeOutput($output);

        $this->assertSame(
            ['containers' => 2, 'volumes' => 1, 'networks' => 0],
            $counts['legacy_runtime']
        );
        $this->assertSame(
            ['containers' => 0, 'volumes' => 0, 'networks' => 1],
            $counts['other-stack']
        );
    }

    public function testProbeReturnsNullOnNonZeroExit(): void
    {
        $this->assertNull(\ProjectIdentity::parseProbeOutput("__rc=127"));
        $this->assertNull(\ProjectIdentity::parseProbeOutput(null));
    }
}
