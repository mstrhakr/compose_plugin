<?php
/**
 * Unit Tests for CronManager
 *
 * Tests centralized cron file management: autoupdate, backup, combined,
 * enable/disable, and marker-based line identification.
 */
declare(strict_types=1);
namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;
use CronManager;

require_once '/usr/local/emhttp/plugins/compose.manager/include/CronManager.php';

class CronManagerTest extends TestCase
{
    private string $cronFile;
    private CronManager $cron;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cronFile = sys_get_temp_dir() . '/cron_manager_test_' . getmypid() . '.cron';
        if (is_file($this->cronFile)) @unlink($this->cronFile);

        $this->cron = new CronManager(
            $this->cronFile,
            '/usr/local/emhttp/plugins/compose.manager/',
            false // disable update_cron sync in tests
        );

        FunctionMocks::setPluginConfig('compose.manager', []);
    }

    protected function tearDown(): void
    {
        if (is_file($this->cronFile)) @unlink($this->cronFile);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Autoupdate only
    // ---------------------------------------------------------------

    public function testEnableAutoupdateCreatesCronFile(): void
    {
        $this->assertTrue($this->cron->enableAutoupdate());
        $this->assertFileExists($this->cronFile);

        $content = file_get_contents($this->cronFile);
        $this->assertStringContainsString('*/15 * * * *', $content);
        $this->assertStringContainsString('/usr/bin/php', $content);
        $this->assertStringContainsString('AutoUpdateRunner.php', $content);
        $this->assertStringContainsString('#compose-autoupdate', $content);
    }

    public function testDisableAutoupdateRemovesFile(): void
    {
        $this->cron->enableAutoupdate();
        $this->assertFileExists($this->cronFile);

        $this->assertTrue($this->cron->disableAutoupdate());
        // No entries left → file removed
        $this->assertFileDoesNotExist($this->cronFile);
    }

    public function testIsAutoupdateInstalled(): void
    {
        $this->assertFalse($this->cron->isAutoupdateInstalled());
        $this->cron->enableAutoupdate();
        $this->assertTrue($this->cron->isAutoupdateInstalled());
        $this->cron->disableAutoupdate();
        $this->assertFalse($this->cron->isAutoupdateInstalled());
    }

    // ---------------------------------------------------------------
    // Backup only
    // ---------------------------------------------------------------

    public function testBackupCronCreatedWhenEnabled(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '04:30',
        ]);

        $this->assertTrue($this->cron->rebuild());
        $this->assertFileExists($this->cronFile);

        $content = file_get_contents($this->cronFile);
        $this->assertStringContainsString('30 4 * * *', $content);
        $this->assertStringContainsString('backup_cron.sh', $content);
        $this->assertStringContainsString('#compose-backup', $content);
    }

    public function testBackupCronWeeklySchedule(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'weekly',
            'BACKUP_SCHEDULE_TIME' => '02:00',
            'BACKUP_SCHEDULE_DAY' => '3', // Wednesday
        ]);

        $this->cron->rebuild();
        $content = file_get_contents($this->cronFile);
        $this->assertStringContainsString('0 2 * * 3', $content);
    }

    public function testBackupDisabledNoFile(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'false',
        ]);

        $this->cron->rebuild();
        $this->assertFileDoesNotExist($this->cronFile);
    }

    public function testIsBackupInstalled(): void
    {
        $this->assertFalse($this->cron->isBackupInstalled());

        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '03:00',
        ]);
        $this->cron->rebuild();
        $this->assertTrue($this->cron->isBackupInstalled());
    }

    // ---------------------------------------------------------------
    // Combined: both autoupdate and backup
    // ---------------------------------------------------------------

    public function testBothFeaturesProduceTwoLines(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '03:00',
        ]);

        // enableAutoupdate calls rebuild which includes both
        $this->cron->enableAutoupdate();
        $content = file_get_contents($this->cronFile);

        $this->assertStringContainsString('#compose-autoupdate', $content);
        $this->assertStringContainsString('#compose-backup', $content);

        $lines = array_filter(explode("\n", trim($content)));
        $this->assertCount(2, $lines);
    }

    public function testDisableAutoupdatePreservesBackup(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '03:00',
        ]);

        $this->cron->enableAutoupdate();
        $this->assertTrue($this->cron->isAutoupdateInstalled());
        $this->assertTrue($this->cron->isBackupInstalled());

        $this->cron->disableAutoupdate();
        $this->assertFalse($this->cron->isAutoupdateInstalled());
        $this->assertTrue($this->cron->isBackupInstalled());

        $content = file_get_contents($this->cronFile);
        $this->assertStringNotContainsString('#compose-autoupdate', $content);
        $this->assertStringContainsString('#compose-backup', $content);
    }

    public function testDisableBackupPreservesAutoupdate(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '03:00',
        ]);

        $this->cron->enableAutoupdate();
        $this->assertTrue($this->cron->isAutoupdateInstalled());
        $this->assertTrue($this->cron->isBackupInstalled());

        // Disable backup via config and rebuild
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'false',
        ]);
        $this->cron->enableAutoupdate(); // rebuild with autoupdate still enabled

        $this->assertTrue($this->cron->isAutoupdateInstalled());
        $this->assertFalse($this->cron->isBackupInstalled());
    }

    public function testDisableBothRemovesFile(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '03:00',
        ]);
        $this->cron->enableAutoupdate();
        $this->assertFileExists($this->cronFile);

        // Disable backup
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'false',
        ]);
        // Disable autoupdate
        $this->cron->disableAutoupdate();

        $this->assertFileDoesNotExist($this->cronFile);
    }

    // ---------------------------------------------------------------
    // No stray 'root' user field
    // ---------------------------------------------------------------

    public function testNoRootUserFieldInCronLines(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'daily',
            'BACKUP_SCHEDULE_TIME' => '03:00',
        ]);

        $this->cron->enableAutoupdate();
        $content = file_get_contents($this->cronFile);
        $this->assertStringNotContainsString('* root ', $content);
    }

    // ---------------------------------------------------------------
    // Overrides parameter
    // ---------------------------------------------------------------

    public function testRebuildWithOverrides(): void
    {
        // Config says backup disabled
        FunctionMocks::setPluginConfig('compose.manager', [
            'BACKUP_SCHEDULE_ENABLED' => 'false',
        ]);

        // But overrides say enabled
        $this->cron->rebuild([
            'BACKUP_SCHEDULE_ENABLED' => 'true',
            'BACKUP_SCHEDULE_FREQUENCY' => 'weekly',
            'BACKUP_SCHEDULE_TIME' => '05:00',
            'BACKUP_SCHEDULE_DAY' => '0',
        ]);

        $content = file_get_contents($this->cronFile);
        $this->assertStringContainsString('0 5 * * 0', $content);
        $this->assertStringContainsString('#compose-backup', $content);
    }

    // ---------------------------------------------------------------
    // Factory function
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Scheduled git check
    // ---------------------------------------------------------------

    /**
     * A projects folder with one git stack whose autoDeploy is $autoDeploy (null: not set).
     */
    private function projectsWithGitStack(?string $autoDeploy): string
    {
        $root = sys_get_temp_dir() . '/cron_manager_projects_' . getmypid();
        @mkdir($root . '/whoami', 0755, true);
        $settings = ['version' => 1, 'url' => 'https://example.com/r.git'];
        if ($autoDeploy !== null) {
            $settings['autoDeploy'] = $autoDeploy;
        }
        file_put_contents($root . '/whoami/git.json', (string) json_encode($settings));
        return $root;
    }

    private function removeProjects(string $root): void
    {
        @unlink($root . '/whoami/git.json');
        @rmdir($root . '/whoami');
        @rmdir($root);
    }

    public function testGitCheckLineIsAddedWhileAGitStackAsksForIt(): void
    {
        $root = $this->projectsWithGitStack('notify');
        try {
            $cron = new CronManager($this->cronFile, '/usr/local/emhttp/plugins/compose.manager/', false, $root);

            $this->assertTrue($cron->rebuild());
            $content = (string) file_get_contents($this->cronFile);
            $this->assertStringContainsString("7-59/15 * * * * '/usr/local/emhttp/plugins/compose.manager/scripts/compose-git' scheduled-check", $content);
            $this->assertStringContainsString('#compose-gitcheck', $content);
            $this->assertTrue($cron->isGitCheckInstalled());

            // Turning image auto-update on and off keeps it.
            $cron->enableAutoupdate();
            $this->assertTrue($cron->isGitCheckInstalled());
            $this->assertTrue($cron->isAutoupdateInstalled());
            $cron->disableAutoupdate();
            $this->assertTrue($cron->isGitCheckInstalled());
            $this->assertFalse($cron->isAutoupdateInstalled());
        } finally {
            $this->removeProjects($root);
        }
    }

    public function testNoGitCheckLineWhenNoGitStackAsksForIt(): void
    {
        foreach (['off', null] as $autoDeploy) {
            $root = $this->projectsWithGitStack($autoDeploy);
            try {
                $cron = new CronManager($this->cronFile, '/usr/local/emhttp/plugins/compose.manager/', false, $root);
                $cron->rebuild();
                $this->assertFalse($cron->isGitCheckInstalled());
            } finally {
                $this->removeProjects($root);
            }
        }
        // Without a projects folder there is nothing to look at.
        $this->cron->rebuild();
        $this->assertFalse($this->cron->isGitCheckInstalled());
    }

    public function testFactoryFunctionRespectsEnvVars(): void
    {
        putenv('COMPOSE_MANAGER_CRON_FILE=' . $this->cronFile);
        putenv('COMPOSE_MANAGER_CRON_NOSYNC=1');

        $manager = composeCronManager();
        $this->assertEquals($this->cronFile, $manager->getCronFile());

        putenv('COMPOSE_MANAGER_CRON_FILE');
        putenv('COMPOSE_MANAGER_CRON_NOSYNC');
    }
}
