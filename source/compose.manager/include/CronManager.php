<?php

/**
 * Centralized cron management for Compose Manager.
 *
 * All plugin cron entries (autoupdate, backup, etc.) are written to a single
 * .cron file and synced to the system via Unraid's update_cron mechanism.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/GitStackSettings.php");

/**
 * Factory function to create a CronManager instance.
 * Tests can override COMPOSE_MANAGER_CRON_FILE env var to redirect writes.
 */
function composeCronManager(): CronManager
{
    $cronFileEnv = getenv('COMPOSE_MANAGER_CRON_FILE');
    $cronFile = ($cronFileEnv !== false && $cronFileEnv !== '') ? $cronFileEnv : null;

    $syncDisabledEnv = getenv('COMPOSE_MANAGER_CRON_NOSYNC');
    $syncEnabled = ($syncDisabledEnv === false || $syncDisabledEnv === '');

    global $plugin_root, $compose_root;
    return new CronManager($cronFile, $plugin_root, $syncEnabled, $compose_root);
}

class CronManager
{
    private const DEFAULT_CRON_FILE = '/boot/config/plugins/compose.manager/compose.manager.cron';
    private const MARKER_AUTOUPDATE = '#compose-autoupdate';
    private const MARKER_BACKUP = '#compose-backup';
    private const MARKER_GITCHECK = '#compose-gitcheck';
    private string $cronFile;
    private string $pluginRoot;
    private bool $syncEnabled;
    private ?string $composeRoot;

    /**
     * @param string|null $cronFile Override cron file path (for testing)
     * @param string|null $pluginRoot Override plugin root path (for testing)
     * @param bool $syncEnabled Whether to call update_cron after writing (disable in tests)
     * @param string|null $composeRoot The projects folder, to find git stacks that are checked on a
     *     schedule; null leaves the git check line out
     */
    public function __construct(
        ?string $cronFile = null,
        ?string $pluginRoot = null,
        bool $syncEnabled = true,
        ?string $composeRoot = null
    ) {
        $this->cronFile = $cronFile ?? self::DEFAULT_CRON_FILE;
        $this->pluginRoot = rtrim($pluginRoot ?? '/usr/local/emhttp/plugins/compose.manager/', '/') . '/';
        $this->syncEnabled = $syncEnabled;
        $this->composeRoot = $composeRoot;
    }

    /**
     * Rebuild the .cron file from current plugin configuration.
     * Preserves autoupdate state based on whether it's currently in the file.
     * Reads config to determine backup schedule.
     *
     * @param array<string, mixed>|null $overrides Override config values (for callers that haven't persisted yet)
     * @return bool True on success
     */
    public function rebuild(?array $overrides = null): bool
    {
        composeLogger('rebuild() called' . ($overrides !== null ? ' with overrides: ' . implode(', ', array_keys($overrides)) : ''), null, 'daemon', 'debug', 'cron');
        $cfg = parse_plugin_cfg('compose.manager');
        if ($overrides !== null) {
            $cfg = array_merge($cfg, $overrides);
        }

        $lines = [];

        // Preserve autoupdate state: only include if already installed
        if ($this->isAutoupdateInstalled()) {
            $lines[] = $this->buildAutoupdateLine();
            composeLogger('rebuild: autoupdate is installed, preserving', null, 'daemon', 'debug', 'cron');
        }

        $backupLine = $this->buildBackupLine($cfg);
        if ($backupLine !== null) {
            $lines[] = $backupLine;
            composeLogger('rebuild: backup cron line included', null, 'daemon', 'debug', 'cron');
        }
        $this->addGitCheckLineIfWanted($lines);

        composeLogger('rebuild: writing ' . count($lines) . ' cron line(s)', null, 'daemon', 'debug', 'cron');
        return $this->writeCronFile($lines);
    }

    /**
     * Enable the autoupdate cron entry and rebuild.
     */
    public function enableAutoupdate(): bool
    {
        composeLogger('enableAutoupdate() called', null, 'daemon', 'debug', 'cron');
        $cfg = parse_plugin_cfg('compose.manager');

        $lines = [];
        $lines[] = $this->buildAutoupdateLine();

        $backupLine = $this->buildBackupLine($cfg);
        if ($backupLine !== null) {
            $lines[] = $backupLine;
        }
        $this->addGitCheckLineIfWanted($lines);

        return $this->writeCronFile($lines);
    }

    /**
     * Disable the autoupdate cron entry by rebuilding without it.
     * Since autoupdate has no config flag (presence of cron = enabled),
     * we need to explicitly exclude it.
     */
    public function disableAutoupdate(): bool
    {
        composeLogger('disableAutoupdate() called', null, 'daemon', 'debug', 'cron');
        $lines = [];

        $cfg = parse_plugin_cfg('compose.manager');
        $backupLine = $this->buildBackupLine($cfg);
        if ($backupLine !== null) {
            $lines[] = $backupLine;
        }
        $this->addGitCheckLineIfWanted($lines);

        return $this->writeCronFile($lines);
    }

    /**
     * Check if autoupdate cron is currently installed.
     */
    public function isAutoupdateInstalled(): bool
    {
        return $this->hasMarker(self::MARKER_AUTOUPDATE);
    }

    /**
     * Check if the scheduled git check's cron line is currently installed.
     */
    public function isGitCheckInstalled(): bool
    {
        return $this->hasMarker(self::MARKER_GITCHECK);
    }

    /**
     * Check if backup cron is currently installed.
     */
    public function isBackupInstalled(): bool
    {
        return $this->hasMarker(self::MARKER_BACKUP);
    }

    /**
     * Get the cron file path (for testing/inspection).
     */
    public function getCronFile(): string
    {
        return $this->cronFile;
    }

    /**
     * One-time migration: remove legacy backup entries from root's crontab
     * and old /etc/cron.d file.
     */
    public function cleanupLegacy(): void
    {
        composeLogger('cleanupLegacy() called', null, 'daemon', 'debug', 'cron');
        // Remove old /etc/cron.d file from previous versions
        $oldCronDFile = '/etc/cron.d/compose-manager-backup';
        if (file_exists($oldCronDFile)) {
            composeLogger('cleanupLegacy: removing old cron.d file: ' . $oldCronDFile, null, 'daemon', 'info', 'cron');
            @unlink($oldCronDFile);
        }

        if (!$this->syncEnabled) {
            composeLogger('cleanupLegacy: sync disabled, skipping crontab cleanup', null, 'daemon', 'debug', 'cron');
            return;
        }

        // Strip legacy #compose-manager-backup lines from root's crontab
        $lines = [];
        exec('crontab -l 2>/dev/null', $lines, $rc);
        if ($rc !== 0 || empty($lines)) {
            return;
        }

        $marker = '#compose-manager-backup';
        $filtered = array_filter($lines, function ($line) use ($marker) {
            return strpos($line, $marker) === false;
        });

        // Only rewrite if we actually removed something
        if (count($filtered) === count($lines)) {
            composeLogger('cleanupLegacy: no legacy entries found in crontab', null, 'daemon', 'debug', 'cron');
            return;
        }

        composeLogger('cleanupLegacy: removing ' . (count($lines) - count($filtered)) . ' legacy crontab entries', null, 'daemon', 'info', 'cron');
        $tmpFile = tempnam('/tmp', 'compose-cron-cleanup-');
        if ($tmpFile === false) {
            return;
        }
        file_put_contents($tmpFile, rtrim(implode("\n", $filtered)) . "\n");
        exec("crontab " . escapeshellarg($tmpFile));
        @unlink($tmpFile);
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    /**
     * Build the autoupdate cron line (every 15 minutes).
     * Autoupdate is considered "enabled" when rebuild() is called via enableAutoupdate().
     * This always returns a line — the caller decides whether to include it.
     */
    private function buildAutoupdateLine(): string
    {
        $phpBinary = '/usr/bin/php';
        $runner = $this->pluginRoot . 'include/AutoUpdateRunner.php';
        return "*/15 * * * * " . escapeshellarg($phpBinary) . " "
            . escapeshellarg($runner) . " >/dev/null 2>&1 " . self::MARKER_AUTOUPDATE;
    }

    /**
     * Add the scheduled git check's line (every 15 minutes) when any git stack
     * asks for it: a stack whose git.json has autoDeploy set to anything but "off".
     *
     * @param string[] $lines
     */
    private function addGitCheckLineIfWanted(array &$lines): void
    {
        if ($this->composeRoot === null || $this->composeRoot === '') {
            return;
        }
        foreach (glob(rtrim($this->composeRoot, '/') . '/*/git.json') ?: [] as $gitSettingsFile) {
            // Read as plain JSON: the scheduled check itself validates each stack's settings.
            $settings = GitStackSettings::readAsPlainJson($gitSettingsFile);
            if (is_array($settings) && is_string($settings['autoDeploy'] ?? null) && $settings['autoDeploy'] !== 'off') {
                $command = $this->pluginRoot . 'scripts/compose-git';
                // Every 15 minutes, 7 minutes off the image auto-update's quarter hours, so a git
                // deploy does not wait on the stack lock of that stack's own auto-update.
                $lines[] = "7-59/15 * * * * " . escapeshellarg($command) . " scheduled-check >/dev/null 2>&1 " . self::MARKER_GITCHECK;
                composeLogger('git check cron line included', null, 'daemon', 'debug', 'cron');
                return;
            }
        }
    }

    /**
     * Build the backup cron line based on config. Returns null if backup schedule is disabled.
     */
    private function buildBackupLine(array $cfg): ?string
    {
        $enabled = ($cfg['BACKUP_SCHEDULE_ENABLED'] ?? 'false') === 'true';
        if (!$enabled) {
            return null;
        }

        $script = $this->pluginRoot . 'scripts/backup_cron.sh';
        $frequency = $cfg['BACKUP_SCHEDULE_FREQUENCY'] ?? 'daily';
        $time = $cfg['BACKUP_SCHEDULE_TIME'] ?? '03:00';
        $dayOfWeek = $cfg['BACKUP_SCHEDULE_DAY'] ?? '1';

        $parts = explode(':', $time);
        $hour = isset($parts[0]) ? intval($parts[0]) : 3;
        $minute = isset($parts[1]) ? intval($parts[1]) : 0;

        if ($frequency === 'weekly') {
            return "{$minute} {$hour} * * {$dayOfWeek} {$script} >/dev/null 2>&1 " . self::MARKER_BACKUP;
        }
        return "{$minute} {$hour} * * * {$script} >/dev/null 2>&1 " . self::MARKER_BACKUP;
    }

    /**
     * Write cron lines to the .cron file and sync.
     *
     * @param string[] $lines Cron lines to write
     * @return bool
     */
    private function writeCronFile(array $lines): bool
    {
        $dir = dirname($this->cronFile);
        if (!is_dir($dir)) {
            composeLogger('writeCronFile: creating directory ' . $dir, null, 'daemon', 'debug', 'cron');
            if (!@mkdir($dir, 0755, true)) {
                composeLogger('writeCronFile: FAILED to create directory ' . $dir, null, 'daemon', 'error', 'cron');
                return false;
            }
        }

        if (empty($lines)) {
            // No cron entries — remove file so update_cron drops our entries
            composeLogger('writeCronFile: no entries, removing cron file', null, 'daemon', 'debug', 'cron');
            if (is_file($this->cronFile)) {
                @unlink($this->cronFile);
            }
        } else {
            composeLogger('writeCronFile: writing ' . count($lines) . ' line(s) to ' . $this->cronFile, null, 'daemon', 'debug', 'cron');
            $content = implode("\n", $lines) . "\n";
            if (file_put_contents($this->cronFile, $content) === false) {
                composeLogger('writeCronFile: FAILED to write ' . $this->cronFile, null, 'daemon', 'error', 'cron');
                return false;
            }
        }

        return $this->syncCron();
    }

    /**
     * Call update_cron to sync plugin .cron files into the system crontab.
     */
    private function syncCron(): bool
    {
        if (!$this->syncEnabled) {
            composeLogger('syncCron: sync disabled, skipping', null, 'daemon', 'debug', 'cron');
            return true;
        }

        composeLogger('syncCron: calling update_cron', null, 'daemon', 'debug', 'cron');
        $output = [];
        $returnVar = 0;
        exec('/usr/local/sbin/update_cron 2>/dev/null', $output, $returnVar);
        if ($returnVar !== 0) {
            composeLogger('syncCron: update_cron FAILED with exit code ' . $returnVar, null, 'daemon', 'error', 'cron');
        }
        return $returnVar === 0;
    }

    /**
     * Check if a marker comment exists in the current cron file.
     */
    private function hasMarker(string $marker): bool
    {
        if (!is_file($this->cronFile)) {
            return false;
        }
        $content = file_get_contents($this->cronFile);
        if ($content === false) {
            return false;
        }
        return strpos($content, $marker) !== false;
    }
}
