<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/ComposeCommandBuilder.php';

$options = getopt('', ['compose-root:', 'project:', 'action:', 'stack-path::', 'format:']);

$composeRoot = isset($options['compose-root']) ? trim((string) $options['compose-root']) : '';
$project = isset($options['project']) ? trim((string) $options['project']) : '';
$action = isset($options['action']) ? trim((string) $options['action']) : '';
$stackPath = isset($options['stack-path']) ? trim((string) $options['stack-path']) : null;
$format = isset($options['format']) ? strtolower(trim((string) $options['format'])) : 'json';

if ($format !== 'json' && $format !== 'tsv') {
    $format = 'json';
}

/**
 * Emit command args payload using the requested output format.
 *
 * @param array<string, mixed> $data
 */
function emitSuccess(array $data, string $format): void
{
    if ($format === 'tsv') {
        echo "result\tsuccess\n";
        echo "action\t" . ($data['action'] ?? '') . "\n";
        echo "projectName\t" . ($data['projectName'] ?? '') . "\n";
        echo "stackPath\t" . ($data['stackPath'] ?? '') . "\n";
        echo "projectDirectory\t" . ($data['projectDirectory'] ?? '') . "\n";
        echo "useDefaultFileDiscovery\t" . ((($data['useDefaultFileDiscovery'] ?? false) ? 'true' : 'false')) . "\n";
        echo "envFilePath\t" . ($data['envFilePath'] ?? '') . "\n";
        echo "credentialId\t" . ($data['credentialId'] ?? '') . "\n";
        foreach (($data['composeFiles'] ?? []) as $filePath) {
            echo "composeFile\t" . $filePath . "\n";
        }
        foreach (($data['profiles'] ?? []) as $profile) {
            echo "profile\t" . $profile . "\n";
        }
        return;
    }

    echo json_encode(['result' => 'success', 'data' => $data], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function emitError(string $message, string $format): void
{
    if ($format === 'tsv') {
        echo "result\terror\n";
        echo "message\t" . $message . "\n";
        return;
    }

    echo json_encode(['result' => 'error', 'message' => $message]) . PHP_EOL;
}

if ($composeRoot === '' || $project === '' || $action === '') {
    fwrite(STDERR, "Usage: compose_args.php --compose-root <dir> --project <name> --action <up|down|update|pull|stop|logs> [--stack-path <path>]\n");
    emitError('Missing required arguments', $format);
    exit(2);
}

try {
    $data = ComposeCommandBuilder::fromProject($composeRoot, $project, $action, $stackPath);
    emitSuccess($data, $format);
    exit(0);
} catch (\Throwable $e) {
    emitError($e->getMessage(), $format);
    exit(1);
}
