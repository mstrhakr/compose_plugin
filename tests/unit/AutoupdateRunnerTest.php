<?php
/**
 * Tests for AutoUpdateRunner.php
 */
declare(strict_types=1);
namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class AutoupdateRunnerTest extends TestCase
{
    private ?string $testStackPath = null;
    private ?string $autoUpdateConfigFile = null;
    private ?string $wrapperPath = null;
    private ?string $markerPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        global $plugin_root, $compose_root;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager/';

        // TestCase::setUp() resets plugin config mocks. Re-seed compose root so
        // AutoUpdateRunner path validation uses a writable test directory.
        $compose_root = $this->createTempDir();
        \StackInfo::clearCache();
        if (!is_dir($compose_root)) {
            mkdir($compose_root, 0755, true);
        }
        $this->mockPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $compose_root,
            'DEBUG_TO_LOG' => 'false',
        ]);

        // Use writable temp files for test-only config and shell wrapper.
        $this->autoUpdateConfigFile = sys_get_temp_dir() . '/autoupdate_runner_cfg_' . getmypid() . '_' . uniqid('', true) . '.json';
        $this->wrapperPath = sys_get_temp_dir() . '/autoupdate_runner_wrapper_' . getmypid() . '_' . uniqid('', true) . '.php';
        $this->markerPath = sys_get_temp_dir() . '/autoupdate_runner_marker_' . getmypid() . '_' . uniqid('', true) . '.json';

        $shim = <<<'PHP'
<?php
$marker = getenv('AUTOTEST_MARKER');
if ($marker) {
    file_put_contents($marker, json_encode([
        'argv' => $argv,
        'parallelLimit' => getenv('COMPOSE_PARALLEL_LIMIT'),
        'credentialId' => getenv('COMPOSE_CREDENTIAL_ID'),
        'composeFiles' => getenv('COMPOSE_FILE_LIST'),
        'envFile' => getenv('COMPOSE_ENV_FILE'),
    ]));
}
exit(0);
PHP;
        file_put_contents($this->wrapperPath, $shim);
        // redirect cron writes to plugin root in tests (not strictly needed here but harmless)
        putenv('COMPOSE_MANAGER_CRON_DIR=' . $plugin_root);
        // ensure autoupdate config file resolves to a writable temp file in tests
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE=' . $this->autoUpdateConfigFile);
        putenv('AUTOTEST_MARKER=' . $this->markerPath);
    }

    protected function tearDown(): void
    {
        global $plugin_root;
        putenv('COMPOSE_MANAGER_CRON_DIR');
        putenv('COMPOSE_MANAGER_SH');
        putenv('AUTOTEST_MARKER');
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE');
        @unlink(COMPOSE_LEGACY_ORIGIN_FILE);
        \ProjectIdentity::setProbe(static fn(): array => []);
        \StackInfo::clearCache();

        if ($this->autoUpdateConfigFile !== null && is_file($this->autoUpdateConfigFile)) {
            unlink($this->autoUpdateConfigFile);
        }

        if ($this->wrapperPath !== null && is_file($this->wrapperPath)) {
            unlink($this->wrapperPath);
        }
        if ($this->markerPath !== null && is_file($this->markerPath)) {
            unlink($this->markerPath);
        }

        if ($this->testStackPath !== null && is_dir($this->testStackPath)) {
            foreach (scandir($this->testStackPath) as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $this->testStackPath . '/' . $f;
                if (is_file($p)) unlink($p);
            }
            rmdir($this->testStackPath);
        }

        $this->testStackPath = null;
        $this->autoUpdateConfigFile = null;
        $this->wrapperPath = null;
        $this->markerPath = null;
        parent::tearDown();
    }

    public function testRunnerTriggersScriptForDueStack(): void
    {
        global $plugin_root, $compose_root;
        // Create a stack folder
        $stack = 'mystack_' . getmypid();
        $path = $compose_root . '/' . $stack;
        $this->testStackPath = $path;
        mkdir($path, 0755, true);
        file_put_contents($path . '/docker-compose.yml', "services:\n web:\n  image: busybox\n");
        file_put_contents($path . '/credential_id', 'scheduled-credential');

        // Create autoupdate.json with entry scheduled for now
        // Use a deterministic due time to avoid timezone mismatches in runner logic.
        $cfg = [
            'defaults' => ['parallel_limit' => 2],
            $path => ['enabled' => true, 'schedule' => 'daily', 'time' => '00:00'],
        ];
        file_put_contents((string)$this->autoUpdateConfigFile, json_encode($cfg, JSON_PRETTY_PRINT));

        // Configure shim path for environments where process execution is available.
        putenv('COMPOSE_MANAGER_SH=' . PHP_BINARY . ' ' . escapeshellarg((string)$this->wrapperPath));

        // Run runner (include directly)
        include $plugin_root . 'include/AutoUpdateRunner.php';

        // Verify the background command actually ran and received both configured values.
        $deadline = microtime(true) + 3;
        while (!is_file((string) $this->markerPath) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($this->markerPath);
        $payload = json_decode((string) file_get_contents((string) $this->markerPath), true);
        $this->assertIsArray($payload);
        $this->assertSame('2', $payload['parallelLimit']);
        $this->assertSame('scheduled-credential', $payload['credentialId']);
        $this->assertSame(\StackInfo::sanitizeProjectString($stack), $payload['argv'][2] ?? null);

        // Verify runner considered this stack due and recorded last_run.
        $updated = json_decode((string) file_get_contents((string) $this->autoUpdateConfigFile), true);
        $this->assertIsArray($updated);
        $this->assertArrayHasKey($path, $updated);
        $this->assertArrayHasKey('last_run', $updated[$path]);
        $this->assertGreaterThan(0, (int) $updated[$path]['last_run']);
    }

    public static function migratedScheduleCases(): array
    {
        return [['ready'], ['invalid-pin'], ['missing-input']];
    }

    #[DataProvider('migratedScheduleCases')]
    public function testMigratedSchedulePreservesInputsAndRetriesBlockedStacks(string $mode): void
    {
        global $plugin_root, $compose_root;
        file_put_contents(COMPOSE_LEGACY_ORIGIN_FILE, \ProjectIdentity::LEGACY_REVISION);
        $folder = 'legacy-stack';
        $runtime = 'legacy___stack';
        $path = $compose_root . '/' . $folder;
        $this->testStackPath = $path;
        mkdir($path);
        $files = [$path . '/docker-compose.yml', $path . '/docker-compose.override.yml'];
        foreach ($files as $file) {
            file_put_contents($file, "services: {}\n");
        }
        file_put_contents($path . '/name', 'legacy___stack');
        $env = $path . '/original env';
        file_put_contents($env, "TOKEN=original\n");
        file_put_contents($path . '/envpath', $env);
        \ProjectIdentity::setProbe(static fn(): ?array => \ProjectIdentity::parseProbeOutput('containers ' . json_encode([
            'project' => $runtime,
            'workingDir' => $path,
            'configFiles' => implode(',', $files),
        ]) . "\n__rc=0"));
        \StackInfo::fromProject($compose_root, $folder);
        if ($mode === 'invalid-pin') {
            file_put_contents($path . '/project_name', '../invalid');
        } elseif ($mode === 'missing-input') {
            unlink($files[1]);
        }
        \StackInfo::clearCache();
        file_put_contents((string) $this->autoUpdateConfigFile, json_encode([
            $path => ['enabled' => true, 'schedule' => 'daily', 'time' => '00:00'],
        ]));
        putenv('COMPOSE_MANAGER_SH=' . PHP_BINARY . ' ' . escapeshellarg((string) $this->wrapperPath));

        include $plugin_root . 'include/AutoUpdateRunner.php';

        $updated = json_decode(file_get_contents((string) $this->autoUpdateConfigFile), true);
        if ($mode !== 'ready') {
            $this->assertArrayNotHasKey('last_run', $updated[$path]);
            $this->assertFileDoesNotExist($this->markerPath);
            return;
        }
        $deadline = microtime(true) + 3;
        while (!is_file((string) $this->markerPath) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($this->markerPath);
        $payload = json_decode(file_get_contents((string) $this->markerPath), true);
        $this->assertSame($runtime, $payload['argv'][2]);
        $this->assertSame(implode(PATH_SEPARATOR, $files), $payload['composeFiles']);
        $this->assertSame($env, $payload['envFile']);
        $this->assertArrayHasKey('last_run', $updated[$path]);
    }
}
