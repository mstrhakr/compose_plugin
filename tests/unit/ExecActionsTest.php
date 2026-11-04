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
        @unlink(COMPOSE_LEGACY_ORIGIN_FILE);
        
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
        @unlink(COMPOSE_CREDENTIAL_VAULT_FILE);
        @unlink(COMPOSE_CREDENTIAL_KEY_FILE);
    }

    protected function tearDown(): void
    {
        @unlink(COMPOSE_LEGACY_ORIGIN_FILE);
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
        global $docker_label_webui, $docker_label_icon, $docker_label_shell;
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

    public function testUpdateEnrichmentCachesOnlyMetadataWithoutLosingRuntimeFields(): void
    {
        $this->executeAction('getPersistentContainerCache');
        $cache = ['db' => [
            'name' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:18',
            'state' => 'running',
            'icon' => '/existing-icon.png',
            'hasUpdate' => true,
            'updateStatus' => 'update-available',
            'localSha' => 'old-local',
            'remoteSha' => 'old-remote',
        ]];
        \composeCacheContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:18',
            'status' => 'up-to-date',
            'hasUpdate' => false,
            'localSha' => 'new',
            'remoteSha' => 'new',
        ]));

        $this->assertArrayNotHasKey('hasUpdate', $cache['db']);
        $this->assertArrayNotHasKey('localSha', $cache['db']);
        $this->assertSame('running', $cache['db']['state']);
        $this->assertSame('/existing-icon.png', $cache['db']['icon']);

        \composeCacheContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:18',
            'status' => 'unknown',
        ]));
        $this->assertArrayNotHasKey('updateStatus', $cache['db']);
        $this->assertArrayNotHasKey('remoteSha', $cache['db']);

        \composeCacheContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:19',
            'status' => 'unknown',
        ]));
        $this->assertArrayNotHasKey('updateStatus', $cache['db']);
        $this->assertArrayNotHasKey('localSha', $cache['db']);
    }

    public function testMetadataCacheNeverRetainsOldEvidenceAcrossImageChanges(): void
    {
        $this->executeAction('getPersistentContainerCache');
        $cache = ['db' => [
            'name' => 'stack-db-1',
            'service' => 'db',
            'image' => 'docker.io/library/postgres:18',
            'hasUpdate' => true,
            'updateStatus' => 'update-available',
            'localSha' => 'old-local',
            'remoteSha' => 'old-remote',
        ]];

        \composeCacheContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:18',
            'status' => 'unknown',
        ]));

        $this->assertArrayNotHasKey('updateStatus', $cache['db']);
        $this->assertArrayNotHasKey('localSha', $cache['db']);
        $this->assertArrayNotHasKey('remoteSha', $cache['db']);

        $cache['db']['image'] = 'postgres:18@sha256:' . str_repeat('a', 64);
        \composeCacheContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:18@sha256:' . str_repeat('b', 64),
            'status' => 'unknown',
        ]));

        $this->assertArrayNotHasKey('updateStatus', $cache['db']);
        $this->assertArrayNotHasKey('localSha', $cache['db']);
        $this->assertArrayNotHasKey('remoteSha', $cache['db']);
    }

    public function testLegacyMetadataCacheEvidenceIsNotReturnedAndIsRemovedOnSave(): void
    {
        file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, json_encode(['stack' => ['app' => [
            'name' => 'app-1', 'service' => 'app', 'image' => 'app:latest',
            'state' => 'running', 'icon' => '/existing.png',
            'hasUpdate' => true, 'updateStatus' => 'update-available', 'updateReadiness' => 'ready-to-apply',
        ]]]));
        try {
            $response = json_decode($this->executeAction('getPersistentContainerCache'), true);
            $container = $response['cache']['stack']['app'];
            $this->assertSame('running', $container['state']);
            $this->assertSame('/existing.png', $container['icon']);
            $this->assertArrayNotHasKey('hasUpdate', $container);
            $this->assertArrayNotHasKey('updateReadiness', $container);
            \composeSavePersistentContainerCache($response['cache']);
            $saved = json_decode(file_get_contents(COMPOSE_CONTAINER_CACHE_FILE), true);
            $this->assertArrayNotHasKey('hasUpdate', $saved['stack']['app']);
        } finally {
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    public function testComposeServiceCanBeResolvedFromDockerLabels(): void
    {
        $this->executeAction('getPersistentContainerCache');

        $this->assertSame('db', \ContainerInfo::resolveService([
            'Names' => 'stack-db-1',
            'Labels' => 'com.docker.compose.project=stack,com.docker.compose.service=db',
        ]));
        $this->assertSame('web', \ContainerInfo::resolveService([
            'Service' => 'web',
            'Labels' => 'com.docker.compose.service=other',
        ]));
        $this->assertSame('backup', \ContainerInfo::resolveService([
            'Labels' => ['com.docker.compose.service' => 'backup'],
        ]));
    }

    public function testPersistentCacheDoesNotCarryUpdateResultsBetweenReplicas(): void
    {
        $this->executeAction('getPersistentContainerCache');
        $cache = ['db' => [
            'name' => 'stack-db-1',
            'image' => 'postgres:18',
            'updateStatus' => 'up-to-date',
            'remoteSha' => 'new',
        ]];
        \composeCacheContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-2',
            'service' => 'db',
            'image' => 'postgres:18',
            'status' => 'unknown',
        ]));
        $this->assertArrayNotHasKey('updateStatus', $cache['db']);
        $this->assertArrayNotHasKey('remoteSha', $cache['db']);
    }

    public function testClearUpdateCacheRemovesUpdateFieldsButPreservesContainerMetadata(): void
    {
        file_put_contents(COMPOSE_UPDATE_STATUS_FILE, '{"stack":{"hasUpdate":true}}');
        file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, json_encode(['stack' => ['db' => [
            'name' => 'stack-db-1',
            'image' => 'postgres:18',
            'icon' => '/existing-icon.png',
            'state' => 'running',
            'hasUpdate' => true,
            'updateStatus' => 'update-available',
            'localSha' => 'old',
            'remoteSha' => 'new',
        ]]]));
        try {
            $response = json_decode($this->executeAction('clearUpdateCache'), true);

            $this->assertSame('success', $response['result']);
            $this->assertSame([], \StackUpdateService::readSavedResults());
            $cached = json_decode(file_get_contents(COMPOSE_CONTAINER_CACHE_FILE), true)['stack']['db'];
            $this->assertSame('stack-db-1', $cached['name']);
            $this->assertSame('running', $cached['state']);
            $this->assertSame('/existing-icon.png', $cached['icon']);
            foreach (['hasUpdate', 'updateStatus', 'localSha', 'remoteSha'] as $field) {
                $this->assertArrayNotHasKey($field, $cached);
            }
        } finally {
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    public static function checkedContainerStates(): array
    {
        return array_map(static fn(string $state): array => [$state], [
            'running', 'exited', 'paused', 'restarting', 'created',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('checkedContainerStates')]
    public function testSingleAndAllStackChecksUseAttachedRegistryCredentials(string $state): void
    {
        $this->createTestStack('private-stack', ['credential_id' => 'missing-private-credential']);
        $dockerDir = $this->createTempDir();
        $docker = $dockerDir . '/docker';
        $row = [
            'Name' => 'private-app', 'Names' => 'private-app', 'Service' => 'app',
            'Image' => 'ghcr.io/org/private:latest', 'State' => 'running',
            'Labels' => 'com.docker.compose.project=private-stack,com.docker.compose.service=app',
        ];
        $stopped = [
            'Names' => 'private-backup', 'Image' => 'ghcr.io/org/private:latest', 'State' => $state,
            'Labels' => 'com.docker.compose.project=private-stack,com.docker.compose.service=backup',
        ];
        $commands = $dockerDir . '/commands';
        $job = [
            'Names' => 'private-job', 'Image' => 'ghcr.io/org/private:latest', 'State' => 'exited',
            'Labels' => 'com.docker.compose.project=private-stack,com.docker.compose.service=app,com.docker.compose.oneoff=True',
        ];
        file_put_contents($docker, "#!/bin/sh\ncase \"\$*\" in\n"
            . "*'config --format json'*) printf '%s\\n' '{\"services\":{}}'; exit 0 ;;\nesac\ncase \"\$1\" in\n"
            . "compose|ps) printf '%s\\n' " . escapeshellarg(json_encode($row))
            . ' ' . escapeshellarg(json_encode($stopped)) . ' ' . escapeshellarg(json_encode($job)) . " ;;\nesac\n"
            . "printf '%s\\n' \"\$*\" >> " . escapeshellarg($commands) . "\n");
        chmod($docker, 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $dockerDir . PATH_SEPARATOR . $path);
        try {
            $singleUpdates = null;
            foreach (['checkStackUpdates', 'checkAllStacksUpdates'] as $action) {
                \StackInfo::clearCache();
                file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode(['other-stack' => ['hasUpdate' => true]]));
                $GLOBALS['composeLoggerMessages'] = [];
                $response = json_decode($this->executeAction($action, ['script' => 'private-stack']), true);
                $this->assertSame('success', $response['result']);
                $this->assertContains('Unable to load attached registry credential for update check', $GLOBALS['composeLoggerMessages']);
                $updates = $action === 'checkStackUpdates' ? $response['updates'] : $response['stacks']['private-stack']['containers'];
                $this->assertCount(2, $updates);
                $this->assertSame('unknown', $updates[0]['updateStatus']);
                $this->assertSame('private-backup', $updates[1]['name']);
                $this->assertSame('backup', $updates[1]['service']);
                if ($singleUpdates === null) {
                    $singleUpdates = $updates;
                } else {
                    $this->assertSame($singleUpdates, $updates);
                }
                $saved = json_decode(file_get_contents(COMPOSE_UPDATE_STATUS_FILE), true);
                $this->assertSame($updates, $saved['private-stack']['containers']);
                $this->assertIsInt($saved['private-stack']['lastChecked']);
                $this->assertSame($action === 'checkStackUpdates', isset($saved['other-stack']));
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\b(pull|build|up|start|stop|restart|create|run|rm)\b/',
                file_get_contents($commands)
            );
        } finally {
            putenv('PATH=' . $path);
            @unlink(UNRAID_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    public function testEmptyStackChecksKeepTheirPublicResponseShape(): void
    {
        $this->createTestStack('empty-stack');
        $dockerDir = $this->createTempDir();
        file_put_contents($dockerDir . '/docker', "#!/bin/sh\nexit 0\n");
        chmod($dockerDir . '/docker', 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $dockerDir . PATH_SEPARATOR . $path);
        try {
            foreach (['checkStackUpdates', 'checkAllStacksUpdates'] as $action) {
                \StackInfo::clearCache();
                $response = json_decode($this->executeAction($action, ['script' => 'empty-stack']), true);
                $this->assertSame('success', $response['result']);
                $updates = $action === 'checkStackUpdates'
                    ? $response['updates'] : $response['stacks']['empty-stack']['containers'];
                $this->assertSame([], $updates);
                $saved = json_decode(file_get_contents(COMPOSE_UPDATE_STATUS_FILE), true);
                $this->assertFalse($saved['empty-stack']['hasUpdate']);
                $this->assertSame('empty-stack', $saved['empty-stack']['projectName']);
            }

        } finally {
            putenv('PATH=' . $path);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
        }
    }

    public function testExpandedContainersUsePerReplicaSavedEvidenceInsteadOfServiceCache(): void
    {
        $this->createTestStack('replicas');
        $bin = $this->createTempDir();
        $rows = [];
        $saved = [];
        $script = "#!/bin/sh\ncase \"\$1\" in\ncompose) printf '%s\\n'";
        foreach (['1', '2', '3'] as $replica) {
            $name = 'app-' . $replica;
            $rows[] = ['Name' => $name, 'Service' => 'app', 'Image' => 'old' . $replica, 'State' => 'running'];
            $saved[] = \ContainerInfo::fromUpdateResponse([
                'name' => $name, 'service' => 'app', 'image' => 'stack-app',
                'hasUpdate' => true, 'updateStatus' => 'update-available',
                'localSha' => 'old' . $replica, 'remoteSha' => 'base-new',
                'updateKind' => 'build-base',
                'updateReadiness' => $replica === '2' ? 'unknown' : 'rebuild-required',
            ])->toUpdateArray();
        }
        foreach ($rows as $row) {
            $script .= ' ' . escapeshellarg(json_encode($row));
        }
        $script .= ";;\ninspect) case \"\$2\" in\n";
        foreach ($rows as $i => $row) {
            $script .= $row['Name'] . ") printf '%s\\n' " . escapeshellarg(json_encode([
                'Image' => 'sha256:' . ($i === 2 ? 'recreated' : 'old' . ($i + 1)),
                'Config' => ['Image' => 'stack-app'], 'State' => [],
            ])) . ";;\n";
        }
        $script .= "esac;;\nesac\n";
        file_put_contents($bin . '/docker', $script);
        chmod($bin . '/docker', 0755);
        file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode(['replicas' => ['containers' => $saved]]));
        $path = getenv('PATH');
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        try {
            foreach ([1, 2] as $reload) {
                $response = json_decode($this->executeAction('getStackContainers', ['script' => 'replicas']), true);
                $this->assertSame('success', $response['result']);
                $this->assertSame(['rebuild-required', 'unknown', 'unknown'], array_column($response['containers'], 'updateReadiness'));
                $this->assertSame([true, true, false], array_column($response['containers'], 'hasUpdate'));
                $cache = json_decode(file_get_contents(COMPOSE_CONTAINER_CACHE_FILE), true);
                $this->assertArrayNotHasKey('hasUpdate', $cache['replicas']['app']);
                $this->assertArrayNotHasKey('updateReadiness', $cache['replicas']['app']);
            }
        } finally {
            putenv('PATH=' . $path);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    public function testBuildOnlyImageIdRowsKeepEvidenceAcrossCheckReloadAndExpansion(): void
    {
        $this->createTestStack('build-only');
        $context = $this->createTempDir();
        file_put_contents($context . '/Dockerfile', "FROM alpine@sha256:pinned-base\n");
        $bin = $this->createTempDir();
        $imageId = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $row = [
            'Name' => 'built-app', 'Names' => 'built-app', 'Service' => 'app',
            'Image' => '0123456789ab', 'State' => 'running',
            'Labels' => 'com.docker.compose.project=build-only,com.docker.compose.service=app',
        ];
        $inspect = ['Image' => $imageId, 'Config' => ['Image' => 'build-only-app'], 'State' => []];
        $config = ['services' => ['app' => ['build' => ['context' => $context]]]];
        file_put_contents($bin . '/docker', "#!/bin/sh\ncase \"\$*\" in\n"
            . "*'config --format json'*) printf '%s\\n' " . escapeshellarg(json_encode($config)) . " ;;\n"
            . "inspect*) printf '%s\\n' " . escapeshellarg(json_encode($inspect)) . " ;;\n"
            . "*) printf '%s\\n' " . escapeshellarg(json_encode($row)) . " ;;\nesac\n");
        chmod($bin . '/docker', 0755);
        \DockerClient::setMockResponse('/containers/built-app/json', $inspect);
        $path = getenv('PATH');
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        try {
            foreach (['checkStackUpdates', 'checkAllStacksUpdates'] as $action) {
                \StackInfo::clearCache();
                $response = json_decode($this->executeAction($action, ['script' => 'build-only']), true);
                $this->assertSame('success', $response['result']);
                $updates = $action === 'checkStackUpdates'
                    ? $response['updates'] : $response['stacks']['build-only']['containers'];
                $this->assertNotEmpty($updates, json_encode($response));
                $this->assertSame('build-only-app', $updates[0]['image']);
                $this->assertSame('current', $updates[0]['updateReadiness']);
                $this->assertSame('build-base', $updates[0]['updateKind']);
                for ($reload = 0; $reload < 2; $reload++) {
                    \StackInfo::clearCache();
                    $saved = json_decode($this->executeAction('getSavedUpdateStatus'), true);
                    $this->assertSame('build-only-app', $saved['stacks']['build-only']['containers'][0]['image']);
                    $details = json_decode($this->executeAction('getStackContainers', ['script' => 'build-only']), true);
                    $this->assertSame('success', $details['result']);
                    $this->assertSame('current', $details['containers'][0]['updateReadiness']);
                    $this->assertSame('build-base', $details['containers'][0]['updateKind']);
                }
            }
        } finally {
            putenv('PATH=' . $path);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    public function testUpdateCheckReportsPersistenceFailureInsteadOfSuccess(): void
    {
        $this->createTestStack('empty-stack');
        file_put_contents(COMPOSE_UPDATE_STATUS_FILE, 'not json');
        try {
            $response = json_decode($this->executeAction('checkStackUpdates', ['script' => 'empty-stack']), true);
            $this->assertSame('error', $response['result']);
            $this->assertNotEmpty($response['message']);
            $this->assertSame('not json', file_get_contents(COMPOSE_UPDATE_STATUS_FILE));
            $this->assertStringContainsString(
                'Stack update check failed:',
                implode("\n", $GLOBALS['composeLoggerMessages'])
            );
        } finally {
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
        }
    }

    public static function cachedImageStatusCases(): array
    {
        return [
            'tag prefix' => ['ghcr.io/example/app:v3.3.5', 'ghcr.io/example/app:v3', 'ghcr.io/example/app:v3.3.5', null, 'up-to-date'],
            'official full reference' => ['docker.io/library/postgres:18', 'postgres:16', 'library/postgres:18', null, 'up-to-date'],
            'official short reference' => ['redis:8', 'other/redis:8', 'docker.io/library/redis:8', null, 'up-to-date'],
            'old tag only' => ['ghcr.io/example/app:v3.3.5', 'ghcr.io/example/app:v3', null, null, 'unknown'],
            'already pulled but not recreated' => ['postgres:18', 'postgres:16', 'library/postgres:18', 'oldimage', 'update-available', true],
            'native manifest digest is not a config ID' => ['postgres:18', 'postgres:16', 'library/postgres:18', 'oldimage', 'up-to-date', false],
            'native writer replaced the remote digest' => ['postgres:18', 'postgres:16', 'library/postgres:18', 'oldimage', 'up-to-date', false, 'previous-config'],
            'config digest needs actual container ID' => ['postgres:18', 'postgres:16', 'library/postgres:18', null, 'unknown', true],
            'recreated container matches config digest' => ['postgres:18', 'postgres:16', 'library/postgres:18', 'current', 'up-to-date', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cachedImageStatusCases')]
    public function testContainerStatusUsesExactNormalizedImageInsteadOfOldTagPrefix(
        string $image,
        string $oldImage,
        ?string $currentImage,
        ?string $containerImageId,
        string $expectedStatus,
        bool $configDigest = false,
        ?string $previousConfigDigest = null
    ): void
    {
        $this->createTestStack('status-stack');
        $dockerDir = $this->createTempDir();
        $docker = $dockerDir . '/docker';
        $row = ['Name' => 'status-stack-app-1', 'Service' => 'app', 'Image' => $image, 'State' => 'running'];
        $inspect = ['Config' => ['Image' => $row['Image']], 'State' => []];
        if ($containerImageId !== null) {
            $inspect['Image'] = $containerImageId;
        }
        file_put_contents($docker, "#!/bin/sh\ncase \"\$1\" in\n"
            . "compose) printf '%s\\n' " . escapeshellarg(json_encode($row)) . " ;;\n"
            . "inspect) printf '%s\\n' " . escapeshellarg(json_encode($inspect)) . " ;;\nesac\n");
        chmod($docker, 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $dockerDir . PATH_SEPARATOR . $path);
        $statuses = [$oldImage => ['local' => 'old', 'remote' => 'new']];
        if ($currentImage !== null) {
            $statuses[$currentImage] = ['local' => 'current', 'remote' => 'current', 'status' => 'true'];
            if ($configDigest) {
                $statuses[$currentImage]['configDigest'] = 'current';
            }
            if ($previousConfigDigest !== null) {
                $statuses[$currentImage]['configDigest'] = $previousConfigDigest;
            }
        }
        file_put_contents(UNRAID_UPDATE_STATUS_FILE, json_encode($statuses));
        try {
            $response = json_decode($this->executeAction('getStackContainers', ['script' => 'status-stack']), true);
            $this->assertSame('success', $response['result']);
            $this->assertCount(1, $response['containers']);
            $this->assertSame($expectedStatus === 'update-available', $response['containers'][0]['hasUpdate']);
            $this->assertSame($expectedStatus, $response['containers'][0]['updateStatus']);
            $usesConfig = $currentImage !== null && isset($statuses[$currentImage]['configDigest'])
                && $statuses[$currentImage]['configDigest'] === 'current';
            $this->assertSame($usesConfig ? ($containerImageId ?? '') : ($currentImage !== null ? 'current' : ''), $response['containers'][0]['localSha']);
        } finally {
            putenv('PATH=' . $path);
            @unlink(UNRAID_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    public function testImportedInputFailureDoesNotHideStackSettings(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->createTestStack('repairable', [
            'docker-compose.yml' => "services: {}\n",
            'docker-compose.override.yml' => "services: {}\n",
            'name' => 'repairable',
        ]);
        \StackInfo::fromProject($this->testComposeRoot, 'repairable');
        unlink($path . '/docker-compose.override.yml');
        \StackInfo::clearCache();

        $response = json_decode($this->executeAction('getStackSettings', ['script' => 'repairable']), true);

        $this->assertSame('success', $response['result']);
        $this->assertStringContainsString('Migrated Compose input is unavailable', $response['migrationInputError']);
        $this->assertContains($path . '/docker-compose.yml', $response['editableComposeFiles']);
        $this->assertFalse(\StackInfo::fromProject($this->testComposeRoot, 'repairable')->hasResolvedIdentity());
    }

    public function testLegacyEnvSelectionIsVisibleAndClearingDoesNotReactivateIt(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $path = $this->createTestStack('legacy-env', [
            'docker-compose.yml' => "services: {}\n",
            'name' => 'legacy-env',
            'envToUse' => '/missing/legacy.env',
        ]);
        $settings = json_decode($this->executeAction('getStackSettings', ['script' => 'legacy-env']), true);
        $this->assertSame('success', $settings['result']);
        $this->assertSame('/missing/legacy.env', $settings['envPath']);

        $saved = json_decode($this->executeAction('setStackSettings', ['script' => 'legacy-env', 'envPath' => '']), true);

        $this->assertSame('success', $saved['result']);
        $this->assertFileExists($path . '/envpath');
        $this->assertSame('', file_get_contents($path . '/envpath'));
        $this->assertSame('/missing/legacy.env', file_get_contents($path . '/envToUse'));
        $settings = json_decode($this->executeAction('getStackSettings', ['script' => 'legacy-env']), true);
        $this->assertSame('', $settings['envPath']);
        $this->assertArrayNotHasKey('envFilePath', \StackInfo::fromProject($this->testComposeRoot, 'legacy-env')->buildComposeArgs());
    }

    public function testUnrelatedSettingsPreserveCompleteMigratedInputOrder(): void
    {
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $fixture = $this->createAllowedSymlinkedPathFixture();
        $source = $fixture['target'];
        $files = [$source . '/docker-compose.extra.yml', $source . '/docker-compose.yml'];
        foreach ($files as $file) {
            file_put_contents($file, "services: {}\n");
        }
        $path = $this->createTestStack('ordered', ['indirect' => $source, 'name' => 'ordered']);
        \ProjectIdentity::setProbe(static fn(): ?array => \ProjectIdentity::parseProbeOutput('containers ' . json_encode([
            'project' => 'ordered', 'workingDir' => $source, 'configFiles' => implode(',', $files),
        ]) . "\n__rc=0"));
        $this->assertSame($files, \StackInfo::fromProject($this->testComposeRoot, 'ordered')->buildComposeArgs()['filePaths']);

        $saved = json_decode($this->executeAction('setStackSettings', [
            'script' => 'ordered', 'externalComposePath' => $source, 'externalComposeFilePath' => '',
            'iconUrl' => 'https://example.com/icon.png', 'defaultProfile' => 'worker',
            'extraComposeFiles' => '', 'useDefaultComposeFiles' => 'false',
        ]), true);

        $this->assertSame('success', $saved['result']);
        $this->assertFileDoesNotExist($path . '/indirect_mode');
        $this->assertFileDoesNotExist($path . '/compose_input_mode');
        $this->assertSame($files, \StackInfo::fromProject($this->testComposeRoot, 'ordered')->buildComposeArgs()['filePaths']);
        $this->assertFileExists($path . '/compose.yaml');
        $saved = json_decode($this->executeAction('setStackSettings', ['script' => 'ordered', 'iconUrl' => 'https://example.com/other.png']), true);
        $this->assertSame('success', $saved['result']);
        $this->assertSame($source, file_get_contents($path . '/indirect'));
        $this->assertSame($files, \StackInfo::fromProject($this->testComposeRoot, 'ordered')->buildComposeArgs()['filePaths']);

        file_put_contents($source . '/compose.added.yaml', "services: {}\n");
        $saved = json_decode($this->executeAction('setStackSettings', ['script' => 'ordered', 'extraComposeFiles' => 'compose.added.yaml']), true);
        $this->assertSame('success', $saved['result']);
        $this->assertSame([...$files, $source . '/compose.added.yaml'], \StackInfo::fromProject($this->testComposeRoot, 'ordered')->buildComposeArgs()['filePaths']);

        $replacement = $this->createAllowedSymlinkedPathFixture(true)['target'];
        $saved = json_decode($this->executeAction('setStackSettings', ['script' => 'ordered', 'externalComposePath' => $replacement, 'extraComposeFiles' => '']), true);
        $this->assertSame('success', $saved['result']);
        $this->assertSame('plus', file_get_contents($path . '/compose_input_mode'));
        $this->assertSame([$replacement . '/compose.yaml'], \StackInfo::fromProject($this->testComposeRoot, 'ordered')->buildComposeArgs()['filePaths']);
        \ProjectIdentity::setProbe(static fn(): array => []);
    }

    public function testExplicitProjectFolderSelectionClearsStaleInvalidMarkerWhenTargetWasAlreadyEmpty(): void
    {
        $path = $this->createTestStack('invalid-folder', [
            'indirect.invalid' => '/mnt/missing/path',
        ]);

        $saved = json_decode($this->executeAction('setStackSettings', [
            'script' => 'invalid-folder', 'externalComposePath' => '', 'externalComposeFilePath' => '',
        ]), true);

        $this->assertSame('success', $saved['result']);
        $this->assertFileDoesNotExist($path . '/indirect.invalid');
    }

    public function testResubmittingUnchangedExternalPathClearsStaleInvalidMarkerWithoutDiscardingFileOrder(): void
    {
        $fixture = $this->createAllowedSymlinkedPathFixture();
        $source = $fixture['target'];
        $files = [$source . '/docker-compose.yml'];
        file_put_contents($files[0], "services: {}\n");
        $path = $this->createTestStack('restored-source', [
            'indirect' => $source,
            'indirect.invalid' => $source,
        ]);
        \ProjectIdentity::setProbe(static fn(): ?array => \ProjectIdentity::parseProbeOutput('containers ' . json_encode([
            'project' => 'restored-source', 'workingDir' => $source, 'configFiles' => implode(',', $files),
        ]) . "\n__rc=0"));
        \StackInfo::fromProject($this->testComposeRoot, 'restored-source');

        $saved = json_decode($this->executeAction('setStackSettings', [
            'script' => 'restored-source', 'externalComposePath' => $source, 'externalComposeFilePath' => '',
        ]), true);

        $this->assertSame('success', $saved['result']);
        $this->assertFileDoesNotExist($path . '/indirect.invalid');
        $this->assertSame($source, file_get_contents($path . '/indirect'));
        \StackInfo::clearCache();
        $this->assertSame($files, \StackInfo::fromProject($this->testComposeRoot, 'restored-source')->buildComposeArgs()['filePaths']);
        \ProjectIdentity::setProbe(static fn(): array => []);
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

    /**
     * Test saveEnv writes a relative envpath inside the stack directory
     */
    public function testSaveEnvWritesRelativeEnvPathInStackDirectory(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/envpath', 'app.env');

        $envContent = "RELATIVE_VAR=value";
        $output = $this->executeAction('saveEnv', [
            'script' => 'test-stack',
            'scriptContents' => $envContent,
        ]);

        $this->assertStringContainsString('saved', $output);
        $this->assertEquals($envContent, file_get_contents($stackPath . '/app.env'));
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

    public function testCredentialCrudAndStackAssignmentRoundTrip(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        $saveOutput = $this->executeAction('saveCredential', [
            'name' => 'Work GitHub', 'provider' => 'github', 'registry' => 'ghcr.io',
            'username' => 'octocat', 'secret' => 'read-only-token',
        ]);
        $saved = json_decode($saveOutput, true);
        $this->assertSame('success', $saved['result']);
        $credentialId = $saved['credential']['id'];
        $this->assertArrayNotHasKey('secret', $saved['credential']);

        $settingsOutput = $this->executeAction('setStackSettings', [
            'script' => 'test-stack', 'credentialId' => $credentialId,
        ]);
        $this->assertSame('success', json_decode($settingsOutput, true)['result']);
        $this->assertSame($credentialId, trim((string) file_get_contents($stackPath . '/credential_id')));

        $getOutput = $this->executeAction('getStackSettings', ['script' => 'test-stack']);
        $this->assertSame($credentialId, json_decode($getOutput, true)['credentialId']);

        $deleteOutput = $this->executeAction('deleteCredential', ['id' => $credentialId]);
        $deleteResult = json_decode($deleteOutput, true);
        $this->assertSame('error', $deleteResult['result']);
        $this->assertSame(['test-stack'], $deleteResult['stacks']);
    }

    public function testCredentialUsedByAGitStackCannotBeDeleted(): void
    {
        $stackPath = $this->createTestStack('git-stack');
        $saved = json_decode($this->executeAction('saveCredential', [
            'name' => 'Git stacks', 'provider' => 'git', 'registry' => 'github.com',
            'username' => 'bot', 'secret' => 'repository-token',
        ]), true);
        $credentialId = $saved['credential']['id'];
        file_put_contents($stackPath . '/git.json', (string) json_encode(['credentialId' => $credentialId]));

        $listed = json_decode($this->executeAction('listCredentials'), true);
        $this->assertSame(['git-stack'], $listed['credentials'][0]['stacks']);

        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => $credentialId]), true);
        $this->assertSame('error', $deleteResult['result']);
        $this->assertSame(['git-stack'], $deleteResult['stacks']);
    }

    public function testCredentialUsedByAGitStackWhoseGitJsonHasAByteOrderMarkCannotBeDeleted(): void
    {
        // As saved by a Windows editor. GitStackSettings::load() accepts it, so the stack keeps working
        // and its credential must not be deleted from under it.
        $stackPath = $this->createTestStack('git-stack');
        $saved = json_decode($this->executeAction('saveCredential', [
            'name' => 'Git stacks', 'provider' => 'git', 'registry' => 'github.com',
            'username' => 'bot', 'secret' => 'repository-token',
        ]), true);
        $credentialId = $saved['credential']['id'];
        file_put_contents($stackPath . '/git.json', "\xEF\xBB\xBF" . json_encode(['credentialId' => $credentialId]));

        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => $credentialId]), true);
        $this->assertSame('error', $deleteResult['result']);
        $this->assertSame(['git-stack'], $deleteResult['stacks']);
    }

    public function testGitCredentialIsNotDeletedWhileAGitStacksSettingsCannotBeRead(): void
    {
        // A hand edit left a trailing comma: the stack may use this credential, but which one cannot be read.
        $stackPath = $this->createTestStack('git-stack');
        $saved = json_decode($this->executeAction('saveCredential', [
            'name' => 'Git stacks', 'provider' => 'git', 'registry' => 'github.com',
            'username' => 'bot', 'secret' => 'repository-token',
        ]), true);
        $credentialId = $saved['credential']['id'];
        file_put_contents($stackPath . '/git.json', '{"credentialId": "' . $credentialId . '",}');

        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => $credentialId]), true);
        $this->assertSame('error', $deleteResult['result']);
        $this->assertStringContainsString('git-stack', $deleteResult['message']);
        $this->assertStringContainsString('could not be read', $deleteResult['message']);
        $this->assertCount(1, (new \CredentialVault())->listCredentials());

        // Once the file is fixed, the stack counts as using it in the usual way.
        file_put_contents($stackPath . '/git.json', '{"credentialId": "' . $credentialId . '"}');
        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => $credentialId]), true);
        $this->assertSame(['git-stack'], $deleteResult['stacks']);
    }

    public function testRegistryCredentialCanBeDeletedWhileAGitStacksSettingsCannotBeRead(): void
    {
        // Only git credentials are named in a git.json, so a broken one does not hold up a registry credential,
        // even one whose id it happens to contain.
        $stackPath = $this->createTestStack('git-stack');
        $saved = json_decode($this->executeAction('saveCredential', [
            'name' => 'Work GitHub', 'provider' => 'github', 'registry' => 'ghcr.io',
            'username' => 'octocat', 'secret' => 'read-only-token',
        ]), true);
        file_put_contents($stackPath . '/git.json', '{"credentialId": "' . $saved['credential']['id'] . '",}');

        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => $saved['credential']['id']]), true);
        $this->assertSame('success', $deleteResult['result']);
    }

    public function testGitCredentialCanBeDeletedWhenNoGitStackNamesIt(): void
    {
        // A broken git.json that names another credential, and a readable one that names none, do not hold it up.
        $brokenPath = $this->createTestStack('broken-stack');
        $readablePath = $this->createTestStack('readable-stack');
        $saved = json_decode($this->executeAction('saveCredential', [
            'name' => 'Git stacks', 'provider' => 'git', 'registry' => 'github.com',
            'username' => 'bot', 'secret' => 'repository-token',
        ]), true);
        file_put_contents($brokenPath . '/git.json', '{"credentialId": "' . str_repeat('cd', 16) . '",}');
        file_put_contents($readablePath . '/git.json', '{"url": "https://github.com/owner/repo.git"}');

        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => $saved['credential']['id']]), true);
        $this->assertSame('success', $deleteResult['result']);
    }

    public function testDeployKeyIsNotDeletedWhileTwoGitStacksSettingsCannotBeRead(): void
    {
        // One names the key; the other was saved as UTF-16, so its text cannot be searched and it may name it too.
        $firstPath = $this->createTestStack('first-stack');
        $secondPath = $this->createTestStack('second-stack');
        $key = (new \CredentialVault())->saveCredential([
            'name' => 'first-stack deploy key', 'provider' => 'git-ssh', 'registry' => 'github.com',
            'username' => 'git', 'secret' => 'private key',
        ]);
        file_put_contents($firstPath . '/git.json', '{"credentialId": "' . $key['id'] . '",}');
        $utf16 = '';
        foreach (str_split('{"credentialId": "' . $key['id'] . '"}') as $character) {
            $utf16 .= $character . "\0";
        }
        file_put_contents($secondPath . '/git.json', $utf16);

        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => $key['id']]), true);
        $this->assertSame('error', $deleteResult['result']);
        $this->assertStringContainsString('first-stack, second-stack could not be read, so they may use', $deleteResult['message']);
        $this->assertCount(1, (new \CredentialVault())->listCredentials());
    }

    public function testCredentialIsNotDeletedWhileTheProjectsFolderIsMissing(): void
    {
        // As with the projects folder on the array while the array is stopped: the stack using it cannot be seen.
        $stackPath = $this->createTestStack('test-stack');
        $saved = json_decode($this->executeAction('saveCredential', [
            'name' => 'Work GitHub', 'provider' => 'github', 'registry' => 'ghcr.io',
            'username' => 'octocat', 'secret' => 'read-only-token',
        ]), true);
        file_put_contents($stackPath . '/credential_id', $saved['credential']['id']);
        $this->recursiveDelete($this->testComposeRoot);

        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => $saved['credential']['id']]), true);
        $this->assertSame('error', $deleteResult['result']);
        $this->assertStringContainsString('start the array first', $deleteResult['message']);
        $this->assertCount(1, (new \CredentialVault())->listCredentials());
    }

    public function testDeletingAMissingCredentialSaysItIsNotFound(): void
    {
        $deleteResult = json_decode($this->executeAction('deleteCredential', ['id' => str_repeat('ab', 16)]), true);
        $this->assertSame('error', $deleteResult['result']);
        $this->assertSame('Credential not found.', $deleteResult['message']);
    }

    public function testGitCredentialIsNotTestedAsARegistryAndCannotBeARegistryCredential(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/envpath', 'old.env');
        file_put_contents($stackPath . '/credential_id', 'registry-credential');
        $saved = json_decode($this->executeAction('saveCredential', [
            'name' => 'Git stacks', 'provider' => 'git', 'registry' => 'github.com',
            'username' => 'bot', 'secret' => 'repository-token',
        ]), true);
        $credentialId = $saved['credential']['id'];

        // No git stack uses it, so there is no repository to try.
        $test = json_decode($this->executeAction('testCredential', ['id' => $credentialId]), true);
        $this->assertSame('error', $test['result']);
        $this->assertStringContainsString('No git stack uses this credential', $test['message']);

        $settings = json_decode($this->executeAction('setStackSettings', [
            'script' => 'test-stack', 'credentialId' => $credentialId, 'envPath' => 'new.env',
        ]), true);
        $this->assertSame('error', $settings['result']);
        $this->assertStringContainsString('cannot be used to pull images', $settings['message']);
        // Refused before anything is saved: the other settings and the stack's registry credential are unchanged.
        $this->assertSame('old.env', file_get_contents($stackPath . '/envpath'));
        $this->assertSame('registry-credential', file_get_contents($stackPath . '/credential_id'));
    }

    public function testADeployKeyCannotBeAddedOnTheCredentialsTab(): void
    {
        $saved = json_decode($this->executeAction('saveCredential', [
            'name' => 'Sneaky', 'provider' => 'git-ssh', 'registry' => 'github.com',
            'username' => 'git', 'secret' => 'private key',
        ]), true);

        $this->assertSame('error', $saved['result']);
        $this->assertStringContainsString('made by compose-git', $saved['message']);
        $this->assertSame([], (new \CredentialVault())->listCredentials());
    }

    public function testDeployKeyIsNotTestedAsARegistryAndCannotBeARegistryCredential(): void
    {
        $this->createTestStack('test-stack');
        $credentialId = (new \CredentialVault())->saveCredential([
            'name' => 'whoami deploy key', 'provider' => 'git-ssh', 'registry' => 'github.com',
            'username' => 'git', 'secret' => 'private key',
        ])['id'];

        $test = json_decode($this->executeAction('testCredential', ['id' => $credentialId]), true);
        $this->assertStringContainsString('No git stack uses this credential', $test['message']);

        $settings = json_decode($this->executeAction('setStackSettings', [
            'script' => 'test-stack', 'credentialId' => $credentialId,
        ]), true);
        $this->assertSame('error', $settings['result']);
        $this->assertStringContainsString('cannot be used to pull images', $settings['message']);
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

    public function testSetStackSettingsRejectsMissingCredentialBeforePersistingOtherSettings(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/envpath', 'old.env');
        file_put_contents($stackPath . '/icon_url', 'https://old.example/icon.png');
        file_put_contents($stackPath . '/webui_url', 'https://old.example/');
        file_put_contents($stackPath . '/default_profile', 'old-profile');

        $output = $this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'credentialId' => 'missing-credential',
            'envPath' => 'new.env',
            'iconUrl' => 'https://new.example/icon.png',
            'webuiUrl' => 'https://new.example/',
            'defaultProfile' => 'new-profile',
        ]);

        $result = json_decode($output, true);
        $this->assertSame('error', $result['result']);
        $this->assertSame('old.env', file_get_contents($stackPath . '/envpath'));
        $this->assertSame('https://old.example/icon.png', file_get_contents($stackPath . '/icon_url'));
        $this->assertSame('https://old.example/', file_get_contents($stackPath . '/webui_url'));
        $this->assertSame('old-profile', file_get_contents($stackPath . '/default_profile'));
        $this->assertFileDoesNotExist($stackPath . '/credential_id');
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
     * Corruption is an explicit error, not a successful empty check.
     */
    public function testGetSavedUpdateStatusHandlesInvalidStatusFile(): void
    {
        file_put_contents(COMPOSE_UPDATE_STATUS_FILE, 'not-valid-json');

        $output = $this->executeAction('getSavedUpdateStatus');
        $result = json_decode($output, true);

        $this->assertIsArray($result);
        $this->assertEquals('error', $result['result']);
        $this->assertNotEmpty($result['message']);
        $this->assertSame('not-valid-json', file_get_contents(COMPOSE_UPDATE_STATUS_FILE));

        @unlink(COMPOSE_UPDATE_STATUS_FILE);
    }

    public function testSavedStatusReadersRemovalsAndClearsWaitForTheWriterLock(): void
    {
        require_once '/usr/local/emhttp/plugins/compose.manager/include/StackUpdateService.php';
        foreach (['readSavedResults()', 'removeSavedResult("remove")', 'clearSavedResults()'] as $operation) {
            file_put_contents(COMPOSE_UPDATE_STATUS_FILE, '{"old":[]}');
            $handle = fopen(COMPOSE_UPDATE_STATUS_FILE, 'c+');
            $this->assertNotFalse($handle);
            $this->assertTrue(flock($handle, LOCK_EX));
            $inode = fstat($handle)['ino'];
            $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
                . 'require "/usr/local/emhttp/plugins/compose.manager/include/StackUpdateService.php";'
                . 'echo "waiting\n"; flush();'
                . '$result = StackUpdateService::' . $operation . ';'
                . 'echo json_encode($result);';
            $process = proc_open([PHP_BINARY, '-r', $code], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            try {
                stream_set_timeout($pipes[1], 5);
                $this->assertSame("waiting\n", fgets($pipes[1]));
                stream_set_blocking($pipes[1], false);
                usleep(100000);
                $this->assertSame('', stream_get_contents($pipes[1]), $operation . ' bypassed the lock');
                $new = ['keep' => ['hasUpdate' => true], 'remove' => []];
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($new));
                fflush($handle);
                flock($handle, LOCK_UN);
                stream_set_blocking($pipes[1], true);
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors);
                $process = null;
                $this->assertSame($inode, fileinode(COMPOSE_UPDATE_STATUS_FILE));
                $saved = \StackUpdateService::readSavedResults();
                if ($operation === 'readSavedResults()') {
                    $this->assertSame($new, json_decode($output, true));
                    $this->assertSame($new, $saved);
                } elseif ($operation === 'removeSavedResult("remove")') {
                    $this->assertSame('true', $output);
                    $this->assertSame(['keep' => ['hasUpdate' => true]], $saved);
                } else {
                    $this->assertSame([], $saved);
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) fclose($pipe);
                    }
                    proc_close($process);
                }
                @unlink(COMPOSE_UPDATE_STATUS_FILE);
            }
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

    public function testCheckStackLockUsesExactMigratedProjectIdentity(): void
    {
        $path = $this->createTestStack('prod-api', [
            'name' => 'prod__api',
            'project_name' => 'prod__api',
            'project_identity.json' => json_encode([
                'version' => \ProjectIdentity::MIGRATION_VERSION,
                'projectName' => 'prod__api',
                'source' => 'owner',
                'origin' => 'unknown',
            ]),
        ]);
        $lockDir = $this->testComposeRoot . '/locks';
        mkdir($lockDir);
        $GLOBALS['compose_lock_dir'] = $lockDir;
        $lock = fopen($lockDir . '/prod__api.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fwrite($lock, json_encode(['pid' => getmypid(), 'stack' => 'prod__api']));
        fflush($lock);
        try {
            $result = json_decode($this->executeAction('checkStackLock', ['script' => 'prod-api']), true);
            $this->assertSame('success', $result['result']);
            $this->assertTrue($result['locked']);
            $this->assertSame('prod__api', $result['info']['stack']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            unset($GLOBALS['compose_lock_dir']);
        }
    }
}
