<?php

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/ColumnLayout.php");
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

if (!function_exists('getExpectedClientPath')) {
    function getExpectedClientPath(): string
    {
        return isset($_POST['expectedPath']) ? trim((string) $_POST['expectedPath']) : '';
    }
}

if (!function_exists('rejectStaleClientPath')) {
    function rejectStaleClientPath(?string $actualPath, string $label): bool
    {
        $expectedPath = getExpectedClientPath();
        if ($expectedPath === '') {
            return false;
        }

        $actualPath = $actualPath !== null ? trim($actualPath) : '';
        if ($actualPath !== '' && Path::refersToSamePath($expectedPath, $actualPath)) {
            return false;
        }

        composeLogger(
            "Rejected stale {$label} write target",
            [
                'expectedPath' => $expectedPath,
                'actualPath' => $actualPath,
            ],
            'user',
            'warning',
            'editor'
        );

        echo json_encode([
            'result' => 'error',
            'errorCode' => 'stale_path',
            'message' => 'The target path changed while the editor was open. Reload the editor and try again.',
            'expectedPath' => $expectedPath,
            'actualPath' => $actualPath,
        ]);

        return true;
    }
}

if (!function_exists('resolveRequestedComposeFile')) {
    /**
     * Resolve an explicitly requested compose file (`file` POST param)
     * against the stack's editable compose files.
     *
     * @return string|false|null Matched path, false when the request is
     *                           invalid, or null when no file was requested.
     */
    function resolveRequestedComposeFile(StackInfo $stackInfo)
    {
        $requested = isset($_POST['file']) ? trim((string) $_POST['file']) : '';
        if ($requested === '') {
            return null;
        }
        foreach ($stackInfo->getEditableComposeFiles() as $candidate) {
            if (Path::refersToSamePath($candidate, $requested)) {
                return $candidate;
            }
        }
        return false;
    }
}

if (!function_exists('composeLoadPersistentContainerCache')) {
    function composeLoadPersistentContainerCache(): array
    {
        $cacheFile = '/boot/config/plugins/compose.manager/containers.cache.json';
        if (!is_file($cacheFile)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($cacheFile), true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('composeSavePersistentContainerCache')) {
    function composeSavePersistentContainerCache(array $cache): void
    {
        $cacheFile = '/boot/config/plugins/compose.manager/containers.cache.json';
        file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('composePurgeDeletedStackCaches')) {
    /**
     * Remove deleted stack entries from persistent cache files.
     *
     * @return array<string,bool> Per-cache purge result flags.
     */
    function composePurgeDeletedStackCaches(string $stackName): array
    {
        $purged = [
            'persistentContainerCache' => false,
            'savedUpdateStatus' => false,
            'pendingRecheck' => false,
        ];

        $persistentContainerCache = composeLoadPersistentContainerCache();
        if (isset($persistentContainerCache[$stackName])) {
            unset($persistentContainerCache[$stackName]);
            composeSavePersistentContainerCache($persistentContainerCache);
            $purged['persistentContainerCache'] = true;
        }

        if (defined('COMPOSE_UPDATE_STATUS_FILE') && is_file(COMPOSE_UPDATE_STATUS_FILE)) {
            $savedStatus = json_decode((string)file_get_contents(COMPOSE_UPDATE_STATUS_FILE), true);
            if (is_array($savedStatus) && isset($savedStatus[$stackName])) {
                unset($savedStatus[$stackName]);
                file_put_contents(COMPOSE_UPDATE_STATUS_FILE, json_encode($savedStatus, JSON_PRETTY_PRINT));
                $purged['savedUpdateStatus'] = true;
            }
        }

        if (defined('PENDING_RECHECK_FILE') && is_file(PENDING_RECHECK_FILE)) {
            $pending = json_decode((string)file_get_contents(PENDING_RECHECK_FILE), true);
            if (is_array($pending) && isset($pending[$stackName])) {
                unset($pending[$stackName]);
                file_put_contents(PENDING_RECHECK_FILE, json_encode($pending, JSON_PRETTY_PRINT));
                $purged['pendingRecheck'] = true;
            }
        }

        return $purged;
    }
}

if (!function_exists('composeResolveContainerIcon')) {
    /**
     * Resolve container icon using stack cache first, then docker inspect fallback.
     *
     * @param string $containerName
     * @param string $service
     * @param array<string,string> $iconByService
     * @param array<string,string> $iconByName
     * @param array<string,string> $inspectIconCache Per-request inspect memoization by container name
     */
    function composeResolveContainerIcon(string $containerName, string $service, array $iconByService, array $iconByName, array &$inspectIconCache): string
    {
        if ($service !== '' && isset($iconByService[$service]) && $iconByService[$service] !== '') {
            return $iconByService[$service];
        }

        if ($containerName !== '' && isset($iconByName[$containerName]) && $iconByName[$containerName] !== '') {
            return $iconByName[$containerName];
        }

        if ($containerName === '') {
            return '';
        }

        if (isset($inspectIconCache[$containerName])) {
            return $inspectIconCache[$containerName];
        }

        global $docker_label_icon;
        $labelTemplate = '{{ index .Config.Labels "' . $docker_label_icon . '" }}';
        $cmd = 'docker inspect ' . escapeshellarg($containerName) . ' --format ' . escapeshellarg($labelTemplate) . ' 2>/dev/null';
        $icon = trim((string) shell_exec($cmd));
        if ($icon === '<no value>') {
            $icon = '';
        }
        $inspectIconCache[$containerName] = $icon;
        return $icon;
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
    case 'getColumnVisibility':
        $visibility = compose_read_column_layout();
        composeLogger('Loaded column visibility layout', [
            'stackVisible' => count($visibility['stackOrder'] ?? []),
            'serviceVisible' => count($visibility['serviceOrder'] ?? []),
        ], 'user', 'debug', 'column-layout');
        echo json_encode(['result' => 'success', 'visibility' => $visibility]);
        break;
    case 'saveColumnVisibility':
        $prefFile = COMPOSE_COLUMN_PREF_FILE;
        $prefDir = dirname($prefFile);

        $raw = $_POST['visibility'] ?? '';
        $parsed = json_decode((string)$raw, true);
        if (!is_array($parsed)) {
            composeLogger('Rejected invalid column visibility payload', [
                'jsonError' => json_last_error_msg(),
                'payloadLength' => strlen((string)$raw),
            ], 'user', 'warning', 'column-layout');
            echo json_encode(['result' => 'error', 'message' => 'Invalid visibility payload.']);
            break;
        }

        $normalized = compose_normalize_column_visibility($parsed);

        if (!is_dir($prefDir)) {
            if (!@mkdir($prefDir, 0777, true) && !is_dir($prefDir)) {
                composeLogger('Failed to create column layout preference directory', [
                    'dir' => $prefDir,
                ], 'user', 'error', 'column-layout');
                echo json_encode(['result' => 'error', 'message' => 'Failed to persist column visibility.']);
                break;
            }
        }

        $tmp = $prefFile . '.tmp';
        $ok = @file_put_contents($tmp, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $renameOk = ($ok !== false) ? @rename($tmp, $prefFile) : false;
        if ($ok === false || !$renameOk) {
            composeLogger('Failed to persist column visibility', [
                'prefFile' => $prefFile,
                'tmpFile' => $tmp,
                'writeOk' => ($ok !== false),
                'renameOk' => $renameOk,
            ], 'user', 'error', 'column-layout');
            @unlink($tmp);
            echo json_encode(['result' => 'error', 'message' => 'Failed to persist column visibility.']);
            break;
        }

        composeLogger('Saved column visibility layout', [
            'stackVisible' => count($normalized['stackOrder'] ?? []),
            'serviceVisible' => count($normalized['serviceOrder'] ?? []),
            'prefFile' => $prefFile,
        ], 'user', 'debug', 'column-layout');
        echo json_encode(['result' => 'success', 'visibility' => $normalized]);
        break;
    case 'getPersistentContainerCache':
        $cacheFile = '/boot/config/plugins/compose.manager/containers.cache.json';
        $cache = is_file($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
        echo json_encode(['result' => 'success', 'cache' => is_array($cache) ? $cache : []]);
        break;
    case 'getComposeLoadSnapshot':
        $snapshotFile = '/tmp/compose_info_nchan.snapshot.json';
        $snapshot = is_file($snapshotFile) ? json_decode((string)file_get_contents($snapshotFile), true) : [];
        echo json_encode([
            'result' => 'success',
            'snapshot' => is_array($snapshot) ? $snapshot : [],
        ]);
        break;
    case 'addStack':
        // Validate optional indirect inputs (folder or specific compose file)
        $indirectDir = isset($_POST['stackPath']) ? trim($_POST['stackPath']) : '';
        $indirectFile = isset($_POST['stackFilePath']) ? trim($_POST['stackFilePath']) : '';
        if ($indirectDir !== '' && $indirectFile !== '') {
            echo json_encode(['result' => 'error', 'message' => 'Set either Indirect Path or Indirect Compose File, not both.']);
            break;
        }

        $indirect = '';
        if ($indirectDir !== '') {
            $realIndirect = realpath($indirectDir);
            if ($realIndirect === false) {
                composeLogger("Failed to create stack: Could not resolve indirect path: $indirectDir", null, 'user', 'error', 'stack');
                echo json_encode(['result' => 'error', 'message' => 'Stack path is invalid or does not exist.']);
                break;
            }
            if (!Path::isAllowedPath($realIndirect, ['/mnt', '/boot/config'])) {
                composeLogger("Failed to create stack: Invalid indirect path: $indirectDir", null, 'user', 'error', 'stack');
                echo json_encode(['result' => 'error', 'message' => 'Stack path must be under /mnt/ or /boot/config/.']);
                break;
            }
            if (!is_dir($realIndirect)) {
                composeLogger("Failed to create stack: Indirect stack path does not exist: $indirectDir", null, 'user', 'error', 'stack');
                echo json_encode(['result' => 'error', 'message' => 'Indirect stack path does not exist.']);
                break;
            }
            // Preserve the user-provided path (e.g. /mnt/user/...) instead of
            // persisting a resolved /mnt/diskX/... path from realpath().
            $indirect = rtrim($indirectDir, '/');
        } elseif ($indirectFile !== '') {
            $realFile = realpath($indirectFile);
            if ($realFile === false || !is_file($realFile)) {
                composeLogger("Failed to create stack: Indirect compose file does not exist: $indirectFile", null, 'user', 'error', 'stack');
                echo json_encode(['result' => 'error', 'message' => 'Indirect compose file does not exist.']);
                break;
            }
            if (!Path::isAllowedPath($realFile, ['/mnt', '/boot/config'])) {
                composeLogger("Failed to create stack: Invalid indirect compose file path: $indirectFile", null, 'user', 'error', 'stack');
                echo json_encode(['result' => 'error', 'message' => 'Indirect compose file must be under /mnt/ or /boot/config/.']);
                break;
            }
            if (preg_match('/\.ya?ml$/i', basename($realFile)) !== 1) {
                echo json_encode(['result' => 'error', 'message' => 'Indirect compose file must be a .yml or .yaml file.']);
                break;
            }
            // Preserve the originally-entered file path so /mnt/user is retained.
            $indirect = $indirectFile;
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

        // Apply creation-time options, falling back to global defaults.
        $cfg = @parse_plugin_cfg($sName);

        $useDefaultComposeFilesRaw = isset($_POST['useDefaultComposeFiles'])
            ? strtolower(trim((string) $_POST['useDefaultComposeFiles']))
            : strtolower(trim((string) ($cfg['NEW_STACK_USE_DEFAULT_COMPOSE_FILES'] ?? 'false')));
        $useDefaultComposeFiles = $useDefaultComposeFilesRaw === 'true';
        if ($indirectFile !== '') {
            $useDefaultComposeFiles = false;
        }

        $overrideManagementAutomaticRaw = isset($_POST['overrideManagementAutomatic'])
            ? strtolower(trim((string) $_POST['overrideManagementAutomatic']))
            : strtolower(trim((string) ($cfg['NEW_STACK_OVERRIDE_MANAGEMENT_AUTOMATIC'] ?? 'true')));
        $overrideManagementAutomatic = $overrideManagementAutomaticRaw !== 'false';

        $stackPath = $compose_root . '/' . $stack->projectFolder;

        $useDefaultComposeFilesFile = "$stackPath/use_default_compose_files";
        if ($useDefaultComposeFiles) {
            file_put_contents($useDefaultComposeFilesFile, 'true');
        } elseif (is_file($useDefaultComposeFilesFile)) {
            @unlink($useDefaultComposeFilesFile);
        }

        $labelsViewModeFile = "$stackPath/labels_view_mode";
        if ($overrideManagementAutomatic) {
            if (is_file($labelsViewModeFile)) {
                @unlink($labelsViewModeFile);
            }
        } else {
            file_put_contents($labelsViewModeFile, 'advanced');
        }

        $envPathInput = isset($_POST['envPath']) ? trim($_POST['envPath']) : '';
        $envPathFile = "$stackPath/envpath";
        if ($envPathInput !== '') {
            file_put_contents($envPathFile, $envPathInput);
        } elseif (is_file($envPathFile)) {
            @unlink($envPathFile);
        }

        composeLogger("Created stack: $stackName", null, 'user', 'info', 'stack');
        echo json_encode([
            'result' => 'success',
            'message' => '',
            'project' => $stack->projectFolder,
            'projectName' => $stack->getName(),
            'useDefaultComposeFiles' => $useDefaultComposeFiles,
            'overrideManagementAutomatic' => $overrideManagementAutomatic,
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

        $deleteError = '';
        $deleteMeta = [];
        $deleteSuccess = composeDeleteStackFolder($compose_root, $stackName, $deleteError, $deleteMeta);
        $folderStillExists = is_dir($folderName);
        if (!$deleteSuccess || $folderStillExists) {
            composeLogger("Stack folder delete failed", [
                'stackName' => $stackName,
                'folderName' => $folderName,
                'deleteError' => $deleteError,
                'deleteMeta' => $deleteMeta,
                'folderStillExists' => $folderStillExists,
                'filesRemain' => $filesRemain
            ], 'user', 'error', 'stack');
            $msg = "Failed to delete stack folder. " .
                ($deleteError !== '' ? "$deleteError " : "") .
                ($folderStillExists ? "Folder still exists after delete. " : "") .
                (isset($deleteMeta['failedPath']) ? "Path: " . $deleteMeta['failedPath'] . ". " : "");
            echo json_encode(['result' => 'error', 'message' => $msg]);
            break;
        }

        $cachePurgeMeta = composePurgeDeletedStackCaches($stackName);

        if ($filesRemain == "") {
            composeLogger("Deleted stack: $stackName", [
                'deleteMeta' => $deleteMeta,
                'cachePurgeMeta' => $cachePurgeMeta,
            ], 'user', 'info', 'stack');
            echo json_encode(['result' => 'success', 'message' => '', 'cachePurge' => $cachePurgeMeta]);
        } else {
            composeLogger("Deleted stack: $stackName (indirect, external files remain at $filesRemain)", [
                'deleteMeta' => $deleteMeta,
                'filesRemain' => $filesRemain,
                'cachePurgeMeta' => $cachePurgeMeta,
            ], 'user', 'warning', 'stack');
            echo json_encode(['result' => 'warning', 'message' => $filesRemain, 'cachePurge' => $cachePurgeMeta]);
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

        // Optional explicit file selection (e.g. additional compose files)
        $requestedFile = resolveRequestedComposeFile($stackInfo);
        if ($requestedFile === false) {
            echo json_encode(['result' => 'error', 'message' => 'Requested file is not part of this stack.']);
            break;
        }
        if ($requestedFile !== null) {
            $composeFilePath = $requestedFile;
        }

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

        $envExists = is_file($fileName);
        $scriptContents = $envExists ? file_get_contents($fileName) : "";
        $scriptContents = str_replace("\r", "", (string) $scriptContents);
        echo json_encode([
            'result' => 'success',
            'fileName' => $fileName,
            'content' => $scriptContents,
            'exists' => $envExists
        ]);
        break;
    case 'createEnvTemplate':
        $script = getPostScript();

        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $envPath = $stackInfo->getEnvFilePath() ?? ($stackInfo->composeSource . '/.env');

        if (rejectStaleClientPath($envPath, '.env template')) {
            break;
        }

        $envDir = dirname($envPath);
        if (!is_dir($envDir)) {
            echo json_encode(['result' => 'error', 'message' => 'Target directory does not exist.']);
            break;
        }

        if (is_dir($envPath)) {
            echo json_encode(['result' => 'error', 'message' => 'Target .env path is a directory.']);
            break;
        }

        if (!is_file($envPath)) {
            $written = @file_put_contents($envPath, OverrideInfo::buildEnvTemplateContent());
            if ($written === false) {
                echo json_encode(['result' => 'error', 'message' => 'Failed to create .env template.']);
                break;
            }
        }

        if (!is_file($envPath)) {
            echo json_encode(['result' => 'error', 'message' => 'Failed to create .env template.']);
            break;
        }

        $content = file_get_contents($envPath);
        $content = str_replace("\r", "", (string) $content);

        echo json_encode([
            'result' => 'success',
            'fileName' => $envPath,
            'content' => $content,
            'exists' => is_file($envPath)
        ]);
        break;
    case 'getOverride':
        $script = getPostScript();

        // Get Override file path - can read from indirect if present, else project
        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $overridePath = $stackInfo->getPreferredOverridePath();

        $scriptContents = is_file($overridePath) ? file_get_contents($overridePath) : "";
        $scriptContents = str_replace("\r", "", $scriptContents);
        if (!$scriptContents) {
            $scriptContents = "";
        }
        echo json_encode([
            'result' => 'success',
            'fileName' => $overridePath,
            'content' => $scriptContents,
            'exists' => is_file($overridePath)
        ]);
        break;
    case 'createOverrideTemplate':
        $script = getPostScript();

        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $overridePath = $stackInfo->getPreferredOverridePath();

        if (rejectStaleClientPath($overridePath, 'override template')) {
            break;
        }

        if ($overridePath === null) {
            echo json_encode(['result' => 'error', 'message' => 'Unable to resolve override path']);
            break;
        }

        $overrideDir = dirname($overridePath);
        if (!is_dir($overrideDir)) {
            echo json_encode(['result' => 'error', 'message' => 'Override target directory does not exist.']);
            break;
        }

        if (is_dir($overridePath)) {
            echo json_encode(['result' => 'error', 'message' => 'Override target path is a directory.']);
            break;
        }

        if (!is_file($overridePath)) {
            $written = @file_put_contents($overridePath, OverrideInfo::buildTemplateContent());
            if ($written === false) {
                echo json_encode(['result' => 'error', 'message' => 'Failed to create override template.']);
                break;
            }
        }

        if (!is_file($overridePath)) {
            echo json_encode(['result' => 'error', 'message' => 'Failed to create override template.']);
            break;
        }

        $content = file_get_contents($overridePath);
        $content = str_replace("\r", "", (string) $content);

        echo json_encode([
            'result' => 'success',
            'fileName' => $overridePath,
            'content' => $content,
            'exists' => is_file($overridePath)
        ]);
        break;
    case 'saveYml':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        // Resolve compose file path via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $script);
        $composeFilePath = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/' . COMPOSE_FILE_NAMES[0]);

        // Optional explicit file selection (e.g. additional compose files)
        $requestedFile = resolveRequestedComposeFile($stackInfo);
        if ($requestedFile === false) {
            echo json_encode(['result' => 'error', 'message' => 'Requested file is not part of this stack.']);
            break;
        }
        $isMainComposeFile = $requestedFile === null || Path::refersToSamePath($requestedFile, $composeFilePath);
        if ($requestedFile !== null) {
            $composeFilePath = $requestedFile;
        }

        if (rejectStaleClientPath($composeFilePath, 'compose file')) {
            break;
        }

        // Before saving, detect service renames and migrate override entries in the project override only.
        // Rename migration only applies to the main compose file.
        if ($isMainComposeFile && is_file($composeFilePath)) {
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

        if (rejectStaleClientPath($fileName, '.env file')) {
            break;
        }

        file_put_contents($fileName, $scriptContents);
        echo "$fileName saved";
        break;
    case 'saveOverride':
        $script = getPostScript();
        $scriptContents = isset($_POST['scriptContents']) ? $_POST['scriptContents'] : "";
        $isManaged = isset($_POST['managed']) && $_POST['managed'] === '1';

        $stackInfo = StackInfo::fromProject($compose_root, $script);
        // managed=1 (Automatic): plugin controls override, write to project dir only
        // managed=0 (Manual): user controls override, write to the preferred override target
        $overridePath = $isManaged
            ? $stackInfo->overrideInfo->getProjectOverridePath()
            : $stackInfo->getPreferredOverridePath();

        if (rejectStaleClientPath($overridePath, 'override file')) {
            break;
        }

        if ($overridePath === null) {
            echo json_encode(['result' => 'error', 'message' => 'Unable to resolve override path']);
            break;
        }

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

        try {
            $stackInfo = StackInfo::fromProject($compose_root, $script);
        } catch (\Throwable $e) {
            echo json_encode(['result' => 'error', 'message' => 'Unable to load stack settings.']);
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

        // Get additional compose files (one path per line)
        $extraComposeFilesFile = "$compose_root/$script/extra_compose_files";
        $extraComposeFiles = is_file($extraComposeFilesFile) ? trim(file_get_contents($extraComposeFilesFile)) : "";

        // Candidate compose files in the compose source folder for the
        // Additional Compose Files selector (*compose*.y(a)ml, excluding the
        // main compose file and override files)
        $composeFileCandidates = [];
        $mainComposeBase = $stackInfo->composeFilePath !== null ? basename($stackInfo->composeFilePath) : '';
        $candidateGlob = glob($stackInfo->composeSource . '/*.{yml,yaml}', GLOB_BRACE) ?: [];
        foreach ($candidateGlob as $candidatePath) {
            $candidateBase = basename($candidatePath);
            if (stripos($candidateBase, 'compose') === false) {
                continue;
            }
            if ($candidateBase === $mainComposeBase) {
                continue;
            }
            if (stripos($candidateBase, '.override.') !== false) {
                continue;
            }
            $composeFileCandidates[] = $candidateBase;
        }
        sort($composeFileCandidates);

        // Get labels tab view mode (per-stack)
        $labelsViewModeFile = "$compose_root/$script/labels_view_mode";
        $labelsViewMode = is_file($labelsViewModeFile) ? strtolower(trim(file_get_contents($labelsViewModeFile))) : 'basic';
        if ($labelsViewMode !== 'advanced') {
            $labelsViewMode = 'basic';
        }

        // Use Docker Compose default file discovery (no explicit -f)
        // Use the StackInfo method so the effective value (gated by manual overrides) is returned.
        $useDefaultComposeFiles = $stackInfo->useDefaultComposeFileDiscovery();

        // Get external compose path (indirect)
        $indirectFile = "$compose_root/$script/indirect";
        $invalidIndirectFile = "$compose_root/$script/indirect.invalid";
        $externalComposePath = "";
        $externalComposeFilePath = "";
        $invalidIndirectPath = "";
        if (is_file($indirectFile)) {
            $raw = trim(file_get_contents($indirectFile));
            if ($raw !== '') {
                if ($stackInfo->indirectMode === 'file') {
                    if (is_file($raw)) {
                        $externalComposeFilePath = $raw;
                    } else {
                        $invalidIndirectPath = $raw;
                    }
                } elseif ($stackInfo->indirectMode === 'folder') {
                    if (is_dir($raw)) {
                        $externalComposePath = $raw;
                    } else {
                        $invalidIndirectPath = $raw;
                    }
                } elseif (is_dir($raw)) {
                    $externalComposePath = $raw;
                } elseif (is_file($raw)) {
                    $externalComposeFilePath = $raw;
                } else {
                    // Path is invalid or unavailable — non-destructive handling
                    $invalidIndirectPath = $raw;
                }
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
            'extraComposeFiles' => $extraComposeFiles,
            'composeFileCandidates' => $composeFileCandidates,
            'editableComposeFiles' => $stackInfo->getEditableComposeFiles(),
            'labelsViewMode' => $labelsViewMode,
            'useDefaultComposeFiles' => $useDefaultComposeFiles,
            'indirectMode' => $stackInfo->indirectMode,
            'externalComposePath' => $externalComposePath,
            'externalComposeFilePath' => $externalComposeFilePath,
            'invalidIndirectPath' => $invalidIndirectPath,
            'availableProfiles' => $availableProfiles,
            'projectPath' => "$compose_root/$script",
            'projectOverridePath' => $stackInfo->overrideInfo->getProjectOverridePath(),
            'effectiveOverridePath' => $stackInfo->getPreferredOverridePath(),
        ]);
        break;
    case 'setLabelsViewMode':
        $script = getPostScript();
        if (!$script) {
            echo json_encode(['result' => 'error', 'message' => 'Stack not specified.']);
            break;
        }

        $labelsViewMode = isset($_POST['labelsViewMode']) ? strtolower(trim((string) $_POST['labelsViewMode'])) : 'basic';
        if ($labelsViewMode !== 'advanced' && $labelsViewMode !== 'basic') {
            echo json_encode(['result' => 'error', 'message' => 'Invalid labels view mode.']);
            break;
        }

        $labelsViewModeFile = "$compose_root/$script/labels_view_mode";
        if ($labelsViewMode === 'advanced') {
            file_put_contents($labelsViewModeFile, 'advanced');
        } else {
            if (is_file($labelsViewModeFile)) {
                @unlink($labelsViewModeFile);
            }
        }

        echo json_encode(['result' => 'success', 'labelsViewMode' => $labelsViewMode]);
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
        $useDefaultComposeFiles = isset($_POST['useDefaultComposeFiles'])
            && strtolower(trim((string) $_POST['useDefaultComposeFiles'])) === 'true';

        $externalComposePath = isset($_POST['externalComposePath']) ? trim($_POST['externalComposePath']) : "";
        $externalComposePath = rtrim($externalComposePath, '/');
        $externalComposeFilePath = isset($_POST['externalComposeFilePath']) ? trim($_POST['externalComposeFilePath']) : "";

        if (!empty($externalComposePath) && !empty($externalComposeFilePath)) {
            echo json_encode(['result' => 'error', 'message' => 'Set either External Compose Path or External Compose File, not both.']);
            break;
        }

        if (!empty($externalComposePath)) {
            $realPath = realpath($externalComposePath) ?: $externalComposePath;
            if (!Path::isAllowedPath($realPath, ['/mnt', '/boot/config'])) {
                echo json_encode(['result' => 'error', 'message' => 'External compose path must be under /mnt/ or /boot/config/.']);
                break;
            }
            if (!is_dir($externalComposePath)) {
                echo json_encode(['result' => 'error', 'message' => 'External compose path directory does not exist: ' . $externalComposePath]);
                break;
            }

            $projectPath = realpath("$compose_root/$script") ?: rtrim("$compose_root/$script", '/');
            if (Path::refersToSamePath($realPath, $projectPath)) {
                echo json_encode(['result' => 'error', 'message' => 'External compose path cannot be the stack project folder. Use a path under /mnt/ or /boot/config/ that is external to this stack.']);
                break;
            }
        }

        if (!empty($externalComposeFilePath)) {
            $realFile = realpath($externalComposeFilePath);
            if ($realFile === false || !is_file($realFile)) {
                echo json_encode(['result' => 'error', 'message' => 'External compose file does not exist: ' . $externalComposeFilePath]);
                break;
            }
            if (!Path::isAllowedPath($realFile, ['/mnt', '/boot/config'])) {
                echo json_encode(['result' => 'error', 'message' => 'External compose file must be under /mnt/ or /boot/config/.']);
                break;
            }
            if (preg_match('/\.ya?ml$/i', basename($realFile)) !== 1) {
                echo json_encode(['result' => 'error', 'message' => 'External compose file must be a .yml or .yaml file.']);
                break;
            }

            $projectPath = realpath("$compose_root/$script") ?: rtrim("$compose_root/$script", '/');
            if (Path::refersToSamePath(dirname($realFile), $projectPath)) {
                echo json_encode(['result' => 'error', 'message' => 'External compose file cannot be inside the stack project folder. Use a path under /mnt/ or /boot/config/ that is external to this stack.']);
                break;
            }
        }

        // Additional compose files (one path per line, absolute or relative to compose source).
        // Only processed when the client sends the field: a stale UI session
        // that predates this setting must not silently clear it.
        $extraComposeFilesProvided = isset($_POST['extraComposeFiles']);
        $extraComposeFiles = $extraComposeFilesProvided ? trim($_POST['extraComposeFiles']) : "";
        $extraComposeFilesNormalized = [];
        $extraComposeFilesError = '';
        foreach (preg_split('/\R/', $extraComposeFiles) as $extraLine) {
            $extraLine = trim($extraLine);
            if ($extraLine === '' || strpos($extraLine, '#') === 0) {
                continue;
            }
            if (preg_match('/\.ya?ml$/i', $extraLine) !== 1) {
                $extraComposeFilesError = 'Additional compose file must be a .yml or .yaml file: ' . $extraLine;
                break;
            }
            if (Path::isAbsolutePath($extraLine)) {
                $realExtra = realpath($extraLine);
                if ($realExtra === false || !is_file($realExtra)) {
                    $extraComposeFilesError = 'Additional compose file does not exist: ' . $extraLine;
                    break;
                }
                if (!Path::isAllowedPath($realExtra, ['/mnt', '/boot/config'])) {
                    $extraComposeFilesError = 'Additional compose files must be under /mnt/ or /boot/config/.';
                    break;
                }
            } elseif (strpos($extraLine, '..') !== false) {
                $extraComposeFilesError = 'Relative additional compose file paths must not contain "..": ' . $extraLine;
                break;
            }
            $extraComposeFilesNormalized[] = $extraLine;
        }
        if ($extraComposeFilesError !== '') {
            echo json_encode(['result' => 'error', 'message' => $extraComposeFilesError]);
            break;
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

        // Set additional compose files (skipped entirely when the field was not sent)
        if ($extraComposeFilesProvided) {
            $extraComposeFilesFile = "$compose_root/$script/extra_compose_files";
            if (empty($extraComposeFilesNormalized)) {
                if (is_file($extraComposeFilesFile))
                    @unlink($extraComposeFilesFile);
            } else {
                file_put_contents($extraComposeFilesFile, implode("\n", $extraComposeFilesNormalized) . "\n");
            }
        }

        // Set compose file discovery mode
        $useDefaultComposeFilesFile = "$compose_root/$script/use_default_compose_files";
        if ($useDefaultComposeFiles) {
            file_put_contents($useDefaultComposeFilesFile, 'true');
        } else {
            if (is_file($useDefaultComposeFilesFile)) {
                @unlink($useDefaultComposeFilesFile);
            }
        }

        // Set external compose path (indirect)
        $indirectFile = "$compose_root/$script/indirect";
        $invalidIndirectFile = "$compose_root/$script/indirect.invalid";
        $indirectModeFile = "$compose_root/$script/indirect_mode";
        $indirectTarget = '';
        if (!empty($externalComposeFilePath)) {
            $indirectTarget = $externalComposeFilePath;
        } elseif (!empty($externalComposePath)) {
            $indirectTarget = $externalComposePath;
        }

        if ($indirectTarget === '') {
            // Removing indirect: move compose file back to project folder if it only exists externally
            if (is_file($indirectFile)) {
                $oldIndirectPath = trim(file_get_contents($indirectFile));
                $localCompose = findComposeFile("$compose_root/$script");
                $externalCompose = false;
                if (is_dir($oldIndirectPath)) {
                    $externalCompose = findComposeFile($oldIndirectPath);
                } elseif (is_file($oldIndirectPath)) {
                    $externalCompose = $oldIndirectPath;
                }
                if (!$localCompose && $externalCompose) {
                    copy($externalCompose, "$compose_root/$script/" . basename($externalCompose));
                }
                @unlink($indirectFile);
            }
            if (is_file($indirectModeFile)) {
                @unlink($indirectModeFile);
            }
            // Also clean up any invalid indirect file when clearing the path
            if (is_file($invalidIndirectFile)) {
                @unlink($invalidIndirectFile);
            }
        } else {
            file_put_contents($indirectFile, $indirectTarget);
            file_put_contents($indirectModeFile, is_file($indirectTarget) ? 'file' : 'folder');
            // Clean up the invalid file now that we have a corrected path
            if (is_file($invalidIndirectFile)) {
                @unlink($invalidIndirectFile);
            }
            // Remove local compose file if it exists since we're now using external
            $localCompose = findComposeFile("$compose_root/$script");
            if ($localCompose) {
                $projectPath = realpath("$compose_root/$script") ?: rtrim("$compose_root/$script", '/');
                $resolvedExternalPath = is_dir($indirectTarget)
                    ? (realpath($indirectTarget) ?: rtrim($indirectTarget, '/'))
                    : dirname($indirectTarget);
                if ($resolvedExternalPath !== $projectPath) {
                    @unlink($localCompose);
                }
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
        $script = getPostScript();
        composeLogger('getStackContainers start', [
            'script' => $script,
            'post' => $_POST,
            'caller' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ], 'user', 'debug', 'getStackContainers');
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
                        $rawContainer['Health'] = $inspect['State']['Health']['Status'] ?? '';

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
        $persistentContainerCache = composeLoadPersistentContainerCache();
        $stackContainerCache = $persistentContainerCache[$script] ?? [];
        $iconByService = [];
        $iconByName = [];
        foreach ($stackContainerCache as $cachedService => $cachedContainer) {
            if (!is_array($cachedContainer)) {
                continue;
            }
            $cachedIcon = trim((string) ($cachedContainer['icon'] ?? ''));
            if ($cachedIcon !== '') {
                $iconByService[(string) $cachedService] = $cachedIcon;
            }
            $cachedName = trim((string) ($cachedContainer['name'] ?? ''));
            if ($cachedName !== '' && $cachedIcon !== '') {
                $iconByName[$cachedName] = $cachedIcon;
            }
        }
        $inspectIconCache = [];
        $stackCacheDirty = false;

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
                $containerLower = array_change_key_case($container, CASE_LOWER);
                $containerName = trim((string) ($containerLower['name'] ?? $containerLower['names'] ?? ''));
                $service = trim((string) ($containerLower['service'] ?? ''));
                $image = trim((string) ($containerLower['image'] ?? ''));

                if ($containerName && $image) {
                    $icon = composeResolveContainerIcon($containerName, $service, $iconByService, $iconByName, $inspectIconCache);

                    if ($service !== '' && $icon !== '') {
                        if (!isset($stackContainerCache[$service]) || !is_array($stackContainerCache[$service])) {
                            $stackContainerCache[$service] = [];
                        }
                        if (($stackContainerCache[$service]['icon'] ?? '') !== $icon) {
                            $stackContainerCache[$service]['icon'] = $icon;
                            $stackCacheDirty = true;
                        }
                        if (($stackContainerCache[$service]['name'] ?? '') === '') {
                            $stackContainerCache[$service]['name'] = $containerName;
                            $stackCacheDirty = true;
                        }
                        if (($stackContainerCache[$service]['service'] ?? '') === '') {
                            $stackContainerCache[$service]['service'] = $service;
                            $stackCacheDirty = true;
                        }
                    }

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
                        'service' => $service,
                        'image' => $image,
                        'icon' => $icon,
                        'hasUpdate' => $hasUpdate,
                        'status' => $statusText,
                        'localSha' => $localSha,
                        'remoteSha' => $remoteSha
                    ])->toUpdateArray();
                }
            }

            if ($stackCacheDirty) {
                $persistentContainerCache[$script] = $stackContainerCache;
                composeSavePersistentContainerCache($persistentContainerCache);
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
        $persistentContainerCache = composeLoadPersistentContainerCache();
        $persistentCacheDirty = false;
        $inspectIconCache = [];

        // Path to update status file
        $dockerManPaths = [
            'update-status' => UNRAID_UPDATE_STATUS_FILE
        ];

        foreach (StackInfo::allFromRoot($compose_root) as $stackInfoItem) {
            $stackName = $stackInfoItem->projectFolder;
            $projectName = $stackInfoItem->projectFolder;
            $stackContainerCache = $persistentContainerCache[$stackName] ?? [];
            $iconByService = [];
            $iconByName = [];
            foreach ($stackContainerCache as $cachedService => $cachedContainer) {
                if (!is_array($cachedContainer)) {
                    continue;
                }
                $cachedIcon = trim((string) ($cachedContainer['icon'] ?? ''));
                if ($cachedIcon !== '') {
                    $iconByService[(string) $cachedService] = $cachedIcon;
                }
                $cachedName = trim((string) ($cachedContainer['name'] ?? ''));
                if ($cachedName !== '' && $cachedIcon !== '') {
                    $iconByName[$cachedName] = $cachedIcon;
                }
            }
            $stackCacheDirty = false;

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
                    $service = trim((string) ($containerLower['service'] ?? ''));
                    $image = trim($containerLower['image'] ?? '');
                    $state = strtolower(trim($containerLower['state'] ?? ''));

                    // Only check updates for running containers
                    if ($containerName && $image && $state === 'running') {
                        $icon = composeResolveContainerIcon($containerName, $service, $iconByService, $iconByName, $inspectIconCache);

                        if ($service !== '' && $icon !== '') {
                            if (!isset($stackContainerCache[$service]) || !is_array($stackContainerCache[$service])) {
                                $stackContainerCache[$service] = [];
                            }
                            if (($stackContainerCache[$service]['icon'] ?? '') !== $icon) {
                                $stackContainerCache[$service]['icon'] = $icon;
                                $stackCacheDirty = true;
                            }
                            if (($stackContainerCache[$service]['name'] ?? '') === '') {
                                $stackContainerCache[$service]['name'] = $containerName;
                                $stackCacheDirty = true;
                            }
                            if (($stackContainerCache[$service]['service'] ?? '') === '') {
                                $stackContainerCache[$service]['service'] = $service;
                                $stackCacheDirty = true;
                            }
                        }

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
                            'service' => $service,
                            'image' => $image,
                            'icon' => $icon,
                            'hasUpdate' => $hasUpdate,
                            'status' => ($updateStatus === null) ? 'unknown' : ($updateStatus ? 'up-to-date' : 'update-available'),
                            'localSha' => $localSha,
                            'remoteSha' => $remoteSha
                        ])->toUpdateArray();
                    }
                }

                if ($stackCacheDirty) {
                    $persistentContainerCache[$stackName] = $stackContainerCache;
                    $persistentCacheDirty = true;
                }
            }

            $allUpdates[$stackName] = [
                'projectName' => $projectName,
                'hasUpdate' => $hasStackUpdate,
                'containers' => $stackUpdates
            ];
        }

        if ($persistentCacheDirty) {
            composeSavePersistentContainerCache($persistentContainerCache);
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
        // Check if docker.versions plugin directory exists (where changelog assets are expected)
        $dockerVersionsInstalled = is_dir('/usr/local/emhttp/plugins/docker.versions');
        if (is_file($composeUpdateStatusFile)) {
            $savedStatus = json_decode(file_get_contents($composeUpdateStatusFile), true);
            if ($savedStatus) {
                echo json_encode(['result' => 'success', 'stacks' => $savedStatus, 'dockerVersionsInstalled' => $dockerVersionsInstalled]);
            } else {
                echo json_encode(['result' => 'success', 'stacks' => [], 'dockerVersionsInstalled' => $dockerVersionsInstalled]);
            }
        } else {
            echo json_encode(['result' => 'success', 'stacks' => [], 'dockerVersionsInstalled' => $dockerVersionsInstalled]);
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
