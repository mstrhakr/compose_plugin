<?php

require_once __DIR__ . '/UiOperation.php';

/**
 * @return array<string, mixed>
 */
function composeReadTerminalSession(string $id): array
{
    $file = composeUiOperationFile('terminal-session', $id);
    if (!is_file($file)) {
        throw new RuntimeException('Terminal session is unavailable.');
    }
    $session = json_decode((string) file_get_contents($file), true);
    if (!is_array($session) || !in_array($session['state'] ?? '', ['queued', 'running', 'completed'], true)) {
        throw new RuntimeException('Terminal session receipt is invalid.');
    }
    return $session;
}

function composeStopTerminalSession(string $id): void
{
    $session = composeReadTerminalSession($id);
    if ($session['state'] === 'completed') {
        return;
    }
    if ($session['state'] !== 'running' || !is_int($session['pid'] ?? null) || $session['pid'] < 2) {
        throw new RuntimeException('The command has not started yet. Try again shortly.');
    }
    $pid = $session['pid'];
    $stat = @file_get_contents("/proc/$pid/stat");
    $command = @file_get_contents("/proc/$pid/cmdline");
    if ($stat === false || $command === false) {
        throw new RuntimeException('The command exited while stopping it. Refresh its status.');
    }
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    $arguments = explode("\0", $command);
    if (($fields[2] ?? '') !== (string) $pid || ($fields[3] ?? '') !== (string) $pid
        || ($fields[19] ?? '') !== ($session['started'] ?? '')
        || !in_array(dirname(__DIR__) . '/scripts/compose_terminal_session.sh', $arguments, true)
        || !in_array(composeUiOperationFile('terminal-session', $id), $arguments, true)) {
        throw new RuntimeException('Terminal process identity changed; refusing to signal it.');
    }
    exec('/bin/kill -TERM -- ' . $pid . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException('Unable to stop terminal command: ' . implode("\n", $output));
    }
}

/**
 * @param array<string, string> $operations
 */
function composeEchoTerminalSession(string $command, array $operations, bool $debug, string $logFile = '', string $action = ''): void
{
    try {
        $url = composeStartTerminalSession($command, $operations, $debug, $logFile);
        $url .= '&command=' . rawurlencode($action) . '&bulk=' . (count($operations) > 1 ? '1' : '0');
        echo json_encode(['url' => $url, 'operations' => $operations]);
    } catch (RuntimeException $error) {
        composeLogger('Unable to open managed terminal', ['message' => $error->getMessage()], 'user', 'error', 'operation');
        echo json_encode(['error' => 'terminal', 'message' => $error->getMessage(), 'operations' => $operations]);
    }
}

/**
 * Start the command independently of ttyd. The terminal only follows its output.
 *
 * @param array<string, string> $operations
 */
function composeStartTerminalSession(string $command, array $operations, bool $debug, string $logFile = ''): string
{
    // Use the same private directory and unguessable identifiers as operation receipts.
    $id = composeCreateUiOperation('terminal-session');
    $file = composeUiOperationFile('terminal-session', $id);
    $output = $file . '.log';
    if (file_put_contents($output, '') === false || !chmod($output, 0600)) {
        throw new RuntimeException('Unable to create terminal output log.');
    }
    $args = ['setsid', 'bash', dirname(__DIR__) . '/scripts/compose_terminal_session.sh', $file, $command, $logFile];
    foreach ($operations as $project => $operationId) {
        $args[] = composeUiOperationFile($project, $operationId);
    }
    $launch = implode(' ', array_map('escapeshellarg', $args));
    $url = '/plugins/compose.manager/include/ShowTtyd.php?done=1&socket=compose_action_' . $id . '&session=' . $id;
    if (defined('COMPOSE_SKIP_TTYD_EXEC') && COMPOSE_SKIP_TTYD_EXEC) {
        composeLogger('Skipping managed terminal execution in test mode: ' . $launch, null, 'user', 'debug', 'ttyd');
        return $url;
    }
    exec($launch . ' < /dev/null > ' . escapeshellarg($output) . ' 2>&1 &');
    $session = [];
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $session = composeReadTerminalSession($id);
        if ($session['state'] !== 'queued') {
            break;
        }
        usleep(20000);
    }
    if ($session['state'] === 'queued') {
        throw new RuntimeException('Terminal command did not confirm startup. Check its operation status before retrying.');
    }
    $viewer = 'cat ' . escapeshellarg($output);
    if ($session['state'] === 'running') {
        $viewer = 'tail --pid=' . (int) $session['pid'] . ' -n +1 -F ' . escapeshellarg($output);
    }
    // Each viewer has its own socket; opening another stack cannot kill this viewer.
    execComposeCommandInTTY($viewer, $debug, '', 'compose_action_' . $id);
    return $url;
}
