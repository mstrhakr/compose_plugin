<?php

/**
 * Compose Util Functions for Compose Manager
 *
 * Contains utility functions used by ComposeUtil.php for compose command execution.
 * Separated from ComposeUtil.php to allow unit testing without triggering the switch statement.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

/**
 * Wait for ttyd UNIX socket to exist.
 *
 * @param string $socketName Base name of socket file (without path)
 * @param int $timeoutMs How long to wait in milliseconds (default 2000)
 * @param int $intervalMs Poll interval in milliseconds (default 100)
 * @return bool True if socket existed within timeout, false otherwise
 */
function waitForTtydSocket($socketName, $timeoutMs = 2000, $intervalMs = 100, $tmpDir = COMPOSE_TTYD_SOCKET_DIR)
{

    $socketPath = rtrim($tmpDir, '/') . "/$socketName.sock";
    $attempts = max(1, (int) ceil($timeoutMs / $intervalMs));
    for ($i = 0; $i < $attempts; $i++) {
        if (file_exists($socketPath)) {
            composeLogger("ttyd socket ready: $socketPath", ['socket' => $socketPath, 'attempt' => $i], 'user', 'debug', 'ttyd');
            return true;
        }
        usleep($intervalMs * 1000);
    }
    composeLogger("ttyd socket timeout: $socketPath", ['socket' => $socketPath, 'timeoutMs' => $timeoutMs], 'user', 'warning', 'ttyd');
    return false;
}

/**
 * Execute a compose command in a ttyd terminal, optionally capturing output to a log file.
 *
 * @param string $cmd The command to execute
 * @param bool $debug Whether to log debug messages
 * @param string $logFile Optional path to write a copy of the output
 * @param string $socketOverride Optional socket name to use instead of the global default
 */
function execComposeCommandInTTY($cmd, $debug, $logFile = '', $socketOverride = '')
{
    global $socket_name;
    $effectiveSocket = $socketOverride !== '' ? $socketOverride : $socket_name;
    $socketFile = rtrim(COMPOSE_TTYD_SOCKET_DIR, '/') . "/$effectiveSocket.sock";
    if (defined('COMPOSE_SKIP_TTYD_EXEC') && COMPOSE_SKIP_TTYD_EXEC) {
        composeLogger("Skipping ttyd execution in test mode: " . $cmd, ['command' => $cmd], 'user', 'debug', 'ttyd');
        return;
    }
    // Use pkill -f for more robust process matching instead of pgrep|awk pipeline
    exec("pkill -f " . escapeshellarg("$effectiveSocket.sock") . " 2>/dev/null");
    usleep(300000); // 300ms for process to exit
    @unlink($socketFile);
    $socketPath = escapeshellarg($socketFile);
    if ($logFile !== '') {
        // Preserve interactive TTY behavior by running the command under "script"
        // (PTY capture). A plain tee pipeline breaks terminal redraw/spinner output.
        $scriptCmd = "script -qefc " . escapeshellarg($cmd) . " " . escapeshellarg($logFile);
        $innerCmd = "bash -lc " . escapeshellarg($scriptCmd);
        $command = "ttyd -R -o -i $socketPath $innerCmd > /dev/null &";
    } else {
        $command = "ttyd -R -o -i $socketPath $cmd > /dev/null &";
    }
    exec($command);
    composeLogger("Executing command in ttyd: " . $cmd, ['command' => $cmd], 'user', 'debug', 'ttyd');

    // Wait for the socket to be created to avoid 502 on first open.
    waitForTtydSocket($effectiveSocket);
}

/**
 * Get the last command log file path for a given compose action.
 *
 * @param string $action Compose action (up, down, update, pull, stop, logs)
 * @param string $path Stack path
 * @return string Log file path or empty string when logs should not be saved.
 */
function getLastCmdLogFileForComposeAction($action, $path)
{
    if ($action === 'logs') {
        return '';
    }
    return rtrim($path, '/') . '/last_cmd.log';
}

/**
 * Append compose file discovery arguments to compose.sh command args.
 *
 * @param array<int, string> $composeCommand
 * @param array<string, mixed> $args
 */
function appendComposeFileArgs(array &$composeCommand, array $args): void
{
    if (!empty($args['useDefaultFileDiscovery'])) {
        $projectDirectory = $args['projectDirectory'] ?? '';
        if (is_string($projectDirectory) && $projectDirectory !== '') {
            $composeCommand[] = "-w" . $projectDirectory;
        }
        return;
    }

    $filePaths = $args['filePaths'] ?? [];
    if (!is_array($filePaths)) {
        return;
    }

    foreach ($filePaths as $filePath) {
        if (is_string($filePath) && $filePath !== '' && is_file($filePath)) {
            $composeCommand[] = "-f" . $filePath;
        }
    }
}

/**
 * Append env-file argument to compose.sh command args when valid.
 *
 * @param array<int, string> $composeCommand
 * @param array<string, mixed> $args
 */
function appendComposeEnvFileArg(array &$composeCommand, array $args): void
{
    $envFilePath = $args['envFilePath'] ?? null;
    if (is_string($envFilePath) && $envFilePath !== '' && is_file($envFilePath)) {
        $composeCommand[] = "-e" . $envFilePath;
    }
}

/**
 * Build and echo a compose command for a single stack.
 *
 * @param string $action The compose action (up, down, update, pull, stop, logs)
 * @param array<string, mixed> $options Command options: recreate, background, removeOrphans
 */
function echoComposeCommand($action, array $options = [])
{
    /**
     * Note: This function is called from an AJAX endpoint and must be careful to only echo the intended command or JSON response.
     * 
     * POST parameters:
     * 
     * path: the stack path (required)
     * profile: optional comma-separated list of profiles to enable
     * 
     * Security: The 'path' parameter is validated to ensure it is within allowed directories to prevent command injection or unauthorized file access.
     */
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = $cfg['DEBUG_TO_LOG'] == "true";
    $path = isset($_POST['path']) ? trim($_POST['path']) : "";
    $profile = isset($_POST['profile']) ? trim($_POST['profile']) : "";
    $recreate = !empty($options['recreate']);
    $background = !empty($options['background']);
    $removeOrphans = !empty($options['removeOrphans']);
    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        composeLogger("Cannot perform action: array not started", ['action' => $action, 'path' => $path], 'user', 'debug', 'compose');
    } else {
        composeLogger("Preparing compose command", ['action' => $action, 'path' => $path, 'profile' => $profile, 'recreate' => $recreate, 'background' => $background, 'removeOrphans' => $removeOrphans], 'user', 'debug', 'compose');
        $composeCommand = array($plugin_root . "scripts/compose.sh");

        // Resolve stack identity via StackInfo
        try {
            $stackInfo = StackInfo::fromProject($compose_root, basename($path));
        } catch (\Throwable $e) {
            composeLogger("Cannot perform action: invalid stack", ['action' => $action, 'path' => $path, 'error' => $e->getMessage()], 'user', 'warning', 'compose');
            echo '';
            return;
        }
        $args = $stackInfo->buildComposeArgs();

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . $args['projectName'];
        appendComposeFileArgs($composeCommand, $args);

        // Prune orphaned services from override before compose up
        if ($action === 'up') {
            $stackInfo->pruneOrphanOverrideServices();
        }

        if ($removeOrphans && ($action === 'up' || $action === 'down')) {
            $composeCommand[] = '--remove-orphans';
        }

        appendComposeEnvFileArg($composeCommand, $args);

        // Support multiple profiles (comma-separated)
        if ($profile) {
            $profileList = array_map('trim', explode(',', $profile));
            foreach ($profileList as $p) {
                if ($p) {
                    $composeCommand[] = "-g" . $p;
                }
            }
        }

        // Pass stack path for timestamp saving
        $composeCommand[] = "-s$path";

        // Add recreate flag if requested
        if ($recreate) {
            $composeCommand[] = "--recreate";
        }

        if ($debug) {
            $composeCommand[] = "--debug";
        }

        if ($background) {
            // Run fully in the background using compose_background.sh.
            // Output is captured to last_cmd.log; notification sent on completion.
            $bgScript = $plugin_root . "scripts/compose_background.sh";
            $bgCmd = escapeshellarg($bgScript);
            foreach ($composeCommand as $arg) {
                $bgCmd .= ' ' . escapeshellarg($arg);
            }
            $bgCmd .= ' > /dev/null 2>&1 &';
            exec($bgCmd);
            composeLogger("Background command: " . $bgCmd, ['command' => $bgCmd], 'user', 'debug', 'compose');
            // Signal to JS that this ran in background (no terminal window to open)
            echo json_encode(['background' => true]);
        } else {
            $logFile = getLastCmdLogFileForComposeAction($action, $path);
            $composeCommandEscaped = array_map(function ($item) {
                return escapeshellarg($item);
            }, $composeCommand);
            $composeCommandStr = join(" ", $composeCommandEscaped);
            // Use a per-stack socket for logs so viewing logs doesn't conflict
            // with action operations (up/update/pull) that share the default socket.
            $logsSocket = '';
            if ($action === 'logs') {
                $logsSocket = 'compose_logs_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($path));
            }
            execComposeCommandInTTY($composeCommandStr, $debug, $logFile, $logsSocket);
            composeLogger("Executing command in ttyd: " . $composeCommandStr, ['command' => $composeCommandStr], 'user', 'debug', 'compose');
            if ($action === 'logs') {
                $composeCommand = "/plugins/compose.manager/include/ShowTtyd.php?socket=" . urlencode($logsSocket);
            } else {
                $composeCommand = "/plugins/compose.manager/include/ShowTtyd.php?done=1";
            }
            echo $composeCommand;
        }
        composeLogger("Final compose command: " . $composeCommand, ['command' => $composeCommand], 'user', 'debug', 'compose');
    }
}

/**
 * Build and echo a compose command for multiple stacks.
 *
 * @param string $action The compose action (up, down, update)
 * @param array<string, mixed> $options Command options: paths, background, removeOrphans
 */
function echoComposeCommandMultiple($action, array $options = [])
{
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = $cfg['DEBUG_TO_LOG'] == "true";
    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
    $paths = $options['paths'] ?? [];
    $background = !empty($options['background']);
    $removeOrphans = !empty($options['removeOrphans']);

    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        composeLogger("Multi Compose operation aborted: Array not started", null, 'user', 'warning', 'compose-multi');
        return;
    }

    // Build a combined command that runs compose up/down for each stack sequentially
    $commands = array();
    $stackNames = array();

    foreach ($paths as $path) {
        composeLogger("Processing stack for multi-compose action: " . $path, ['path' => $path, 'action' => $action], 'user', 'debug', 'compose-multi');
        $composeCommand = array($plugin_root . "scripts/compose.sh");

        $project = basename($path);

        // Resolve stack identity via StackInfo
        try {
            $stackInfo = StackInfo::fromProject($compose_root, $project);
        } catch (\Throwable $e) {
            composeLogger("Skipping invalid stack during multi-compose action", ['action' => $action, 'path' => $path, 'error' => $e->getMessage()], 'user', 'warning', 'compose-multi');
            continue;
        }
        $stackNames[] = $stackInfo->getName();
        $args = $stackInfo->buildComposeArgs();

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . $args['projectName'];
        appendComposeFileArgs($composeCommand, $args);

        // Prune orphaned services from override before compose up
        if ($action === 'up') {
            $stackInfo->pruneOrphanOverrideServices();
        }

        if ($removeOrphans && ($action === 'up' || $action === 'down')) {
            $composeCommand[] = '--remove-orphans';
        }

        appendComposeEnvFileArg($composeCommand, $args);

        // Profile selection per action:
        //  - up:     use user-configured default profiles (running_profiles
        //            is stale/absent when the stack isn't running).
        //  - update: preserve the currently active profile set so the same
        //            services are recreated; fall back to defaults on first run.
        //  - down:   wildcard * ensures every profiled service is torn down,
        //            regardless of what was recorded or configured.
        if ($action === 'down') {
            $composeCommand[] = "-g*";
        } elseif ($action === 'update') {
            $profiles = $stackInfo->getRunningProfiles();
            if (empty($profiles)) {
                $profiles = $stackInfo->getDefaultProfiles();
            }
            foreach ($profiles as $p) {
                $composeCommand[] = "-g" . $p;
            }
        } else {
            // 'up' and any future actions
            foreach ($stackInfo->getDefaultProfiles() as $p) {
                $composeCommand[] = "-g" . $p;
            }
        }

        // Pass stack path for timestamp saving
        $composeCommand[] = "-s" . $path;

        if ($debug) {
            $composeCommand[] = "--debug";
        }

        $commands[] = $composeCommand;
    }

    if (empty($commands)) {
        composeLogger("Multi Compose operation aborted: no valid stacks resolved", ['action' => $action], 'user', 'warning', 'compose-multi');
        echo '';
        return;
    }

    // Human-readable action label for terminal headings
    $actionLabelByType = [
        'up' => 'Starting',
        'down' => 'Stopping',
        'update' => 'Updating',
    ];
    $actionLabel = $actionLabelByType[$action] ?? ucfirst($action);

    if ($background) {
        // Queue stacks sequentially in a single background wrapper script
        // so we don't slam the system with parallel compose operations.
        $bgScript = $plugin_root . "scripts/compose_background.sh";
        $tmpScript = "/tmp/compose_multi_bg_" . uniqid() . ".sh";
        $scriptContent = "#!/bin/bash\n";
        foreach ($commands as $cmd) {
            $line = escapeshellarg($bgScript);
            foreach ($cmd as $arg) {
                $line .= ' ' . escapeshellarg($arg);
            }
            $scriptContent .= "$line\n";
        }
        $scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";
        file_put_contents($tmpScript, $scriptContent);
        chmod($tmpScript, 0700);
        exec(escapeshellarg($tmpScript) . ' > /dev/null 2>&1 &');
        composeLogger("Background multi-stack queued: " . $tmpScript, ['script' => $tmpScript, 'stacks' => count($commands)], 'user', 'debug', 'compose-multi');
        echo json_encode(['background' => true]);
        return;
    }

    // Create a temporary script and execute it via ttyd.
    // This avoids nested shell-quote edge cases and continues after per-stack failures.
    $tmpScript = "/tmp/compose_multi_" . uniqid() . ".sh";
    $scriptContent = "#!/bin/bash\n";
    $scriptContent .= "# Multi-stack compose script (ttyd) - auto-generated\n\n";

    foreach ($commands as $idx => $cmd) {
        $cmdStr = implode(" ", array_map('escapeshellarg', $cmd));
        $stackTitle = str_replace(['\\', '"'], ['\\\\', '\\"'], $stackNames[$idx]);

        $scriptContent .= "echo \"\"\n";
        $scriptContent .= "echo \"=== " . $actionLabel . ": " . $stackTitle . " ===\"\n";
        $scriptContent .= "echo \"\"\n";
        $scriptContent .= $cmdStr . "\n";
        $scriptContent .= "rc=$?\n";
        $scriptContent .= "if [ \$rc -ne 0 ]; then\n";
        $scriptContent .= "  echo \"X Stack " . $stackTitle . " failed to " . strtolower($actionLabel) . " (exit code: \$rc)\"\n";
        $scriptContent .= "fi\n";
        $scriptContent .= "echo \"\"\n";
    }

    $scriptContent .= "echo \"========================================\"\n";
    $scriptContent .= "echo \"=== All operations complete ===\"\n";
    $scriptContent .= "echo \"========================================\"\n";
    $scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";

    file_put_contents($tmpScript, $scriptContent);
    chmod($tmpScript, 0755);

    $ttydCommand = "bash " . escapeshellarg($tmpScript);
    execComposeCommandInTTY($ttydCommand, $debug);
    composeLogger("Multi-stack script created: " . $tmpScript, null, 'user', 'debug', 'compose-multi');
    echo "/plugins/compose.manager/include/ShowTtyd.php?done=1";
}
