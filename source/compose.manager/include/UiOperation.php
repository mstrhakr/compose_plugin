<?php

require_once __DIR__ . '/Defines.php';

function composeUiOperationFile(string $project, string $id): string
{
    if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
        throw new InvalidArgumentException('Invalid operation identifier.');
    }
    return sys_get_temp_dir() . '/compose-manager-ui-operations/' . hash('sha256', $project) . '-' . $id . '.json';
}

function composeCreateUiOperation(string $project): string
{
    $id = bin2hex(random_bytes(16));
    $file = composeUiOperationFile($project, $id);
    $directory = dirname($file);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create operation receipt directory.');
    }
    if (is_link($directory)
        || fileowner($directory) !== (function_exists('posix_geteuid') ? posix_geteuid() : 0)
        || (fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('Operation receipt directory is not private.');
    }
    composeExpireUiOperations($directory);
    if (file_put_contents($file, '{"state":"queued"}', LOCK_EX) === false) {
        throw new RuntimeException('Unable to queue operation receipt.');
    }
    return $id;
}

function composeExpireUiOperations(string $directory): void
{
    $lock = fopen($directory . '/.housekeeping', 'c+');
    if ($lock === false) throw new RuntimeException('Unable to open operation housekeeping lock.');
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return;
        $lastRun = (int) stream_get_contents($lock);
        if ($lastRun > time() - 60) return;
        // Once per minute across requests, not once per stack in a bulk dispatch.
        $files = glob($directory . '/*.json');
        if ($files === false) throw new RuntimeException('Unable to enumerate operation receipts.');
        foreach ($files as $file) {
            if (preg_match('/^[a-f0-9]{64}-[a-f0-9]{32}\.json$/D', basename($file)) !== 1) continue;
            $stream = @fopen($file, 'rb');
            if ($stream === false) {
                clearstatcache(true, $file);
                if (!file_exists($file) && !is_link($file)) continue;
                throw new RuntimeException('Unable to inspect operation receipt.');
            }
            try {
                // Read bytes and age from the same inode while publishers atomically replace receipts.
                $contents = stream_get_contents($stream);
                $stat = fstat($stream);
                if ($contents === false || $stat === false) {
                    throw new RuntimeException('Unable to inspect operation receipt.');
                }
                $modified = $stat['mtime'];
            } finally {
                fclose($stream);
            }
            $receipt = json_decode($contents, true);
            if (in_array($receipt['state'] ?? '', ['queued', 'running'], true)) continue;
            if ($modified < time() - 3600) {
                foreach (['.log', '.stderr', '.log.generation', '.log.viewed', '.log.connected', '.viewer.json', '.viewer.lock',
                    '.detach-requested', '.detach-ready', '.detach.lock', '.input'] as $suffix) {
                    composeExpireUiOperationFile($file . $suffix, 'Unable to expire terminal output.');
                }
            }
            if ($modified < time() - 86400) {
                composeExpireUiOperationFile($file, 'Unable to expire operation receipt.');
            }
        }
        if (!ftruncate($lock, 0) || fseek($lock, 0) !== 0 || fwrite($lock, (string) time()) === false) {
            throw new RuntimeException('Unable to record operation housekeeping.');
        }
    } finally {
        fclose($lock);
    }
}

function composeExpireUiOperationFile(string $file, string $message): void
{
    clearstatcache(true, $file);
    if (!file_exists($file) && !is_link($file)) return;
    if (@unlink($file)) return;
    clearstatcache(true, $file);
    if (file_exists($file) || is_link($file)) throw new RuntimeException($message);
}

function composeFailQueuedUiOperation(string $file): void
{
    $receipt = json_decode((string) file_get_contents($file), true);
    if (($receipt['state'] ?? '') !== 'queued') return;
    $temporary = $file . '.failed-' . getmypid();
    if (file_put_contents($temporary, '{"state":"completed","exitCode":1}') === false || !rename($temporary, $file)) {
        throw new RuntimeException('Unable to record dispatch failure.');
    }
}

/**
 * @param string[] $command
 * @param array<string, string> $operations
 * @return string[]
 */
function composeTrackUiCommand(array $command, string $project, array &$operations): array
{
    if (($_POST['trackOperation'] ?? '') !== '1') {
        return $command;
    }
    $id = composeCreateUiOperation($project);
    $file = composeUiOperationFile($project, $id);
    $operations[$project] = $id;
    return array_merge(['sh', __DIR__ . '/../scripts/compose_ui_operation.sh', $file], $command);
}
