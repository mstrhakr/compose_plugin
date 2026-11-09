<?php

require_once __DIR__ . '/UiOperation.php';

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
    if ($timeoutMs < 0 || $intervalMs <= 0) {
        throw new InvalidArgumentException('Invalid terminal readiness timeout.');
    }
    $deadline = hrtime(true) + $timeoutMs * 1000000;
    for ($i = 0; ; $i++) {
        clearstatcache(true, $socketPath);
        if (file_exists($socketPath)) {
            composeLogger("ttyd socket ready: $socketPath", ['socket' => $socketPath, 'attempt' => $i], 'user', 'debug', 'ttyd');
            return true;
        }
        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) break;
        usleep((int) min($intervalMs * 1000, max(1, ceil($remaining / 1000))));
    }
    composeLogger("ttyd socket timeout: $socketPath", ['socket' => $socketPath, 'timeoutMs' => $timeoutMs], 'user', 'warning', 'ttyd');
    return false;
}

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
    $accepted = false;
    try {
        $url = composeStartTerminalSession($command, $operations, $debug, $logFile, $accepted);
        $url .= '&command=' . rawurlencode($action) . '&bulk=' . (count($operations) > 1 ? '1' : '0');
        echo json_encode(['url' => $url, 'operations' => $operations]);
    } catch (RuntimeException $error) {
        composeLogger('Unable to open managed terminal', ['message' => $error->getMessage()], 'user', 'error', 'operation');
        if ($accepted) {
            echo json_encode(['background' => true, 'warning' => true, 'message' => $error->getMessage(), 'operations' => $operations]);
        } else {
            foreach ($operations as $project => $id) {
                composeFailQueuedUiOperation(composeUiOperationFile($project, $id));
            }
            echo json_encode(['error' => 'terminal', 'message' => $error->getMessage(), 'operations' => $operations]);
        }
    }
}

/**
 * Start the command independently of ttyd. The terminal only follows its output.
 *
 * @param array<string, string> $operations
 */
function composeStartTerminalSession(string $command, array $operations, bool $debug, string $logFile = '', bool &$accepted = false): string
{
    // Use the same private directory and unguessable identifiers as operation receipts.
    $id = composeCreateUiOperation('terminal-session');
    $file = composeUiOperationFile('terminal-session', $id);
    $output = $file . '.log';
    if (file_put_contents($output, '') === false || !chmod($output, 0600)) {
        composeFailQueuedUiOperation($file);
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
    exec($launch . ' < /dev/null > /dev/null 2>' . escapeshellarg($file . '.stderr') . ' & echo $!', $launchOutput, $launchCode);
    $pid = (int) ($launchOutput[0] ?? 0);
    if ($launchCode !== 0 || $pid < 2) {
        composeFailQueuedUiOperation($file);
        throw new RuntimeException('Unable to launch terminal command.');
    }
    $accepted = true;
    $session = [];
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $session = composeReadTerminalSession($id);
        if ($session['state'] !== 'queued') {
            break;
        }
        usleep(20000);
    }
    if ($session['state'] === 'queued') {
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $unused, $alive);
        if ($alive !== 0) {
            $accepted = false;
            composeFailQueuedUiOperation($file);
            throw new RuntimeException('Terminal command exited before confirming startup. Check the server command prerequisites.');
        }
        // Accepted work remains independent of viewer startup and status polling.
        return $url;
    }
    return $url;
}

/** @return array{ready: bool, connected: bool} */
function composeConnectTerminalViewer(string $id, bool $retry = false): array
{
    $session = composeReadTerminalSession($id);
    if ($session['state'] === 'queued') {
        throw new RuntimeException('The command is still starting. Retrying the live connection.');
    }
    if ($session['state'] === 'running' && (!is_int($session['pid'] ?? null) || $session['pid'] < 2)) {
        throw new RuntimeException('Invalid command process receipt.');
    }
    $file = composeUiOperationFile('terminal-session', $id);
    $socketName = 'compose_action_' . $id;
    $socket = rtrim(COMPOSE_TTYD_SOCKET_DIR, '/') . "/$socketName.sock";
    $lock = fopen($file . '.viewer.lock', 'c+');
    if ($lock === false) throw new RuntimeException('Unable to open viewer startup lock.');
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another live connection attempt is still in progress.');
        }
        $metadata = $file . '.viewer.json';
        if (is_file($metadata)) {
            $viewer = json_decode((string) file_get_contents($metadata), true);
            if (!is_array($viewer) || !is_int($viewer['pid'] ?? null) || $viewer['pid'] < 2) {
                throw new RuntimeException('Invalid viewer process receipt.');
            }
            $pid = $viewer['pid'];
            $command = @file_get_contents("/proc/$pid/cmdline");
            $owned = $command !== false
                && in_array(dirname(__DIR__) . '/scripts/compose_terminal_viewer.sh', explode("\0", $command), true)
                && in_array($socket, explode("\0", $command), true);
            if ($owned && !$retry) {
                return ['ready' => waitForTtydSocket($socketName, 10000),
                    'connected' => is_file($file . '.log.connected')];
            }
            if ($owned) {
                exec('/bin/kill -TERM -- ' . $pid . ' 2>&1', $errors, $code);
                if ($code !== 0) throw new RuntimeException('Unable to close previous viewer: ' . implode("\n", $errors));
                for ($attempt = 0; $attempt < 100; $attempt++) {
                    $command = @file_get_contents("/proc/$pid/cmdline");
                    if ($command === false || $command === '') break;
                    usleep(20000);
                }
                if ($command !== false && $command !== '') {
                    throw new RuntimeException('Previous viewer is still closing. Retry shortly.');
                }
            }
        }
        if (file_exists($socket)) {
            throw new RuntimeException('An unowned viewer socket already exists; refusing to replace it.');
        }
        if (is_file($file . '.log.viewed') && !unlink($file . '.log.viewed')) {
            throw new RuntimeException('Unable to reset live connection acknowledgement.');
        }
        $viewer = ['bash', dirname(__DIR__) . '/scripts/compose_terminal_viewer.sh', $socket, $file . '.log',
            (string) ($session['state'] === 'running' ? $session['pid'] : 0)];
        exec(implode(' ', array_map('escapeshellarg', $viewer)) . ' < /dev/null > /dev/null 2>>'
            . escapeshellarg($file . '.stderr') . ' & echo $!', $launchOutput, $launchCode);
        $pid = (int) ($launchOutput[0] ?? 0);
        if ($launchCode !== 0 || $pid < 2) throw new RuntimeException('Unable to start the live output viewer.');
        if (file_put_contents($metadata, json_encode(['pid' => $pid]), LOCK_EX) === false) {
            throw new RuntimeException('Unable to save viewer process receipt.');
        }
        return ['ready' => waitForTtydSocket($socketName, 10000),
            'connected' => is_file($file . '.log.connected')];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @return array{data: string, offset: int, generation: int, reset: bool, remaining: bool} */
function composeReadTerminalOutput(string $id, int $offset, int $generation): array
{
    composeReadTerminalSession($id);
    if ($offset < 0 || $offset > 1048576 || $generation < 0) {
        throw new InvalidArgumentException('Invalid terminal output cursor.');
    }
    $file = composeUiOperationFile('terminal-session', $id) . '.log';
    $stream = @fopen($file, 'rb');
    if ($stream === false) throw new RuntimeException('Command output is unavailable.');
    try {
        if (!flock($stream, LOCK_SH)) throw new RuntimeException('Unable to lock command output.');
        $stat = fstat($stream);
        if ($stat === false) throw new RuntimeException('Unable to inspect command output.');
        $currentGeneration = is_file($file . '.generation') ? file_get_contents($file . '.generation') : '0';
        if ($currentGeneration === false || !ctype_digit(trim($currentGeneration))) {
            throw new RuntimeException('Invalid command output generation.');
        }
        $currentGeneration = (int) trim($currentGeneration);
        $reset = $generation !== $currentGeneration || $offset > $stat['size'];
        if ($reset) $offset = 0;
        if (fseek($stream, $offset) !== 0) throw new RuntimeException('Unable to seek command output.');
        $chunk = stream_get_contents($stream, 65536);
        if ($chunk === false) throw new RuntimeException('Unable to read command output.');
        $offset += strlen($chunk);
        return ['data' => base64_encode($chunk), 'offset' => $offset, 'generation' => $currentGeneration,
            'reset' => $reset, 'remaining' => $offset < $stat['size']];
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}
