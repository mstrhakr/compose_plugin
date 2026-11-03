<?php

require_once __DIR__ . '/Defines.php';

function composeUiOperationFile(string $project, string $id): string
{
    if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
        throw new InvalidArgumentException('Invalid operation identifier.');
    }
    return sys_get_temp_dir() . '/compose-manager-ui-operations/' . hash('sha256', $project) . '-' . $id . '.json';
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
    $id = bin2hex(random_bytes(16));
    $file = composeUiOperationFile($project, $id);
    $directory = dirname($file);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create operation receipt directory.');
    }
    // Receipts are transient and may be abandoned by disconnected browsers.
    foreach (glob($directory . '/*.json') ?: [] as $oldFile) {
        if (filemtime($oldFile) < time() - 86400 && !unlink($oldFile)) {
            throw new RuntimeException('Unable to expire operation receipt.');
        }
    }
    if (file_put_contents($file, '{"state":"queued"}', LOCK_EX) === false) {
        throw new RuntimeException('Unable to queue operation receipt.');
    }
    $operations[$project] = $id;
    return array_merge(['sh', __DIR__ . '/../scripts/compose_ui_operation.sh', $file], $command);
}
