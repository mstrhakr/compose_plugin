<?php

/**
 * Compose Util - AJAX Action Handler for Compose Manager
 *
 * Handles compose actions like up, down, pull, etc.
 * Functions are defined in Helpers.php for testability.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Helpers.php");

$background = isset($_POST['background']) && $_POST['background'] == '1';
$removeOrphans = isset($_POST['removeOrphans']) && $_POST['removeOrphans'] == '1';
$followLogs = isset($_POST['followLogs']) && $_POST['followLogs'] == '1';

switch ($_POST['action']) {
    case 'composeUp':
        echoComposeCommand('up', [
            'background' => $background,
            'removeOrphans' => $removeOrphans,
            'followLogs' => $followLogs
        ]);
        break;
    case 'composeDetachFollow':
        $path = $_POST['path'] ?? '';
        echo json_encode([
            'result' => suspendComposeFollowProcess($path) ? 'success' : 'not_found',
            'path' => $path,
        ]);
        break;
    case 'composeUpRecreate':
        echoComposeCommand('up', [
            'recreate' => true,
            'background' => $background,
            'removeOrphans' => $removeOrphans
        ]);
        break;
    case 'composeDown':
        echoComposeCommand('down', [
            'background' => $background,
            'removeOrphans' => $removeOrphans
        ]);
        break;
    case 'composeUpPullBuild':
        echoComposeCommand('update', [
            'background' => $background
        ]);
        break;
    case 'composePull':
        echoComposeCommand('pull', [
            'background' => $background
        ]);
        break;
    case 'composeStop':
        echoComposeCommand('stop', [
            'background' => $background
        ]);
        break;
    case 'composeLogs':
        echoComposeCommand('logs');
        break;
    case 'composeUpMultiple':
        $paths = isset($_POST['paths']) ? json_decode($_POST['paths'], true) : array();
        if (!empty($paths)) {
            echoComposeCommandMultiple('up', [
                'paths' => $paths,
                'background' => $background,
                'removeOrphans' => $removeOrphans
            ]);
        }
        break;
    case 'composeDownMultiple':
        $paths = isset($_POST['paths']) ? json_decode($_POST['paths'], true) : array();
        if (!empty($paths)) {
            echoComposeCommandMultiple('down', [
                'paths' => $paths,
                'background' => $background,
                'removeOrphans' => $removeOrphans
            ]);
        }
        break;
    case 'composeUpdateMultiple':
        $paths = isset($_POST['paths']) ? json_decode($_POST['paths'], true) : array();
        if (!empty($paths)) {
            echoComposeCommandMultiple('update', [
                'paths' => $paths,
                'background' => $background,
                'removeOrphans' => $removeOrphans
            ]);
        }
        break;
    case 'containerConsole':
        // Open a ttyd console for a specific container (docker exec -it <name> <shell>)
        $containerName = $_POST['container'] ?? '';
        $shell = $_POST['shell'] ?? '/bin/bash';
        if ($containerName) {
            // Check if the requested shell exists in the container; fall back to sh
            $checkCmd = "docker exec " . escapeshellarg($containerName) . " which " . escapeshellarg($shell) . " 2>/dev/null";
            $shellPath = trim(exec($checkCmd));
            if (empty($shellPath)) {
                $shell = 'sh';
            }
            // Sanitise container name for use as socket filename
            $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $containerName);
            $socketName = "compose_ct_" . $safeName;
            $socketFile = rtrim(COMPOSE_TTYD_SOCKET_DIR, '/') . "/$socketName.sock";

            // Kill any existing ttyd on this socket (pkill -f is more robust
            // than pgrep|awk — ensures stale read-only instances are gone)
            exec("pkill -f " . escapeshellarg($socketName . ".sock") . " 2>/dev/null");
            usleep(300000); // 300ms for process to exit
            @unlink($socketFile);

            // Start ttyd via ttyd-exec wrapper (same as Unraid native docker console).
            // ttyd-exec sources /etc/default/ttyd for TTYD_OPTS and adds -d0.
            // No -R flag = writable interactive terminal.
            $cmd = "ttyd-exec -s9 -om1 -i " . escapeshellarg($socketFile)
                . " docker exec -it " . escapeshellarg($containerName)
                . " " . escapeshellarg($shell);
            exec($cmd);

            // Wait for ttyd to create the socket (up to 2s) to avoid 502
            waitForTtydSocket($socketName);

            // /logterminal/ proxies to COMPOSE_TTYD_SOCKET_DIR/<name>.sock with full
            // bidirectional WebSocket — writable because we omit -R.
            echo "/logterminal/$socketName/";
        }
        break;
    case 'containerLogs':
        // Open a ttyd viewer for docker logs -f <name>
        $containerName = $_POST['container'] ?? '';
        if ($containerName) {
            $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $containerName);
            $socketName = "compose_log_" . $safeName;
            $socketFile = rtrim(COMPOSE_TTYD_SOCKET_DIR, '/') . "/$socketName.sock";

            // Kill any existing ttyd on this socket
            exec("pkill -f " . escapeshellarg($socketName . ".sock") . " 2>/dev/null");
            usleep(300000);
            @unlink($socketFile);

            // Start ttyd with docker logs -f (read-only)
            $cmd = "ttyd -R -o -i " . escapeshellarg($socketFile)
                . " docker logs -f " . escapeshellarg($containerName) . " > /dev/null 2>&1 &";
            exec($cmd);

            // Wait for ttyd to create the socket (up to 2s) to avoid 502
            waitForTtydSocket($socketName);

            echo "/plugins/compose.manager/include/ShowTtyd.php?socket=" . urlencode($socketName);
        }
        break;
}
