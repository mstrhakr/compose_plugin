<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

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
        @unlink(COMPOSE_LEGACY_ORIGIN_FILE);
        $this->tempRoot = $this->createTempDir();
    }

    protected function tearDown(): void
    {
        \ProjectIdentity::setProbe(static fn(): array => []);
        @unlink(COMPOSE_LEGACY_ORIGIN_FILE);
        parent::tearDown();
    }

    /**
     * Create a legacy-style stack: folder name and `name` file deliberately differ.
     */
    private function makeStack(string $folder, ?string $name = null): string
    {
        $path = $this->tempRoot . '/' . $folder;
        mkdir($path, 0755, true);
        file_put_contents($path . '/docker-compose.yml', "services:\n");
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

    public static function archivedIdentityCases(): array
    {
        return [
            'issue 183' => ['moodle-ctgaia2', 'moodle-ctgaia2', 'moodle_ctgaia2'],
            'repeated separators' => ['a-b', 'a - b', 'a___b'],
            'existing underscores' => ['prod-api', 'Prod__API', 'prod__api'],
            'trailing separator' => ['stack', 'Stack-', 'stack_'],
            'original autostart first hyphen only' => ['a-b-c', 'A-B-C', 'a_b-c'],
            'original autostart word splitting' => ['my-stack', 'My Stack', 'my'],
            'preexisting Plus display identity' => ['folder', 'My-Stack', 'my-stack'],
        ];
    }

    #[DataProvider('archivedIdentityCases')]
    public function testArchivedRuntimeIdentitySurvivesMigrationAndReload(string $folder, string $name, string $runtime): void
    {
        $path = $this->makeStack($folder, $name);
        \ProjectIdentity::setProbe(fn(): array => $this->containers([$runtime => 2]));

        $stack = \StackInfo::fromProject($this->tempRoot, $folder);

        $this->assertTrue($stack->hasResolvedIdentity());
        $this->assertSame($runtime, $stack->projectName);
        $this->assertSame($runtime, file_get_contents($path . '/project_name'));
        $this->assertSame($name, file_get_contents($path . '/name'));
        \StackInfo::clearCache();
        $this->assertSame($runtime, \StackInfo::fromProject($this->tempRoot, $folder)->projectName);
    }

    public function testPinnedLegacySeparatorsAreNotRewritten(): void
    {
        $path = $this->makeStack('stack', 'Renamed Display');
        file_put_contents($path . '/project_name', 'stack___name--');

        $this->assertSame('stack___name--', \StackInfo::fromProject($this->tempRoot, 'stack')->projectName);
    }

    public function testOwnerCanChooseExactLegacyIdentity(): void
    {
        $this->makeStack('a-b', 'a - b');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['a-b' => 1, 'a___b' => 1]));
        $stack = \StackInfo::fromProject($this->tempRoot, 'a-b');

        $this->assertFalse($stack->hasResolvedIdentity());
        $stack->applyIdentityChoice('a___b');

        $this->assertSame('a___b', $stack->projectName);
    }

    public function testHistoricalAutostartConflictFailsClosed(): void
    {
        $path = $this->makeStack('a-b-c', 'A-B-C');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['a_b_c' => 1, 'a_b-c' => 1]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'a-b-c');

        $this->assertFalse($stack->hasResolvedIdentity());
        $this->assertSame(\ProjectIdentity::CONFLICT_AMBIGUOUS, $stack->identity->conflictReason);
        $this->assertFileDoesNotExist($path . '/project_name');
    }

    public function testConfirmedImportKeepsLegacyIdentityWithoutLabelledResources(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack('moodle-ctgaia2', 'moodle-ctgaia2');

        $stack = \StackInfo::fromProject($this->tempRoot, 'moodle-ctgaia2');

        $this->assertSame('moodle_ctgaia2', $stack->projectName);
        $state = json_decode(file_get_contents($path . '/' . \ProjectIdentity::STATE_FILE), true);
        $this->assertSame('dcflachs', $state['origin']);
        $this->assertSame(\ProjectIdentity::LEGACY_REVISION, $state['legacyRevision']);
    }

    public function testNewPlusStackNeverAdoptsLegacyResourcesEvenOnImportedInstallation(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        \ProjectIdentity::setProbe(function (): array {
            $this->fail('A new Plus stack must not probe or adopt an imported identity');
        });

        $stack = \StackInfo::createNew($this->tempRoot, 'New-Stack');

        $this->assertSame('new-stack', $stack->projectName);
        $this->assertSame('plus', $stack->identity->origin);
    }

    public function testIncorrectOldPinRequiresExplicitRecoveryAndKeepsBackup(): void
    {
        $path = $this->makeStack('moodle-ctgaia2', 'moodle-ctgaia2');
        file_put_contents($path . '/project_name', 'moodle-ctgaia2');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['moodle_ctgaia2' => 3]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'moodle-ctgaia2');

        $this->assertTrue($stack->hasResolvedIdentity());
        $this->assertSame('plus', $stack->identity->origin);
        $this->assertSame('moodle-ctgaia2', file_get_contents($path . '/project_name'));
        $stack->applyIdentityChoice('moodle_ctgaia2');
        $this->assertSame('moodle-ctgaia2', file_get_contents($path . '/project_name.pre-migration'));
        $this->assertSame('moodle_ctgaia2', file_get_contents($path . '/project_name'));
        $state = json_decode(file_get_contents($path . '/' . \ProjectIdentity::STATE_FILE), true);
        $this->assertSame('owner', $state['source']);
    }

    public function testInvalidPinBlocksRatherThanChangingIdentity(): void
    {
        $path = $this->makeStack('stack', 'stack');
        file_put_contents($path . '/project_name', '../Other Stack');

        $stack = \StackInfo::fromProject($this->tempRoot, 'stack');

        $this->assertFalse($stack->hasResolvedIdentity());
        $this->assertSame(\ProjectIdentity::CONFLICT_INVALID_PIN, $stack->identity->conflictReason);
        $this->assertSame('../Other Stack', file_get_contents($path . '/project_name'));
    }

    public function testUnversionedStablePinDoesNotRequireDocker(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        file_put_contents($path . '/project_name', 'legacy-folder');
        \ProjectIdentity::setProbe(static fn() => null);

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertTrue($stack->hasResolvedIdentity());
        $this->assertSame('legacy-folder', $stack->projectName);
        $this->assertSame('plus', $stack->identity->origin);
        $this->assertFileDoesNotExist($path . '/' . \ProjectIdentity::STATE_FILE);
        $this->expectException(\RuntimeException::class);
        $stack->applyIdentityChoice('legacy_runtime');
    }

    public function testMismatchedReceiptDoesNotReauditExistingStablePin(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        file_put_contents($path . '/project_name', 'legacy-folder');
        file_put_contents($path . '/' . \ProjectIdentity::STATE_FILE, '{"version":1,"projectName":"legacy_runtime"}');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['legacy_runtime' => 1]));

        $stack = \StackInfo::fromProject($this->tempRoot, 'legacy-folder');

        $this->assertTrue($stack->hasResolvedIdentity());
        $this->assertSame('legacy-folder', $stack->projectName);
        $this->assertSame('plus', $stack->identity->origin);
    }

    public static function stableUpgradeCases(): array
    {
        return [['prod-api', false], ['prod__api--', false], ['prod__api--', true]];
    }

    #[DataProvider('stableUpgradeCases')]
    public function testStableUpgradePreservesValidPinWithoutProbesOrMetadataWrites(string $runtime, bool $staleLegacyMarker): void
    {
        $path = $this->makeStack('folder', 'Renamed Display');
        file_put_contents($path . '/project_name', $runtime);
        file_put_contents($path . '/extra_compose_files', 'compose.extra.yaml');
        file_put_contents($path . '/compose.extra.yaml', "services: {}\n");
        file_put_contents($path . '/default_profile', 'worker');
        if ($staleLegacyMarker) {
            file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        }
        \ProjectIdentity::setProbe(function (): array {
            $this->fail('A normal Plus upgrade must not query migration evidence');
        });

        foreach (['up', 'down', 'stop', 'pull'] as $action) {
            \StackInfo::clearCache();
            $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'folder', $action);
            $this->assertSame($runtime, $spec['projectName']);
            $this->assertSame([$path . '/docker-compose.yml', $path . '/compose.extra.yaml'], $spec['composeFiles']);
        }
        $this->assertSame($runtime, file_get_contents($path . '/project_name'));
        $this->assertSame('Renamed Display', file_get_contents($path . '/name'));
        $this->assertSame('worker', file_get_contents($path . '/default_profile'));
        $this->assertFileDoesNotExist($path . '/' . \ProjectIdentity::STATE_FILE);
        $this->assertFileDoesNotExist($path . '/project_identity.lock');
    }

    public function testFailedReceiptWriteBlocksMutations(): void
    {
        $path = $this->makeStack('stack', 'stack');
        mkdir($path . '/' . \ProjectIdentity::STATE_FILE);

        $stack = \StackInfo::fromProject($this->tempRoot, 'stack');

        $this->assertFalse($stack->hasResolvedIdentity());
        $this->assertSame(\ProjectIdentity::CONFLICT_PERSIST_FAILED, $stack->identity->conflictReason);
        $this->expectException(\RuntimeException::class);
        \ComposeCommandBuilder::buildForAction($stack, 'up');
    }

    public function testHyphenatedImportIsPreservedWhenOnlyNetworksRemain(): void
    {
        $this->makeStack('my-stack', 'my-stack');
        \ProjectIdentity::setProbe(static fn(): array => [
            'my_stack' => ['containers' => 0, 'volumes' => 0, 'networks' => 1],
        ]);

        $this->assertSame('my_stack', \StackInfo::fromProject($this->tempRoot, 'my-stack')->projectName);
    }

    public function testConfirmedIndirectImportPreservesFilesEnvProfilesAndSourceContents(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack('legacy-stack', 'Legacy-Stack');
        $source = $this->tempRoot . '/external compose';
        mkdir($source);
        $contents = [
            $source . '/docker-compose.yml' => "services:\n  app:\n    image: alpine:3.20\n    volumes: [./data:/data]\n",
            $source . '/docker-compose.extra.yml' => "services:\n  app:\n    environment: [EXTRA=true]\n",
            $source . '/docker-compose.override.yml' => "services:\n  app:\n    environment: [INDIRECT=true]\n",
            $path . '/docker-compose.override.yml' => "services:\n  app:\n    environment: [LOCAL=true]\n",
            $source . '/custom.env' => "TOKEN=unchanged\n",
            $path . '/indirect' => $source,
            $path . '/envpath' => $source . '/custom.env',
            $path . '/profiles' => '["worker"]',
            $path . '/autostart' => 'true',
            $path . '/description' => 'Original description',
        ];
        foreach ($contents as $file => $content) {
            file_put_contents($file, $content);
        }
        $ordered = [
            $source . '/docker-compose.yml',
            $source . '/docker-compose.extra.yml',
            $source . '/docker-compose.override.yml',
            $path . '/docker-compose.override.yml',
        ];
        \ProjectIdentity::setProbe(fn(): ?array => \ProjectIdentity::parseProbeOutput($this->configEvidence($ordered) . "\n__rc=0"));

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');

        $this->assertSame('legacy_stack', $spec['projectName']);
        $this->assertSame($source . '/custom.env', $spec['envFilePath']);
        $this->assertSame([], $spec['profiles']);
        $this->assertSame(['worker'], \StackInfo::fromProject($this->tempRoot, 'legacy-stack')->getProfiles());
        $this->assertCount(4, $spec['composeFiles']);
        $this->assertSame($path . '/docker-compose.override.yml', $spec['composeFiles'][3]);
        $this->assertContains($source . '/docker-compose.extra.yml', $spec['composeFiles']);
        $this->assertContains($source . '/docker-compose.override.yml', $spec['composeFiles']);
        foreach ($contents as $file => $content) {
            $this->assertSame($content, file_get_contents($file));
        }
    }

    public function testConfirmedDirectImportKeepsOriginalFileWhenModernAlternativeExists(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack('stack', 'stack');
        file_put_contents($path . '/docker-compose.yml', "services: {}\n");
        file_put_contents($path . '/compose.yaml', "services: {}\n");

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'stack', 'up');

        $this->assertSame([$path . '/docker-compose.yml'], $spec['composeFiles']);
    }

    private function makeOrderedIndirectImport(): array
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack('legacy-stack', 'legacy-stack');
        $source = $this->createTempDir() . '/external';
        mkdir($source);
        $files = [$source . '/docker-compose.yml', $source . '/docker-compose.extra.yml'];
        foreach ($files as $file) {
            file_put_contents($file, "services: {}\n");
        }
        file_put_contents($path . '/indirect', $source);
        return [$path, $files];
    }

    private function configEvidence(array $files): string
    {
        return 'containers ' . json_encode([
            'project' => 'legacy_stack',
            'workingDir' => dirname($files[0]),
            'configFiles' => implode(',', $files),
        ]);
    }

    public function testRuntimeComposeOrderIsPinnedAcrossReloadWithoutDocker(): void
    {
        [$path, $files] = $this->makeOrderedIndirectImport();
        $files = array_reverse($files);
        \ProjectIdentity::setProbe(fn(): ?array => \ProjectIdentity::parseProbeOutput($this->configEvidence($files) . "\n__rc=0"));

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');

        $this->assertSame($files, $spec['composeFiles']);
        $state = json_decode(file_get_contents($path . '/' . \ProjectIdentity::STATE_FILE), true);
        $this->assertSame($files, $state['composeFiles']);
        $this->assertSame('runtime-labels', $state['composeFilesSource']);
        \StackInfo::clearCache();
        \ProjectIdentity::setProbe(function (): array {
            $this->fail('Persisted file order must not depend on a later Docker probe');
        });
        $this->assertSame($files, \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up')['composeFiles']);
    }

    public function testConflictingRuntimeComposeOrdersBlockMutation(): void
    {
        [$path, $files] = $this->makeOrderedIndirectImport();
        \ProjectIdentity::setProbe(fn(): ?array => \ProjectIdentity::parseProbeOutput(
            $this->configEvidence($files) . "\n" . $this->configEvidence(array_reverse($files)) . "\n__rc=0"
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conflicting runtime Compose file lists');
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');
    }

    public function testMissingPinnedComposeInputCannotBeSilentlyDropped(): void
    {
        [$path, $files] = $this->makeOrderedIndirectImport();
        \ProjectIdentity::setProbe(fn(): ?array => \ProjectIdentity::parseProbeOutput($this->configEvidence($files) . "\n__rc=0"));
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');
        unlink($files[1]);
        \StackInfo::clearCache();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Migrated Compose input is unavailable');
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');
    }

    public function testLiveMultifileImportWithoutOrderEvidenceIsBlocked(): void
    {
        $this->makeOrderedIndirectImport();
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['legacy_stack' => 1]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Historical Compose file order cannot be verified');
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');
    }

    public function testPartialRuntimeFileEvidenceCannotProveOrder(): void
    {
        [$path, $files] = $this->makeOrderedIndirectImport();
        \ProjectIdentity::setProbe(fn(): ?array => \ProjectIdentity::parseProbeOutput(
            $this->configEvidence($files) . "\ncontainers {\"project\":\"legacy_stack\"}\n__rc=0"
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Historical Compose file order cannot be verified');
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');
    }

    public function testPinnedOrderDoesNotAdoptNewlyDiscoveredFiles(): void
    {
        [$path, $files] = $this->makeOrderedIndirectImport();
        \ProjectIdentity::setProbe(fn(): ?array => \ProjectIdentity::parseProbeOutput($this->configEvidence($files) . "\n__rc=0"));
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');
        file_put_contents(dirname($files[0]) . '/docker-compose.new.yml', "services: {}\n");
        \StackInfo::clearCache();

        $this->assertSame($files, \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up')['composeFiles']);
    }

    public function testConcurrentMigrationReadersLeaveCompleteReceipt(): void
    {
        [$path, $files] = $this->makeOrderedIndirectImport();
        $script = $this->createTempDir() . '/resolve.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true)
            . '; require_once "/usr/local/emhttp/plugins/compose.manager/include/ComposeCommandBuilder.php"; '
            . 'echo json_encode(ComposeCommandBuilder::fromProject(' . var_export($this->tempRoot, true) . ', "legacy-stack", "up"));');
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $process = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        foreach ($children as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $this->assertSame($files, json_decode($output, true)['composeFiles']);
        }
        $state = json_decode(file_get_contents($path . '/' . \ProjectIdentity::STATE_FILE), true);
        $this->assertSame('legacy_stack', $state['projectName']);
        $this->assertSame($files, $state['composeFiles']);
        $this->assertSame('legacy_stack', file_get_contents($path . '/project_name'));
        $this->assertSame([], glob($path . '/.project-name-*'));
    }

    public function testSharedOwnershipRegistrySerializesCompetingOwnerChoices(): void
    {
        $first = $this->makeStack('one', 'shared');
        $second = $this->makeStack('two', 'shared');
        \ProjectIdentity::initializeNew($first, 'one');
        \ProjectIdentity::initializeNew($second, 'two');
        $script = $this->createTempDir() . '/choose.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true)
            . '; try { $identity = ProjectIdentity::resolve($argv[1], basename($argv[1]), "shared"); '
            . '$identity->chooseIdentity($argv[1], "shared"); echo "chosen"; } catch (Throwable $error) { echo "blocked"; }');
        $registry = fopen($this->tempRoot . '/' . \ProjectIdentity::REGISTRY_LOCK_FILE, 'c');
        flock($registry, LOCK_EX);
        $children = [];
        try {
            foreach ([$first, $second] as $path) {
                $process = proc_open([PHP_BINARY, $script, $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $children[] = [$process, $pipes];
            }
            $read = [$children[0][1][1], $children[1][1][1]];
            $write = null;
            $except = null;
            $this->assertSame(0, stream_select($read, $write, $except, 0, 300000));
        } finally {
            flock($registry, LOCK_UN);
            fclose($registry);
        }
        $outcomes = [];
        foreach ($children as [$process, $pipes]) {
            $outcomes[] = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
        }
        sort($outcomes);
        $this->assertSame(['blocked', 'chosen'], $outcomes);
        $this->assertSame(1, count(array_filter([$first, $second], static fn(string $path): bool => file_get_contents($path . '/project_name') === 'shared')));
    }

    public static function migrationEvents(): array
    {
        return [
            'autostart' => ['docker_started', false],
            'shutdown' => ['stopping_docker', false],
            'blocked autostart' => ['docker_started', true],
            'blocked shutdown' => ['stopping_docker', true],
        ];
    }

    #[DataProvider('migrationEvents')]
    public function testActualEventHandlersPreserveMigratedCommandArguments(string $event, bool $blocked): void
    {
        [$path, $files] = $this->makeOrderedIndirectImport();
        $files = array_reverse($files);
        \ProjectIdentity::setProbe(fn(): ?array => \ProjectIdentity::parseProbeOutput($this->configEvidence($files) . "\n__rc=0"));
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'legacy-stack', 'up');
        file_put_contents($path . '/autostart', 'true');
        file_put_contents($path . '/default_profile', 'worker');
        $envFile = $path . '/custom env';
        file_put_contents($envFile, "TOKEN=original\n");
        file_put_contents($path . '/envpath', $envFile);
        if ($blocked) {
            file_put_contents($path . '/project_name', '../invalid');
        }

        $repository = dirname(__DIR__, 2);
        $runtime = $this->createTempDir();
        mkdir($runtime . '/scripts');
        mkdir($runtime . '/bin');
        $marker = $runtime . '/commands.jsonl';
        $recorder = "#!/usr/bin/env php\n<?php\nif ((\$argv[1] ?? '') === 'info') exit(0);\nfile_put_contents(" . var_export($marker, true)
            . ", json_encode(array_slice(\$argv, 1)) . PHP_EOL, FILE_APPEND);\n";
        foreach ([$runtime . '/scripts/compose.sh', $runtime . '/bin/docker'] as $binary) {
            file_put_contents($binary, $recorder);
            chmod($binary, 0755);
        }
        file_put_contents($runtime . '/bin/logger', "#!/bin/sh\nexit 0\n");
        chmod($runtime . '/bin/logger', 0755);
        $phpWrapper = $runtime . '/bin/test-php';
        file_put_contents($phpWrapper, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file='
            . escapeshellarg($repository . '/tests/bootstrap.php') . ' "$@"' . "\n");
        chmod($phpWrapper, 0755);
        copy($repository . '/source/compose.manager/scripts/compose_args.php', $runtime . '/scripts/compose_args.php');
        $common = file_get_contents($repository . '/source/compose.manager/scripts/common.sh');
        file_put_contents($runtime . '/scripts/common.sh', str_replace('/usr/local/emhttp/plugins/compose.manager', $runtime, $common));
        file_put_contents($runtime . '/default.cfg', 'PROJECTS_FOLDER=' . escapeshellarg($this->tempRoot) . "\nAUTOSTART_DOCKER_WAIT_TIMEOUT=1\n");
        file_put_contents($runtime . '/config.cfg', '');
        $eventSource = file_get_contents($repository . '/source/compose.manager/event/' . $event);
        $eventSource = str_replace(
            ['/usr/local/emhttp/plugins/compose.manager', '/boot/config/plugins/compose.manager/compose.manager.cfg', '/var/log/compose.manager.log'],
            [$runtime, $runtime . '/config.cfg', $runtime . '/events.log'],
            $eventSource
        );
        file_put_contents($runtime . '/event', $eventSource);
        $environment = array_merge(getenv(), [
            'PATH' => $runtime . '/bin:' . getenv('PATH'),
            'COMPOSE_MANAGER_PHP' => $phpWrapper,
            'COMPOSE_MANAGER_AUTOSTART_CHILD' => '1',
        ]);
        $process = proc_open(['bash', $runtime . '/event'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($blocked) {
            $this->assertFileDoesNotExist($marker);
            $this->assertStringContainsString('skipping', file_get_contents($runtime . '/events.log'));
            return;
        }
        $this->assertSame(0, $status, $output);
        $this->assertFileExists($marker);
        $commands = array_values(array_filter(explode("\n", trim(file_get_contents($marker)))));
        $this->assertCount(1, $commands);
        $arguments = json_decode($commands[0], true);
        $this->assertSame('legacy_stack', $arguments[array_search('-p', $arguments, true) + 1]);
        $actualFiles = [];
        foreach ($arguments as $index => $argument) {
            if ($argument === '-f') {
                $actualFiles[] = $arguments[$index + 1];
            }
        }
        $this->assertSame($files, $actualFiles);
        $envOption = $event === 'docker_started' ? '-e' : '--env-file';
        $this->assertSame($envFile, $arguments[array_search($envOption, $arguments, true) + 1]);
        if ($event === 'docker_started') {
            $this->assertSame('worker', $arguments[array_search('-g', $arguments, true) + 1]);
        } else {
            $this->assertSame('stop', $arguments[count($arguments) - 1]);
        }
    }

    public function testConfirmedImportNeverFallsBackFromMissingExplicitEnv(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack('stack', 'stack');
        file_put_contents($path . '/envpath', '/missing/legacy.env');
        file_put_contents($path . '/.env', "DATABASE=different\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('env file is unavailable');
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'stack', 'up');
    }

    public static function olderEnvMetadataCases(): array
    {
        return [['envToUse'], ['envPath']];
    }

    #[DataProvider('olderEnvMetadataCases')]
    public function testOlderArchivedMetadataIsPreservedWithoutRenamingInputs(string $envMetadata): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack('old-stack');
        unlink($path . '/docker-compose.yml');
        file_put_contents($path . '/compose.yml', "services: {}\n");
        file_put_contents($path . '/Name', 'Old-Stack');
        $env = $path . '/original.env';
        file_put_contents($env, "TOKEN=original\n");
        file_put_contents($path . '/' . $envMetadata, $env);

        $spec = \ComposeCommandBuilder::fromProject($this->tempRoot, 'old-stack', 'up');

        $this->assertSame('old_stack', $spec['projectName']);
        $this->assertSame([$path . '/compose.yml'], $spec['composeFiles']);
        $this->assertSame($env, $spec['envFilePath']);
        $this->assertSame('Old-Stack', \StackInfo::fromProject($this->tempRoot, 'old-stack')->displayName);
        $this->assertSame('Old-Stack', file_get_contents($path . '/Name'));
        $this->assertSame($env, file_get_contents($path . '/' . $envMetadata));
        $this->assertFileDoesNotExist($path . '/docker-compose.yml');
    }

    public function testCurrentEnvMetadataOverridesOlderEnvToUse(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack('stack', 'stack');
        file_put_contents($path . '/current.env', "TOKEN=current\n");
        file_put_contents($path . '/envpath', $path . '/current.env');
        file_put_contents($path . '/envPath', '/missing/intermediate.env');
        file_put_contents($path . '/envToUse', '/missing/obsolete.env');

        $this->assertSame($path . '/current.env', \ComposeCommandBuilder::fromProject($this->tempRoot, 'stack', 'up')['envFilePath']);
    }

    public function testConfirmedImportNeverFallsBackFromInvalidIndirect(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack('stack', 'stack');
        file_put_contents($path . '/indirect', '../invalid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compose source is unavailable');
        \ComposeCommandBuilder::fromProject($this->tempRoot, 'stack', 'up');
    }

    public function testTwoImportedFoldersCannotAdoptSameRuntimeProject(): void
    {
        $first = $this->makeStack('first-folder', 'Shared-Project');
        $second = $this->makeStack('second-folder', 'Shared-Project');
        \ProjectIdentity::setProbe(fn(): array => $this->containers(['shared_project' => 1]));

        foreach (['first-folder', 'second-folder'] as $folder) {
            $stack = \StackInfo::fromProject($this->tempRoot, $folder);
            $this->assertFalse($stack->hasResolvedIdentity());
            $this->assertSame(\ProjectIdentity::CONFLICT_SHARED_IDENTITY, $stack->identity->conflictReason);
        }
        $this->assertFileDoesNotExist($first . '/project_name');
        $this->assertFileDoesNotExist($second . '/project_name');
    }

    public function testMatchingNameWithForeignDirectoryCannotBeAdoptedAutomatically(): void
    {
        $path = $this->makeStack('my-stack', 'my-stack');
        \ProjectIdentity::setProbe(static fn(): ?array => \ProjectIdentity::parseProbeOutput(
            'containers {"project":"my_stack","workingDir":"/another/compose/directory"}' . "\n__rc=0"
        ));

        $stack = \StackInfo::fromProject($this->tempRoot, 'my-stack');

        $this->assertFalse($stack->hasResolvedIdentity());
        $this->assertSame(\ProjectIdentity::CONFLICT_DIRECTORY_MISMATCH, $stack->identity->conflictReason);
        $this->assertFileDoesNotExist($path . '/project_name');
    }

    public static function mutatingActions(): array
    {
        return array_map(static fn(string $action): array => [$action], ['up', 'down', 'update', 'pull', 'stop']);
    }

    #[DataProvider('mutatingActions')]
    public function testEveryMutatingActionBlocksUnresolvedMigration(string $action): void
    {
        $this->makeStack('my-stack', 'my-stack');
        \ProjectIdentity::setProbe(static fn() => null);
        $stack = \StackInfo::fromProject($this->tempRoot, 'my-stack');

        $this->expectException(\RuntimeException::class);
        \ComposeCommandBuilder::buildForAction($stack, $action);
    }

    public static function dockerMigrationCases(): array
    {
        return [['running', false], ['stopped', false], ['volume-only', false], ['named-container-and-bind', true]];
    }

    #[Group('migration-docker')]
    #[DataProvider('dockerMigrationCases')]
    public function testIsolatedDockerMigrationPreservesContainersAndVolumeData(string $mode, bool $withBind): void
    {
        if (getenv('COMPOSE_MIGRATION_DOCKER_TESTS') !== '1') {
            $this->markTestSkipped('Set COMPOSE_MIGRATION_DOCKER_TESTS=1 for isolated Docker migration validation.');
        }
        $suffix = bin2hex(random_bytes(6));
        $folder = 'cm183-' . $suffix;
        $runtime = 'cm183___' . $suffix;
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->makeStack($folder, $runtime);
        $files = [$path . '/docker-compose.yml'];
        if ($mode !== 'volume-only') {
            file_put_contents($path . '/docker-compose.override.yml', "services:\n  app:\n    environment: [MIGRATION_ORDER=override]\n");
            $files = [$path . '/docker-compose.override.yml', ...$files];
        }
        $compose = ['compose'];
        foreach ($files as $file) {
            $compose = [...$compose, '-f', $file];
        }
        $compose = [...$compose, '-p', $runtime];
        $bindVolume = null;
        try {
            $mounts = ['data:/data'];
            if ($withBind) {
                $bindVolume = 'cm183_bind_' . $suffix;
                $this->dockerCommand(['volume', 'create', $bindVolume]);
                $mounts[] = trim($this->dockerCommand(['volume', 'inspect', '--format', '{{.Mountpoint}}', $bindVolume])) . ':/bind';
            }
            $containerName = $withBind ? '    container_name: cm183_named_' . $suffix . "\n" : '';
            file_put_contents($path . '/docker-compose.yml', "services:\n  app:\n    image: alpine:3.20\n" . $containerName
                . "    command: [sleep, '300']\n    environment: [MIGRATION_ORDER=historical]\n    volumes: " . json_encode($mounts, JSON_UNESCAPED_SLASHES)
                . "\nvolumes:\n  data: {}\n");
            $this->dockerCommand([...$compose, 'up', '-d']);
            $container = trim($this->dockerCommand([...$compose, 'ps', '-q']));
            $this->assertNotSame('', $container);
            $this->dockerCommand([...$compose, 'exec', '-T', 'app', 'sh', '-c', 'printf preserved > /data/migration-sentinel']);
            if ($withBind) {
                $this->dockerCommand([...$compose, 'exec', '-T', 'app', 'sh', '-c', 'printf bind-preserved > /bind/migration-sentinel']);
            }
            if ($mode === 'stopped') {
                $this->dockerCommand([...$compose, 'stop']);
            } elseif ($mode === 'volume-only') {
                $this->dockerCommand([...$compose, 'down']);
            }
            \ProjectIdentity::setProbe(null);

            $stack = \StackInfo::fromProject($this->tempRoot, $folder);
            $spec = \ComposeCommandBuilder::buildForAction($stack, 'up');

            $this->assertSame($runtime, $spec['projectName']);
            $this->assertSame($files, $spec['composeFiles']);
            if ($mode !== 'volume-only') {
                $this->assertSame($container, trim($this->dockerCommand([...$compose, 'ps', '-a', '-q'])));
                $this->assertSame($mode === 'stopped' ? 'false' : 'true', trim($this->dockerCommand(['inspect', '--format', '{{.State.Running}}', $container])));
            }
            $migrated = ['compose'];
            foreach ($spec['composeFiles'] as $file) {
                $migrated = [...$migrated, '-f', $file];
            }
            $migrated = [...$migrated, '-p', $spec['projectName']];
            $this->dockerCommand([...$migrated, 'up', '-d']);
            if ($mode !== 'volume-only') {
                $this->assertSame($container, trim($this->dockerCommand([...$migrated, 'ps', '-q'])));
            }
            $this->assertSame('preserved', $this->dockerCommand([...$migrated, 'exec', '-T', 'app', 'cat', '/data/migration-sentinel']));
            $this->assertSame('historical', $this->dockerCommand([...$migrated, 'exec', '-T', 'app', 'printenv', 'MIGRATION_ORDER']));
            if ($withBind) {
                $this->assertSame('bind-preserved', $this->dockerCommand([...$migrated, 'exec', '-T', 'app', 'cat', '/bind/migration-sentinel']));
            }

            $this->dockerCommand([...$migrated, 'down']);
            \StackInfo::clearCache();
            $this->assertSame($runtime, \StackInfo::fromProject($this->tempRoot, $folder)->projectName);
            $this->dockerCommand([...$migrated, 'up', '-d']);
            $this->assertSame('preserved', $this->dockerCommand([...$migrated, 'exec', '-T', 'app', 'cat', '/data/migration-sentinel']));
        } finally {
            try {
                $this->dockerCommand([...$compose, 'down', '-v', '--remove-orphans']);
            } finally {
                if ($bindVolume !== null) {
                    $this->dockerCommand(['volume', 'rm', $bindVolume]);
                }
            }
        }
    }

    private function dockerCommand(array $arguments): string
    {
        $output = [];
        $status = 0;
        exec('docker ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        return implode("\n", $output);
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

    public function testAuditedPinnedIdentityIsReusedWithoutProbing(): void
    {
        $path = $this->makeStack('legacy-folder', 'legacy_runtime');
        file_put_contents($path . '/' . \ProjectIdentity::METADATA_FILE, 'legacy_runtime');
        \StackInfo::fromProject($this->tempRoot, 'legacy-folder');
        \StackInfo::clearCache();
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
