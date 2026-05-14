<?php

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");
require_once('/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php');

/**
 * Safely retrieve the 'script' POST parameter (stack directory name).
 * Applies basename() to prevent path traversal attacks.
 * Does NOT apply urldecode() because PHP already decodes POST data.
 *
 * @return string The sanitized script/stack directory name
 */
if (!function_exists('getPostScript')) {
    function getPostScript(): string
    {
        $script = $_POST['script'] ?? '';
        return basename(trim($script));
    }
}

switch ($_POST['action']) {
    case 'composeLogger':
        $message = $_POST['msg'] ?? '';
        $data = $_POST['data'] ?? null;
        $type = $_POST['type'] ?? 'user';
        $level = $_POST['lvl'] ?? 'info';
        $category = $_POST['category'] ?? '';
        composeLogger($message, $data, $type, $level, $category);
        break;
    case 'getConfig':
        $cfg = @parse_ini_file("/boot/config/plugins/compose.manager/compose.manager.cfg", true, INI_SCANNER_NORMAL);
        echo json_encode(['result' => 'success', 'config' => $cfg]);
        break;
    case 'addStack':
        // Validate indirect path (HTTP-boundary security check)
        $indirect = isset($_POST['stackPath']) ? trim($_POST['stackPath']) : '';
        if ($indirect !== '') {
            $realIndirect = realpath(dirname($indirect));
            if ($realIndirect === false) {
                composeLogger("Failed to create stack: Could not resolve indirect path: $indirect", null, 'user', 'error', 'stack');
                echo json_encode(['result' => 'error', 'message' => 'Stack path is invalid or does not exist.']);
                break;
            }
            if (strpos($realIndirect, '/mnt/') !== 0 && strpos($realIndirect, '/boot/config/') !== 0) {
                composeLogger("Failed to create stack: Invalid indirect path: $indirect", null, 'user', 'error', 'stack');
                echo json_encode(['result' => 'error', 'message' => 'Stack path must be under /mnt/ or /boot/config/.']);
                break;
            }
            if (!is_dir($indirect)) {
                composeLogger("Failed to create stack: Indirect stack path does not exist: $indirect", null, 'user', 'error', 'stack');
                echo json_encode(['result' => 'error', 'message' => 'Indirect stack path does not exist.']);
                break;
            }
        }

        $stackName = isset($_POST['stackName']) ? trim($_POST['stackName']) : '';
        $stackDesc = isset($_POST['stackDesc']) ? trim($_POST['stackDesc']) : '';

        try {
            $stack = StackInfo::createNew($compose_root, $stackName, $stackDesc, $indirect);
        } catch (\RuntimeException $e) {
            composeLogger('Failed to create stack: ' . $e->getMessage(), null, 'user', 'error', 'stack');
            // Return user-safe messages; avoid exposing filesystem paths
            $userMessage = match (true) {
                str_contains($e->getMessage(), 'cannot be empty') => 'Stack name cannot be empty.',
                str_contains($e->getMessage(), 'empty folder name') => 'Invalid stack name.',
                str_contains($e->getMessage(), 'unique folder name') => 'Could not create a unique folder for this stack.',
                str_contains($e->getMessage(), 'escape compose root') => 'Invalid stack name.',
                str_contains($e->getMessage(), 'Invalid compose root') => 'Server configuration error.',
                default => 'Failed to create stack. Check server logs for details.',
            };
            echo json_encode(['result' => 'error', 'message' => $userMessage]);
            break;
        }

        composeLogger("Created stack: $stackName", null, 'user', 'info', 'stack');
        echo json_encode(['result' => 'success', 'message' => '', 'project' => $stack->projectFolder, 'projectName' => $stack->getName()]);
        break;
    case 'getDockerContainersForImport':
        $candidates = getDockerManagerImportCandidates();
        echo json_encode(['result' => 'success', 'containers' => $candidates]);
        break;

    case 'generateImportData':
        // Rich import data for the 5-stage wizard — returns per-service metadata, port conflicts, networks
        $containerIds = [];
        if (!empty($_POST['containerIds'])) {
            $data = json_decode($_POST['containerIds'], true);
            if (is_array($data)) {
                $containerIds = $data;
            }
        }
        if (empty($containerIds)) {
            echo json_encode(['result' => 'error', 'message' => 'No containers selected']);
            break;
        }

        $importResult = buildImportServicesFromIds($containerIds);
        $services = $importResult['services'];
        $inspectData = $importResult['inspectData'];

        if ($services === []) {
            echo json_encode(['result' => 'error', 'message' => 'No valid Docker Manager containers found for import']);
            break;
        }

        // Build per-service metadata for the wizard from inspect data
        $servicesMeta = [];
        foreach ($services as $name => $service) {
            $info = $inspectData[$name];
            $converted = dockerContainerToComposeService($info);
            $origName = $converted['originalName'] ?? $name;

            $serviceIcon = $info['Config']['Labels']['net.unraid.docker.icon'] ?? '';
            $serviceWebui = $info['Config']['Labels']['net.unraid.docker.webui'] ?? '';

            // Parse ports into structured data for the frontend
            $parsedPorts = [];
            if (!empty($service['ports'])) {
                foreach ($service['ports'] as $portStr) {
                    $parsedPorts[] = parsePortMapping($portStr);
                }
            }

            $meta = [
                'originalName' => $origName,
                'containerName' => $service['container_name'] ?? $name,
                'image' => $service['image'] ?? '',
                'ports' => $parsedPorts,
                'exposedPorts' => array_keys($service['__exposed_ports'] ?? []),
                'icon' => $serviceIcon,
                'webui' => $serviceWebui,
                'networkMode' => $service['network_mode'] ?? null,
                'networks' => $service['networks'] ?? [],
                'networkIPs' => $service['__network_ips'] ?? [],
            ];

            // Healthcheck info
            if (!empty($service['healthcheck'])) {
                $meta['healthcheck'] = $service['healthcheck'];
                $meta['healthcheckSource'] = $service['__healthcheck_source'] ?? 'image';
            } elseif (!empty($service['__guessed_healthcheck'])) {
                $meta['guessedHealthcheck'] = $service['__guessed_healthcheck'];
                $meta['healthcheckSource'] = 'auto';
            } else {
                $meta['healthcheckSource'] = 'none';
            }

            $servicesMeta[$name] = $meta;
        }

        // Detect port conflicts across all services
        $portConflicts = detectPortConflicts($services);

        // Get available Docker networks
        $dockerNetworks = getDockerNetworks();

        echo json_encode([
            'result' => 'success',
            'services' => $servicesMeta,
            'portConflicts' => $portConflicts,
            'networks' => $dockerNetworks,
        ]);
        break;

    case 'finalizeImportCompose':
        // Accept full wizard config and generate final compose YAML with validation
        $containerIds = [];
        if (!empty($_POST['containerIds'])) {
            $data = json_decode($_POST['containerIds'], true);
            if (is_array($data)) {
                $containerIds = $data;
            }
        }
        if (empty($containerIds)) {
            echo json_encode(['result' => 'error', 'message' => 'No containers selected']);
            break;
        }

        // Decode wizard configuration
        $containerNames = [];
        if (!empty($_POST['containerNames'])) {
            $data = json_decode($_POST['containerNames'], true);
            if (is_array($data)) {
                $containerNames = $data;
            }
        }
        $networkConfig = [];
        if (!empty($_POST['networkConfig'])) {
            $data = json_decode($_POST['networkConfig'], true);
            if (is_array($data)) {
                $networkConfig = $data;
            }
        }
        $healthchecks = [];
        if (!empty($_POST['healthchecks'])) {
            $data = json_decode($_POST['healthchecks'], true);
            if (is_array($data)) {
                $healthchecks = $data;
            }
        }
        $dependencies = [];
        if (!empty($_POST['dependencies'])) {
            $data = json_decode($_POST['dependencies'], true);
            if (is_array($data)) {
                $dependencies = $data;
            }
        }

        // Re-fetch and convert containers using shared helper
        $importResult = buildImportServicesFromIds($containerIds);
        $services = $importResult['services'];

        if (empty($services)) {
            echo json_encode(['result' => 'error', 'message' => 'No valid containers found']);
            break;
        }

        // Apply wizard configuration to services
        $wizardConfig = [
            'containerNames' => $containerNames,
            'networkConfig' => $networkConfig,
            'healthchecks' => $healthchecks,
            'dependencies' => $dependencies,
        ];

        $env = dockerResolveEnvAndCompose($services);
        $composeYml = dockerServicesToComposeYml($services, $wizardConfig);
        $override = dockerAddOverrideIcons($services);

        // Validate the generated YAML
        $validation = ['valid' => true, 'errors' => []];
        try {
            if (function_exists('yaml_parse')) {
                $parsed = yaml_parse($composeYml);
                if ($parsed === false) {
                    $validation['valid'] = false;
                    $validation['errors'][] = 'Generated YAML is not valid';
                }
            }
        } catch (\Throwable $e) {
            $validation['valid'] = false;
            $validation['errors'][] = 'YAML parse error: ' . $e->getMessage();
        }

        echo json_encode([
            'result' => 'success',
            'composeYml' => $composeYml,
            'env' => $env,
            'override' => $override,
            'validation' => $validation,
        ]);
        break;

    case 'performImportTransfer':
        $stackName = trim($_POST['stackName'] ?? '');
        $stackDesc = trim($_POST['stackDesc'] ?? '');
        $stopContainers = (!empty($_POST['stopContainers']) && $_POST['stopContainers'] === '1');
        $removeContainers = (!empty($_POST['removeContainers']) && $_POST['removeContainers'] === '1');
        $startStack = (!empty($_POST['startStack']) && $_POST['startStack'] === '1');
        $composeYml = trim($_POST['composeYml'] ?? '');
        $env = trim($_POST['env'] ?? '');
        $override = trim($_POST['override'] ?? '');

        if ($stackName === '') {
            echo json_encode(['result' => 'error', 'message' => 'Stack name is required']);
            break;
        }

        if ($composeYml === '') {
            echo json_encode(['result' => 'error', 'message' => 'Compose content is required']);
            break;
        }

        if ($removeContainers && !$stopContainers) {
            // Enforce logical dependency (remove implies stop)
            $stopContainers = true;
        }

        if ($startStack && !$removeContainers) {
            echo json_encode(['result' => 'error', 'message' => 'Cannot start imported stack without removing original containers — container names would conflict']);
            break;
        }

        try {
            $stackInfo = StackInfo::createNew($compose_root, $stackName, $stackDesc);
        } catch (\RuntimeException $e) {
            composeLogger('[stack] Failed to create import stack: ' . $e->getMessage(), null, 'daemon', 'error');
            $userMessage = match (true) {
                str_contains($e->getMessage(), 'cannot be empty') => 'Stack name cannot be empty.',
                str_contains($e->getMessage(), 'empty folder name') => 'Invalid stack name.',
                str_contains($e->getMessage(), 'unique folder name') => 'Could not create a unique folder for this stack.',
                str_contains($e->getMessage(), 'escape compose root') => 'Invalid stack name.',
                str_contains($e->getMessage(), 'Invalid compose root') => 'Server configuration error.',
                default => 'Failed to create stack. Check server logs for details.',
            };
            echo json_encode(['result' => 'error', 'message' => $userMessage]);
            break;
        }

        // Helper to clean up the newly created stack folder on failure
        $cleanupStack = function () use ($stackInfo) {
            if (is_dir($stackInfo->path)) {
                exec('rm -rf ' . escapeshellarg($stackInfo->path));
                \StackInfo::clearCache();
            }
        };

        $composePath = $stackInfo->composeFilePath;
        $envPath = $stackInfo->getEnvFilePath() ?? $stackInfo->composeSource . '/.env';
        $overridePath = $stackInfo->getOverridePath();

        // Validate that the compose content is parseable YAML before writing
        try {
            if (function_exists('yaml_parse')) {
                $parsed = yaml_parse($composeYml);
                if ($parsed === false) {
                    throw new \Exception('Invalid YAML');
                }
            }
        } catch (\Throwable $e) {
            $cleanupStack();
            echo json_encode(['result' => 'error', 'message' => 'Generated compose YAML is invalid']);
            break;
        }

        if (file_put_contents($composePath, $composeYml) === false) {
            $cleanupStack();
            echo json_encode(['result' => 'error', 'message' => 'Failed to write compose file']);
            break;
        }
        if ($env !== '') {
            if (file_put_contents($envPath, $env) === false) {
                $cleanupStack();
                echo json_encode(['result' => 'error', 'message' => 'Failed to write .env file']);
                break;
            }
        }
        if ($override !== '') {
            if (file_put_contents($overridePath, $override) === false) {
                $cleanupStack();
                echo json_encode(['result' => 'error', 'message' => 'Failed to write override file']);
                break;
            }
        }

        $containerIds = [];
        if (!empty($_POST['containerIds'])) {
            $data = json_decode($_POST['containerIds'], true);
            if (is_array($data)) {
                $containerIds = $data;
            }
        }

        // ── Two-phase atomic container operations with full rollback ──
        if (($stopContainers || $removeContainers) && !empty($containerIds)) {
            require_once('/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php');
            $dockerClient = new \DockerClient();
            $dockerTemplates = new \DockerTemplates();

            // Pre-snapshot: record each container's state and XML template for rollback
            $containerSnapshots = [];
            foreach ($containerIds as $id) {
                $id = trim($id);
                if ($id === '') {
                    continue;
                }
                $details = $dockerClient->getContainerDetails($id);
                if (!is_array($details)) {
                    continue;
                }
                $containerName = ltrim(trim($details['Name'] ?? ''), '/');
                $state = strtolower($details['State']['Status'] ?? 'unknown');
                $xmlTemplate = $containerName !== '' ? $dockerTemplates->getUserTemplate($containerName) : false;
                $containerSnapshots[] = [
                    'id' => $id,
                    'name' => $containerName,
                    'state' => $state,          // running, paused, exited, etc.
                    'xmlTemplate' => $xmlTemplate, // path or false
                ];
            }

            // Rollback helper: restart containers that were stopped
            $rollbackStopped = function (array $stoppedIds) {
                foreach (array_reverse($stoppedIds) as $sid) {
                    exec('docker start ' . escapeshellarg($sid) . ' 2>&1');
                }
            };

            // Rollback helper: recreate containers that were removed from XML templates
            $rollbackRemoved = function (array $removedSnapshots) {
                foreach (array_reverse($removedSnapshots) as $snap) {
                    if (empty($snap['xmlTemplate'])) {
                        composeLogger('Cannot restore container ' . $snap['name'] . ': no XML template found', null, 'daemon', 'error', 'import');
                        continue;
                    }
                    $cmdResult = xmlToCommand($snap['xmlTemplate']);
                    if (!is_array($cmdResult) || empty($cmdResult[0])) {
                        composeLogger('Failed to generate recreate command for ' . $snap['name'], null, 'daemon', 'error', 'import');
                        continue;
                    }
                    $cmd = $cmdResult[0];
                    if ($snap['state'] === 'running') {
                        $cmd = str_replace('/docker create ', '/docker run -d ', $cmd);
                    }
                    exec($cmd . ' 2>&1', $cmdOutput, $exitCode);
                    if ($exitCode !== 0) {
                        composeLogger('Failed to recreate container ' . $snap['name'] . ': exit ' . $exitCode, null, 'daemon', 'error', 'import');
                    }
                }
            };

            // Phase 1: STOP all running/paused containers
            $stoppedIds = [];
            $stopFailed = false;
            $failedName = '';
            foreach ($containerSnapshots as $snap) {
                if ($snap['state'] !== 'running' && $snap['state'] !== 'paused') {
                    continue; // Already stopped
                }
                exec('docker stop ' . escapeshellarg($snap['id']) . ' 2>&1', $stopOutput, $exitCode);
                if ($exitCode !== 0) {
                    $stopFailed = true;
                    $failedName = $snap['name'];
                    break;
                }
                $stoppedIds[] = $snap['id'];
            }

            if ($stopFailed) {
                $rollbackStopped($stoppedIds);
                $cleanupStack();
                echo json_encode(['result' => 'error', 'message' => 'Failed to stop container "' . $failedName . '". Import rolled back — no containers were changed.']);
                break;
            }

            // Phase 2: REMOVE all containers (if requested)
            if ($removeContainers) {
                $removedSnapshots = [];
                $rmFailed = false;
                $failedName = '';
                foreach ($containerSnapshots as $snap) {
                    exec('docker rm -f ' . escapeshellarg($snap['id']) . ' 2>&1', $rmOutput, $exitCode);
                    if ($exitCode !== 0) {
                        $rmFailed = true;
                        $failedName = $snap['name'];
                        break;
                    }
                    $removedSnapshots[] = $snap;
                }

                if ($rmFailed) {
                    $rollbackRemoved($removedSnapshots);
                    $cleanupStack();
                    echo json_encode(['result' => 'error', 'message' => 'Failed to remove container "' . $failedName . '". Import rolled back — containers restored from Docker Manager templates.']);
                    break;
                }
            }
        }

        echo json_encode([
            'result' => 'success',
            'message' => 'Stack imported successfully',
            'project' => $stackInfo->projectFolder,
            'projectName' => $stackInfo->getName(),
            'projectPath' => $stackInfo->path,
            'startStack' => $startStack ? 1 : 0
        ]);

        break;
    case 'deleteStack':
        $stackName = isset($_POST['stackName']) ? basename(trim($_POST['stackName'])) : "";
        if (!$stackName) {
            composeLogger("Stack deletion failed: Stack name not specified.", null, 'user', 'error', 'stack');
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $folderName = "$compose_root/$stackName";
        $isIndirect = is_file("$folderName/indirect");
        $isInvalidIndirect = !$isIndirect && is_file("$folderName/indirect.invalid");
        $filesRemain = $isIndirect ? file_get_contents("$folderName/indirect")
            : ($isInvalidIndirect ? file_get_contents("$folderName/indirect.invalid") : "");
        composeLogger("Deleting stack: $stackName", [
            'folderName' => $folderName,
            'isIndirect' => $isIndirect,
            'isInvalidIndirect' => $isInvalidIndirect,
            'filesRemain' => $filesRemain
        ], 'user', 'debug', 'stack');

        $execOutput = [];
        $execRc = 0;
        exec("rm -rf " . escapeshellarg($folderName), $execOutput, $execRc);

        $folderStillExists = is_dir($folderName);
        if ($execRc !== 0 || $folderStillExists) {
            composeLogger("Stack folder delete failed", [
                'stackName' => $stackName,
                'folderName' => $folderName,
                'execRc' => $execRc,
                'execOutput' => $execOutput,
                'folderStillExists' => $folderStillExists,
                'filesRemain' => $filesRemain
            ], 'user', 'error', 'stack');
            $msg = "Failed to delete stack folder. " .
                ($execRc !== 0 ? "rm exit code: $execRc. " : "") .
                ($folderStillExists ? "Folder still exists after rm. " : "") .
                (count($execOutput) ? "Output: " . implode("; ", $execOutput) : "");
            echo json_encode(['result' => 'error', 'message' => $msg]);
            break;
        }

        if ($filesRemain == "") {
            composeLogger("Deleted stack: $stackName", null, 'user', 'info', 'stack');
            echo json_encode(['result' => 'success', 'message' => '']);
        } else {
            composeLogger("Deleted stack: $stackName (indirect, external files remain at $filesRemain)", null, 'user', 'warning', 'stack');
            echo json_encode(['result' => 'warning', 'message' => $filesRemain]);
        }
        break;
    case 'changeName':
        $script = getPostScript();
        $newName = isset($_POST['newName']) ? trim($_POST['newName']) : "";
        // Strip characters that could cause shell injection when name is
        // used in bash scripts (e.g. event/started autostart)
        $newName = preg_replace('/[^a-zA-Z0-9 _.\-()\[\]]/', '', $newName);
        file_put_contents("$compose_root/$script/name", $newName);
        echo json_encode(['result' => 'success', 'message' => '']);
        break;
    case 'changeDesc':
        $script = getPostScript();
        $newDesc = isset($_POST['newDesc']) ? trim($_POST['newDesc']) : "";
        file_put_contents("$compose_root/$script/description", trim($newDesc));
        echo json_encode(['result' => 'success', 'message' => '']);
        break;
    case 'getDescription':
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $fileName = "$compose_root/$script/description";
        $fileContents = is_file($fileName) ? file_get_contents($fileName) : "";
        $fileContents = str_replace("\r", "", $fileContents);
        echo json_encode(['result' => 'success', 'content' => $fileContents]);
        break;
    case 'getYml':
        $script = getPostScript();
        // Resolve compose file path via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $composeFilePath = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/compose.yaml');

        // Check file existence consistently regardless of how path was resolved
        if (is_file($composeFilePath)) {
            $scriptContents = file_get_contents($composeFilePath);
            if ($scriptContents === false) {
                echo json_encode(['result' => 'error', 'message' => "Unable to read compose file: $composeFilePath"]);
                break;
            }
        } else {
            // File doesn't exist yet (new stack) - return empty content
            $scriptContents = "";
        }
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (!$scriptContents) {
            $scriptContents = "services:\n";
        }
        echo json_encode(['result' => 'success', 'fileName' => $composeFilePath, 'content' => $scriptContents]);
        break;
    case 'getEnv':
        $script = getPostScript();
        // Resolve env file path via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $fileName = $stackInfo->getEnvFilePath() ?? ($stackInfo->composeSource . '/.env');

        $scriptContents = is_file($fileName) ? file_get_contents($fileName) : "";
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (!$scriptContents) {
            $scriptContents = "\n";
        }
        echo json_encode(['result' => 'success', 'fileName' => $fileName, 'content' => $scriptContents]);
        break;
    case 'getOverride':
        $script = getPostScript();
        $projectPath = "$compose_root/$script";

        // Get Override file path and ensure project override exists (create blank if not)
        $overridePath = StackInfo::fromProject($compose_root, $script)->getOverridePath();

        $scriptContents = is_file($overridePath) ? file_get_contents($overridePath) : "";
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (!$scriptContents) {
            $scriptContents = "";
        }
        echo json_encode(['result' => 'success', 'fileName' => $overridePath, 'content' => $scriptContents]);
        break;
    case 'saveYml':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        // Resolve compose file path via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $composeFilePath = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/' . COMPOSE_FILE_NAMES[0]);

        // Before saving, detect service renames and migrate override entries
        if (is_file($composeFilePath)) {
            $oldContent = file_get_contents($composeFilePath);
            $stackInfo->overrideInfo->migrateOnRename($oldContent, $scriptContents);
        }

        file_put_contents($composeFilePath, $scriptContents);
        echo "$composeFilePath saved";
        break;
    case 'saveEnv':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        // Resolve env file path via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $fileName = $stackInfo->getEnvFilePath() ?? ($stackInfo->composeSource . '/.env');

        file_put_contents($fileName, $scriptContents);
        echo "$fileName saved";
        break;
    case 'saveOverride':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $projectPath = "$compose_root/$script";

        // Get Override file path and ensure project override exists (create blank if not)
        $overridePath = StackInfo::fromProject($compose_root, $script)->getOverridePath();

        file_put_contents($overridePath, $scriptContents);
        echo "$overridePath saved";
        break;
    case 'clearIconCache':
        $script = getPostScript();
        $services = json_decode($_POST['services'] ?? '[]', true);
        if (!$script || !is_array($services) || empty($services)) {
            echo json_encode(['result' => 'error', 'message' => 'Missing project or services.']);
            break;
        }

        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $args = $stackInfo->buildComposeArgs();

        // Resolve container names via docker compose config (works without running containers)
        $cmd = "docker compose {$args['files']} {$args['envFile']} -p "
            . escapeshellarg($args['projectName'])
            . " config --format json 2>/dev/null";
        $configJson = shell_exec($cmd);
        $config = $configJson ? json_decode($configJson, true) : null;

        $iconCacheRAM = '/usr/local/emhttp/state/plugins/dynamix.docker.manager/images';
        $iconCacheUSB = '/var/lib/docker/unraid/images';
        $cleared = [];

        foreach ($services as $service) {
            if (!is_string($service)) continue;

            // Resolve container name from compose config, fall back to default naming
            $containerName = null;
            if (isset($config['services'][$service]['container_name'])) {
                $containerName = $config['services'][$service]['container_name'];
            } else {
                $containerName = $args['projectName'] . '-' . $service . '-1';
            }

            $ramFile = $iconCacheRAM . '/' . $containerName . '-icon.png';
            $usbFile = $iconCacheUSB . '/' . $containerName . '-icon.png';
            @unlink($ramFile);
            @unlink($usbFile);
            $cleared[] = $containerName;
        }

        echo json_encode(['result' => 'success', 'cleared' => $cleared]);
        break;
    case 'updateAutostart':
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $autostart = isset($_POST['autostart']) ? trim($_POST['autostart']) : "false";
        $fileName = "$compose_root/$script/autostart";
        if (is_file($fileName)) {
            @unlink($fileName);
        }
        file_put_contents($fileName, $autostart);
        echo json_encode(['result' => 'success', 'message' => '']);
        break;

    case 'saveStackOrder':
        $projects = $_POST['projects'] ?? [];
        if (!is_array($projects)) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid projects payload']);
            break;
        }

        $availableProjects = StackInfo::listProjectFolders($compose_root);
        $availableLookup = array_fill_keys($availableProjects, true);
        $orderedProjects = [];

        foreach ($projects as $project) {
            if (!is_string($project)) {
                continue;
            }

            $project = basename($project);
            if (!isset($availableLookup[$project]) || in_array($project, $orderedProjects, true)) {
                continue;
            }

            $orderedProjects[] = $project;
        }

        foreach ($availableProjects as $project) {
            if (!in_array($project, $orderedProjects, true)) {
                $orderedProjects[] = $project;
            }
        }

        if (!saveComposeStackOrder($compose_root, $orderedProjects)) {
            echo json_encode(['result' => 'error', 'message' => 'Failed to save stack order']);
            break;
        }

        echo json_encode(['result' => 'success']);
        break;

    case 'runPatch':
        $cmd = isset($_POST['cmd']) ? $_POST['cmd'] : 'apply';
        if (!in_array($cmd, ['apply', 'remove'])) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid command']);
            break;
        }
        $script = "$plugin_root/scripts/patch.sh";
        // Quote each argument to preserve spaces and special characters and avoid the fragility of escapeshellcmd()
        $fullcmd = escapeshellarg($script) . ' ' . escapeshellarg($cmd) . ' ' . escapeshellarg('--verbose') . ' 2>&1';
        exec($fullcmd, $output, $rc);
        // Save a copy to plugin log file
        $logfile = "/boot/config/plugins/compose.manager/patch_last_run.log";
        $ts = date('c');
        $entry = "[{$ts}] runPatch {$cmd} exit={$rc}\n" . implode("\n", $output) . "\n\n";
        @file_put_contents($logfile, $entry, FILE_APPEND);
        foreach ($output as $line) {
            composeLogger(escapeshellarg($cmd) . ' ' . $line, null, 'user', 'debug', 'patch.sh');
        }
        echo json_encode(['result' => $rc === 0 ? 'success' : 'error', 'output' => implode("\n", $output), 'rc' => $rc]);
        break;

    case 'clearUpdateCache':
        // Clear the compose manager update status cache
        $composeUpdateStatusFile = COMPOSE_UPDATE_STATUS_FILE;
        if (is_file($composeUpdateStatusFile)) {
            unlink($composeUpdateStatusFile);
        }
        // Also clear entries from Unraid's update status that were created by compose manager
        // by removing entries that don't correspond to running Docker containers
        $unraidUpdateStatusFile = UNRAID_UPDATE_STATUS_FILE;
        if (is_file($unraidUpdateStatusFile)) {
            require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
            $DockerClient = new DockerClient();
            $runningImages = [];
            foreach ($DockerClient->getDockerContainers() as $ct) {
                $img = $ct['Image'] ?? '';
                if ($img) {
                    $runningImages[DockerUtil::ensureImageTag($img)] = true;
                }
            }
            $updateStatus = DockerUtil::loadJSON($unraidUpdateStatusFile);
            $cleaned = [];
            foreach ($updateStatus as $key => $value) {
                if (isset($runningImages[$key])) {
                    $cleaned[$key] = $value;
                }
            }
            DockerUtil::saveJSON($unraidUpdateStatusFile, $cleaned);
        }
        echo json_encode(['result' => 'success', 'message' => 'Update cache cleared']);
        break;
    case 'setEnvPath':
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $fileContent = isset($_POST['envPath']) ? trim($_POST['envPath']) : "";
        $fileName = "$compose_root/$script/envpath";
        // Validate env path is under an allowed root
        if (!empty($fileContent)) {
            $realEnvDir = realpath(dirname($fileContent));
            $realComposeRoot = realpath($compose_root);
            $allowed = $realEnvDir !== false && (
                strpos($realEnvDir, '/mnt/') === 0 ||
                strpos($realEnvDir, '/boot/config/') === 0 ||
                ($realComposeRoot !== false && strpos($realEnvDir, $realComposeRoot) === 0)
            );
            if (!$allowed) {
                echo json_encode(['result' => 'error', 'message' => 'Env file path must be under /mnt/, /boot/config/, or the compose root.']);
                break;
            }
        }
        if (is_file($fileName)) {
            @unlink($fileName);
        }
        if (!empty($fileContent)) {
            file_put_contents($fileName, $fileContent);
        }
        echo json_encode(['result' => 'success', 'message' => '']);
        break;
    case 'getEnvPath':
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $fileName = "$compose_root/$script/envpath";
        $fileContents = is_file("$fileName") ? file_get_contents("$fileName") : "";
        $fileContents = str_replace("\r", "", $fileContents);
        if (!$fileContents) {
            $fileContents = "";
        }
        echo json_encode(['result' => 'success', 'fileName' => "$fileName", 'content' => $fileContents]);
        break;
    case 'getStackSettings':
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        // Get env path
        $envPathFile = "$compose_root/$script/envpath";
        $envPath = is_file($envPathFile) ? trim(file_get_contents($envPathFile)) : "";

        // Get icon URL
        $iconUrlFile = "$compose_root/$script/icon_url";
        $iconUrl = is_file($iconUrlFile) ? trim(file_get_contents($iconUrlFile)) : "";

        // Get WebUI URL (stack-level)
        $webuiUrlFile = "$compose_root/$script/webui_url";
        $webuiUrl = is_file($webuiUrlFile) ? trim(file_get_contents($webuiUrlFile)) : "";

        // Get default profile
        $defaultProfileFile = "$compose_root/$script/default_profile";
        $defaultProfile = is_file($defaultProfileFile) ? trim(file_get_contents($defaultProfileFile)) : "";

        // Get external compose path (indirect)
        $indirectFile = "$compose_root/$script/indirect";
        $invalidIndirectFile = "$compose_root/$script/indirect.invalid";
        $externalComposePath = "";
        $invalidIndirectPath = "";
        if (is_file($indirectFile)) {
            $raw = trim(file_get_contents($indirectFile));
            if ($raw !== '' && is_dir($raw)) {
                $externalComposePath = $raw;
            } else {
                // Path is invalid or directory unavailable — non-destructive handling
                $invalidIndirectPath = $raw;
            }
        } elseif (is_file($invalidIndirectFile)) {
            // Legacy fallback: older versions renamed indirect → indirect.invalid
            $invalidIndirectPath = trim(file_get_contents($invalidIndirectFile));
        }

        // Get available profiles from the profiles file
        $profilesFile = "$compose_root/$script/profiles";
        $availableProfiles = [];
        if (is_file($profilesFile)) {
            $profilesData = json_decode(file_get_contents($profilesFile), true);
            if (is_array($profilesData)) {
                $availableProfiles = $profilesData;
            }
        }

        echo json_encode([
            'result' => 'success',
            'envPath' => $envPath,
            'iconUrl' => $iconUrl,
            'webuiUrl' => $webuiUrl,
            'defaultProfile' => $defaultProfile,
            'externalComposePath' => $externalComposePath,
            'invalidIndirectPath' => $invalidIndirectPath,
            'availableProfiles' => $availableProfiles
        ]);
        break;
    case 'detectWebui':
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $detected = detectWebuiUrl($compose_root, $script);
        echo json_encode(['result' => 'success', 'detected' => $detected]);
        break;
    case 'setStackSettings':
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        // --- Validate all inputs first, before writing anything ---

        $iconUrl = isset($_POST['iconUrl']) ? trim($_POST['iconUrl']) : "";
        if (!empty($iconUrl)) {
            $isUrl = filter_var($iconUrl, FILTER_VALIDATE_URL) && (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0);
            $isDataImageUrl = preg_match('/^data:image\/[a-z0-9.+-]+(?:;[a-z0-9.+-]+=[^;,]+)*(?:;base64)?,.+$/i', $iconUrl) === 1;
            $isLocalPath = strpos($iconUrl, '/') === 0
                && strpos($iconUrl, '..') === false
                && (strpos($iconUrl, '/mnt/') === 0 || strpos($iconUrl, '/boot/config/plugins/compose.manager/projects/') === 0);
            if (!$isUrl && !$isDataImageUrl && !$isLocalPath) {
                echo json_encode(['result' => 'error', 'message' => 'Invalid icon. Must be http(s) URL, data:image URL, or a local path under /mnt/ or /boot/config/plugins/compose.manager/projects/.']);
                break;
            }
        }

        $webuiUrl = isset($_POST['webuiUrl']) ? trim($_POST['webuiUrl']) : "";
        if (!empty($webuiUrl)) {
            if (!isValidWebuiUrl($webuiUrl)) {
                echo json_encode(['result' => 'error', 'message' => 'Invalid WebUI URL. Must be http:// or https:// (supports [IP] and [PORT:xxxx] placeholders).']);
                break;
            }
            if (preg_match('/\[PORT\]/i', $webuiUrl)) {
                echo json_encode(['result' => 'error', 'message' => 'Bare [PORT] is not supported at stack level. Use [PORT:xxxx] with a default port (e.g. [PORT:8080]).']);
                break;
            }
        }

        $envPath = isset($_POST['envPath']) ? trim($_POST['envPath']) : "";
        $defaultProfile = isset($_POST['defaultProfile']) ? trim($_POST['defaultProfile']) : "";

        $externalComposePath = isset($_POST['externalComposePath']) ? trim($_POST['externalComposePath']) : "";
        $externalComposePath = rtrim($externalComposePath, '/');
        if (!empty($externalComposePath)) {
            $realPath = realpath($externalComposePath) ?: $externalComposePath;
            if (strpos($realPath, '/mnt/') !== 0 && strpos($realPath, '/boot/config/') !== 0) {
                echo json_encode(['result' => 'error', 'message' => 'External compose path must be under /mnt/ or /boot/config/.']);
                break;
            }
            if (!is_dir($externalComposePath)) {
                echo json_encode(['result' => 'error', 'message' => 'External compose path directory does not exist: ' . $externalComposePath]);
                break;
            }
        }

        // --- All validation passed, now write everything ---

        // Set env path
        $envPathFile = "$compose_root/$script/envpath";
        if (empty($envPath)) {
            if (is_file($envPathFile))
                @unlink($envPathFile);
        } else {
            file_put_contents($envPathFile, $envPath);
        }

        // Set icon URL
        $iconUrlFile = "$compose_root/$script/icon_url";
        if (empty($iconUrl)) {
            if (is_file($iconUrlFile))
                @unlink($iconUrlFile);
        } else {
            file_put_contents($iconUrlFile, $iconUrl);
        }

        // Set WebUI URL
        $webuiUrlFile = "$compose_root/$script/webui_url";
        if (empty($webuiUrl)) {
            if (is_file($webuiUrlFile))
                @unlink($webuiUrlFile);
        } else {
            file_put_contents($webuiUrlFile, $webuiUrl);
        }

        // Set default profile
        $defaultProfileFile = "$compose_root/$script/default_profile";
        if (empty($defaultProfile)) {
            if (is_file($defaultProfileFile))
                @unlink($defaultProfileFile);
        } else {
            file_put_contents($defaultProfileFile, $defaultProfile);
        }

        // Set external compose path (indirect)
        $indirectFile = "$compose_root/$script/indirect";
        $invalidIndirectFile = "$compose_root/$script/indirect.invalid";
        if (empty($externalComposePath)) {
            // Removing indirect: move compose file back to project folder if it only exists externally
            if (is_file($indirectFile)) {
                $oldIndirectPath = trim(file_get_contents($indirectFile));
                $localCompose = findComposeFile("$compose_root/$script");
                $externalCompose = findComposeFile($oldIndirectPath);
                if (!$localCompose && $externalCompose) {
                    copy($externalCompose, "$compose_root/$script/" . basename($externalCompose));
                }
                @unlink($indirectFile);
            }
            // Also clean up any invalid indirect file when clearing the path
            if (is_file($invalidIndirectFile)) {
                @unlink($invalidIndirectFile);
            }
        } else {
            file_put_contents($indirectFile, $externalComposePath);
            // Clean up the invalid file now that we have a corrected path
            if (is_file($invalidIndirectFile)) {
                @unlink($invalidIndirectFile);
            }
            // Remove local compose file if it exists since we're now using external
            $localCompose = findComposeFile("$compose_root/$script");
            if ($localCompose) {
                @unlink($localCompose);
            }
        }

        echo json_encode(['result' => 'success', 'message' => 'Settings saved']);
        break;
    case 'saveProfiles':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $basePath = "$compose_root/$script";
        $fileName = "$basePath/profiles";

        if ($scriptContents == "[]") {
            if (is_file($fileName)) {
                @unlink($fileName);
            }
            echo json_encode(['result' => 'success', 'message' => '']);
            break;
        }

        file_put_contents("$fileName", $scriptContents);
        echo json_encode(['result' => 'success', 'message' => "$fileName saved"]);
        break;
    case 'getStackContainers':
        composeLogger('getStackContainers start', [
            'script' => $script,
            'post' => $_POST,
            'caller' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ], 'user', 'debug', 'getStackContainers');
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        // Resolve stack identity and compose CLI arguments via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $script);
        // Get container details in JSON format (all states)
        $rows = $stackInfo->getContainerList();
        // Hard dependency on Docker manager: use shared helpers directly.
        $networkDrivers = DockerUtil::driver();
        $hostIP = trim((string) DockerUtil::host());

        $containers = [];
        // Load update status once before the loop (static data, doesn't change per-container)
        $updateStatusFile = UNRAID_UPDATE_STATUS_FILE;
        $updateStatusData = [];
        if (is_file($updateStatusFile)) {
            $updateStatusData = json_decode(file_get_contents($updateStatusFile), true) ?: [];
        }

        // Get stack state via centralized StackInfo method
        $stackState = $stackInfo->getStackState();

        foreach ($rows as $rawContainer) {
            composeLogger('getStackContainers found container row', [
                'rawContainer' => $rawContainer
            ], 'user', 'debug', 'getStackContainers');
            // Get additional details using docker inspect
            $ctName = $rawContainer['Name'] ?? '';
            if ($ctName) {
                $inspectCmd = "docker inspect " . escapeshellarg($ctName) . " --format '{{json .}}' 2>/dev/null";
                $inspectOutput = shell_exec($inspectCmd);
                if ($inspectOutput) {
                    $inspect = json_decode($inspectOutput, true);
                    if ($inspect) {
                        // Extract useful info from inspect
                        $rawContainer['ID'] = $inspect['Id'] ?? '';
                        $rawContainer['Image'] = $inspect['Config']['Image'] ?? '';
                        $rawContainer['Created'] = $inspect['Created'] ?? '';
                        $rawContainer['StartedAt'] = $inspect['State']['StartedAt'] ?? '';

                        // Get ports (raw bindings - IP resolved below after network detection)
                        $ports = [];
                        $portBindings = $inspect['HostConfig']['PortBindings'] ?? [];
                        foreach ($portBindings as $containerPort => $bindings) {
                            if ($bindings) {
                                foreach ($bindings as $binding) {
                                    $hostPort = $binding['HostPort'] ?? '';
                                    if ($hostPort) {
                                        $ports[] = ['hostPort' => $hostPort, 'containerPort' => $containerPort];
                                    }
                                }
                            }
                        }

                        // Get volumes
                        $volumes = [];
                        $mounts = $inspect['Mounts'] ?? [];
                        foreach ($mounts as $mount) {
                            $src = $mount['Source'] ?? '';
                            $dst = $mount['Destination'] ?? '';
                            $type = $mount['Type'] ?? 'bind';
                            if ($src && $dst) {
                                $volumes[] = ['source' => $src, 'destination' => $dst, 'type' => $type];
                            }
                        }
                        $rawContainer['Volumes'] = $volumes;

                        // Get network info (include driver for IP resolution)
                        $networks = [];
                        $networkSettings = $inspect['NetworkSettings']['Networks'] ?? [];
                        foreach ($networkSettings as $netName => $netConfig) {
                            $networks[] = [
                                'name' => $netName,
                                'ip' => $netConfig['IPAddress'] ?? '',
                                'driver' => $networkDrivers[$netName] ?? ''
                            ];
                        }
                        $rawContainer['Networks'] = $networks;

                        // Get labels for WebUI
                        $labels = $inspect['Config']['Labels'] ?? [];
                        $webUITemplate = $labels[$docker_label_webui] ?? '';
                        $rawContainer['Icon'] = $labels[$docker_label_icon] ?? '';
                        $rawContainer['Shell'] = $labels[$docker_label_shell] ?? '/bin/bash';

                        // Resolve WebUI URL server-side (matching Unraid's DockerClient logic)
                        $networkMode = $inspect['HostConfig']['NetworkMode'] ?? 'bridge';
                        if (strpos($networkMode, ':') !== false) {
                            [$networkMode] = explode(':', $networkMode);
                        }

                        $rawContainer['WebUI'] = '';
                        $resolvedIP = $hostIP;
                        if ($networkMode === 'host') {
                            $resolvedIP = $hostIP;
                        } elseif (
                            isset($networkDrivers[$networkMode]) &&
                            in_array($networkDrivers[$networkMode], ['macvlan', 'ipvlan'])
                        ) {
                            $modeNetwork = $networkSettings[$networkMode] ?? null;
                            $containerIP = $modeNetwork['IPAddress'] ?? '';
                            if (!$containerIP && !empty($networkSettings)) {
                                $firstNet = reset($networkSettings);
                                $containerIP = $firstNet['IPAddress'] ?? '';
                            }
                            if ($containerIP) {
                                $resolvedIP = $containerIP;
                            }
                        }

                        $portStrings = [];
                        foreach ($ports as $p) {
                            $lanIp = $resolvedIP ?: $hostIP;
                            $portStrings[] = "$lanIp:{$p['hostPort']}->{$p['containerPort']}";
                        }
                        $rawContainer['Ports'] = $portStrings;

                        $webUiIp = $resolvedIP ?: $hostIP;
                        if (!empty($webUITemplate) && $webUiIp) {
                            $resolvedURL = preg_replace('%\[IP\]%i', $webUiIp, $webUITemplate);
                            if (preg_match('%\[PORT:(\d+)\]%i', $resolvedURL, $portMatch)) {
                                $configPort = $portMatch[1];
                                foreach ($portBindings as $ctPort => $bindings) {
                                    $ctPortNum = preg_replace('/\/.*$/', '', $ctPort);
                                    if ($ctPortNum === $configPort && $bindings) {
                                        $hostPort = $bindings[0]['HostPort'] ?? '';
                                        if ($hostPort) {
                                            $configPort = $hostPort;
                                        }
                                        break;
                                    }
                                }
                                $resolvedURL = preg_replace('%\[PORT:\d+\]%i', $configPort, $resolvedURL);
                            }
                            $rawContainer['WebUI'] = $resolvedURL;
                        }

                        // Get update status from saved status file (read once before loop)
                        $imageName = $rawContainer['Image'];
                        if (strpos($imageName, ':') === false) {
                            $imageName .= ':latest';
                        }
                        $imageNameShort = preg_replace('/^[^\/]+\//', '', $imageName);

                        $rawContainer['updateStatus'] = 'unknown';
                        $rawContainer['localSha'] = '';
                        $rawContainer['remoteSha'] = '';

                        $checkNames = [$imageName, $imageNameShort];
                        foreach ($updateStatusData as $key => $status) {
                            foreach ($checkNames as $checkName) {
                                if ($key === $checkName || strpos($key, $checkName) !== false || strpos($checkName, $key) !== false) {
                                    $localRaw = $status['local'] ?? '';
                                    $remoteRaw = $status['remote'] ?? '';
                                    $rawContainer['localSha'] = substr(str_replace('sha256:', '', $localRaw), 0, 8);
                                    $rawContainer['remoteSha'] = substr(str_replace('sha256:', '', $remoteRaw), 0, 8);
                                    if (!empty($status['local']) && !empty($status['remote'])) {
                                        $rawContainer['updateStatus'] = ($status['local'] === $status['remote']) ? 'up-to-date' : 'update-available';
                                    }
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
            // Normalize through ContainerInfo for consistent camelCase output
            $containers[] = ContainerInfo::fromDockerInspect($rawContainer)->toArray();
        }

        // --- Persistent container metadata cache ---
        $cacheFile = '/boot/config/plugins/compose.manager/containers.cache.json';
        $cache = is_file($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
        $stackKey = $stackInfo->projectFolder;
        if (!isset($cache[$stackKey])) $cache[$stackKey] = [];
        foreach ($containers as $ct) {
            $service = $ct['service'] ?? $ct['Name'] ?? '';
            if ($service) {
                $cache[$stackKey][$service] = $ct;
            }
        }
        file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        echo json_encode(['result' => 'success', 'containers' => $containers, 'stackState' => $stackState, 'projectName' => $stackInfo->projectFolder, 'startedAt' => $stackInfo->getStartedAt()]);
        composeLogger('getStackContainers done', [
            'script' => $script,
            'containersCount' => count($containers),
            'containerNames' => array_map(function ($c) {
                return $c['name'] ?? ($c['Name'] ?? '');
            }, $containers),
            'stackState' => $stackState,
            'projectName' => $stackInfo->projectFolder,
        ], 'user', 'debug', 'getStackContainers');
        break;
    case 'getProfileServices':
        // Returns the list of services that docker compose would act on for the
        // given profile selection.  Uses `docker compose config --services` with
        // --profile flags so compose is the authoritative parser/interpreter.
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }
        $profiles = isset($_POST['profiles']) ? trim($_POST['profiles']) : '';

        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $args = $stackInfo->buildComposeArgs();

        $cmd = "docker compose {$args['files']} {$args['envFile']} -p " . escapeshellarg($args['projectName']);
        if ($profiles !== '') {
            foreach (array_filter(array_map('trim', explode(',', $profiles))) as $p) {
                $cmd .= " --profile " . escapeshellarg($p);
            }
        }
        $cmd .= " config --services 2>/dev/null";

        $output = shell_exec($cmd);
        $services = [];
        if (is_string($output) && trim($output) !== '') {
            $services = array_values(array_filter(array_map('trim', explode("\n", trim($output))), fn($s) => $s !== ''));
        }

        echo json_encode(['result' => 'success', 'services' => $services]);
        break;
    case 'containerAction':
        $containerName = isset($_POST['container']) ? trim($_POST['container']) : "";
        $containerAction = isset($_POST['containerAction']) ? trim($_POST['containerAction']) : "";

        if (!$containerName || !$containerAction) {
            echo json_encode(['result' => 'error', 'message' => 'Container or action not specified.']);
            break;
        }

        $allowedActions = ['start', 'stop', 'restart', 'pause', 'unpause'];
        if (!in_array($containerAction, $allowedActions)) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid action.']);
            break;
        }

        $cmd = "docker " . escapeshellarg($containerAction) . " " . escapeshellarg($containerName) . " 2>&1";
        $output = shell_exec($cmd);

        echo json_encode(['result' => 'success', 'message' => trim($output)]);
        break;
    case 'checkStackUpdates':
        // Check for updates for all containers in a compose stack
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        // Include Docker manager classes for update checking
        require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

        // Resolve stack identity and compose CLI arguments via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $projectName = $stackInfo->projectFolder;

        // Get containers (all states) for update checking
        $rows = $stackInfo->getContainerList();

        $updateResults = [];
        $DockerUpdate = new DockerUpdate();

        // Load the update status file to get SHA values
        $dockerManPaths = [
            'update-status' => UNRAID_UPDATE_STATUS_FILE
        ];

        if ($rows) {
            // Load the update status data ONCE before the loop instead of per-container
            $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
            $statusDirty = false;

            // First pass: clear cached local SHAs for all images that need checking
            foreach ($rows as $container) {
                $image = $container['Image'] ?? '';
                if ($image) {
                    $image = ContainerInfo::normalizeImageForUpdateCheck($image);
                    if (isset($updateStatusData[$image])) {
                        $updateStatusData[$image]['local'] = null;
                        $statusDirty = true;
                    }
                }
            }

            // Save once after clearing all cached SHAs
            if ($statusDirty) {
                DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatusData);
            }

            // Second pass: check updates and collect results
            foreach ($rows as $container) {
                $containerName = $container['Name'] ?? '';
                $image = $container['Image'] ?? '';

                if ($containerName && $image) {
                    // Normalize image name (strip docker.io/ prefix, @sha256: digest, add library/ for official images)
                    $image = ContainerInfo::normalizeImageForUpdateCheck($image);

                    // Check update status using Unraid's DockerUpdate class
                    $DockerUpdate->reloadUpdateStatus($image);
                    $updateStatus = $DockerUpdate->getUpdateStatus($image);

                    // Re-read status data (may have been updated by reloadUpdateStatus)
                    $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                    $localSha = '';
                    $remoteSha = '';

                    if (isset($updateStatusData[$image])) {
                        $localSha = $updateStatusData[$image]['local'] ?? '';
                        $remoteSha = $updateStatusData[$image]['remote'] ?? '';
                        // Shorten SHA for display (first 12 chars after sha256:)
                        if ($localSha && strpos($localSha, 'sha256:') === 0) {
                            $localSha = substr($localSha, 7, 12);
                        }
                        if ($remoteSha && strpos($remoteSha, 'sha256:') === 0) {
                            $remoteSha = substr($remoteSha, 7, 12);
                        }
                    }

                    // null = unknown, true = up to date, false = update available
                    $hasUpdate = ($updateStatus === false);
                    $statusText = ($updateStatus === null) ? 'unknown' : ($updateStatus ? 'up-to-date' : 'update-available');

                    $updateResults[] = ContainerInfo::fromUpdateResponse([
                        'container' => $containerName,
                        'image' => $image,
                        'hasUpdate' => $hasUpdate,
                        'status' => $statusText,
                        'localSha' => $localSha,
                        'remoteSha' => $remoteSha
                    ])->toUpdateArray();
                }
            }
        }

        echo json_encode(['result' => 'success', 'updates' => $updateResults, 'projectName' => $projectName]);

        // Save the update status for this stack
        $composeUpdateStatusFile = COMPOSE_UPDATE_STATUS_FILE;
        $savedStatus = [];
        if (is_file($composeUpdateStatusFile)) {
            $savedStatus = json_decode(file_get_contents($composeUpdateStatusFile), true) ?: [];
        }
        $savedStatus[$script] = [
            'projectName' => $projectName,
            'hasUpdate' => count(array_filter($updateResults, function ($r) {
                return $r['hasUpdate'];
            })) > 0,
            'containers' => $updateResults,
            'lastChecked' => time()
        ];
        file_put_contents($composeUpdateStatusFile, json_encode($savedStatus, JSON_PRETTY_PRINT));
        break;
    case 'checkAllStacksUpdates':
        // Check for updates for all compose stacks
        require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

        composeLogger('Starting update check for all stacks', null, 'user', 'debug', 'update-check');

        $allUpdates = [];
        $DockerUpdate = new DockerUpdate();

        // Path to update status file
        $dockerManPaths = [
            'update-status' => UNRAID_UPDATE_STATUS_FILE
        ];

        foreach (StackInfo::allFromRoot($compose_root) as $stackInfoItem) {
            $stackName = $stackInfoItem->projectFolder;
            $projectName = $stackInfoItem->projectFolder;

            $rows = $stackInfoItem->getContainerList();

            $stackUpdates = [];
            $hasStackUpdate = false;

            if ($rows) {
                // Load once, batch-clear local SHAs, save once (avoid per-container I/O)
                $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                $statusDirty = false;

                // First pass: collect running images and clear cached local SHAs
                foreach ($rows as $container) {
                    $state = $container['State'] ?? '';
                    if ($state === 'running') {
                        $image = $container['Image'] ?? '';
                        if ($image) {
                            $image = ContainerInfo::normalizeImageForUpdateCheck($image);
                            if (isset($updateStatusData[$image])) {
                                $updateStatusData[$image]['local'] = null;
                                $statusDirty = true;
                            }
                        }
                    }
                }

                // Save once after clearing all cached SHAs
                if ($statusDirty) {
                    DockerUtil::saveJSON($dockerManPaths['update-status'], $updateStatusData);
                }

                // Second pass: check updates for running containers
                foreach ($rows as $container) {
                    $containerLower = array_change_key_case($container, CASE_LOWER);
                    $containerName = trim($containerLower['name'] ?? $containerLower['names'] ?? '');
                    $image = trim($containerLower['image'] ?? '');
                    $state = strtolower(trim($containerLower['state'] ?? ''));

                    // Only check updates for running containers
                    if ($containerName && $image && $state === 'running') {
                        $image = ContainerInfo::normalizeImageForUpdateCheck($image);

                        $DockerUpdate->reloadUpdateStatus($image);
                        $updateStatus = $DockerUpdate->getUpdateStatus($image);

                        // Re-read status data (may have been updated by reloadUpdateStatus)
                        $updateStatusData = DockerUtil::loadJSON($dockerManPaths['update-status']);
                        $localSha = '';
                        $remoteSha = '';

                        if (isset($updateStatusData[$image])) {
                            $localSha = $updateStatusData[$image]['local'] ?? '';
                            $remoteSha = $updateStatusData[$image]['remote'] ?? '';
                            // Shorten SHA for display (first 12 chars after sha256:)
                            if ($localSha && strpos($localSha, 'sha256:') === 0) {
                                $localSha = substr($localSha, 7, 12);
                            }
                            if ($remoteSha && strpos($remoteSha, 'sha256:') === 0) {
                                $remoteSha = substr($remoteSha, 7, 12);
                            }
                        }

                        $hasUpdate = ($updateStatus === false);
                        if ($hasUpdate)
                            $hasStackUpdate = true;

                        $stackUpdates[] = ContainerInfo::fromUpdateResponse([
                            'container' => $containerName,
                            'image' => $image,
                            'hasUpdate' => $hasUpdate,
                            'status' => ($updateStatus === null) ? 'unknown' : ($updateStatus ? 'up-to-date' : 'update-available'),
                            'localSha' => $localSha,
                            'remoteSha' => $remoteSha
                        ])->toUpdateArray();
                    }
                }
            }

            $allUpdates[$stackName] = [
                'projectName' => $projectName,
                'hasUpdate' => $hasStackUpdate,
                'containers' => $stackUpdates
            ];
        }

        // Save the update status for all stacks
        $savedStatus = $allUpdates;
        foreach ($savedStatus as $stackKey => &$stackData) {
            $stackData['lastChecked'] = time();
        }
        file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode($savedStatus, JSON_PRETTY_PRINT));

        $totalStacks = count($allUpdates);
        $updatesFound = 0;
        foreach ($allUpdates as $sn => $si) {
            if ($si['hasUpdate'])
                $updatesFound++;
        }
        composeLogger("Completed: $totalStacks stacks checked, $updatesFound with updates", null, 'user', 'debug', 'update-check');

        echo json_encode(['result' => 'success', 'stacks' => $allUpdates]);
        break;
    case 'getSavedUpdateStatus':
        // Load saved update status from file
        $composeUpdateStatusFile = COMPOSE_UPDATE_STATUS_FILE;
        if (is_file($composeUpdateStatusFile)) {
            $savedStatus = json_decode(file_get_contents($composeUpdateStatusFile), true);
            if ($savedStatus) {
                echo json_encode(['result' => 'success', 'stacks' => $savedStatus]);
            } else {
                echo json_encode(['result' => 'success', 'stacks' => []]);
            }
        } else {
            echo json_encode(['result' => 'success', 'stacks' => []]);
        }
        break;
    case 'getLogs':
        // Get compose-related log entries from syslog
        $lines = isset($_POST['lines']) ? intval($_POST['lines']) : 100;
        $filter = isset($_POST['filter']) ? trim($_POST['filter']) : '';

        // Sanitize inputs
        $lines = max(10, min(5000, $lines)); // Limit between 10 and 5000 lines

        // Build grep command to find compose-related entries
        // Look for: compose, docker compose, compose.manager entries
        $grepPattern = 'compose\\|docker compose\\|compose.manager\\|compose.sh';

        // Read from syslog
        $syslogFile = '/var/log/syslog';
        if (!is_file($syslogFile)) {
            $syslogFile = '/var/log/messages';
        }

        if (!is_file($syslogFile)) {
            echo json_encode(['result' => 'error', 'message' => 'Syslog file not found']);
            break;
        }

        // Use grep to find relevant entries and tail to limit output
        $cmd = "grep -i " . escapeshellarg($grepPattern) . " " . escapeshellarg($syslogFile);

        // Apply additional filter if provided
        if (!empty($filter)) {
            $cmd .= " | grep -i " . escapeshellarg($filter);
        }

        $cmd .= " | tail -n " . escapeshellarg($lines);

        $output = [];
        exec($cmd, $output, $returnCode);

        // Parse log entries
        $logs = [];
        foreach ($output as $line) {
            // Parse syslog format: "Mon DD HH:MM:SS hostname source[pid]: message"
            // or: "YYYY-MM-DD HH:MM:SS hostname source[pid]: message"
            if (preg_match('/^(\w+\s+\d+\s+\d+:\d+:\d+|\d{4}-\d{2}-\d{2}\s+\d+:\d+:\d+)\s+(\S+)\s+([^:]+):\s*(.*)$/', $line, $matches)) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'hostname' => $matches[2],
                    'source' => trim($matches[3]),
                    'message' => $matches[4]
                ];
            } else {
                // Fallback for lines that don't match expected format
                $logs[] = [
                    'timestamp' => '',
                    'hostname' => '',
                    'source' => 'unknown',
                    'message' => $line
                ];
            }
        }

        echo json_encode([
            'result' => 'success',
            'logs' => $logs,
            'count' => count($logs)
        ]);
        break;

    case 'getLastCmdLog':
        // Return the last command log file for a stack (background or foreground run)
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        $logFile = "$compose_root/$script/last_cmd.log";
        if (!is_file($logFile)) {
            echo json_encode(['result' => 'success', 'log' => null]);
            break;
        }

        $logContent = @file_get_contents($logFile);
        if ($logContent === false) {
            echo json_encode(['result' => 'error', 'message' => 'Could not read log file.']);
            break;
        }

        echo json_encode(['result' => 'success', 'log' => $logContent]);
        break;

    case 'checkStackLock':
        // Check if a stack is currently locked (operation in progress)
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        $lockInfo = isStackLocked($script);
        if ($lockInfo) {
            echo json_encode([
                'result' => 'success',
                'locked' => true,
                'info' => $lockInfo
            ]);
        } else {
            echo json_encode([
                'result' => 'success',
                'locked' => false
            ]);
        }
        break;

    case 'getStackResult':
        // Get the last operation result for a stack
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        $stackPath = "$compose_root/$script";
        $lastResult = getStackLastResult($stackPath);

        echo json_encode([
            'result' => 'success',
            'lastResult' => $lastResult
        ]);
        break;

    case 'markStackForRecheck':
        // Mark one or more stacks for recheck after update
        // This persists across page reloads so the recheck happens even if page refreshes
        $stacks = isset($_POST['stacks']) ? $_POST['stacks'] : "";
        if (is_string($stacks)) {
            $stacks = json_decode($stacks, true);
        }
        if (!is_array($stacks) || empty($stacks)) {
            echo json_encode(['result' => 'error', 'message' => 'No stacks specified.']);
            break;
        }

        $pendingRecheckFile = PENDING_RECHECK_FILE;
        $pending = [];
        if (is_file($pendingRecheckFile)) {
            $pending = json_decode(file_get_contents($pendingRecheckFile), true) ?: [];
        }

        // Add stacks to pending list with timestamp
        foreach ($stacks as $stackName) {
            $pending[$stackName] = time();
        }

        file_put_contents($pendingRecheckFile, json_encode($pending, JSON_PRETTY_PRINT));
        echo json_encode(['result' => 'success', 'pending' => array_keys($pending)]);
        break;

    case 'getPendingRecheckStacks':
        // Get list of stacks that need recheck
        $pendingRecheckFile = PENDING_RECHECK_FILE;
        $pending = [];
        if (is_file($pendingRecheckFile)) {
            $pending = json_decode(file_get_contents($pendingRecheckFile), true) ?: [];
        }
        echo json_encode(['result' => 'success', 'pending' => $pending]);
        break;

    case 'clearStackRecheck':
        // Clear recheck flag for one or more stacks
        $stacks = isset($_POST['stacks']) ? $_POST['stacks'] : "";
        if (is_string($stacks)) {
            $stacks = json_decode($stacks, true);
        }
        if (!is_array($stacks) || empty($stacks)) {
            echo json_encode(['result' => 'error', 'message' => 'No stacks specified.']);
            break;
        }

        $pendingRecheckFile = PENDING_RECHECK_FILE;
        $pending = [];
        if (is_file($pendingRecheckFile)) {
            $pending = json_decode(file_get_contents($pendingRecheckFile), true) ?: [];
        }

        // Remove stacks from pending list
        foreach ($stacks as $stackName) {
            unset($pending[$stackName]);
        }

        file_put_contents($pendingRecheckFile, json_encode($pending, JSON_PRETTY_PRINT));
        echo json_encode(['result' => 'success', 'remaining' => array_keys($pending)]);
        break;

    case 'createBackup':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php");
        composeLogger('Manual backup starting...', null, 'user', 'info', 'backup');
        $result = createBackup();
        if ($result['result'] === 'success') {
            composeLogger("Manual backup completed: " . $result['archive'] . " (" . $result['size'] . ", " . $result['stacks'] . " stacks)", null, 'user', 'info', 'backup');
        } else {
            composeLogger('Manual backup FAILED: ' . ($result['message'] ?? 'Unknown error'), null, 'user', 'error', 'backup');
        }
        echo json_encode($result);
        break;

    case 'listBackups':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php");
        $directory = isset($_POST['directory']) && $_POST['directory'] !== '' ? trim($_POST['directory']) : null;
        $archives = listBackupArchives($directory);
        echo json_encode(['result' => 'success', 'archives' => $archives]);
        break;

    case 'uploadBackup':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php");
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errMsg = 'No file uploaded.';
            if (isset($_FILES['file'])) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
                    UPLOAD_ERR_PARTIAL => 'File only partially uploaded.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp directory.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk.',
                ];
                $errMsg = $uploadErrors[$_FILES['file']['error']] ?? 'Upload error code ' . $_FILES['file']['error'];
            }
            echo json_encode(['result' => 'error', 'message' => $errMsg]);
            break;
        }
        $filename = basename($_FILES['file']['name']);
        if (!preg_match('/\.(tar\.gz|tgz)$/i', $filename)) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid file type. Only .tar.gz archives are accepted.']);
            break;
        }
        $dest = getBackupDestination();
        if (!is_dir($dest)) {
            if (!@mkdir($dest, 0755, true)) {
                composeLogger('Failed to create backup destination directory: ' . $dest, null, 'user', 'error', 'backup');
                echo json_encode(['result' => 'error', 'message' => 'Backup destination does not exist and could not be created: ' . $dest]);
                break;
            }
        }
        if (!is_writable($dest)) {
            composeLogger('Backup destination is not writable: ' . $dest, null, 'user', 'error', 'backup');
            echo json_encode(['result' => 'error', 'message' => 'Backup destination is not writable: ' . $dest]);
            break;
        }
        $targetPath = $dest . '/' . $filename;
        if (file_exists($targetPath)) {
            composeLogger('Archive already exists in backup destination: ' . $filename, null, 'user', 'error', 'backup');
            echo json_encode(['result' => 'error', 'message' => 'Archive "' . $filename . '" already exists in backup destination.']);
            break;
        }
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            echo json_encode(['result' => 'error', 'message' => 'Failed to save uploaded file.']);
            break;
        }
        composeLogger('Uploaded backup archive: ' . $filename, null, 'user', 'info', 'backup');
        echo json_encode(['result' => 'success', 'message' => 'Archive uploaded successfully.', 'archive' => $filename]);
        break;

    case 'readManifest':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php");
        $archive = isset($_POST['archive']) ? trim($_POST['archive']) : '';
        $directory = isset($_POST['directory']) && $_POST['directory'] !== '' ? trim($_POST['directory']) : null;
        if (empty($archive)) {
            echo json_encode(['result' => 'error', 'message' => 'No archive specified.']);
            break;
        }
        $archivePath = resolveArchivePath($archive, $directory);
        $result = readArchiveStacks($archivePath);
        echo json_encode($result);
        break;

    case 'restoreBackup':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php");
        $archive = isset($_POST['archive']) ? basename(trim($_POST['archive'])) : '';
        $stacks = isset($_POST['stacks']) ? $_POST['stacks'] : '';
        if (is_string($stacks)) {
            $stacks = json_decode($stacks, true);
        }
        if (empty($archive)) {
            echo json_encode(['result' => 'error', 'message' => 'No archive specified.']);
            break;
        }
        if (!is_array($stacks) || empty($stacks)) {
            echo json_encode(['result' => 'error', 'message' => 'No stacks selected for restore.']);
            break;
        }
        composeLogger('Restore starting from archive: ' . $archive . ' with ' . count($stacks) . ' stacks selected', null, 'user', 'info', 'restore');
        $archivePath = resolveArchivePath($archive);
        $result = restoreStacks($archivePath, $stacks);
        if ($result['result'] === 'error') {
            composeLogger('Restore FAILED: ' . ($result['message'] ?? 'Unknown error'), null, 'user', 'error', 'restore');
        } else {
            $restoredList = implode(', ', $result['restored'] ?? []);
            composeLogger('Restore completed: ' . count($result['restored']) . ' stacks restored (' . $restoredList . ')', null, 'user', 'info', 'restore');
            if (!empty($result['errors'])) {
                composeLogger('Restore errors: ' . implode(', ', $result['errors']), null, 'user', 'error', 'restore');
            }
        }
        echo json_encode($result);
        break;

    case 'deleteBackup':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php");
        $archive = isset($_POST['archive']) ? trim($_POST['archive']) : '';
        if (empty($archive)) {
            echo json_encode(['result' => 'error', 'message' => 'No archive specified.']);
            break;
        }
        $archivePath = resolveArchivePath($archive);
        if (!file_exists($archivePath)) {
            echo json_encode(['result' => 'error', 'message' => 'Archive not found.']);
            break;
        }
        @unlink($archivePath);
        composeLogger('Deleted backup archive: ' . $archive, null, 'user', 'info', 'backup');
        echo json_encode(['result' => 'success', 'message' => 'Backup deleted.']);
        break;

    case 'updateBackupCron':
        require_once("/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php");
        if (updateBackupCron()) {
            echo json_encode(['result' => 'success', 'message' => 'Backup schedule updated.']);
        } else {
            http_response_code(500);
            echo json_encode(['result' => 'error', 'message' => 'Failed to update backup schedule.']);
        }
        break;

    case 'saveBackupSettings':
        // Save backup settings to config file AND update cron
        $settings = $_POST['settings'] ?? '';
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        if (!is_array($settings)) {
            echo json_encode(['result' => 'error', 'message' => 'Invalid settings data.']);
            break;
        }

        // Whitelist allowed setting keys to prevent arbitrary config injection
        $allowedKeys = [
            'BACKUP_DESTINATION',
            'BACKUP_RETENTION',
            'BACKUP_SCHEDULE_ENABLED',
            'BACKUP_SCHEDULE_FREQUENCY',
            'BACKUP_SCHEDULE_TIME',
            'BACKUP_SCHEDULE_DAY'
        ];
        $settings = array_intersect_key($settings, array_flip($allowedKeys));

        // Validate numeric/enum fields
        if (isset($settings['BACKUP_RETENTION'])) {
            $settings['BACKUP_RETENTION'] = max(0, intval($settings['BACKUP_RETENTION']));
        }
        if (isset($settings['BACKUP_SCHEDULE_DAY'])) {
            $settings['BACKUP_SCHEDULE_DAY'] = max(0, min(6, intval($settings['BACKUP_SCHEDULE_DAY'])));
        }
        if (isset($settings['BACKUP_SCHEDULE_FREQUENCY']) && !in_array($settings['BACKUP_SCHEDULE_FREQUENCY'], ['daily', 'weekly'], true)) {
            $settings['BACKUP_SCHEDULE_FREQUENCY'] = 'daily';
        }
        if (isset($settings['BACKUP_SCHEDULE_ENABLED']) && !in_array($settings['BACKUP_SCHEDULE_ENABLED'], ['true', 'false'], true)) {
            $settings['BACKUP_SCHEDULE_ENABLED'] = 'false';
        }
        if (isset($settings['BACKUP_SCHEDULE_TIME']) && !preg_match('/^\d{1,2}:\d{2}$/', $settings['BACKUP_SCHEDULE_TIME'])) {
            $settings['BACKUP_SCHEDULE_TIME'] = '03:00';
        }

        // Write settings to config file
        $cfgFile = '/boot/config/plugins/compose.manager/compose.manager.cfg';
        $existingCfg = is_file($cfgFile) ? parse_ini_file($cfgFile) : [];
        $updatedCfg = array_merge($existingCfg, $settings);

        $lines = [];
        foreach ($updatedCfg as $key => $value) {
            // Sanitize value: strip newlines and quotes to prevent INI injection
            $value = str_replace(['"', "\n", "\r"], '', $value);
            $lines[] = "$key=\"$value\"";
        }
        file_put_contents($cfgFile, implode("\n", $lines) . "\n");

        // Update cron job and log the action
        require_once("/usr/local/emhttp/plugins/compose.manager/include/BackupFunctions.php");
        if (!updateBackupCron()) {
            composeLogger('Warning: failed to sync cron schedule', null, 'user', 'warning', 'backup');
        }

        // Log the scheduler status
        $enabled = ($settings['BACKUP_SCHEDULE_ENABLED'] ?? 'false') === 'true';
        if ($enabled) {
            $freq = $settings['BACKUP_SCHEDULE_FREQUENCY'] ?? 'daily';
            $time = $settings['BACKUP_SCHEDULE_TIME'] ?? '03:00';
            $day = '';
            if ($freq === 'weekly') {
                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $dayNum = intval($settings['BACKUP_SCHEDULE_DAY'] ?? '1');
                $day = ' on ' . ($days[$dayNum] ?? 'Monday');
            }
            // Convert to 12-hour AM/PM format
            $timeParts = explode(':', $time);
            $hour = intval($timeParts[0]);
            $minute = $timeParts[1];
            $ampm = $hour >= 12 ? 'PM' : 'AM';
            $hour12 = $hour % 12;
            if ($hour12 === 0)
                $hour12 = 12;
            $time12 = "{$hour12}:{$minute} {$ampm}";
            composeLogger("Scheduler ENABLED: {$freq}{$day} at {$time12}", null, 'user', 'info', 'backup');
        } else {
            composeLogger('Scheduler DISABLED', null, 'user', 'info', 'backup');
        }

        echo json_encode(['result' => 'success', 'message' => 'Backup settings saved.']);
        break;

    case 'listProjects':
        // Return list of compose projects for UI dropdowns/tables
        $out = [];
        foreach (StackInfo::allFromRoot($compose_root) as $stackInfo) {
            $id = preg_replace('/[^A-Za-z0-9_\-]/', '-', $stackInfo->projectFolder);
            $out[] = [
                'id' => $id,
                'project' => $stackInfo->projectFolder,
                'name' => $stackInfo->getName(),
                'path' => $stackInfo->composeSource
            ];
        }
        echo json_encode($out);
        break;
}
