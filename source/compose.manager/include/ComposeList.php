<?PHP

/**
 * Async stack list loader for Compose Manager
 * This file is called via AJAX to load the stack list without blocking page load
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/ColumnLayout.php");

$cfg = parse_plugin_cfg($sName);

// Resolve saved column order so rows render in the user's chosen order on first
// paint. Hidden columns are still emitted (hide-col-* on the table controls
// visibility); the client customizer's reapply() becomes a no-op on load.
$stackColumnOrder = compose_stack_render_order(compose_read_column_layout());

$mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : 'html';
if ($mode === 'list') {
    $projects = StackInfo::listProjectFolders($compose_root);
    echo json_encode([
        'result' => 'success',
        'projects' => array_values($projects),
    ]);
    exit;
}

$o = "";
$stackCount = 0;

$stackInfos = [];
if ($mode === 'row') {
    $project = isset($_GET['project']) ? basename(trim((string)$_GET['project'])) : '';
    if ($project === '') {
        echo json_encode(['result' => 'error', 'message' => 'Project not specified.']);
        exit;
    }
    try {
        $stackInfos = [StackInfo::fromProject($compose_root, $project)];
    } catch (\Throwable $e) {
        echo json_encode(['result' => 'error', 'message' => 'Project not found.']);
        exit;
    }
} else {
    $stackInfos = StackInfo::allFromRoot($compose_root);
}

foreach ($stackInfos as $stackInfo) {
    $stackCount++;

    $projectName = $stackInfo->getName();
    $id = str_replace(".", "-", $stackInfo->projectFolder);
    $id = str_replace(" ", "", $id);

    // Get the compose file path and override via StackInfo
    $composeFile = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/' . COMPOSE_FILE_NAMES[0]);
    $overridePath = $stackInfo->getOverridePath();

    // getStackState() internally calls getContainerCounts(), which caches
    // the result.  Calling getContainerCounts() afterwards is free.
    $stackState = $stackInfo->getStackState();
    $counts = $stackInfo->getContainerCounts();

    $runningCount = $counts['running'];
    $stoppedCount = $counts['stopped'];
    $pausedCount = $counts['paused'];
    $restartingCount = $counts['restarting'];
    $actualContainerCount = $counts['total'];
    $containerCount = $counts['total'];

    // Collect container names for the hide-from-docker feature (data attribute)
    $containerNamesList = [];
    // Collect short container IDs for CPU/MEM load mapping (docker stats uses 12-char short IDs)
    $containerIdsList = [];
    foreach ($stackInfo->getContainerList() as $ct) {
        $n = $ct['Names'] ?? '';
        if ($n) $containerNamesList[] = $n;
        $ctId = $ct['ID'] ?? '';
        if ($ctId) $containerIdsList[] = substr($ctId, 0, 12);
    }
    $containerNamesAttr = htmlspecialchars(json_encode($containerNamesList), ENT_QUOTES, 'UTF-8');
    $containerIdsAttr = htmlspecialchars(implode(',', $containerIdsList), ENT_QUOTES, 'UTF-8');

    // Determine states
    $isrunning = $runningCount > 0;
    $isexited = $stoppedCount > 0;
    $ispaused = $pausedCount > 0;
    $isrestarting = $restartingCount > 0;
    $isup = $actualContainerCount > 0;

    // Read metadata via StackInfo lazy getters
    $descriptionRaw = $stackInfo->getDescription();
    if ($descriptionRaw) {
        $descriptionRaw = str_replace("\r", "", $descriptionRaw);
        $description = htmlspecialchars($descriptionRaw, ENT_QUOTES, 'UTF-8');
        $description = str_replace("\n", "<br>", $description);
    } else {
        $description = "";
    }

    $autostart = $stackInfo->getAutostart() ? 'checked' : '';

    $projectIcon = $stackInfo->getIconUrl();
    $webuiUrl = $stackInfo->getWebUIUrl();

    $profiles = $stackInfo->getProfiles();
    $profilesJson = htmlspecialchars(json_encode($profiles ?: []), ENT_QUOTES, 'UTF-8');

    // Get default profiles for actions like force update
    $defaultProfiles = $stackInfo->getDefaultProfiles();
    $defaultProfilesStr = implode(',', $defaultProfiles);
    $defaultProfilesHtml = htmlspecialchars($defaultProfilesStr, ENT_QUOTES, 'UTF-8');

    // Get running profiles so UI can prioritize current runtime selection
    $runningProfiles = $stackInfo->getRunningProfiles();
    $runningProfilesStr = implode(',', $runningProfiles);
    $runningProfilesHtml = htmlspecialchars($runningProfilesStr, ENT_QUOTES, 'UTF-8');

    // Determine status text and class for badge
    $statusText = "Stopped";
    $statusClass = "status-stopped";
    if ($isup) {
        if ($isexited && !$isrunning) {
            $statusText = "Exited";
            $statusClass = "status-exited";
        } elseif ($isrunning && !$isexited && !$ispaused && !$isrestarting) {
            $statusText = "Running";
            $statusClass = "status-running";
        } elseif ($ispaused && !$isexited && !$isrunning && !$isrestarting) {
            $statusText = "Paused";
            $statusClass = "status-paused";
        } elseif ($ispaused && !$isexited) {
            $statusText = "Partial";
            $statusClass = "status-partial";
        } elseif ($isrestarting) {
            $statusText = "Restarting";
            $statusClass = "status-restarting";
        } else {
            $statusText = "Mixed";
            $statusClass = "status-mixed";
        }
    }

    // Escape for HTML output
    $projectNameHtml = htmlspecialchars($stackInfo->displayName, ENT_QUOTES, 'UTF-8');
    $projectHtml = htmlspecialchars($stackInfo->projectFolder, ENT_QUOTES, 'UTF-8');
    $descriptionHtml = $description; // Already contains <br> tags from earlier processing
    $pathHtml = htmlspecialchars($stackInfo->path, ENT_QUOTES, 'UTF-8');
    $projectIconUrl = htmlspecialchars($stackInfo->getIconUrl() ?? '', ENT_QUOTES, 'UTF-8');
    $invalidIndirectPath = $stackInfo->invalidIndirectPath;
    $hasInvalidIndirect = ($invalidIndirectPath !== null && trim($invalidIndirectPath) !== '');
    $invalidIndirectPathHtml = htmlspecialchars($invalidIndirectPath ?? '', ENT_QUOTES, 'UTF-8');

    // Status icon, label, color — derived from centralized getStackState()
    $status = $stackState['state'];
    $shape = $stackState['shape'];
    $color = $stackState['color'];
    $outerClass = $status;
    $statusLabel = $stackState['label'];

    // Get stack started_at timestamp via StackInfo
    $stackStartedAt = $stackInfo->getStartedAt();

    // Calculate uptime display from started_at timestamp
    $stackUptime = '';
    if ($stackStartedAt && $isrunning) {
        $startTime = strtotime($stackStartedAt);
        if ($startTime) {
            $diffSecs = time() - $startTime;
            $mins = floor($diffSecs / 60);
            $hours = floor($diffSecs / 3600);
            $days = floor($diffSecs / 86400);
            $weeks = floor($days / 7);
            $months = floor($days / 30);
            $years = floor($days / 365);

            if ($mins < 120) {
                $stackUptime = $mins . " min" . ($mins !== 1 ? "s" : "");
            } elseif ($hours < 48) {
                $stackUptime = $hours . " hour" . ($hours !== 1 ? "s" : "");
            } elseif ($days < 14) {
                $stackUptime = $days . " day" . ($days !== 1 ? "s" : "");
            } elseif ($weeks < 8) {
                $stackUptime = $weeks . " week" . ($weeks !== 1 ? "s" : "");
            } elseif ($months < 24) {
                $stackUptime = $months . " month" . ($months !== 1 ? "s" : "");
            } else {
                $stackUptime = $years . " year" . ($years !== 1 ? "s" : "");
            }
        }
    }
    if (!$stackUptime && $isrunning) {
        $stackUptime = "Uptime: running";
    } elseif (!$stackUptime) {
        $stackUptime = "stopped";
    }

    // Escape webui URL for HTML attribute
    $webuiUrlHtml = htmlspecialchars($webuiUrl, ENT_QUOTES, 'UTF-8');

    // Check if stack has build configurations (needs rebuild on update)
    $hasBuild = $stackInfo->hasBuildConfig() ? '1' : '0';

    // Main row - Docker tab structure with expand arrow on left
    $o .= "<tr class='compose-sortable' id='stack-row-$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-path='$pathHtml' data-isup='$isup' data-profiles='$profilesJson' data-running-profile='$runningProfilesHtml' data-default-profile='$defaultProfilesHtml' data-webui='$webuiUrlHtml' data-containers='$containerNamesAttr' data-ctids='$containerIdsAttr' data-hasbuild='$hasBuild' data-invalid-indirect='" . ($hasInvalidIndirect ? '1' : '0') . "' data-invalid-indirect-path='$invalidIndirectPathHtml'>";

    // Arrow column
    $o .= "<td class='col-arrow'>";
    $o .= "<i class='fa fa-chevron-right expand-icon' id='expand-icon-$id' onclick='toggleStackDetails(\"$id\");event.stopPropagation();' style='cursor:pointer;'></i>";
    $o .= "<i class='fa fa-arrows-v mover orange-text' aria-hidden='true' style='display:none;cursor:move;'></i>";
    $o .= "</td>";

    // Icon column
    $imgSrc = $projectIconUrl ?: '/plugins/dynamix.docker.manager/images/question.png';
    $o .= "<td class='col-icon'>";
    $o .= "<span class='outer $outerClass'>";
    $o .= "<span id='stack-$id' class='hand' data-stackid='$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-isup='$isup' data-running='" . ($isrunning ? '1' : '0') . "'>";
    $o .= "<img src='$imgSrc' class='img' onerror=\"this.src='/plugins/dynamix.docker.manager/images/question.png';\">";
    $o .= "</span>";
    $o .= "</span>";
    $o .= "</td>";

    // Name column
    $o .= "<td class='col-name'>";
    $o .= "<span class='inner'><span class='appname'>$projectNameHtml</span><br>";
    $o .= "<i class='fa fa-$shape $status $color compose-status-icon' data-status='$status'></i><span class='state'>$statusLabel</span>";
    if ($hasInvalidIndirect) {
        composeLogger('Rendering invalid indirect warning in stack list', [
            'project' => $stackInfo->projectFolder,
            'projectPath' => $stackInfo->path,
            'invalidIndirectPath' => $invalidIndirectPath,
            'isIndirect' => $stackInfo->isIndirect,
            'composeSource' => $stackInfo->composeSource,
        ], 'user', 'debug', 'stack-list');
        $o .= " <i class='fa fa-warning orange-text' title='External compose path is invalid or unavailable: $invalidIndirectPathHtml'></i>";
    }
    $o .= "<div class='cm-advanced compose-text-muted' style='margin-top:4px;font-size:0.85em;'>";
    $o .= "Project: $projectHtml";
    $o .= "</div>";
    $o .= "</span>";
    $o .= "</td>";

    // Toggleable columns are built into a keyed map and emitted below in the
    // user's saved order, so rows render correctly on first paint.
    $stackCells = [];

    // Update column (like Docker tab) - default to "not checked" until update check runs
    $updateCell = "<td class='col-update compose-updatecolumn'>";
    if ($isrunning) {
        $updateCell .= "<span class='grey-text' style='white-space:nowrap;cursor:default;' title='Click Check for Updates to check'><i class='fa fa-question-circle fa-fw'></i> not checked</span>";
    } else {
        $updateCell .= "<span class='grey-text' style='white-space:nowrap;'><i class='fa fa-stop fa-fw'></i> stopped</span>";
    }
    $updateCell .= "</td>";
    $stackCells['update'] = $updateCell;

    // Containers column (shows running/total)
    $containersDisplay = $isrunning ? "$runningCount / $containerCount" : "0 / $containerCount";
    $containersClass = ($runningCount == $containerCount && $runningCount > 0) ? 'green-text' : ($runningCount > 0 ? 'orange-text' : 'grey-text');
    $stackCells['containers'] = "<td class='col-containers'><span class='$containersClass'>$containersDisplay</span></td>";

    // Uptime column (both basic and advanced views)
    $uptimeDisplay = $stackUptime;
    $uptimeClass = $isrunning ? 'green-text' : 'grey-text';
    $stackCells['uptime'] = "<td class='col-uptime'><span class='$uptimeClass'>$uptimeDisplay</span></td>";

    // Health column (updated from detailed inspect data by frontend; initial fallback here)
    $healthDisplay = $isrunning ? 'n/a' : 'stopped';
    $healthClass = $isrunning ? 'compose-text-muted' : 'grey-text';
    $stackCells['health'] = "<td class='col-health'><span class='$healthClass'>$healthDisplay</span></td>";

    // Metric columns (advanced only)
    $cpuCell = "<td class='cm-advanced col-cpu compose-load-cell'>";
    $cpuCell .= "<span class='compose-stack-cpu-$id compose-text-muted'>-</span>";
    $cpuCell .= "<div class='usage-disk mm'><span id='compose-stack-cpu-bar-$id' style='width:0'></span><span></span></div>";
    $cpuCell .= "</td>";
    $stackCells['cpu'] = $cpuCell;

    $memCell = "<td class='cm-advanced col-memory compose-load-cell'>";
    $memCell .= "<span class='compose-stack-mem-$id compose-text-muted'>-</span>";
    $memCell .= "<div class='usage-disk mm'><span id='compose-stack-mem-bar-$id' style='width:0'></span><span></span></div>";
    $memCell .= "</td>";
    $stackCells['memory'] = $memCell;

    $stackCells['net_io'] = "<td class='cm-advanced col-net_io'><span class='compose-stack-netio-$id compose-text-muted'>-</span></td>";
    $stackCells['block_io'] = "<td class='cm-advanced col-block_io'><span class='compose-stack-blockio-$id compose-text-muted'>-</span></td>";

    // Description column (advanced only)
    $descriptionCell = "<td class='cm-advanced col-description' style='overflow-wrap:break-word;word-wrap:break-word;'>";
    if ($hasInvalidIndirect) {
        $descriptionCell .= "<div class='orange-text' style='margin-bottom:4px;font-size:0.85em;'><i class='fa fa-warning'></i> External compose path unavailable, using local stack path.</div>";
    }
    $descriptionCell .= "<span class='docker_readmore'>$descriptionHtml</span></td>";
    $stackCells['description'] = $descriptionCell;

    // Path column (advanced only)
    $stackCells['path'] = "<td class='cm-advanced col-path compose-text-muted' style='font-size:12px;'>$pathHtml</td>";

    // Emit toggleable columns in the saved order (hidden columns still render;
    // hide-col-* classes on the table control their visibility).
    foreach ($stackColumnOrder as $stackCol) {
        if (isset($stackCells[$stackCol])) {
            $o .= $stackCells[$stackCol];
        }
    }

    // Auto Start toggle
    $o .= "<td class='nine col-autostart'>";
    $o .= "<input type='checkbox' class='auto_start' data-scriptName='$projectHtml' id='autostart-$id' $autostart>";
    $o .= "</td>";

    $o .= "</tr>";

    // Expandable details row
    $o .= "<tr class='stack-details-row' id='details-row-$id' style='display:none;'>";
    $o .= "<td colspan='14' class='stack-details-cell' style='padding:0 0 0 60px;background:var(--dynamix-tablesorter-tbody-row-bg-color);'>";
    $o .= "<div class='stack-details-container' id='details-container-$id' style='padding:8px 16px;'>";
    $o .= "<i class='fa fa-spinner fa-spin compose-spinner'></i> Loading containers...";
    $o .= "</div>";
    $o .= "</td>";
    $o .= "</tr>";
}

// If no stacks found, show a message
if ($mode !== 'row' && $stackCount === 0) {
    $o = "<tr><td colspan='14' style='text-align:center;padding:20px;color:var(--alt-text-color);'>No Docker Compose stacks found. Click 'Add New Stack' to create one.</td></tr>";
}

// Output the HTML
if ($mode === 'row') {
    echo json_encode([
        'result' => 'success',
        'html' => $o,
    ]);
} else {
    echo $o;
}
