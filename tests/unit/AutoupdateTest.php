<?php
/**
 * Tests for autoupdate.php endpoints
 */
declare(strict_types=1);
namespace ComposeManager\Tests;

use PluginTests\TestCase;

class AutoupdateTest extends TestCase
{
    private ?string $wrapperPath = null;
    private ?string $cronFile = null;
    private ?string $autoUpdateConfigFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure plugin_root writable mapping exists (framework provides)
        global $plugin_root;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager/';
        $this->autoUpdateConfigFile = sys_get_temp_dir() . '/autoupdate_test_cfg_' . getmypid() . '_' . uniqid('', true) . '.json';
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE=' . $this->autoUpdateConfigFile);
        // Ensure scripts path exists
        if (!is_dir($plugin_root . 'scripts')) mkdir($plugin_root . 'scripts', 0755, true);

        // Use a real temp file for the shell wrapper so child processes can execute it in CI.
        $this->wrapperPath = sys_get_temp_dir() . '/autoupdate_test_wrapper_' . getmypid() . '_' . uniqid('', true) . '.php';
        $shim = <<<'PHP'
    <?php
    $marker = getenv('AUTOTEST_MARKER');
    if ($marker) {
        file_put_contents($marker, json_encode([
            'argv' => $argv,
                'parallelLimit' => getenv('COMPOSE_PARALLEL_LIMIT'),
        ]));
    }
    exit(0);
    PHP;
        file_put_contents($this->wrapperPath, $shim);
        // redirect cron writes to temp file in tests
        $this->cronFile = sys_get_temp_dir() . '/compose_cron_test_' . getmypid() . '.cron';
        putenv('COMPOSE_MANAGER_CRON_FILE=' . $this->cronFile);
        putenv('COMPOSE_MANAGER_CRON_NOSYNC=1');
    }

    protected function tearDown(): void
    {
        putenv('COMPOSE_MANAGER_CRON_FILE');
        putenv('COMPOSE_MANAGER_CRON_NOSYNC');
        putenv('COMPOSE_MANAGER_SH');
        putenv('AUTOTEST_MARKER');
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE');
        if ($this->autoUpdateConfigFile !== null && is_file($this->autoUpdateConfigFile)) {
            @unlink($this->autoUpdateConfigFile);
        }
        $this->autoUpdateConfigFile = null;
        if ($this->cronFile !== null && is_file($this->cronFile)) {
            @unlink($this->cronFile);
        }
        $this->cronFile = null;
        if ($this->wrapperPath !== null && is_file($this->wrapperPath)) {
            unlink($this->wrapperPath);
        }
        $this->wrapperPath = null;
        $_POST = [];
        parent::tearDown();
    }

    public function testGetConfigWhenMissingReturnsEmptyObject(): void
    {
        $_POST = ['action' => 'getConfig'];
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php';
        $out = ob_get_clean();
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertEmpty($json);
        $_POST = [];
    }

    public function testRunNowMissingPathReturnsError(): void
    {
        $_POST = ['action' => 'runNow'];
        ob_start(); include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php'; $out = ob_get_clean();
        $r = json_decode($out, true);
        $this->assertArrayHasKey('error', $r);
        $_POST = [];
    }

    public function testRunNowExecutesScript(): void
    {
        // Create a fake stack under compose_root so path validation passes
        global $plugin_root, $compose_root;
        $tmp = $compose_root . '/PrintMaster_' . getmypid();
        if (!is_dir($tmp)) mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/docker-compose.yml', "services:\n  a:\n    image: busybox\n");
        file_put_contents((string) $this->autoUpdateConfigFile, json_encode([
            'defaults' => ['parallel_limit' => 3],
        ]));

        // Create stub script that writes marker file
        $marker = sys_get_temp_dir() . '/autoupdate_marker_' . getmypid();
        $scriptPath = $plugin_root . 'scripts/compose_autoupdate.sh';
        // real script file (not executed directly in tests because we override COMPOSE_MANAGER_SH)
        $script = "#!/bin/sh\necho SHOULD_NOT_RUN > /dev/null\nexit 0\n";
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        // configure test shim so the autoupdate code executes our PHP wrapper instead of a system sh
        putenv('AUTOTEST_MARKER=' . $marker);
        putenv('COMPOSE_MANAGER_SH=' . PHP_BINARY . ' ' . escapeshellarg((string)$this->wrapperPath));

        $_POST = ['action' => 'runNow', 'path' => $tmp];
        ob_start(); include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php'; $out = ob_get_clean();
        $r = json_decode($out, true);
        // rc should be 0 for our stub
        $this->assertEquals(0, $r['rc']);
        $this->assertFileExists($marker);

        $payload = json_decode((string) file_get_contents($marker), true);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['argv'] ?? null);
        $this->assertGreaterThanOrEqual(3, count($payload['argv']));
        $expectedProjectName = \StackInfo::sanitizeProjectString(basename($tmp));
        $this->assertSame($expectedProjectName, $payload['argv'][2]);
        $this->assertSame('3', $payload['parallelLimit']);

        // cleanup
        unlink($marker);
        unlink($scriptPath);
        // remove tmp
        unlink($tmp . '/docker-compose.yml'); rmdir($tmp);
        $_POST = [];
    }

    public function testSaveConfigNormalizesParallelLimit(): void
    {
        $_POST = [
            'action' => 'saveConfig',
            'data' => json_encode(['defaults' => ['parallel_limit' => 'invalid']]),
        ];
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php';
        $response = json_decode((string) ob_get_clean(), true);

        $this->assertTrue((bool) ($response['ok'] ?? false));

        $_POST = ['action' => 'getConfig'];
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php';
        $config = json_decode((string) ob_get_clean(), true);
        $this->assertSame(4, $config['defaults']['parallel_limit']);
        $_POST = [];
    }

    public function testInstallCronUsesAbsolutePhpBinary(): void
    {
        if (is_file($this->cronFile)) {
            unlink($this->cronFile);
        }

        $_POST = ['action' => 'installCron'];
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php';
        $out = ob_get_clean();

        $response = json_decode($out, true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('ok', $response);
        $this->assertTrue((bool)$response['ok']);
        $this->assertFileExists($this->cronFile);

        $content = file_get_contents($this->cronFile);
        $this->assertStringContainsString('/usr/bin/php', $content);
        $this->assertStringContainsString('AutoUpdateRunner.php', $content);
        $this->assertStringContainsString('#compose-autoupdate', $content);
        // Verify no stray 'root' user field
        $this->assertStringNotContainsString('* root ', $content);

        $_POST = [];
    }
}
