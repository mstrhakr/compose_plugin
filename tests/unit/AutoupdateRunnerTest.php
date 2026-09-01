<?php
/**
 * Tests for AutoUpdateRunner.php
 */
declare(strict_types=1);
namespace ComposeManager\Tests;

use PluginTests\TestCase;

class AutoupdateRunnerTest extends TestCase
{
    private ?string $testStackPath = null;
    private ?string $autoUpdateConfigFile = null;
    private ?string $wrapperPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        global $plugin_root, $compose_root;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager/';

        // TestCase::setUp() resets plugin config mocks. Re-seed compose root so
        // AutoUpdateRunner path validation uses a writable test directory.
        $compose_root = sys_get_temp_dir() . '/compose_test_runner_projects';
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

        $shim = <<<'PHP'
<?php
$marker = getenv('AUTOTEST_MARKER');
if ($marker) file_put_contents($marker, "RAN\n");
exit(0);
PHP;
        file_put_contents($this->wrapperPath, $shim);
        // redirect cron writes to plugin root in tests (not strictly needed here but harmless)
        putenv('COMPOSE_MANAGER_CRON_DIR=' . $plugin_root);
        // ensure autoupdate config file resolves to a writable temp file in tests
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE=' . $this->autoUpdateConfigFile);
    }

    protected function tearDown(): void
    {
        global $plugin_root;
        putenv('COMPOSE_MANAGER_CRON_DIR');
        putenv('COMPOSE_MANAGER_SH');
        putenv('AUTOTEST_MARKER');
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE');

        if ($this->autoUpdateConfigFile !== null && is_file($this->autoUpdateConfigFile)) {
            unlink($this->autoUpdateConfigFile);
        }

        if ($this->wrapperPath !== null && is_file($this->wrapperPath)) {
            unlink($this->wrapperPath);
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

        // Create autoupdate.json with entry scheduled for now
        // Use a deterministic due time to avoid timezone mismatches in runner logic.
        $cfg = [ $path => ['enabled' => true, 'schedule' => 'daily', 'time' => '00:00'] ];
        file_put_contents((string)$this->autoUpdateConfigFile, json_encode($cfg, JSON_PRETTY_PRINT));

        // Configure shim path for environments where process execution is available.
        putenv('COMPOSE_MANAGER_SH=' . PHP_BINARY . ' ' . escapeshellarg((string)$this->wrapperPath));

        // Run runner (include directly)
        include $plugin_root . 'include/AutoUpdateRunner.php';

        // Verify runner considered this stack due and recorded last_run.
        $updated = json_decode((string) file_get_contents((string) $this->autoUpdateConfigFile), true);
        $this->assertIsArray($updated);
        $this->assertArrayHasKey($path, $updated);
        $this->assertArrayHasKey('last_run', $updated[$path]);
        $this->assertGreaterThan(0, (int) $updated[$path]['last_run']);
    }
}
