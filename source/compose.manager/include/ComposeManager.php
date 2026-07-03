<?PHP

/**
 * Compose Manager Main Page
 * The stack list is loaded asynchronously via AJAX for better UX
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/ColumnLayout.php");

// Load plugin config
$cfg = parse_plugin_cfg($sName);

// Resolve the saved column layout so the stack table renders in the user's
// chosen order/visibility/widths on first paint (no client-side reorder snap).
$columnLayout = compose_read_column_layout();
$columnModel = compose_column_client_model();
$stackColumnOrder = compose_stack_render_order($columnLayout);
$stackColumnMeta = compose_stack_column_meta();
$stackHiddenColumns = compose_stack_hidden_columns($columnLayout);
$stackWidthFractions = compose_stack_width_fractions($columnLayout);
composeLogger('Prepared column model bootstrap', [
    'stackCols' => count($columnModel['stackCols'] ?? []),
    'serviceCols' => count($columnModel['serviceCols'] ?? []),
    'stackVisible' => count($columnLayout['stackOrder'] ?? []),
    'serviceVisible' => count($columnLayout['serviceOrder'] ?? []),
], 'user', 'debug', 'column-layout');
$stackHideClass = '';
foreach ($stackHiddenColumns as $hiddenCol) {
    $stackHideClass .= ' hide-col-' . $hiddenCol;
}
$autoCheckUpdates = ($cfg['AUTO_CHECK_UPDATES'] ?? 'false') === 'true';
$autoCheckDays = floatval($cfg['AUTO_CHECK_UPDATES_DAYS'] ?? '1');
$showComposeOnTop = ($cfg['SHOW_COMPOSE_ON_TOP'] ?? 'false') === 'true';
$hideComposeFromDocker = ($cfg['HIDE_COMPOSE_FROM_DOCKER'] ?? 'false') === 'true';

// Get Docker Compose CLI version
$composeVersion = trim(shell_exec('docker compose version --short 2>/dev/null') ?? '');

// Host total memory in bytes for stack-level memory denominator.
$composeSystemMemBytes = 0;
$memKbRaw = trim(shell_exec("awk '/^MemTotal:/ {print \$2}' /proc/meminfo 2>/dev/null") ?? '');
if (is_numeric($memKbRaw)) {
    $composeSystemMemBytes = (int)$memKbRaw * 1024;
}

// CPU count for load normalization (matches Docker manager's cpu_list approach).
// cpu_list() returns thread_siblings_list entries (e.g. "0-3,8-11").
// We expand each range segment so "0-3" counts as 4, not 2 endpoints.
function compose_manager_cpu_spec_count($cpuSpec)
{
    $count = 0;
    foreach (explode(',', trim((string)$cpuSpec)) as $segment) {
        $segment = trim($segment);
        if ($segment === '') continue;
        if (strpos($segment, '-') !== false) {
            [$start, $end] = explode('-', $segment, 2);
            $start = (int)$start;
            $end   = (int)$end;
            if ($end < $start) [$start, $end] = [$end, $start];
            $count += max(0, $end - $start + 1);
        } else {
            $count += 1;
        }
    }
    return $count;
}

/** @disregard P1010 cpu_list is defined externally and imported here */
// @phpstan-ignore-next-line
$cpus = cpu_list();
$cpuCount = 0;
foreach ($cpus as $cpuSpec) {
    $cpuCount += compose_manager_cpu_spec_count($cpuSpec);
}
if ($cpuCount <= 0) {
    $cpuCount = (int)trim(shell_exec('nproc 2>/dev/null') ?: '1');
}
if ($cpuCount <= 0) {
    $cpuCount = 1;
}

// Note: Stack list is now loaded asynchronously via ComposeList.php
// This improves page load time by deferring expensive docker commands
?>

<?php /* Critical inline CSS
    Guarantees table-layout, column widths, and column visibility rules
    are applied synchronously BEFORE any HTML renders to prevent FOUC.
   Non-critical styles remain in comboButton.css loaded via <link>. */ ?>
<style>
    /* Table structure — always fixed layout */
    #compose_stacks {
        width: 100%;
        table-layout: fixed;
        /* Rendered width vars from ColumnLayout.php; keep runtime math here in sync with that model. */
        --cm-col-arrow-px: 24px;
        --cm-col-icon-px: 48px;
        --cm-col-fixed-px: calc(var(--cm-col-arrow-px) + var(--cm-col-icon-px));
<?php foreach ($stackWidthFractions as $fracCol => $fracVal): ?>
        --cm-col-<?php echo str_replace('_', '-', $fracCol); ?>-frac: <?php echo number_format($fracVal, 9, '.', ''); ?>;
<?php endforeach; ?>
    }

    /* Stabilize header row height across column visibility changes */
    #compose_stacks thead tr th {
        font-weight: normal;
        font-size: 1.1rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--dynamix-tablesorter-thead-th-text-color);
        background-color: var(--dynamix-tablesorter-thead-th-bg-color);
        padding: 8px 20px 8px 6px;
        white-space: nowrap;
        text-align: left;
    }

    /* Clip overflowing content in fixed-layout cells.
       border-box is REQUIRED so the percentage column widths below include
       their own padding; otherwise padding is added on top of the computed
       width and the columns overflow/shrink, leaving a gap at the right. */
    #compose_stacks th,
    #compose_stacks td {
        box-sizing: border-box;
        overflow: hidden;
        text-overflow: ellipsis
    }

    /* Stack-table column widths.
   Arrow + Icon are fixed px (small fixed content); remaining columns use CSS vars. */
    #compose_stacks thead th.col-arrow {
        width: var(--cm-col-arrow-px);
        padding: 0;
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all {
        width: 24px;
        height: 24px;
        margin: 0 auto;
        padding: 0;
        border: 0;
        background: none;
        appearance: none;
        -webkit-appearance: none;
        border-radius: 4px;
        color: var(--dynamix-tablesorter-thead-th-text-color);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        line-height: 1;
        opacity: 0.95;
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all i {
        transition: transform 0.2s ease;
        transform: rotate(0deg);
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all.is-expanded i {
        transform: rotate(180deg);
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all:hover {
        color: var(--orange-text, var(--brand-orange));
        opacity: 1;
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all:focus-visible {
        outline: 1px solid var(--brand-orange);
        outline-offset: 2px;
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all:disabled {
        cursor: default;
        opacity: 0.45;
    }

    #compose_stacks td.col-arrow {
        text-align: center;
        white-space: nowrap;
    }

    #compose_stacks td.col-arrow i {
        vertical-align: middle;
    }

    #compose_list.compose-sort-enabled tr.compose-sortable {
        cursor: move;
    }

    #compose_stacks thead th.col-icon {
        width: var(--cm-col-icon-px);
        padding: 0;
    }

    #compose_stacks thead th.col-name {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-name-frac));
    }

    #compose_stacks thead th.col-update {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-update-frac));
    }

    #compose_stacks thead th.col-containers {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-containers-frac));
    }

    #compose_stacks thead th.col-uptime {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-uptime-frac));
    }

    #compose_stacks thead th.col-health {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-health-frac));
    }

    #compose_stacks thead th.col-autostart {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-autostart-frac));
    }

    #compose_stacks thead th.col-cpu {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-cpu-frac))
    }

    #compose_stacks thead th.col-memory {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-memory-frac))
    }

    #compose_stacks thead th.col-net_io {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-net-io-frac))
    }

    #compose_stacks thead th.col-block_io {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-block-io-frac))
    }

    #compose_stacks thead th.col-description {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-description-frac))
    }

    #compose_stacks thead th.col-path {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-path-frac))
    }

    /* Center the Containers column */
    #compose_stacks thead th.col-containers,
    #compose_stacks td.col-containers {
        text-align: center
    }

    /* Autostart column: right-align content to push toggles to edge */
    #compose_stacks thead th.col-autostart,
    #compose_stacks td.col-autostart {
        text-align: right
    }

    /* Arrow and icon columns: no overflow clipping, no padding bloat */
    #compose_stacks td.col-arrow,
    #compose_stacks td.col-icon {
        overflow: visible;
        padding: 8px 0;
        text-align: center;
        vertical-align: middle
    }

    /* Detail row */
    #compose_stacks .stack-details-cell {
        width: auto !important
    }

    #compose_stacks tbody tr.compose-sortable.compose-stack-row-alt,
    #compose_stacks tbody tr.compose-sortable.compose-stack-row-alt td {
        background-color: var(--dynamix-tablesorter-tbody-row-alt-bg-color)
    }

    #compose_stacks tbody tr.stack-details-row {
        background-color: var(--dynamix-sb-body-bg-color) !important
    }

    /* Autostart cell */
    #compose_stacks td.nine {
        white-space: nowrap;
        padding-right: 20px
    }

    .dropdown-menu {
        z-index: 100 !important;
    }

    /* Keep context menus above the fixed footer (z-index must out-rank the
       .dropdown-menu !important rule above); the scroll spacer appended in
       fixContextDropdownOverflow() keeps them reachable (unraid/webgui#2639) */
    .dropdown-context:not(.dropdown-context-sub) {
        z-index: 10001 !important;
    }

    /* CPU & Memory load display (matches Docker manager usage-disk style) */
    .compose-load-cell {
        white-space: nowrap;
        font-size: 0.9em;
    }

    .compose-load-cell .compose-load-cpu,
    .compose-load-cell .compose-load-mem {
        display: block;
    }

    .compose-load-cell .compose-load-mem {
        margin-top: 2px;
    }

    .compose-load-cell .usage-disk.mm {
        height: 3px;
        margin: 3px 20px 0 0;
        position: relative;
        background-color: var(--usage-disk-background-color, #e0e0e0);
    }

    .compose-load-cell .usage-disk.mm>span:first-child {
        position: absolute;
        left: 0;
        height: 3px;
        background-color: var(--gray-400, #888);
    }

    .compose-load-cell .usage-disk.mm>span:last-child {
        position: relative;
        z-index: 1;
    }
</style>

<?php
// Use Dynamix's bundled Ace if available (Unraid 7.0.0+), else fall back to our plugin-local copy
// (downloaded during install for pre-7.0.0 Unraid via the PLG post-install script)
$acePath = file_exists('/usr/local/emhttp/plugins/dynamix/javascript/ace/ace.js')
    ? '/webGui/javascript/ace'
    : '/plugins/compose.manager/javascript/ace';
?>
<script src="<?php autov($acePath . '/ace.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/js-yaml/js-yaml.min.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/common.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeSortable.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeStackUtils.js'); ?>" type="text/javascript"></script>
<?php if (file_exists('/usr/local/emhttp/plugins/docker.versions/styles/styles.css')): ?>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/docker.versions/styles/styles.css'); ?>">
<?php endif; ?>
<?php if (file_exists('/usr/local/emhttp/plugins/docker.versions/scripts/changelog.js')): ?>
<script src="<?php autov('/plugins/docker.versions/scripts/changelog.js'); ?>" type="text/javascript"></script>
<?php endif; ?>
<script>
    window.composeManagerBootstrap = {
        compose_root: <?php echo json_encode($compose_root); ?>,
        aceTheme: <?php echo (in_array($theme, ['black', 'gray']) ? json_encode('ace/theme/tomorrow_night') : json_encode('ace/theme/tomorrow')); ?>,
        aceBasePath: <?php echo json_encode($acePath); ?>,
        icon_label: <?php echo json_encode($docker_label_icon); ?>,
        webui_label: <?php echo json_encode($docker_label_webui); ?>,
        shell_label: <?php echo json_encode($docker_label_shell); ?>,
        managed_label: <?php echo json_encode($docker_label_managed); ?>,
        managed_label_name: <?php echo json_encode($docker_label_managed_name); ?>,
        autoCheckUpdates: <?php echo json_encode($autoCheckUpdates); ?>,
        autoCheckDays: <?php echo json_encode($autoCheckDays); ?>,
        showComposeOnTop: <?php echo json_encode($showComposeOnTop); ?>,
        hideComposeFromDocker: <?php echo json_encode($hideComposeFromDocker); ?>,
        composeCliVersion: <?php echo json_encode($composeVersion); ?>,
        composeSystemMemBytes: <?php echo json_encode($composeSystemMemBytes); ?>,
        composeCpuCount: <?php echo json_encode($cpuCount); ?>,
        comboButtonCss: "<?php autov('/plugins/compose.manager/sheets/ComboButton.css'); ?>",
        editorModalCss: "<?php autov('/plugins/compose.manager/sheets/EditorModal.css'); ?>",
        columnModel: <?php echo json_encode($columnModel); ?>,
        columnLayout: <?php echo json_encode($columnLayout); ?>
    };

    var composeBootstrap = window.composeManagerBootstrap || {};
    var compose_root = composeBootstrap.compose_root || '';
    var caURL = "/plugins/compose.manager/include/Exec.php";
    var compURL = "/plugins/compose.manager/include/ComposeUtil.php";
    var aceTheme = composeBootstrap.aceTheme || 'ace/theme/tomorrow';
    var aceBasePath = composeBootstrap.aceBasePath || '/plugins/compose.manager/javascript/ace';
    const icon_label = composeBootstrap.icon_label || '';

    // Configure Ace base path explicitly so it finds mode/theme files
    // regardless of how the script URL was resolved
    if (typeof ace !== 'undefined') {
        ace.config.set('basePath', aceBasePath);
    }
    const webui_label = composeBootstrap.webui_label || '';
    const shell_label = composeBootstrap.shell_label || '';
    const managed_label = composeBootstrap.managed_label || '';
    const managed_label_name = composeBootstrap.managed_label_name || '';

    // Auto-check settings from config
    var autoCheckUpdates = !!composeBootstrap.autoCheckUpdates;
    var autoCheckDays = Number(composeBootstrap.autoCheckDays || 1);
    var showComposeOnTop = !!composeBootstrap.showComposeOnTop;
    var hideComposeFromDocker = !!composeBootstrap.hideComposeFromDocker;
    var composeCliVersion = composeBootstrap.composeCliVersion || '';
    var composeSystemMemBytes = Number(composeBootstrap.composeSystemMemBytes || 0);
    var composeCpuCount = Number(composeBootstrap.composeCpuCount || 1);
</script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeManagerPageInit.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeColumnCustomizer.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeManagerMain.js'); ?>" type="text/javascript"></script>

<HTML>

<HEAD>
</HEAD>

<BODY>

    <span class='tipsterallowed' hidden></span>
    <div class="TableContainer">
        <table id="compose_stacks" class="tablesorter shift<?php echo $stackHideClass; ?>" style="table-layout:fixed;width:100%">
            <thead>
                <tr>
                    <th class="col-arrow">
                        <div type="button" class="compose-stack-toggle-all" id="compose-stack-toggle-all" role="button" tabindex="0" onclick="toggleAllStackDetails(); return false;" title="Expand all stacks" aria-label="Expand all stacks">
                            <i class="fa fa-angle-double-down"></i>
                        </div>
                    </th>
                    <th class="col-icon"></th>
                    <th class="col-name">Stack</th>
<?php foreach ($stackColumnOrder as $stackCol): ?>
<?php if (!isset($stackColumnMeta[$stackCol])) continue; ?>
                    <th class="<?php echo $stackColumnMeta[$stackCol]['thClass']; ?>"><?php echo htmlspecialchars($stackColumnMeta[$stackCol]['label'], ENT_QUOTES, 'UTF-8'); ?></th>
<?php endforeach; ?>
                    <th class="nine col-autostart">Autostart</th>
                </tr>
            </thead>
            <tbody id="compose_list">
                <tr>
                    <td colspan='14'></td>
                </tr>
            </tbody>
        </table>
    </div>
    <span class='tipsterallowed' hidden>
        <input type='button' value='Add New Stack' onclick='addStack();'>
        <input type='button' value='Start All' onclick='startAllStacks();' id='startAllBtn'>
        <input type='button' value='Stop All' onclick='stopAllStacks();' id='stopAllBtn'>
        <input type='button' value='Check for Updates' onclick='checkAllUpdates();' id='checkUpdatesBtn'>
        <input type='button' value='Update All' onclick='updateAllStacks();' id='updateAllBtn' disabled title='Show dialog with run-in-background checkbox, then update selected stacks'>
        <label style='margin-left:10px;cursor:pointer;vertical-align:middle;' title='When enabled, only stacks with Autostart enabled will be affected'>
            <input type='checkbox' id='autostartOnlyToggle' style='vertical-align:middle;'>
            <span style='vertical-align:middle;'>Autostart only</span>
        </label>
        <a href='/Settings/compose.manager.settings' style='margin-left:20px;'><input type='button' value='Settings'></a>
    </span><br>

    <!-- Stack Actions Modal -->
    <div id="stack-actions-modal" class="stack-actions-modal" style="display:none;">
        <div class="stack-actions-modal-header">
            <span class="stack-actions-modal-title">Stack Actions</span>
            <button class="stack-actions-modal-close" onclick="closeStackActionsMenu();">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="stack-actions-modal-body">
            <button class="stack-action-item" onclick="executeStackAction('up');">
                <i class="fa fa-play"></i> Compose Up
            </button>
            <button class="stack-action-item" onclick="executeStackAction('down');">
                <i class="fa fa-stop"></i> Compose Down
            </button>
            <button class="stack-action-item" onclick="executeStackAction('update');">
                <i class="fa fa-refresh"></i> Update Stack
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item" onclick="executeStackAction('logs');">
                <i class="fa fa-file-text-o"></i> View Logs
            </button>
            <button class="stack-action-item" onclick="executeStackAction('viewCmdLog');">
                <i class="fa fa-list-alt"></i> View Last Cmd Log
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item" onclick="executeStackAction('editFiles');">
                <i class="fa fa-edit"></i> Edit Files
            </button>
            <button class="stack-action-item" onclick="executeStackAction('editName');">
                <i class="fa fa-pencil"></i> Edit Name
            </button>
            <button class="stack-action-item" onclick="executeStackAction('editDesc');">
                <i class="fa fa-align-left"></i> Edit Description
            </button>
            <button class="stack-action-item" onclick="executeStackAction('uiLabels');">
                <i class="fa fa-tags"></i> UI Labels
            </button>
            <button class="stack-action-item" onclick="executeStackAction('settings');">
                <i class="fa fa-cog"></i> Stack Settings
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item stack-action-danger" onclick="executeStackAction('delete');">
                <i class="fa fa-trash"></i> Delete Stack
            </button>
        </div>
    </div>
    <div id="stack-actions-overlay" class="stack-actions-overlay" onclick="closeStackActionsMenu();" style="display:none;"></div>

    <!-- Editor Modal -->
    <div id="editor-modal-overlay" class="editor-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="editor-modal-title">
        <div class="editor-modal">
            <!-- Modal Header -->
            <div class="editor-modal-header">
                <h2 class="editor-modal-title" id="editor-modal-title">Edit Stack</h2>
                <button class="editor-modal-close" onclick="closeEditorModal()" aria-label="Close editor modal">
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <!-- Unified Tab Bar -->
            <div class="editor-tabs" role="tablist">
                <button class="editor-tab active" id="editor-tab-compose" onclick="switchTab('compose')" role="tab" aria-selected="true" aria-controls="editor-panel-compose">
                    <i class="fa fa-file-code-o" aria-hidden="true"></i>
                    Compose
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-env" onclick="switchTab('env')" role="tab" aria-selected="false" aria-controls="editor-panel-env">
                    <i class="fa fa-cog" aria-hidden="true"></i>
                    .env
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-labels" onclick="switchTab('labels')" role="tab" aria-selected="false" aria-controls="editor-panel-labels">
                    <i class="fa fa-tags" aria-hidden="true"></i>
                    <span id="editor-tab-labels-text">Labels</span>
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-settings" onclick="switchTab('settings')" role="tab" aria-selected="false" aria-controls="editor-panel-settings">
                    <i class="fa fa-sliders" aria-hidden="true"></i>
                    Settings
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
            </div>

            <!-- ========== COMPOSE EDITOR PANEL ========== -->
            <div class="editor-panel active" id="editor-panel-compose" role="tabpanel" aria-labelledby="editor-tab-compose">
                <div class="editor-modal-body">
                    <div id="compose-file-selector-wrap" style="display:none;align-items:center;gap:8px;padding:6px 12px;">
                        <label for="compose-file-selector" style="margin:0;">File:</label>
                        <select id="compose-file-selector" onchange="switchComposeFile(this.value)"></select>
                    </div>
                    <div class="editor-container active" id="editor-container-compose">
                        <div id="editor-compose" style="width: 100%; height: 100%;"></div>
                    </div>
                </div>
                <div class="editor-validation" id="editor-validation-compose">
                    <i class="fa fa-check editor-validation-icon"></i> Ready
                </div>
            </div>

            <!-- ========== ENV EDITOR PANEL ========== -->
            <div class="editor-panel" id="editor-panel-env" role="tabpanel" aria-labelledby="editor-tab-env">
                <div class="editor-modal-body">
                    <div class="editor-empty-state" id="env-empty-state">
                        <p>No <code>.env</code> file exists at this location yet.</p>
                        <button class="editor-action-btn" onclick="createEnvTemplate()">Create .env Template</button>
                    </div>
                    <div class="editor-file-wrap" id="env-editor-wrap">
                        <div class="editor-container active" id="editor-container-env">
                            <div id="editor-env" style="width: 100%; height: 100%;"></div>
                        </div>
                    </div>
                </div>
                <div class="editor-validation" id="editor-validation-env">
                    <i class="fa fa-check editor-validation-icon"></i> Ready
                </div>
            </div>

            <!-- ========== WEBUI LABELS PANEL ========== -->
            <div class="editor-panel" id="editor-panel-labels" role="tabpanel" aria-labelledby="editor-tab-labels">
                <!-- Basic mode: form UI -->
                <div class="labels-panel" id="labels-basic-view">
                    <div class="labels-panel-header">
                        <div class="labels-panel-header-row">
                            <p>Configure icons, WebUI links, and shell commands for each service. These labels integrate your containers with the unRAID Docker UI.</p>
                        </div>
                    </div>
                    <div id="labels-services-container">
                        <div class="labels-empty-state">
                            <i class="fa fa-spinner fa-spin"></i>
                            Loading services...
                        </div>
                    </div>
                </div>
                <!-- Advanced mode: raw override editor -->
                <div class="labels-override-view" id="labels-advanced-view" style="display:none;">
                    <div class="labels-override-header">
                        <div class="labels-override-header-row">
                            <div>
                                <span class="labels-override-title"><i class="fa fa-file-code-o"></i> compose.override.yaml</span>
                                <span class="labels-override-desc">Editing the raw override file. Changes here are saved directly without affecting the Labels form view.</span>
                            </div>
                        </div>
                    </div>
                    <div class="editor-empty-state" id="labels-override-empty-state">
                        <p>No override file exists at this location yet.</p>
                        <button class="editor-action-btn" onclick="createOverrideTemplate()">Create Override Template</button>
                    </div>
                    <div class="editor-file-wrap labels-override-editor-wrap" id="labels-override-editor-wrap">
                        <div id="editor-override" style="width:100%;height:100%;"></div>
                    </div>
                    <div class="editor-validation" id="editor-validation-override">
                        <i class="fa fa-check editor-validation-icon"></i> Ready
                    </div>
                </div>
            </div>

            <!-- ========== SETTINGS PANEL ========== -->
            <div class="editor-panel" id="editor-panel-settings" role="tabpanel" aria-labelledby="editor-tab-settings">
                <div class="settings-panel">
                    <!-- Stack Identity -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-info-circle"></i> Stack Identity</div>

                        <div class="settings-field">
                            <label for="settings-name">Stack Name</label>
                            <input type="text" id="settings-name" placeholder="Enter stack name">
                            <div class="settings-field-help">Display name shown in the UI. Does not affect the project folder name.</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-description">Description</label>
                            <textarea id="settings-description" placeholder="Enter description for this stack"></textarea>
                            <div class="settings-field-help">Brief description of what this stack does.</div>
                        </div>
                    </div>

                    <!-- Appearance -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-picture-o"></i> Appearance</div>

                        <div class="settings-field">
                            <label for="settings-icon-url">Icon URL / Path</label>
                            <input type="text" id="settings-icon-url" placeholder="https://example.com/icon.png or /path/to/icon.png" data-pickroot="/" data-picktop="/boot/config/plugins/compose.manager/projects" data-pickcloseonfile="true" data-pickfilter="png,jpg,jpeg,gif,svg,ico,webp">
                            <div class="settings-field-help">URL or local path to a custom icon for this stack. Use the file picker or enter a URL. Leave empty to use the default icon.</div>
                            <div class="settings-field-icon-preview" id="settings-icon-preview" style="display:none;">
                                <span>Preview:</span>
                                <img id="settings-icon-preview-img" src="" alt="Icon preview" onerror="this.parentElement.style.display='none';">
                            </div>
                        </div>
                    </div>

                    <!-- Stack WebUI -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-globe"></i> Stack WebUI</div>

                        <div class="settings-field">
                            <label for="settings-webui-url">WebUI URL</label>
                            <input type="text" id="settings-webui-url" placeholder="http://tower.local:8080/">
                            <div class="settings-field-help">URL to the main WebUI for this stack. This adds a "WebUI" option to the stack's context menu. </div>
                            <div id="settings-webui-suggestion" style="display:none; margin-top:4px; padding:6px 10px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,165,0,0.3); border-radius:4px; font-size:0.9em;">
                                <span style="color:#aaa;">Detected: </span><code id="settings-webui-detected-url" style="user-select:all;"></code>
                                <span id="settings-webui-detected-source" style="color:#888; margin-left:6px; font-size:0.85em;"></span>
                                <button type="button" id="settings-webui-use-btn" class="btn btn-sm" style="margin-left:8px; padding:1px 10px; font-size:0.85em;">Use</button>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-sliders"></i> Advanced</div>

                        <div class="settings-field">
                            <label for="settings-external-compose-path">External Compose Path</label>
                            <input type="text" id="settings-external-compose-path" placeholder="Default (uses compose file in project folder)" data-pickroot="/" data-picktop="/mnt" data-pickfolders="true" data-pickcloseonfile="true">
                            <div class="settings-field-help">Path to an external folder containing your compose file(s) (e.g., /mnt/user/appdata/myapp/). The folder must contain a file matching *compose*.yml. Leave empty to use the compose file stored in the project folder.</div>
                            <div id="settings-invalid-indirect-warning" class="compose-status-danger" style="margin-top:8px;display:none;padding:8px 12px;border-radius:4px;">
                                <span class="compose-status-danger" style="font-size:0.9em;"><i class="fa fa-exclamation-triangle"></i> <strong>Invalid external path.</strong> The path shown above is broken or the directory was not found. Correct the path and save to restore the stack, or clear it to use a local compose file instead.</span>
                            </div>
                            <div id="settings-external-compose-info" style="margin-top:8px;display:none;">
                                <span class="compose-status-warning" style="font-size:0.9em;"><i class="fa fa-info-circle"></i> This stack uses an external compose file. The Compose editor tab will load the file from the external path.</span>
                            </div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-external-compose-file">External Compose File</label>
                            <input type="text" id="settings-external-compose-file" placeholder="Optional specific compose file path" data-pickroot="/" data-picktop="/mnt" data-pickcloseonfile="true" data-pickfilter="yml,yaml">
                            <div class="settings-field-help">Path to a specific external compose file (e.g., /mnt/user/appdata/myapp/custom.compose.yml). Leave empty to use folder mode or local project files.</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-extra-compose-candidates">Additional Compose Files</label>
                            <div id="settings-extra-compose-candidates" style="display:none;"></div>
                            <div id="settings-extra-compose-none" class="settings-field-help" style="display:none;">No additional compose files found in the compose source folder (looking for <code>*compose*.yml</code> / <code>*compose*.yaml</code>).</div>
                            <details id="settings-extra-compose-external-wrap" style="margin-top:8px;">
                                <summary style="cursor:pointer;">External files (advanced)</summary>
                                <textarea id="settings-extra-compose-external" rows="2" placeholder="One absolute path per line, e.g. /mnt/user/appdata/shared/gpu.compose.yml"></textarea>
                            </details>
                            <div class="settings-field-help">Selected files are appended as additional <code>-f</code> flags after the main compose and override files (e.g., GPU or environment overrides). Setting this disables default file discovery.</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-env-path">External ENV File Path</label>
                            <input type="text" id="settings-env-path" placeholder="Default (uses .env in compose source folder)" data-pickroot="/" data-picktop="/mnt" data-pickcloseonfile="true">
                            <div class="settings-field-help">Path to an external .env file (e.g., /mnt/user/appdata/myapp/.env). Leave empty to use the default .env file in the compose source folder (project folder or indirect folder).</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-default-profile">Default Profile(s)</label>
                            <input type="text" id="settings-default-profile" placeholder="Leave empty for all services">
                            <div class="settings-field-help">
                                Comma-separated list of profiles to use by default for Autostart and multi-stack operations (e.g., "production,monitoring").
                                <br>Leave empty to start all services. Available profiles are auto-detected from your compose file.
                            </div>
                            <div id="settings-available-profiles" style="margin-top:8px;display:none;">
                                <span class="compose-text-muted" style="font-size:0.9em;">Available profiles: </span>
                                <span id="settings-profiles-list" style="font-family:var(--font-bitstream);"></span>
                            </div>
                        </div>

                        <div class="settings-field">
                            <label for="REMOVE_ORPHANS_DEFAULT">Remove Orphans by Default</label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                <input type="checkbox" id="REMOVE_ORPHANS_DEFAULT" name="REMOVE_ORPHANS_DEFAULT" <?= ($cfg['REMOVE_ORPHANS_DEFAULT'] ?? 'false') == 'true' ? 'checked' : '' ?>>
                                Enable <code>--remove-orphans</code> by default for Compose Up/Down actions
                            </label>
                            <div class="settings-field-help">When enabled, the Remove orphans option is pre-checked in Compose Up/Down dialogs and bulk start/stop dialogs. Useful when a stack is edited while containers still exist.</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-use-default-compose-files">Compose File Selection</label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                <input type="checkbox" id="settings-use-default-compose-files">
                                Use Docker Compose default file discovery (no explicit <code>-f</code> flags)
                            </label>
                            <div class="settings-field-help">Enable this for projects that rely on auto-loaded <code>compose.override.*</code> and/or <code>COMPOSE_FILE</code> defined in <code>.env</code>. Leave disabled to keep explicit file selection behavior.</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-override-management">Override File Management</label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                <input type="checkbox" id="settings-override-management">
                                <span id="settings-override-management-label">Automatic</span>
                            </label>
                            <div class="settings-field-help">When <strong>Automatic</strong>, the plugin manages the override file in the project directory — label edits and background maintenance are applied automatically. When <strong>Manual</strong>, you control the override file directly; all automated edits are blocked and the Labels tab opens the raw override editor instead of the form view.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="editor-modal-footer">
                <div class="editor-footer-left">
                    <div class="editor-paths" id="editor-file-info">
                        <div class="editor-path-row">
                            <span class="editor-path-label">Project Dir</span>
                            <span class="editor-path-value" id="editor-project-dir"></span>
                        </div>
                        <div class="editor-path-row">
                            <span class="editor-path-label">Editing File</span>
                            <span class="editor-path-value" id="editor-edit-file"></span>
                        </div>
                    </div>
                    <span class="editor-shortcuts">
                        <kbd>Ctrl+S</kbd> Save &nbsp; <kbd>Esc</kbd> Close
                    </span>
                </div>
                <div class="editor-footer-right">
                    <span class="editor-change-count" id="editor-change-count">0 changes</span>
                    <button class="editor-btn editor-btn-save-all" id="editor-btn-okay" onclick="handleOkayAction()">Okay</button>
                    <button class="editor-btn editor-btn-save-all" id="editor-btn-apply" onclick="saveAllChanges(false)" disabled>Apply</button>
                    <button class="editor-btn editor-btn-cancel" id="editor-btn-close" onclick="closeEditorModal()">Close</button>
                </div>
            </div>
        </div>
    </div>

</BODY>

</HTML>