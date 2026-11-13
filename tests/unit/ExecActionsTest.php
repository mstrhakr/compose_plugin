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

    private function mergeContainerMetadata(array &$cache, \ContainerInfo $update): void
    {
        $original = is_file(COMPOSE_CONTAINER_CACHE_FILE) ? file_get_contents(COMPOSE_CONTAINER_CACHE_FILE) : false;
        try {
            file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, json_encode(['stack' => $cache], JSON_THROW_ON_ERROR));
            $tokens = \ContainerMetadataCache::begin(['stack']);
            $patch = ['name' => $update->name, 'service' => $update->service, 'image' => $update->image];
            if ($update->icon !== '') $patch['icon'] = $update->icon;
            \ContainerMetadataCache::merge(['stack' => [$update->service => $patch]], $tokens);
            $cache = \ContainerMetadataCache::read()['stack'];
        } finally {
            if ($original === false) @unlink(COMPOSE_CONTAINER_CACHE_FILE);
            else file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, $original);
        }
    }

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

    public function testRecheckPersistencePreservesResponseContractsAndReportsWriteFailure(): void
    {
        $original = is_file(PENDING_RECHECK_FILE) ? file_get_contents(PENDING_RECHECK_FILE) : null;
        try {
            file_put_contents(PENDING_RECHECK_FILE, '{"existing":1}');
            $marked = json_decode($this->executeAction('markStackForRecheck', ['stacks' => '["constructor","app"]']), true);
            $this->assertSame('success', $marked['result']);
            $this->assertSame(['existing', 'constructor', 'app'], $marked['pending']);
            $saved = json_decode((string) file_get_contents(PENDING_RECHECK_FILE), true);
            $this->assertIsInt($saved['app']['timestamp']);
            $token = \PendingRechecks::token('app');
            $cleared = json_decode($this->executeAction('clearStackRecheck', [
                'stacks' => '["app"]', 'tokens' => json_encode(['app' => $token]),
            ]), true);
            $this->assertSame(['result' => 'success', 'remaining' => ['existing', 'constructor']], $cleared);
            unlink(PENDING_RECHECK_FILE);
            mkdir(PENDING_RECHECK_FILE);
            foreach (['markStackForRecheck', 'clearStackRecheck'] as $action) {
                $response = json_decode($this->executeAction($action, [
                    'stacks' => '["app"]', 'tokens' => '{"app":null}',
                ]), true);
                $this->assertSame('error', $response['result']);
                $this->assertNotEmpty($response['message']);
            }
        } finally {
            if (is_dir(PENDING_RECHECK_FILE)) rmdir(PENDING_RECHECK_FILE);
            if ($original !== null) file_put_contents(PENDING_RECHECK_FILE, $original);
            else @unlink(PENDING_RECHECK_FILE);
        }
    }

    public function testRecheckEndpointsRejectInvalidListsAndUnownedAcknowledgements(): void
    {
        $original = is_file(PENDING_RECHECK_FILE) ? file_get_contents(PENDING_RECHECK_FILE) : null;
        try {
            file_put_contents(PENDING_RECHECK_FILE, '{"app":1}');
            foreach (['[["app"]]', '[12]', '{"app":"app"}', 'not json'] as $stacks) {
                foreach (['markStackForRecheck', 'clearStackRecheck'] as $action) {
                    $result = json_decode($this->executeAction($action, ['stacks' => $stacks]), true);
                    $this->assertSame('error', $result['result']);
                    $this->assertSame('{"app":1}', file_get_contents(PENDING_RECHECK_FILE));
                }
            }
            $missing = json_decode($this->executeAction('clearStackRecheck', ['stacks' => '["app"]']), true);
            $this->assertSame('error', $missing['result']);
            foreach (['broken', '"scalar"', '{"app":"bad"}', ''] as $raw) {
                file_put_contents(PENDING_RECHECK_FILE, $raw);
                foreach (['markStackForRecheck', 'clearStackRecheck', 'getPendingRecheckStacks'] as $action) {
                    $result = json_decode($this->executeAction($action, [
                        'stacks' => '["app"]', 'tokens' => '{"app":null}',
                    ]), true);
                    $this->assertSame('error', $result['result']);
                    $this->assertSame($raw, file_get_contents(PENDING_RECHECK_FILE));
                }
            }
        } finally {
            if ($original !== null) file_put_contents(PENDING_RECHECK_FILE, $original);
            else @unlink(PENDING_RECHECK_FILE);
        }
    }

    public function testImageCheckReturnsOwnedTokenWhilePublicPendingValuesRemainTimestamps(): void
    {
        $original = is_file(PENDING_RECHECK_FILE) ? file_get_contents(PENDING_RECHECK_FILE) : null;
        $this->createTestStack('token-stack');
        $bin = $this->createTempDir();
        file_put_contents($bin . '/docker', "#!/bin/sh\nexit 0\n");
        chmod($bin . '/docker', 0700);
        $path = getenv('PATH');
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        try {
            file_put_contents(PENDING_RECHECK_FILE, '{"token-stack":12}');
            $response = json_decode($this->executeAction('checkStackUpdates', ['script' => 'token-stack']), true);
            $this->assertSame('success', $response['result']);
            $this->assertSame(\PendingRechecks::token('token-stack'), $response['recheckToken']);
            $token = $response['recheckToken'];
            $this->executeAction('markStackForRecheck', ['stacks' => '["token-stack"]']);
            $pending = json_decode($this->executeAction('getPendingRecheckStacks'), true);
            $this->assertIsInt($pending['pending']['token-stack']);
            $cleared = json_decode($this->executeAction('clearStackRecheck', [
                'stacks' => '["token-stack"]', 'tokens' => json_encode(['token-stack' => $token]),
            ]), true);
            $this->assertContains('token-stack', $cleared['remaining']);
        } finally {
            putenv('PATH=' . $path);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            if ($original !== null) file_put_contents(PENDING_RECHECK_FILE, $original);
            else @unlink(PENDING_RECHECK_FILE);
        }
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
        $this->mergeContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
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

        $this->mergeContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:18',
            'status' => 'unknown',
        ]));
        $this->assertArrayNotHasKey('updateStatus', $cache['db']);
        $this->assertArrayNotHasKey('remoteSha', $cache['db']);

        $this->mergeContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
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

        $this->mergeContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:18',
            'status' => 'unknown',
        ]));

        $this->assertArrayNotHasKey('updateStatus', $cache['db']);
        $this->assertArrayNotHasKey('localSha', $cache['db']);
        $this->assertArrayNotHasKey('remoteSha', $cache['db']);

        $cache['db']['image'] = 'postgres:18@sha256:' . str_repeat('a', 64);
        $this->mergeContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
            'container' => 'stack-db-1',
            'service' => 'db',
            'image' => 'postgres:18@sha256:' . str_repeat('b', 64),
            'status' => 'unknown',
        ]));

        $this->assertArrayNotHasKey('updateStatus', $cache['db']);
        $this->assertArrayNotHasKey('localSha', $cache['db']);
        $this->assertArrayNotHasKey('remoteSha', $cache['db']);
    }

    public function testLegacyMetadataCacheEvidenceIsNotReturnedOrWrittenOnRead(): void
    {
        file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, json_encode(['stack' => ['app' => [
            'name' => 'app-1', 'service' => 'app', 'image' => 'app:latest',
            'state' => 'running', 'icon' => '/existing.png',
            'hasUpdate' => true, 'updateStatus' => 'update-available', 'updateReadiness' => 'ready-to-apply',
        ]]]));
        try {
            $originalBytes = file_get_contents(COMPOSE_CONTAINER_CACHE_FILE);
            $response = json_decode($this->executeAction('getPersistentContainerCache'), true);
            $container = $response['cache']['stack']['app'];
            $this->assertSame('running', $container['state']);
            $this->assertSame('/existing.png', $container['icon']);
            $this->assertArrayNotHasKey('hasUpdate', $container);
            $this->assertArrayNotHasKey('updateReadiness', $container);
            $this->assertArrayNotHasKey('hasUpdate', \composeLoadPersistentContainerCache()['stack']['app']);
            $this->assertSame($originalBytes, file_get_contents(COMPOSE_CONTAINER_CACHE_FILE));
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
        $this->mergeContainerMetadata($cache, \ContainerInfo::fromUpdateResponse([
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
        $nativeBytes = "{\n  \"postgres:18\": {\"local\":\"old\",\"remote\":\"new\",\"status\":\"false\"},\n  \"unused:latest\": {\"status\":\"true\"}\n}\n";
        file_put_contents(UNRAID_UPDATE_STATUS_FILE, $nativeBytes);
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
            $this->assertSame($nativeBytes, file_get_contents(UNRAID_UPDATE_STATUS_FILE), 'Clearing plugin evidence must not prune or rewrite native Docker cache entries.');
            $cached = \composeLoadPersistentContainerCache()['stack']['db'];
            $this->assertSame('stack-db-1', $cached['name']);
            $this->assertSame('running', $cached['state']);
            $this->assertSame('/existing-icon.png', $cached['icon']);
            foreach (['hasUpdate', 'updateStatus', 'localSha', 'remoteSha'] as $field) {
                $this->assertArrayNotHasKey($field, $cached);
            }
        } finally {
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
            @unlink(UNRAID_UPDATE_STATUS_FILE);
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
            . "*'config --no-consistency --format json'*) printf '%s\\n' '{\"services\":{}}'; exit 0 ;;\nesac\ncase \"\$1\" in\n"
            . "compose) printf '%s\\n' " . escapeshellarg(json_encode(array_diff_key($row, ['Labels' => true])))
            . ' ' . escapeshellarg(json_encode(array_diff_key($stopped, ['Labels' => true])))
            . ' ' . escapeshellarg(json_encode(array_diff_key($job, ['Labels' => true]))) . " ;;\n"
            . "ps) printf '%s\\n' " . escapeshellarg(json_encode($row))
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
                $saved = \StackUpdateService::readSavedResults();
                $this->assertSame($updates, $saved['private-stack']['containers']);
                $this->assertIsInt($saved['private-stack']['lastChecked']);
                $this->assertSame($action === 'checkStackUpdates', isset($saved['other-stack']));
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\b(pull|build|up|start|stop|restart|create|run|rm)\b/',
                file_get_contents($commands)
            );
            $this->assertStringNotContainsString('ps --all', file_get_contents($commands));
        } finally {
            putenv('PATH=' . $path);
            @unlink(UNRAID_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    public function testCheckAllReturnsScopedConfigFailureAndHealthyResults(): void
    {
        $this->createTestStack('broken');
        $this->createTestStack('healthy');
        $bin = $this->createTempDir();
        $row = json_encode(['Names' => 'broken-app', 'Image' => 'alpine',
            'Labels' => 'com.docker.compose.project=broken,com.docker.compose.service=app']);
        file_put_contents($bin . '/docker', "#!/bin/sh\ncase \"\$1\" in\n"
            . "ps) printf '%s\\n' " . escapeshellarg($row) . ";;\n"
            . "compose) echo broken-config >&2; exit 7;;\nesac\n");
        chmod($bin . '/docker', 0755);
        $path = getenv('PATH');
        $saved = is_file(COMPOSE_UPDATE_STATUS_FILE) ? file_get_contents(COMPOSE_UPDATE_STATUS_FILE) : false;
        $pending = is_file(PENDING_RECHECK_FILE) ? file_get_contents(PENDING_RECHECK_FILE) : false;
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        try {
            file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode(['broken' => ['containers' => [], 'lastChecked' => 17]]));
            $response = json_decode($this->executeAction('checkAllStacksUpdates'), true);
            $this->assertSame('partial', $response['result']);
            $this->assertSame(['healthy'], array_keys($response['stacks']));
            $this->assertSame(['broken'], array_keys($response['failures']));
            $this->assertStringContainsString('broken-config', $response['failures']['broken']);
            $this->assertSame([], $response['superseded']);
            $this->assertSame(17, \StackUpdateService::readSavedResults()['broken']['lastChecked']);
            $single = json_decode($this->executeAction('checkStackUpdates', ['script' => 'broken']), true);
            $this->assertSame('error', $single['result']);
            $this->assertStringContainsString('broken-config', $single['message']);
        } finally {
            putenv('PATH=' . $path);
            if ($saved === false) @unlink(COMPOSE_UPDATE_STATUS_FILE);
            else file_put_contents(COMPOSE_UPDATE_STATUS_FILE, $saved);
            if ($pending === false) @unlink(PENDING_RECHECK_FILE);
            else file_put_contents(PENDING_RECHECK_FILE, $pending);
        }
    }

    public function testDockerFilterNamesUseRuntimeProjectIdentityAndIncludeAllStates(): void
    {
        $this->createTestStack('renamed-folder', ['project_name' => 'saved-project']);
        $directory = $this->createTempDir();
        $rows = [];
        foreach ([
            ['running-app', 'saved-project', 'running'],
            ['/stopped-app', 'saved-project', 'exited'],
            ['one-off-job', 'saved-project', 'exited'],
            ['unmanaged-app', 'another-project', 'running'],
        ] as [$name, $project, $state]) {
            $rows[] = json_encode([
                'Names' => $name, 'Image' => 'app:latest', 'State' => $state,
                'Labels' => 'com.docker.compose.project=' . $project,
            ]);
        }
        file_put_contents($directory . '/docker', "#!/bin/sh\nif [ \"\$1\" = ps ]; then\ncat <<'ROWS'\n"
            . implode("\n", $rows) . "\nROWS\nelse\nexit 9\nfi\n");
        chmod($directory . '/docker', 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $directory . PATH_SEPARATOR . $path);
        try {
            $response = json_decode($this->executeAction('getComposeContainerNames'), true);
            $this->assertSame('success', $response['result']);
            $this->assertSame(['running-app', 'stopped-app', 'one-off-job'], $response['names']);
            file_put_contents($directory . '/docker', "#!/bin/sh\nexit 0\n");
            $response = json_decode($this->executeAction('getComposeContainerNames'), true);
            $this->assertSame(['result' => 'success', 'names' => []], $response);
        } finally {
            putenv('PATH=' . $path);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUpdateSnapshots')]
    public function testDockerFilterEnumerationReportsFailures(string $command, string $message): void
    {
        $directory = $this->createTempDir();
        file_put_contents($directory . '/docker', "#!/bin/sh\n$command\n");
        chmod($directory . '/docker', 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $directory . PATH_SEPARATOR . $path);
        try {
            $response = json_decode($this->executeAction('getComposeContainerNames'), true);
            $this->assertSame('error', $response['result']);
            $this->assertStringContainsString($message, $response['message']);
            $this->assertArrayNotHasKey('names', $response);
        } finally {
            putenv('PATH=' . $path);
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
                file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode(['empty-stack' => [
                    'hasUpdate' => true, 'containers' => [[
                        'name' => 'removed', 'image' => 'app:latest',
                        'hasUpdate' => true, 'updateStatus' => 'update-available',
                    ]],
                ]]));
                $response = json_decode($this->executeAction($action, ['script' => 'empty-stack']), true);
                $this->assertSame('success', $response['result']);
                $updates = $action === 'checkStackUpdates'
                    ? $response['updates'] : $response['stacks']['empty-stack']['containers'];
                $this->assertSame([], $updates);
                $saved = \StackUpdateService::readSavedResults();
                $this->assertFalse($saved['empty-stack']['hasUpdate']);
                $this->assertSame('empty-stack', $saved['empty-stack']['projectName']);
            }

        } finally {
            putenv('PATH=' . $path);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
        }
    }

    public static function invalidUpdateSnapshots(): array
    {
        return [
            'command failure' => ["echo 'fixture daemon unavailable' >&2; exit 7", 'Unable to enumerate Compose containers'],
            'truncated JSON' => ["printf '%s\\n' '{\"Names\":'", 'Syntax error'],
            'invalid row' => ["echo '[]'", 'Invalid Docker container row'],
            'partial valid then malformed' => [
                "echo '{\"Names\":\"app\",\"Image\":\"app:latest\",\"Labels\":\"com.docker.compose.project=failed-stack\"}'; echo '{'",
                'Syntax error',
            ],
            'missing project label' => [
                "echo '{\"Names\":\"app\",\"Image\":\"app:latest\",\"Labels\":\"com.docker.compose.service=app\"}'",
                'Missing Compose project label',
            ],
        ];
    }

    public function testCheckInvalidatedDuringDockerInspectionIsExplicitlySuperseded(): void
    {
        $this->createTestStack('superseded-stack');
        require_once '/usr/local/emhttp/plugins/compose.manager/include/ContainerMetadataCache.php';
        file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, json_encode(['superseded-stack' => ['app' => [
            'name' => 'previous-app', 'service' => 'app', 'image' => 'previous:latest',
            'icon' => '/previous.png', 'state' => 'running',
        ]]]));
        $previousMetadata = \ContainerMetadataCache::read();
        $directory = $this->createTempDir();
        $invalidate = $directory . '/invalidate.php';
        file_put_contents($invalidate, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true)
            . '; require "/usr/local/emhttp/plugins/compose.manager/include/StackUpdateService.php";'
            . 'StackUpdateService::removeSavedResult("superseded-stack");');
        $row = [
            'Names' => 'superseded-app-new', 'Image' => 'new-app@sha256:pinned',
            'Labels' => 'com.docker.compose.project=superseded-stack,com.docker.compose.service=app',
        ];
        \DockerClient::setMockResponse('/containers/superseded-app-new/json', ['Image' => 'sha256:pinned']);
        file_put_contents($directory . '/docker', "#!/bin/sh\ncase \"\$1\" in\nps) "
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($invalidate) . "\n"
            . "printf '%s\\n' " . escapeshellarg(json_encode($row)) . " ;;\n"
            . "compose) echo '{\"services\":{\"app\":{\"image\":\"new-app@sha256:pinned\"}}}' ;;\n"
            . "*) exit 0 ;;\nesac\n");
        chmod($directory . '/docker', 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $directory . PATH_SEPARATOR . $path);
        try {
            foreach (['checkStackUpdates', 'checkAllStacksUpdates'] as $action) {
                \StackInfo::clearCache();
                $response = json_decode($this->executeAction($action, ['script' => 'superseded-stack']), true);
                if ($action === 'checkStackUpdates') {
                    $this->assertSame('superseded', $response['result'], json_encode($response));
                    $this->assertArrayNotHasKey('recheckToken', $response);
                    $this->assertArrayNotHasKey('updates', $response);
                } else {
                    $this->assertSame('success', $response['result']);
                    $this->assertContains('superseded-stack', $response['superseded']);
                    $this->assertArrayNotHasKey('superseded-stack', $response['stacks']);
                }
                $this->assertArrayNotHasKey('superseded-stack', \StackUpdateService::readSavedResults());
                $this->assertSame($previousMetadata, \composeLoadPersistentContainerCache(), 'Superseded checks must not publish replacement container names, images, or icons.');
            }
        } finally {
            putenv('PATH=' . $path);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUpdateSnapshots')]
    public function testFailedUpdateEnumerationPreservesSavedEvidence(string $command, string $message): void
    {
        $this->createTestStack('failed-stack');
        $directory = $this->createTempDir();
        file_put_contents($directory . '/docker', "#!/bin/sh\ncase \"\$1\" in\nps) $command ;;\n*) exit 0 ;;\nesac\n");
        chmod($directory . '/docker', 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $directory . PATH_SEPARATOR . $path);
        $saved = json_encode([
            'failed-stack' => ['hasUpdate' => true, 'lastChecked' => 123, 'containers' => [[
                'name' => 'app', 'image' => 'app:latest', 'hasUpdate' => true, 'updateStatus' => 'update-available',
            ]]],
            'other-stack' => ['hasUpdate' => true, 'containers' => []],
        ]);
        try {
            foreach (['checkStackUpdates', 'checkAllStacksUpdates'] as $action) {
                \StackInfo::clearCache();
                file_put_contents(COMPOSE_UPDATE_STATUS_FILE, $saved);
                $response = json_decode($this->executeAction($action, ['script' => 'failed-stack']), true);
                $this->assertSame('error', $response['result']);
                $this->assertStringContainsString($message, $response['message']);
                $stored = json_decode(file_get_contents(COMPOSE_UPDATE_STATUS_FILE), true);
                $this->assertSame(json_decode($saved, true), $stored['stacks']);
                $this->assertSame(1, $stored['version']);
            }
        } finally {
            putenv('PATH=' . $path);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
        }
    }

    public function testOneOffRemainsVisibleButCannotRegainPendingEvidenceOnExpansion(): void
    {
        $this->createTestStack('jobs');
        $directory = $this->createTempDir();
        $job = [
            'Name' => 'job', 'Names' => 'job', 'Service' => 'app',
            'Image' => 'registry.example/app:latest', 'State' => 'exited',
            'Labels' => 'com.docker.compose.project=jobs,com.docker.compose.service=app,com.docker.compose.oneoff=True',
        ];
        $inspect = [
            'Id' => 'job-id', 'Image' => 'sha256:old',
            'Config' => ['Image' => $job['Image'], 'Labels' => [
                'com.docker.compose.project' => 'jobs', 'com.docker.compose.service' => 'app',
                'com.docker.compose.oneoff' => 'True',
            ]],
            'State' => ['Status' => 'exited'],
        ];
        file_put_contents($directory . '/docker', "#!/bin/sh\ncase \"\$1\" in\n"
            . "ps) echo " . escapeshellarg(json_encode($job)) . " ;;\n"
            . "compose) echo " . escapeshellarg(json_encode(array_diff_key($job, ['Labels' => true]))) . " ;;\n"
            . "inspect) echo " . escapeshellarg(json_encode($inspect)) . " ;;\nesac\n");
        chmod($directory . '/docker', 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $directory . PATH_SEPARATOR . $path);
        try {
            foreach (['checkStackUpdates', 'checkAllStacksUpdates'] as $action) {
                \StackInfo::clearCache();
                $response = json_decode($this->executeAction($action, ['script' => 'jobs']), true);
                $this->assertSame('success', $response['result']);
                $updates = $action === 'checkStackUpdates' ? $response['updates'] : $response['stacks']['jobs']['containers'];
                $this->assertSame([], $updates);
                $this->assertFalse(\StackUpdateService::readSavedResults()['jobs']['hasUpdate']);
            }
            file_put_contents(UNRAID_UPDATE_STATUS_FILE, json_encode([$job['Image'] => [
                'local' => 'sha256:new', 'remote' => 'sha256:new', 'configDigest' => 'sha256:new', 'status' => 'true',
            ]]));
            file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode(['jobs' => [
                'hasUpdate' => true, 'containers' => [[
                    'name' => 'job', 'image' => $job['Image'], 'localSha' => 'old', 'remoteSha' => 'new',
                    'hasUpdate' => true, 'updateStatus' => 'update-available', 'updateReadiness' => 'ready-to-apply',
                ]],
            ]]));
            foreach ([1, 2] as $reload) {
                \StackInfo::clearCache();
                $response = json_decode($this->executeAction('getStackContainers', ['script' => 'jobs']), true);
                $this->assertSame('success', $response['result']);
                $this->assertCount(1, $response['containers'], 'Runtime jobs remain visible.');
                $container = $response['containers'][0];
                $this->assertSame('job', $container['name']);
                $this->assertTrue($container['oneOff'], 'Inspection must recover labels omitted by older Compose ps.');
                $this->assertFalse($container['hasUpdate']);
                $this->assertSame('unknown', $container['updateStatus']);
                $this->assertSame('', $container['remoteSha']);
                $cache = \composeLoadPersistentContainerCache();
                $this->assertArrayNotHasKey('app', $cache['jobs'] ?? [], 'A job must not replace service metadata.');
            }
        } finally {
            putenv('PATH=' . $path);
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
            @unlink(UNRAID_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
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
                $cache = \composeLoadPersistentContainerCache();
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
            . "*'config --no-consistency --format json'*) printf '%s\\n' " . escapeshellarg(json_encode($config)) . " ;;\n"
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

    public function testBuildOnlyRebuildSurvivesReloadAndExpansionWhenPsShowsImageId(): void
    {
        $this->createTestStack('build-only');
        $context = $this->createTempDir();
        file_put_contents($context . '/Dockerfile', "FROM fixture/base:latest\n");
        $imageId = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $reference = 'build-only-app';
        $row = [
            'Name' => 'built-app', 'Service' => 'app', 'Image' => '0123456789ab', 'State' => 'running',
        ];
        $inspect = ['Image' => $imageId, 'Config' => ['Image' => $reference], 'State' => []];
        \DockerClient::setMockResponse('/containers/built-app/json', $inspect);
        \DockerClient::setMockResponse('/images/' . rawurlencode($imageId) . '/json', [
            'RootFS' => ['Layers' => ['sha256:old-base', 'sha256:application']],
        ]);
        \DockerClient::setMockResponse('/images/' . rawurlencode($reference) . '/json', ['Id' => $imageId]);
        $checker = new class extends \ComposeUpdateCheck {
            protected function fetchRemoteImageConfig(string $image, ?string $platform = null): ?array
            {
                return ['digest' => 'sha256:new-base', 'diffIds' => ['sha256:new-base-layer']];
            }
        };
        $result = $checker->checkContainers([$row], [
            'app' => ['image' => null, 'build' => ['context' => $context]],
        ])['built-app'];
        $this->assertSame('rebuild-required', $result['updateReadiness']);
        $this->assertTrue($result['hasUpdate']);
        $this->assertSame($reference, $result['image']);
        $update = \ContainerInfo::fromUpdateResponse([
            'name' => 'built-app', 'service' => 'app', 'image' => $result['image'],
            'localSha' => substr($result['local'], 7, 12),
            'remoteSha' => $result['remote'], 'hasUpdate' => $result['hasUpdate'],
            'status' => $result['status'], 'updateKind' => $result['updateKind'],
            'updateReadiness' => $result['updateReadiness'],
        ])->toUpdateArray();
        $save = new \ReflectionMethod(\StackUpdateService::class, 'saveResults');
        $ownership = (new \ReflectionMethod(\StackUpdateService::class, 'reserveChecks'))->invoke(null, ['build-only'], false);
        $save->invoke(new \StackUpdateService(), [
            'build-only' => ['projectName' => 'build-only', 'containers' => [$update]],
        ], false, $ownership);
        $bin = $this->createTempDir();
        file_put_contents($bin . '/docker', "#!/bin/sh\ncase \"\$1\" in\n"
            . "inspect) printf '%s\\n' " . escapeshellarg(json_encode($inspect)) . " ;;\n"
            . "compose) printf '%s\\n' " . escapeshellarg(json_encode($row)) . " ;;\nesac\n");
        chmod($bin . '/docker', 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        try {
            for ($refresh = 0; $refresh < 3; $refresh++) {
                \StackInfo::clearCache();
                $saved = json_decode($this->executeAction('getSavedUpdateStatus'), true);
                $this->assertTrue($saved['stacks']['build-only']['hasUpdate']);
                $this->assertSame('rebuild-required', $saved['stacks']['build-only']['containers'][0]['updateReadiness']);
                $details = json_decode($this->executeAction('getStackContainers', ['script' => 'build-only']), true);
                $this->assertSame('success', $details['result']);
                $container = $details['containers'][0];
                $this->assertSame($reference, $container['image']);
                $this->assertTrue($container['hasUpdate']);
                $this->assertSame('build-base', $container['updateKind']);
                $this->assertSame('rebuild-required', $container['updateReadiness']);
                $this->assertSame($update['localSha'], $container['localSha']);
                $this->assertSame($update['remoteSha'], $container['remoteSha']);
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
            foreach (['checkStackUpdates', 'checkAllStacksUpdates'] as $action) {
                $response = json_decode($this->executeAction($action, ['script' => 'empty-stack']), true);
                $this->assertSame('error', $response['result']);
                $this->assertNotEmpty($response['message']);
                $this->assertSame('not json', file_get_contents(COMPOSE_UPDATE_STATUS_FILE));
            }
            \StackUpdateService::clearSavedResults();
            $this->assertSame([], \StackUpdateService::readSavedResults());
            $this->assertStringContainsString(
                'Stack update check failed:',
                implode("\n", $GLOBALS['composeLoggerMessages'])
            );
        } finally {
            @unlink(COMPOSE_UPDATE_STATUS_FILE);
        }
    }

    public function testUiOperationEndpointDoesNotTreatMissingOrQueuedReceiptsAsCompleted(): void
    {
        require_once '/usr/local/emhttp/plugins/compose.manager/include/UiOperation.php';
        $id = bin2hex(random_bytes(16));
        $file = \composeUiOperationFile('operation-stack', $id);
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        try {
            $missing = json_decode($this->executeAction('getUiOperation', ['script' => 'operation-stack', 'operationId' => $id]), true);
            $this->assertSame('error', $missing['result']);
            foreach ([['state' => 'queued'], ['state' => 'running'], ['state' => 'completed', 'exitCode' => 7]] as $receipt) {
                file_put_contents($file, json_encode($receipt));
                $response = json_decode($this->executeAction('getUiOperation', ['script' => 'operation-stack', 'operationId' => $id]), true);
                $this->assertSame('success', $response['result']);
                $this->assertSame($receipt, $response['operation']);
            }
            $otherStack = json_decode($this->executeAction('getUiOperation', ['script' => 'other-stack', 'operationId' => $id]), true);
            $this->assertSame('error', $otherStack['result']);
            $invalid = json_decode($this->executeAction('getUiOperation', ['script' => 'operation-stack', 'operationId' => '../outside']), true);
            $this->assertSame('error', $invalid['result']);
        } finally {
            @unlink($file);
        }
    }

    public static function cachedImageStatusCases(): array
    {
        return [
            'tag prefix' => ['ghcr.io/example/app:v3.3.5', 'ghcr.io/example/app:v3', 'ghcr.io/example/app:v3.3.5', null, 'unknown'],
            'official full reference' => ['docker.io/library/postgres:18', 'postgres:16', 'library/postgres:18', null, 'unknown'],
            'official short reference' => ['redis:8', 'other/redis:8', 'docker.io/library/redis:8', null, 'unknown'],
            'old tag only' => ['ghcr.io/example/app:v3.3.5', 'ghcr.io/example/app:v3', null, null, 'unknown'],
            'already pulled but not recreated' => ['postgres:18', 'postgres:16', 'library/postgres:18', 'oldimage', 'unknown', true],
            'native manifest digest is not a config ID' => ['postgres:18', 'postgres:16', 'library/postgres:18', 'oldimage', 'unknown', false],
            'native writer replaced the remote digest' => ['postgres:18', 'postgres:16', 'library/postgres:18', 'oldimage', 'unknown', false, 'previous-config'],
            'config digest needs actual container ID' => ['postgres:18', 'postgres:16', 'library/postgres:18', null, 'unknown', true],
            'recreated container matches config digest' => ['postgres:18', 'postgres:16', 'library/postgres:18', 'current', 'unknown', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cachedImageStatusCases')]
    public function testContainerDetailsIgnoreNativeImageEvidenceWithoutAcceptedPluginEvidence(
        string $image,
        string $oldImage,
        ?string $currentImage,
        ?string $containerImageId,
        string $expectedStatus,
        bool $configDigest = false,
        ?string $previousConfigDigest = null
    ): void
    {
        @unlink(COMPOSE_UPDATE_STATUS_FILE);
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
        $nativeBytes = json_encode($statuses, JSON_PRETTY_PRINT) . "\n";
        file_put_contents(UNRAID_UPDATE_STATUS_FILE, $nativeBytes);
        try {
            $response = json_decode($this->executeAction('getStackContainers', ['script' => 'status-stack']), true);
            $this->assertSame('success', $response['result']);
            $this->assertCount(1, $response['containers']);
            $this->assertSame($expectedStatus === 'update-available', $response['containers'][0]['hasUpdate']);
            $this->assertSame($expectedStatus, $response['containers'][0]['updateStatus']);
            $this->assertSame('unknown', $response['containers'][0]['updateReadiness']);
            $this->assertSame('', $response['containers'][0]['localSha']);
            $this->assertSame('', $response['containers'][0]['remoteSha']);
            $this->assertSame($nativeBytes, file_get_contents(UNRAID_UPDATE_STATUS_FILE), 'Details must not rewrite native Docker evidence.');
        } finally {
            putenv('PATH=' . $path);
            @unlink(UNRAID_UPDATE_STATUS_FILE);
            @unlink(COMPOSE_CONTAINER_CACHE_FILE);
        }
    }

    public function testBusyCanonicalProjectDefersEndpointWithoutAcknowledgementToken(): void
    {
        $this->createTestStack('Display Folder', ['project_name' => 'canonical-project']);
        $this->executeAction('getPersistentContainerCache');
        \PendingRechecks::mark(['Display Folder']);
        $token = \PendingRechecks::token('Display Folder');
        $lockDirectory = getenv('COMPOSE_LOCK_DIR');
        $directory = $this->createTempDir();
        putenv('COMPOSE_LOCK_DIR=' . $directory);
        $lock = fopen($directory . '/canonical-project.lock', 'c+');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $response = json_decode($this->executeAction('checkStackUpdates', ['script' => 'Display Folder']), true);
            $this->assertSame('superseded', $response['result'], json_encode($response));
            $this->assertArrayNotHasKey('recheckToken', $response);
            $response = json_decode($this->executeAction('clearStackRecheck', [
                'stacks' => json_encode(['Display Folder']),
                'tokens' => json_encode(['Display Folder' => $token]),
            ]), true);
            $this->assertSame('success', $response['result'], json_encode($response));
            $this->assertArrayHasKey('Display Folder', \PendingRechecks::timestamps());
        } finally {
            fclose($lock);
            putenv($lockDirectory === false ? 'COMPOSE_LOCK_DIR' : 'COMPOSE_LOCK_DIR=' . $lockDirectory);
            \PendingRechecks::remove('Display Folder');
        }
    }

    public function testDetailCollectionSpanningMutationDoesNotPublishMetadata(): void
    {
        $this->createTestStack('details-race');
        $directory = $this->createTempDir();
        $path = getenv('PATH');
        $lockDirectory = getenv('COMPOSE_LOCK_DIR');
        putenv('COMPOSE_LOCK_DIR=' . $directory);
        $row = json_encode(['Name' => 'new-app', 'Service' => 'app', 'Image' => 'alpine:latest', 'State' => 'running']);
        file_put_contents($directory . '/docker', "#!/bin/sh\ncase \"\$1\" in\n"
            . "compose) printf '%s' " . escapeshellarg(str_repeat('b', 32)) . ' > '
            . escapeshellarg($directory . '/details-race.epoch') . "\n echo " . escapeshellarg($row) . " ;;\n"
            . "inspect) echo '{}';;\nesac\n");
        chmod($directory . '/docker', 0755);
        putenv('PATH=' . $directory . PATH_SEPARATOR . $path);
        $original = is_file(COMPOSE_CONTAINER_CACHE_FILE) ? file_get_contents(COMPOSE_CONTAINER_CACHE_FILE) : false;
        file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, '{"details-race":{"app":{"name":"kept","service":"app","image":"kept:latest"}}}');
        try {
            $response = json_decode($this->executeAction('getStackContainers', ['script' => 'details-race']), true);
            $this->assertSame('success', $response['result'], json_encode($response));
            $this->assertSame('new-app', $response['containers'][0]['name']);
            $this->assertSame('kept', \ContainerMetadataCache::read()['details-race']['app']['name']);
        } finally {
            putenv('PATH=' . $path);
            putenv($lockDirectory === false ? 'COMPOSE_LOCK_DIR' : 'COMPOSE_LOCK_DIR=' . $lockDirectory);
            if ($original === false) @unlink(COMPOSE_CONTAINER_CACHE_FILE);
            else file_put_contents(COMPOSE_CONTAINER_CACHE_FILE, $original);
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

    public function testSharedSaveRequiresFreshConfirmationAndDismissalIsPerStackAndReversible(): void
    {
        $a = $this->createTestStack('a');
        $b = $this->createTestStack('b');
        file_put_contents($b . '/indirect', $a . '/compose.yaml');
        file_put_contents($b . '/indirect_mode', 'file');
        \StackInfo::clearCache();
        $original = file_get_contents($a . '/compose.yaml');
        $payload = ['script' => 'a', 'scriptContents' => "services:\n  app:\n    image: busybox\n"];
        $challenge = json_decode($this->executeAction('saveYml', $payload), true);
        $this->assertSame('shared_source_confirmation', $challenge['errorCode']);
        $this->assertSame(['b'], $challenge['shared'][0]['stacks']);
        $this->assertSame($original, file_get_contents($a . '/compose.yaml'));
        $this->assertStringContainsString('saved', $this->executeAction('saveYml', $payload + ['sharedConfirmation' => $challenge['confirmation']]));
        $dismissed = json_decode($this->executeAction('setSharedSourceWarning', ['script' => 'a', 'dismissed' => '1']), true);
        $this->assertTrue($dismissed['dismissed']);
        $this->assertStringContainsString('saved', $this->executeAction('saveYml', $payload));
        $other = json_decode($this->executeAction('getSharedStackSources', ['script' => 'b']), true);
        $this->assertFalse($other['dismissed']);
        $this->assertNotEmpty($other['shared']);
        $this->executeAction('setSharedSourceWarning', ['script' => 'a', 'dismissed' => '0']);
        $this->assertSame('shared_source_confirmation', json_decode($this->executeAction('saveYml', $payload), true)['errorCode']);
    }

    public function testLoadedRevisionsRejectStaleContentsForEveryEditorSaveAndAllowRepeatedOwnedSaves(): void
    {
        $path = $this->createTestStack('revision');
        $cases = [
            ['getYml', 'saveYml', $path . '/compose.yaml', "services:\n  app:\n    image: alpine\n"],
            ['getEnv', 'saveEnv', $path . '/.env', "VALUE=first\r\n"],
            ['getOverride', 'saveOverride', $path . '/compose.override.yaml', "services: {}\n"],
        ];
        foreach ($cases as [$get, $save, $file, $contents]) {
            $loaded = json_decode($this->executeAction($get, ['script' => 'revision']), true);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $loaded['revision']);
            $payload = ['script' => 'revision', 'scriptContents' => $contents, 'expectedPath' => $loaded['fileName'],
                'expectedRevision' => $loaded['revision'], 'managed' => '0'];
            $saved = json_decode($this->executeAction($save, $payload), true);
            $this->assertSame('success', $saved['result']);
            $this->assertSame(\StackSourceEditor::revision($contents, true), $saved['revision']);
            $before = filemtime($file);
            file_put_contents($file, 'external changes');
            touch($file, $before);
            $rejected = json_decode($this->executeAction($save, array_replace($payload, ['expectedRevision' => $saved['revision']])), true);
            $this->assertSame('stale_content', $rejected['errorCode']);
            $this->assertSame('external changes', file_get_contents($file));
            $fresh = json_decode($this->executeAction($get, ['script' => 'revision']), true);
            $second = json_decode($this->executeAction($save, array_replace($payload, ['expectedRevision' => $fresh['revision']])), true);
            $this->assertSame('success', $second['result']);
        }
    }

    public function testMissingFileRevisionDoesNotPermitOverwritingNewlyCreatedEmptyFile(): void
    {
        $path = $this->createTestStack('missing-revision');
        $loaded = json_decode($this->executeAction('getEnv', ['script' => 'missing-revision']), true);
        $this->assertFalse($loaded['exists']);
        file_put_contents($path . '/.env', '');
        $rejected = json_decode($this->executeAction('createEnvTemplate', [
            'script' => 'missing-revision', 'expectedPath' => $loaded['fileName'], 'expectedRevision' => $loaded['revision'],
        ]), true);
        $this->assertSame('stale_content', $rejected['errorCode']);
        $this->assertSame('', file_get_contents($path . '/.env'));
    }

    public function testDismissedSharingDoesNotBypassContentConflictProtection(): void
    {
        $a = $this->createTestStack('conflict-a');
        $b = $this->createTestStack('conflict-b');
        file_put_contents($b . '/indirect', $a . '/compose.yaml');
        file_put_contents($b . '/indirect_mode', 'file');
        \StackInfo::clearCache();
        $this->executeAction('setSharedSourceWarning', ['script' => 'conflict-a', 'dismissed' => '1']);
        $this->executeAction('setSharedSourceWarning', ['script' => 'conflict-b', 'dismissed' => '1']);
        $first = json_decode($this->executeAction('getYml', ['script' => 'conflict-a']), true);
        $second = json_decode($this->executeAction('getYml', ['script' => 'conflict-b']), true);
        $saved = json_decode($this->executeAction('saveYml', [
            'script' => 'conflict-a', 'expectedRevision' => $first['revision'], 'scriptContents' => "services:\n  new:\n    image: busybox\n",
        ]), true);
        $this->assertSame('success', $saved['result']);
        $rejected = json_decode($this->executeAction('saveYml', [
            'script' => 'conflict-b', 'expectedRevision' => $second['revision'], 'scriptContents' => "services: {}\n",
        ]), true);
        $this->assertSame('stale_content', $rejected['errorCode']);
        $this->assertStringContainsString('new:', file_get_contents($a . '/compose.yaml'));
    }

    public function testLabelsSaveRejectsChangedMainComposeWithoutChangingOverride(): void
    {
        $path = $this->createTestStack('labels-revision');
        $main = json_decode($this->executeAction('getYml', ['script' => 'labels-revision']), true);
        $override = json_decode($this->executeAction('getOverride', ['script' => 'labels-revision']), true);
        file_put_contents($path . '/compose.yaml', "services:\n  newer:\n    image: busybox\n");
        $result = json_decode($this->executeAction('saveOverride', [
            'script' => 'labels-revision', 'managed' => '1', 'scriptContents' => "services: {}\n",
            'expectedRevision' => $override['revision'], 'expectedComposeRevision' => $main['revision'],
        ]), true);
        $this->assertSame('stale_content', $result['errorCode']);
        $this->assertFileDoesNotExist($path . '/compose.override.yaml');
    }

    public function testCreatedOverrideRevisionSupportsImmediateLabelsSave(): void
    {
        $this->createTestStack('labels-template');
        $main = json_decode($this->executeAction('getYml', ['script' => 'labels-template']), true);
        $override = json_decode($this->executeAction('getOverride', ['script' => 'labels-template']), true);
        $created = json_decode($this->executeAction('createOverrideTemplate', [
            'script' => 'labels-template', 'expectedPath' => $override['fileName'],
            'expectedRevision' => $override['revision'],
        ]), true);
        $this->assertSame('success', $created['result']);
        $saved = json_decode($this->executeAction('saveOverride', [
            'script' => 'labels-template', 'managed' => '1', 'expectedPath' => $created['fileName'],
            'expectedRevision' => $created['revision'], 'expectedComposeRevision' => $main['revision'],
            'scriptContents' => "services: {}\n",
        ]), true);
        $this->assertSame('success', $saved['result']);
        $this->assertSame("services: {}\n", file_get_contents($created['fileName']));
    }

    public function testMainComposeSaveWarnsBeforeMigratingSharedOverride(): void
    {
        $a = $this->createTestStack('migration-a');
        $b = $this->createTestStack('migration-b');
        $override = $a . '/compose.override.yaml';
        file_put_contents($override, "services:\n  app:\n    labels:\n      example: value\n");
        file_put_contents($b . '/indirect', $override);
        file_put_contents($b . '/indirect_mode', 'file');
        \StackInfo::clearCache();
        $original = file_get_contents($a . '/compose.yaml');
        $challenge = json_decode($this->executeAction('saveYml', [
            'script' => 'migration-a', 'scriptContents' => "services:\n  renamed:\n    image: alpine\n",
        ]), true);
        $this->assertSame('shared_source_confirmation', $challenge['errorCode']);
        $this->assertSame($override, $challenge['shared'][0]['path']);
        $this->assertSame(['migration-b'], $challenge['shared'][0]['stacks']);
        $this->assertSame($original, file_get_contents($a . '/compose.yaml'));
        $this->assertStringContainsString('app:', file_get_contents($override));
    }

    public function testSourceWritersRespectCanonicalLockAndInvalidateOldAcknowledgements(): void
    {
        $path = $this->createTestStack('source-owner');
        $stack = \StackInfo::fromProject($this->testComposeRoot, 'source-owner');
        $originalPending = is_file(PENDING_RECHECK_FILE) ? file_get_contents(PENDING_RECHECK_FILE) : false;
        $oldLockDirectory = getenv('COMPOSE_LOCK_DIR');
        $lockDirectory = $this->createTempDir();
        putenv('COMPOSE_LOCK_DIR=' . $lockDirectory);
        try {
            file_put_contents(PENDING_RECHECK_FILE, '{}');
            $actions = [
                'saveYml' => ['scriptContents' => "services:\n  app:\n    image: alpine\n"],
                'saveEnv' => ['scriptContents' => "A=1\n"],
                'saveOverride' => ['managed' => '1', 'scriptContents' => "services: {}\n"],
                'createEnvTemplate' => [],
                'createOverrideTemplate' => [],
                'setEnvPath' => ['envPath' => ''],
                'setStackSettings' => ['extraComposeFiles' => ''],
            ];
            foreach ($actions as $action => $data) {
                $lock = \StackMutationGuard::tryExclusive($stack->projectName);
                $this->assertIsResource($lock);
                try {
                    $result = json_decode($this->executeAction($action, ['script' => 'source-owner'] + $data), true);
                    $this->assertSame('error', $result['result'], $action);
                    $this->assertStringContainsString('operation is in progress', $result['message'], $action);
                } finally {
                    fclose($lock);
                }
                \PendingRechecks::mark(['source-owner']);
                $token = \PendingRechecks::token('source-owner');
                $epoch = \StackMutationGuard::epoch($stack->projectName);
                $output = $this->executeAction($action, ['script' => 'source-owner'] + $data);
                $this->assertTrue(str_contains($output, 'saved') || (json_decode($output, true)['result'] ?? '') === 'success', $output);
                $this->assertNotSame($epoch, \StackMutationGuard::epoch($stack->projectName), $action);
                $this->assertSame(['source-owner'], \PendingRechecks::acknowledge(['source-owner'], ['source-owner' => $token]), $action);
                \PendingRechecks::acknowledge(['source-owner'], ['source-owner' => \PendingRechecks::token('source-owner')]);
                $this->executeAction($action, ['script' => 'source-owner'] + $data);
                $this->assertSame([], \PendingRechecks::timestamps(), $action . ' must not create automatic checks.');
            }
            $this->assertFileExists($path . '/.env');
        } finally {
            putenv($oldLockDirectory === false ? 'COMPOSE_LOCK_DIR' : 'COMPOSE_LOCK_DIR=' . $oldLockDirectory);
            if ($originalPending === false) @unlink(PENDING_RECHECK_FILE);
            else file_put_contents(PENDING_RECHECK_FILE, $originalPending);
        }
    }

    public function testFailedComposeSaveDoesNotMigrateOverrideAndReportsFailure(): void
    {
        $path = $this->createTestStack('save-failure');
        $main = $path . '/' . COMPOSE_FILE_NAMES[0];
        unlink($main);
        mkdir($main);
        $override = "services:\n  old:\n    labels:\n      example: keep\n";
        file_put_contents($path . '/compose.override.yaml', $override);
        $result = json_decode($this->executeAction('saveYml', [
            'script' => 'save-failure', 'scriptContents' => "services:\n  renamed:\n    image: alpine\n",
        ]), true);
        $this->assertSame('error', $result['result']);
        $this->assertSame($override, file_get_contents($path . '/compose.override.yaml'));
    }

    public function testSourceSettingsWriteFailureIsNotReportedAsSuccess(): void
    {
        $path = $this->createTestStack('settings-failure');
        mkdir($path . '/extra_compose_files');
        file_put_contents($path . '/other.yaml', "services: {}\n");
        $result = json_decode($this->executeAction('setStackSettings', [
            'script' => 'settings-failure', 'extraComposeFiles' => 'other.yaml',
        ]), true);
        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('not a file', $result['message']);
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
        $this->assertSame('shared_source_confirmation', $result['errorCode']);
        $this->assertFileDoesNotExist($indirectDir . '/.env');
        $result = json_decode($this->executeAction('createEnvTemplate', [
            'script' => 'test-stack', 'sharedConfirmation' => $result['confirmation'],
        ]), true);
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

    public function testFailedOverrideRegistrationRestoresSourceAndRetainsRevision(): void
    {
        $path = $this->createTestStack('test-stack');
        file_put_contents($path . '/project_name', 'test-stack');
        $state = json_encode(['projectName' => 'test-stack', 'composeFiles' => [$path . '/compose.yaml']]);
        file_put_contents($path . '/project_identity.json', $state);
        $override = $path . '/compose.override.yaml';
        file_put_contents($override, "services:\n  app: {}\n");
        $before = file_get_contents($override);
        mkdir($path . '/project_identity.lock');
        $revision = \StackSourceEditor::revision($before, true);
        $output = $this->executeAction('saveOverride', ['script' => 'test-stack',
            'scriptContents' => "services: {}\n", 'expectedRevision' => $revision]);
        $result = json_decode($output, true);
        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('registration', $result['message']);
        $this->assertSame($before, file_get_contents($override));
        $this->assertSame($revision, \StackSourceEditor::revision(file_get_contents($override), true));
        $this->assertSame($state, file_get_contents($path . '/project_identity.json'));
    }

    public function testFailedTemplateRegistrationRemovesNewOverride(): void
    {
        $path = $this->createTestStack('test-stack');
        file_put_contents($path . '/project_name', 'test-stack');
        $state = json_encode(['projectName' => 'test-stack', 'composeFiles' => [$path . '/compose.yaml']]);
        file_put_contents($path . '/project_identity.json', $state);
        @unlink($path . '/compose.override.yaml');
        mkdir($path . '/project_identity.lock');
        $output = $this->executeAction('createOverrideTemplate', ['script' => 'test-stack']);
        $this->assertSame('error', json_decode($output, true)['result']);
        $this->assertFileDoesNotExist($path . '/compose.override.yaml');
        $this->assertSame($state, file_get_contents($path . '/project_identity.json'));
    }

    public function testIdentityAuditUsesOneFreshSnapshotPerRequest(): void
    {
        foreach (['first', 'second'] as $project) {
            $path = $this->createTestStack($project);
            file_put_contents($path . '/project_name', $project);
        }
        $calls = 0;
        \ProjectIdentity::setProbe(static function () use (&$calls): array {
            $calls++;
            return [];
        });
        try {
            \ProjectIdentity::probe();
            $this->assertSame(1, $calls);
            foreach ([2, 3] as $expected) {
                $result = json_decode($this->executeAction('getProjectIdentities', []), true);
                $this->assertCount(2, $result['identities']);
                $this->assertSame($expected, $calls);
                $this->assertSame(['no-evidence', 'no-evidence'], array_column($result['identities'], 'auditStatus'));
            }
        } finally {
            \ProjectIdentity::setProbe(null);
        }
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

    public function testSetStackSettingsSavesAutoContainerNames(): void
    {
        $stackPath = $this->createTestStack('test-stack');

        $saved = json_decode($this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'autoContainerName' => 'true',
        ]), true);
        $this->assertSame('success', $saved['result']);
        $this->assertSame('true', trim((string) file_get_contents($stackPath . '/auto_container_name')));

        $read = json_decode($this->executeAction('getStackSettings', ['script' => 'test-stack']), true);
        $this->assertTrue($read['autoContainerName']);

        $this->executeAction('setStackSettings', ['script' => 'test-stack', 'autoContainerName' => 'false']);
        $this->assertFileDoesNotExist($stackPath . '/auto_container_name');
    }

    public function testSetStackSettingsRefusesAutoContainerNamesWithDefaultComposeFileDiscovery(): void
    {
        $stackPath = $this->createTestStack('test-stack');

        $result = json_decode($this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'autoContainerName' => 'true',
            'useDefaultComposeFiles' => 'true',
        ]), true);

        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('default compose file discovery', $result['message']);
        $this->assertFileDoesNotExist($stackPath . '/auto_container_name');
    }

    public function testSetStackSettingsAcceptsAutoContainerNamesForADisplayNameWithSpaces(): void
    {
        $stackPath = $this->createTestStack('test-stack');
        file_put_contents($stackPath . '/name', 'My Stack');

        $result = json_decode($this->executeAction('setStackSettings', [
            'script' => 'test-stack',
            'autoContainerName' => 'true',
        ]), true);

        $this->assertSame('success', $result['result']);
        $this->assertSame('true', trim((string) file_get_contents($stackPath . '/auto_container_name')));
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
