<?php

/**
 * Runner invoked by cron to check autoupdate.json and run updates when scheduled
 */
require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");

$autofile = getAutoUpdateConfigFilePath();
if (!is_file($autofile)) exit(0);

// Acquire exclusive lock on the config file to prevent concurrent runner instances
// from reading/writing simultaneously (cron can overlap if updates take > 15 min)
$lockHandle = fopen($autofile, 'c+');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another runner instance is active — skip this cycle
    if ($lockHandle) fclose($lockHandle);
    exit(0);
}

$raw = stream_get_contents($lockHandle);
$data = json_decode($raw, true);
if (!is_array($data)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
}

// Set timezone from /etc/timezone if available, otherwise use system default
$timezoneFile = '/etc/timezone';
if (is_file($timezoneFile)) {
    $tz = trim(@file_get_contents($timezoneFile));
    if ($tz && @date_default_timezone_set($tz)) {
        // Successfully set timezone
    }
}
// If no /etc/timezone or failed, PHP uses system default which is fine

$now = time();
$wday = intval(date('w', $now)); // 0 (Sun) - 6
$mday = intval(date('j', $now)); // 1-31

// Track whether we need to save updated last_run timestamps
$dataModified = false;

foreach ($data as $path => $entry) {
    if ($path === 'defaults') continue;
    if (!is_array($entry)) continue;
    if (empty($entry['enabled'])) continue;

    // Validate path is within allowed locations for security
    if (!isAllowedAutoUpdatePath($path)) {
        composeLogger('Skipping disallowed path: ' . sanitizeLogText($path), null, 'daemon', 'warn', 'autoupdate');
        continue;
    }

    $schedule = isset($entry['schedule']) ? $entry['schedule'] : 'daily';
    $defaultTime = '02:00';
    $time = isset($entry['time']) ? $entry['time'] : $defaultTime;

    // Validate time format (H:MM or HH:MM) and bounds; fall back to safe default on invalid
    $sh = 2; // default hour from 02:00
    $sm = 0; // default minute from 02:00
    if (is_string($time) && preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $parts = explode(':', $time);
        $parsedHour = intval($parts[0]);
        $parsedMinute = intval($parts[1]);
        if ($parsedHour >= 0 && $parsedHour <= 23 && $parsedMinute >= 0 && $parsedMinute <= 59) {
            $sh = $parsedHour;
            $sm = $parsedMinute;
        }
    }

    // Calculate today's scheduled timestamp and check if we should run
    // Using last_run tracking to handle non-15-minute-boundary times
    $scheduledToday = mktime($sh, $sm, 0);
    $lastRun = isset($entry['last_run']) ? intval($entry['last_run']) : 0;

    $shouldRun = false;
    // Check if current time is past the scheduled time and we haven't run yet today
    if ($now >= $scheduledToday && $lastRun < $scheduledToday) {
        if ($schedule == 'daily') {
            $shouldRun = true;
        } elseif ($schedule == 'weekly') {
            $weekday = isset($entry['weekday']) ? intval($entry['weekday']) : 0; // Default Sunday
            if ($weekday == $wday) $shouldRun = true;
        } elseif ($schedule == 'monthly') {
            $monthday = isset($entry['monthday']) ? intval($entry['monthday']) : 1; // Default 1st
            if ($monthday == $mday) $shouldRun = true;
        }
    }

    if ($shouldRun) {
        // Find compose file using shared utility
        $composeFile = findComposeFile($path);
        if (!$composeFile) continue;

        // Update last_run timestamp only after compose file is verified
        $data[$path]['last_run'] = $now;
        $dataModified = true;

        // Resolve project name via StackInfo if possible
        // Use projectName (sanitized lowercase) — not projectFolder or display name —
        // because docker compose requires lowercase alphanumeric project names.
        $stackInfo = StackInfo::fromComposePath($compose_root, $path);
        if ($stackInfo !== null) {
            if (!$stackInfo->hasResolvedIdentity()) {
                composeLogger(
                    "Skipped scheduled auto-update for '{$stackInfo->projectFolder}': compose project identity is unresolved",
                    ['identity' => $stackInfo->identity->toArray()],
                    'daemon',
                    'warning',
                    'identity'
                );
                continue;
            }
            $projectName = $stackInfo->projectName;
        } else {
            $projectName = StackInfo::sanitizeProjectString(basename($path));
        }

        // Log the scheduled auto-update trigger
        composeLogger("Scheduled auto-update triggered for: $projectName ($schedule)", null, 'daemon', 'info', 'autoupdate');

        $script = $plugin_root . "scripts/compose_autoupdate.sh";
        if ($stackInfo !== null) {
            $args = $stackInfo->buildComposeArgs();
            $composeFileList = $stackInfo->buildComposeFileList();
            $envFilePath = $args['envFilePath'] ?? null;
            $projectDirectory = $args['projectDirectory'];
        } else {
            $composeFileList = findComposeFile($path);
            $envFilePath = null;
            $projectDirectory = $path;
        }

        // Allow overriding the shell command via environment for tests; default to sh
        $shCmd = getenv('COMPOSE_MANAGER_SH') ? getenv('COMPOSE_MANAGER_SH') : 'sh';

        $envPrefix = '';
        if ($composeFileList !== '') {
            $envPrefix .= 'COMPOSE_FILE_LIST=' . escapeshellarg($composeFileList) . ' ';
        }
        if ($envFilePath !== null && $envFilePath !== '') {
            $envPrefix .= 'COMPOSE_ENV_FILE=' . escapeshellarg($envFilePath) . ' ';
        }
        if ($composeFileList === '' && $projectDirectory !== '') {
            $envPrefix .= 'COMPOSE_PROJECT_DIR=' . escapeshellarg($projectDirectory) . ' ';
        }

        $cmd = $envPrefix . $shCmd . ' ' . escapeshellarg($script) . " " . escapeshellarg($projectName) . " >/dev/null 2>&1 &";
        exec($cmd);
    }
}

// Save updated last_run timestamps if any were modified
if ($dataModified) {
    ftruncate($lockHandle, 0);
    rewind($lockHandle);
    fwrite($lockHandle, json_encode($data, JSON_PRETTY_PRINT));
    fflush($lockHandle);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
