<?php
require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");

$compose_root = $compose_root ?? locate_compose_root('compose.manager');

$action = isset($_POST['action']) ? $_POST['action'] : '';
$autofile = getAutoUpdateConfigFilePath();
$legacyAutofile = rtrim($plugin_root ?? '', '/') . "/autoupdate.json";

if ($autofile !== $legacyAutofile && !is_file($autofile) && is_file($legacyAutofile)) {
    $targetDir = dirname($autofile);
    if (is_dir($targetDir) || @mkdir($targetDir, 0755, true)) {
        @copy($legacyAutofile, $autofile);
    }
}

header('Content-Type: application/json');

switch ($action) {
    case 'getConfig':
        if (is_file($autofile)) {
            $raw = file_get_contents($autofile);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                echo json_encode(array());
            } else {
                echo $raw;
            }
        } else {
            echo json_encode(array());
        }
        break;
    case 'saveConfig':
        $data = isset($_POST['data']) ? $_POST['data'] : '{}';
        // basic validation
        $arr = json_decode($data, true);
        if ($arr === null && $data !== 'null') {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid JSON'));
            break;
        }
        // Normalize keys: ensure top-level is array
        if (!is_array($arr)) $arr = array();
        // Filter top-level keys: only allow stack paths that pass isAllowedAutoUpdatePath
        // Skip validation for 'defaults' key which stores default settings
        $filtered = array();
        foreach ($arr as $stackPath => $config) {
            if ($stackPath === 'defaults' || (is_string($stackPath) && isAllowedAutoUpdatePath($stackPath))) {
                $filtered[$stackPath] = $config;
            }
        }
        // ensure directory exists (test environment may map plugin_root differently)
        $dir = dirname($autofile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to create config directory'));
                break;
            }
        }
        if (file_put_contents($autofile, json_encode($filtered, JSON_PRETTY_PRINT)) === false) {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to write config file'));
            break;
        }
        echo json_encode(array('ok' => true));
        break;
    case 'installCron':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/CronManager.php");
        $cronManager = composeCronManager();
        if ($cronManager->enableAutoupdate()) {
            echo json_encode(array('ok' => true));
        } else {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to update cron'));
        }
        break;
    case 'removeCron':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/CronManager.php");
        $cronManager = composeCronManager();
        if ($cronManager->disableAutoupdate()) {
            echo json_encode(array('ok' => true));
        } else {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to update cron'));
        }
        break;
    case 'getCronStatus':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/CronManager.php");
        $cronManager = composeCronManager();
        echo json_encode(array('installed' => $cronManager->isAutoupdateInstalled()));
        break;
    case 'runNow':
        $path = isset($_POST['path']) ? $_POST['path'] : '';
        if (!$path) {
            http_response_code(400);
            echo json_encode(array('error' => 'Missing path'));
            break;
        }
        // Security: validate path is under allowed directories
        if (!isAllowedAutoUpdatePath($path)) {
            composeLogger('Rejected invalid path: ' . sanitizeLogText($path), null, 'user', 'error', 'autoupdate');
            http_response_code(403);
            echo json_encode(array('error' => 'Path not allowed'));
            break;
        }
        $composeFile = findComposeFile($path);
        if (!$composeFile) {
            http_response_code(404);
            echo json_encode(array('error' => 'Compose file not found'));
            break;
        }
        // Resolve project name - always use compose-safe sanitized projectName.
        $stackInfo = StackInfo::fromComposePath($compose_root, $path);
        if ($stackInfo !== null) {
            if (!$stackInfo->hasResolvedIdentity()) {
                composeLogger(
                    "Blocked manual auto-update for '{$stackInfo->projectFolder}': compose project identity is unresolved",
                    ['identity' => $stackInfo->identity->toArray()],
                    'user',
                    'warning',
                    'identity'
                );
                http_response_code(409);
                echo json_encode(array('error' => $stackInfo->getIdentityBlockReason()));
                break;
            }
            $projectName = $stackInfo->projectName;
        } else {
            $projectName = basename($path);
            if (is_file("$path/name")) {
                $projectName = trim(file_get_contents("$path/name"));
            }
            $projectName = StackInfo::sanitizeProjectString($projectName);
        }

        composeLogger("Running manual auto-update for: $projectName", null, 'user', 'info', 'autoupdate');

        $script = $plugin_root . "scripts/compose_autoupdate.sh";
        if ($stackInfo !== null) {
            $args = $stackInfo->buildComposeArgs();
            $composeFileList = $stackInfo->buildComposeFileList();
            $envFilePath = $args['envFilePath'] ?? null;
            $projectDirectory = $args['projectDirectory'];
        } else {
            $composeFileList = $composeFile;
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

        $cmd = $envPrefix . $shCmd . ' ' . escapeshellarg($script) . " " . escapeshellarg($projectName) . " 2>&1";
        exec($cmd, $output, $rc);
        echo json_encode(array('rc' => $rc, 'output' => $output));
        break;
    default:
        http_response_code(400);
        echo json_encode(array('error' => 'Unknown action'));
        break;
}
