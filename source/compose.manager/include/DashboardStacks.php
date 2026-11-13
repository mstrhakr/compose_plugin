<?PHP

/**
 * AJAX endpoint for dashboard tile - returns compose stacks data
 * Called asynchronously to avoid blocking dashboard load
 */

$plugin_root = "/usr/local/emhttp/plugins/compose.manager";

require_once("$plugin_root/include/Defines.php");
require_once("$plugin_root/include/Util.php");
require_once("$plugin_root/include/StackUpdateService.php");

$summary = [
    'total' => 0,
    'started' => 0,
    'stopped' => 0,
    'partial' => 0,
    'stacks' => [],
    'composeContainerNames' => []
];

if (!is_dir($compose_root)) {
    header('Content-Type: application/json');
    echo json_encode($summary);
    exit;
}

// Load saved update status from central JSON file
try {
    $savedUpdateStatus = StackUpdateService::readSavedResults();
} catch (RuntimeException | JsonException $e) {
    composeLogger('Unable to read dashboard update status: ' . $e->getMessage(), null, 'user', 'error', 'update-check');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['result' => 'error', 'message' => $e->getMessage()]);
    return;
}

foreach (StackInfo::allFromRoot($compose_root) as $stackInfo) {
    $summary['total']++;

    // Centralized stack state
    $stackState = $stackInfo->getStackState();
    $state = $stackState['state'];
    $runningCount = $stackState['running'];
    $totalContainers = $stackState['total'];

    // Read stack started_at timestamp via StackInfo
    $startedAt = $stackInfo->getStartedAt();

    // Collect container names for hiding from Docker tile
    foreach ($stackInfo->getContainerList() as $ct) {
        $name = ltrim(trim($ct['Names'] ?? ''), '/');
        if ($name) {
            $summary['composeContainerNames'][] = $name;
        }
    }

    if ($state === 'started') {
        $summary['started']++;
    } elseif ($state === 'partial' || $state === 'paused') {
        $summary['partial']++;
    } else {
        $summary['stopped']++;
    }

    // Get custom project icon and webui URL via StackInfo
    $icon = $stackInfo->getIconUrl();
    $webui = $stackInfo->getWebUIUrl();

    $updateContainers = $savedUpdateStatus[$stackInfo->projectFolder]['containers'] ?? [];
    $updateStatus = 'unknown';
    if (array_filter($updateContainers, static fn(array $ct): bool => !empty($ct['hasUpdate']) && empty($ct['isPinned']))) {
        $updateStatus = 'update-available';
    } elseif ($updateContainers && !array_filter($updateContainers, static fn(array $ct): bool =>
        empty($ct['isPinned']) && ($ct['updateReadiness'] ?? 'unknown') !== 'current')) {
        $updateStatus = 'up-to-date';
    }

    $summary['stacks'][] = [
        'name' => $stackInfo->getName(),
        'folder' => $stackInfo->projectFolder,
        'path' => $stackInfo->path,
        'state' => $state,
        'running' => $runningCount,
        'total' => $totalContainers,
        'icon' => $icon,
        'webui' => $webui,
        'startedAt' => $startedAt,
        'update' => $updateStatus,
        'updateContainers' => $updateContainers,
    ];
}

header('Content-Type: application/json');

echo json_encode($summary);
