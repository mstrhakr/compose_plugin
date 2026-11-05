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
    // Receipts are transient and may be abandoned by disconnected browsers.
    foreach (glob($directory . '/*.json') ?: [] as $oldFile) {
        if (filemtime($oldFile) < time() - 86400 && !unlink($oldFile)) {
            throw new RuntimeException('Unable to expire operation receipt.');
        }
        if (!is_file($oldFile) && is_file($oldFile . '.log') && !unlink($oldFile . '.log')) {
            throw new RuntimeException('Unable to expire terminal output log.');
        }
    }
    if (file_put_contents($file, '{"state":"queued"}', LOCK_EX) === false) {
        throw new RuntimeException('Unable to queue operation receipt.');
    }
    return $id;
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
