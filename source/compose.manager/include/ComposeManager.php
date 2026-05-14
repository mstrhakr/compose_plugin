<?PHP

/**
 * Compose Manager Main Page
 * The stack list is loaded asynchronously via AJAX for better UX
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");

// Load plugin config
$cfg = parse_plugin_cfg($sName);
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

<?php /* ── Critical inline CSS ──────────────────────────────────────────────
   Guarantees table-layout, column widths, and advanced/basic visibility
   are applied synchronously BEFORE any HTML renders — prevents FOUC.
   Non-critical styles remain in comboButton.css loaded via <link>. */ ?>
<style>
    /* Table structure — always fixed layout */
    #compose_stacks {
        width: 100%;
        table-layout: fixed
    }

    /* Stabilize header row height across basic/advanced toggle transitions */
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

    /* Clip overflowing content in fixed-layout cells */
    #compose_stacks th,
    #compose_stacks td {
        overflow: hidden;
        text-overflow: ellipsis
    }

    /* Basic-view column widths (7 visible columns)
   Arrow + Icon are fixed px (small fixed content); rest are % of table. */
    #compose_stacks thead th.col-arrow {
        width: 15px;
        padding: 0;
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
        width: 30px;
        padding: 0;
    }

    #compose_stacks thead th.col-name {
        width: 12%;
    }

    #compose_stacks thead th.col-update {
        width: 25%;
    }

    #compose_stacks thead th.col-containers {
        width: 25%;
    }

    #compose_stacks thead th.col-uptime {
        width: 25%;
    }

    #compose_stacks thead th.col-autostart {
        width: 15%;
    }

    /* Advanced-view column widths (10 visible columns)
   Arrow + Icon stay fixed px; Description + Path get the most %. */
    #compose_stacks.cm-advanced-view thead th.col-arrow {
        width: 15px;
    }

    #compose_stacks.cm-advanced-view thead th.col-icon {
        width: 30px;
    }

    #compose_stacks.cm-advanced-view thead th.col-name {
        width: 12%;
    }

    #compose_stacks.cm-advanced-view thead th.col-update {
        width: 10%
    }

    #compose_stacks.cm-advanced-view thead th.col-containers {
        width: 5%
    }

    #compose_stacks.cm-advanced-view thead th.col-uptime {
        width: 6%
    }

    #compose_stacks.cm-advanced-view thead th.col-load {
        width: 12%
    }

    #compose_stacks.cm-advanced-view thead th.col-description {
        width: 22%
    }

    #compose_stacks.cm-advanced-view thead th.col-path {
        width: 22%
    }

    #compose_stacks.cm-advanced-view thead th.col-autostart {
        width: 8%
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

    /* Advanced/basic visibility — CSS-only so no flash of hidden content */
    #compose_stacks .cm-advanced {
        display: none
    }

    #compose_stacks.cm-advanced-view .cm-advanced {
        display: table-cell
    }

    #compose_stacks.cm-advanced-view div.cm-advanced {
        display: block
    }

    /* Detail row */
    #compose_stacks .stack-details-cell {
        width: auto !important
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

    /* Keep long context menus visible above fixed bottom UI bars */
    .dropdown-context:not(.dropdown-context-sub) {
        max-height: calc(100vh - 72px);
        overflow-y: auto;
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
<script>
    var compose_root = <?php echo json_encode($compose_root); ?>;
    var caURL = "/plugins/compose.manager/include/Exec.php";
    var compURL = "/plugins/compose.manager/include/ComposeUtil.php";
    var aceTheme = <?php echo (in_array($theme, ['black', 'gray']) ? json_encode('ace/theme/tomorrow_night') : json_encode('ace/theme/tomorrow')); ?>;
    var aceBasePath = <?php echo json_encode($acePath); ?>;
    const icon_label = <?php echo json_encode($docker_label_icon); ?>;

    // Configure Ace base path explicitly so it finds mode/theme files
    // regardless of how the script URL was resolved
    if (typeof ace !== 'undefined') {
        ace.config.set('basePath', aceBasePath);
    }
    const webui_label = <?php echo json_encode($docker_label_webui); ?>;
    const shell_label = <?php echo json_encode($docker_label_shell); ?>;

    // Auto-check settings from config
    var autoCheckUpdates = <?php echo json_encode($autoCheckUpdates); ?>;
    var autoCheckDays = <?php echo json_encode($autoCheckDays); ?>;
    var showComposeOnTop = <?php echo json_encode($showComposeOnTop); ?>;
    var hideComposeFromDocker = <?php echo json_encode($hideComposeFromDocker); ?>;
    var composeCliVersion = <?php echo json_encode($composeVersion); ?>;
    var composeSystemMemBytes = <?php echo json_encode($composeSystemMemBytes); ?>;
    var composeCpuCount = <?php echo json_encode($cpuCount); ?>;

    // Parse a single memory value (for example "123.4MiB" or "512MB") to bytes.
    // Supports both IEC (KiB, MiB, GiB, TiB) and SI (kB, MB, GB, TB) suffixes.
    function parseMemValueToBytes(memVal) {
        if (!memVal) return 0;
        var cleaned = String(memVal).trim();
        if (!cleaned) return 0;
        var match = cleaned.match(/([\d.]+)\s*([kmgt]?i?b)?/i);
        if (!match) return 0;

        var num = parseFloat(match[1]);
        if (!isFinite(num)) return 0;
        var unit = (match[2] || 'b').toLowerCase();

        switch (unit) {
            case 'tb':
                return num * 1000000000000;
            case 'tib':
                return num * 1099511627776;
            case 'gb':
                return num * 1000000000;
            case 'gib':
                return num * 1073741824;
            case 'mb':
                return num * 1000000;
            case 'mib':
                return num * 1048576;
            case 'kb':
                return num * 1000;
            case 'kib':
                return num * 1024;
            default:
                return num;
        }
    }

    // Parse docker stats memory string "used / limit" into bytes.
    function parseMemUsagePair(memStr) {
        if (!memStr) return {
            used: 0,
            limit: 0
        };
        var parts = String(memStr).split('/');
        var used = parseMemValueToBytes(parts[0] || '');
        var limit = parseMemValueToBytes(parts[1] || '');
        return {
            used: used,
            limit: limit
        };
    }

    // Backward-compatible helper used by existing code paths.
    function parseMemToBytes(memStr) {
        return parseMemUsagePair(memStr).used;
    }

    // Format bytes to human-readable string with fixed 2 decimals.
    function formatBytes(bytes) {
        var val = Number(bytes) || 0;
        if (val < 0) val = 0;
        if (val >= 1073741824) return (val / 1073741824).toFixed(2) + 'GiB';
        if (val >= 1048576) return (val / 1048576).toFixed(2) + 'MiB';
        if (val >= 1024) return (val / 1024).toFixed(2) + 'KiB';
        return val.toFixed(2) + 'B';
    }

    function formatCpuPercent(value) {
        var num = Number(value) || 0;
        return num.toFixed(2) + '%';
    }

    function formatMemUsageText(usedBytes, limitBytes) {
        return formatBytes(usedBytes) + ' / ' + formatBytes(limitBytes);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Standard factory functions for container and stack identity objects
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Create a normalized container info object from any raw source.
     * Handles PascalCase→camelCase, resolves name from multiple field aliases,
     * and derives hasUpdate/isPinned when not explicitly set.
     *
     * @param {Object} raw - Raw container object (from server, cache, or update response)
     * @returns {Object} Normalized container info
     */
    function createContainerInfo(raw) {
        if (!raw) return null;
        var name = raw.name || raw.Name || raw.container || raw.Service || raw.service || '';
        var service = raw.service || raw.Service || name;
        var updateStatus = raw.updateStatus || raw.UpdateStatus || '';
        var hasUpdate = (raw.hasUpdate !== undefined) ? !!raw.hasUpdate : (updateStatus === 'update-available');

        return {
            name: name,
            service: service,
            image: raw.image || raw.Image || '',
            state: raw.state || raw.State || '',
            isRunning: (raw.state || raw.State || '') === 'running',
            hasUpdate: hasUpdate,
            updateStatus: updateStatus,
            localSha: raw.localSha || raw.LocalSha || '',
            remoteSha: raw.remoteSha || raw.RemoteSha || '',
            isPinned: (raw.isPinned !== undefined) ? !!raw.isPinned : false,
            pinnedDigest: raw.pinnedDigest || raw.PinnedDigest || '',
            icon: raw.icon || raw.Icon || '',
            shell: raw.shell || raw.Shell || '/bin/bash',
            webUI: raw.webUI || raw.WebUI || '',
            ports: raw.ports || raw.Ports || [],
            networks: raw.networks || raw.Networks || [],
            volumes: raw.volumes || raw.Volumes || [],
            id: raw.id || raw.Id || raw.ID || '',
            created: raw.created || raw.Created || '',
            startedAt: raw.startedAt || raw.StartedAt || ''
        };
    }

    /**
     * Create a normalized stack info object.
     *
     * @param {string} project - The project/stack folder name
     * @param {Array} containers - Array of raw container objects (will be normalized)
     * @param {Object} [opts] - Optional overrides (totalServices, lastChecked, etc.)
     * @returns {Object} Normalized stack info
     */
    function createStackInfo(project, containers, opts) {
        opts = opts || {};
        var normalized = (containers || []).map(createContainerInfo).filter(Boolean);
        var isRunning = normalized.some(function(c) {
            return c.isRunning;
        });
        var hasUpdate = normalized.some(function(c) {
            return c.hasUpdate;
        });

        return {
            projectName: opts.projectName || project,
            containers: normalized,
            isRunning: isRunning,
            hasUpdate: (opts.hasUpdate !== undefined) ? opts.hasUpdate : hasUpdate,
            totalServices: opts.totalServices || normalized.length,
            lastChecked: opts.lastChecked || null
        };
    }

    /**
     * Merge update status info from a previous stackInfo into a new one.
     * Matches containers by name and copies update fields.
     *
     * @param {Object} stackInfo - The target stack info (mutated in place)
     * @param {Object} prevStatus - Previously saved stack update status
     * @returns {Object} The mutated stackInfo
     */
    function mergeStackUpdateStatus(stackInfo, prevStatus) {
        if (!prevStatus) return stackInfo;

        // Copy stack-level fields
        ['lastChecked', 'updateAvailable', 'checking', 'checked'].forEach(function(k) {
            if (typeof prevStatus[k] !== 'undefined') stackInfo[k] = prevStatus[k];
        });

        // Merge container-level update data
        if (prevStatus.containers && stackInfo.containers) {
            stackInfo.containers.forEach(function(c) {
                var cName = c.name;
                prevStatus.containers.forEach(function(pc) {
                    var prev = (typeof pc.name === 'string') ? pc : createContainerInfo(pc);
                    if (cName === prev.name) {
                        if (prev.hasUpdate && !c.hasUpdate) c.hasUpdate = prev.hasUpdate;
                        if (prev.updateStatus && !c.updateStatus) c.updateStatus = prev.updateStatus;
                        if (prev.localSha && !c.localSha) c.localSha = prev.localSha;
                        if (prev.remoteSha && !c.remoteSha) c.remoteSha = prev.remoteSha;
                        if (prev.isPinned !== undefined) c.isPinned = prev.isPinned;
                    }
                });
            });
            // Recompute stack-level hasUpdate from merged containers
            stackInfo.hasUpdate = stackInfo.containers.some(function(c) {
                return c.hasUpdate;
            });
        }

        return stackInfo;
    }

    // ═══════════════════════════════════════════════════════════════════

    // Timers for async operations (plugin-specific to avoid collision with Unraid's global timers)
    var composeTimers = {};

    function showComposeSpinner() {
        var $spinner = $('div.spinner.fixed');
        if (!$spinner.length) {
            return;
        }
        $spinner.css({
            'z-index': 100000
        });
        $spinner.stop(true, true).show('slow');
    }

    function hideComposeSpinner() {
        var $spinner = $('div.spinner.fixed');
        if (!$spinner.length) {
            return;
        }
        $spinner.stop(true, true).hide('slow');
    }

    // Load stack list asynchronously (namespaced to avoid conflict with Docker tab's loadlist)
    function composeLoadlist() {
        // Return a Promise so callers can reliably .then() / .catch() on completion
        return new Promise(function(resolve, reject) {
            composeTimers.load = setTimeout(function() {
                showComposeSpinner('Loading stack list...');
            }, 500);

            $.get('/plugins/compose.manager/include/ComposeList.php')
                .done(function(data) {
                    clearTimeout(composeTimers.load);

                    // Insert the loaded content
                    $('#compose_list').html(data);

                    // Signal load subscribers (e.g. dockerload cache) that the list changed
                    $(document).trigger('composeListRefreshed');

                    // Initialize UI components for the newly loaded content
                    initStackListUI();

                    // Debug: log initial per-stack rendered status icons (data-status attribute)
                    try {
                        var initialStatuses = [];
                        $('#compose_stacks tr.compose-sortable').each(function() {
                            var project = $(this).data('project');
                            var icon = $(this).find('.compose-status-icon').first();
                            var status = icon.attr('data-status') || icon.attr('class') || '';
                            initialStatuses.push({
                                project: project,
                                status: status
                            });
                        });
                        composeLogger('initial-stack-statuses', {
                            stacks: initialStatuses
                        }, 'user', 'debug', 'composeLoadlist');
                    } catch (e) {}

                    // Normalize icons based on state text to ensure server-side render and
                    // client-side update logic agree (workaround for caching or older server HTML)
                    try {
                        $('#compose_stacks tr.compose-sortable').each(function() {
                            var $row = $(this);
                            var stateText = $row.find('.state').text().toLowerCase();
                            var $icon = $row.find('.compose-status-icon').first();
                            if (!$icon.length) return;
                            var desiredShape = null;
                            var desiredColor = 'grey-text';
                            if (stateText.indexOf('partial') !== -1) {
                                desiredShape = 'exclamation-circle';
                                desiredColor = 'orange-text';
                            } else if (stateText.indexOf('started') !== -1) {
                                desiredShape = 'play';
                                desiredColor = 'green-text';
                            } else if (stateText.indexOf('paused') !== -1) {
                                desiredShape = 'pause';
                                desiredColor = 'orange-text';
                            } else {
                                desiredShape = 'square';
                                desiredColor = 'grey-text';
                            }

                            // If icon already matches, skip
                            if (($icon.hasClass('fa-' + desiredShape) && $icon.hasClass(desiredColor))) return;

                            composeLogger('normalize-icon', {
                                project: $row.data('project'),
                                stateText: stateText,
                                desiredShape: desiredShape,
                                desiredColor: desiredColor,
                                before: $icon.attr('class')
                            }, 'user', 'debug', 'composeLoadlist');
                            // Remove old fa-* classes, color classes and apply desired ones
                            $icon.removeClass(function(i, cls) {
                                return (cls.match(/fa-[^\s]+/g) || []).join(' ');
                            });
                            $icon.removeClass('green-text orange-text grey-text cyan-text');
                            $icon.addClass('fa fa-' + desiredShape + ' ' + desiredColor + ' compose-status-icon');
                            composeLogger('normalize-icon-done', {
                                project: $row.data('project'),
                                after: $icon.attr('class')
                            }, 'user', 'debug', 'composeLoadlist');
                        });
                    } catch (e) {}

                    // Cleanup any temporary per-container spinners or leftover in-progress state
                    try {
                        $('#compose_list').find('.compose-container-spinner').each(function() {
                            var $sp = $(this);
                            var $wrap = $sp.closest('.hand');
                            $sp.remove();
                            $wrap.find('img').css('opacity', 1);
                        });
                        // Restore any state text preserved by setStackActionInProgress
                        $('#compose_stacks .state').each(function() {
                            var $s = $(this);
                            if ($s.data('orig-text')) {
                                $s.text($s.data('orig-text'));
                                $s.removeData('orig-text');
                            }
                        });
                    } catch (e) {}

                    // Hide compose spinner overlay
                    hideComposeSpinner();

                    // Show buttons now that content is loaded
                    $('input[type=button]').show();

                    // Notify other features (e.g. hide-from-docker) that compose list is ready
                    $(document).trigger('compose-list-loaded');

                    // Resolve the promise so callers know the list has been loaded
                    try {
                        resolve(data);
                    } catch (e) {
                        resolve();
                    }
                })
                .fail(function(xhr, status, error) {
                    composeLogger('failed', {
                        status: status,
                        error: error
                    }, 'user', 'error', 'composeLoadlist');
                    clearTimeout(composeTimers.load);
                    hideComposeSpinner();
                    $('#compose_list').html('<tr><td colspan="10" class="compose-status-danger" style="text-align:center;padding:20px;">Failed to load stack list. Please refresh the page.</td></tr>');

                    // Reject the promise so callers can handle the error
                    try {
                        reject({
                            xhr: xhr,
                            status: status,
                            error: error
                        });
                    } catch (e) {
                        reject(error);
                    }
                });
        });
    }

    // Sortable functions loaded from composeSortable.js

    // Initialize UI components after stack list is loaded
    function initStackListUI() {
        // Initialize autostart switches - scope to compose_list to avoid conflict with Docker tab
        // Avoid re-initializing switchButton on elements that may be re-rendered.
        $('#compose_list .auto_start').each(function() {
            var $el = $(this);
            if ($el.data('switchbutton-initialized')) return;
            $el.switchButton({
                labels_placement: 'right',
                on_label: "On",
                off_label: "Off",
                clear: false
            });
            $el.data('switchbutton-initialized', true);
        });
        // Ensure change handler is bound only once
        $('#compose_list').off('change', '.auto_start').on('change', '.auto_start', function() {
            var script = $(this).attr("data-scriptname");
            var auto = $(this).prop('checked');
            $.post(caURL, {
                action: 'updateAutostart',
                script: script,
                autostart: auto
            });
        });

        // Initialize context menus for stack icons
        $('[id^="stack-"][data-stackid]').each(function() {
            addComposeStackContext(this.id);
        });

        // Apply readmore to descriptions - scope to compose_stacks, exclude container detail rows
        var $readmoreEls = $('#compose_stacks .docker_readmore').not('.stack-details-container .docker_readmore');
        $readmoreEls.readmore('destroy');
        $readmoreEls.readmore({
            maxHeight: 32,
            moreLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",
            lessLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"
        });

        // Apply current view mode (advanced/basic) with centralized logic
        applyListView(false);

        // Seed expandedStacks from any rows rendered expanded server-side
        $('.stack-details-row:visible').each(function() {
            var stackId = this.id.replace('details-row-', '');
            expandedStacks[stackId] = true;
        });

        // Load saved update status after list is loaded
        loadSavedUpdateStatus();

        syncComposeSortModeUI();
    }

    // Load external stylesheets (non-critical styles — critical ones are inline above)
    (function() {
        var base = '<?php autov("/plugins/compose.manager/sheets/ComboButton.css"); ?>';
        var editor = '<?php autov("/plugins/compose.manager/sheets/EditorModal.css"); ?>';
        if (!$('link[href="' + base + '"]').length)
            $('head').append($('<link rel="stylesheet" type="text/css" />').attr('href', base));
        if (!$('link[href="' + editor + '"]').length)
            $('head').append($('<link rel="stylesheet" type="text/css" />').attr('href', editor));
    })();

    function basename(path) {
        return path.replace(/\\/g, '/').replace(/.*\//, '');
    }

    function dirname(path) {
        return path.replace(/\\/g, '/').replace(/\/[^\/]*$/, '');
    }

    // Safely attempt to parse a JSON string; returns null on failure
    function tryParseJson(str) {
        if (!str || typeof str !== 'string') return null;
        try {
            return JSON.parse(str);
        } catch (e) {
            return null;
        }
    }

    // Editor modal state
    var editorModal = {
        editors: {},
        currentTab: 'compose',
        originalContent: {},
        modifiedTabs: new Set(),
        currentProject: null,
        validationTimeout: null,
        // Settings state
        originalSettings: {},
        modifiedSettings: new Set(),
        // Labels state
        originalLabels: {},
        modifiedLabels: new Set(),
        labelsData: null // Stores the parsed compose and override data
    };

    // Debounce helper for validation
    function debounceValidation(type, content) {
        if (editorModal.validationTimeout) {
            clearTimeout(editorModal.validationTimeout);
        }
        editorModal.validationTimeout = setTimeout(function() {
            validateYaml(type, content);
        }, 300);
    }

    // Calculate unRAID header offset dynamically
    function updateModalOffset() {
        var headerOffset = 0;
        var header = document.getElementById('header');
        var menu = document.getElementById('menu');
        var tabs = document.querySelector('div.tabs');

        if (header) {
            headerOffset += header.offsetHeight;
        }
        if (menu) {
            headerOffset += menu.offsetHeight;
        }
        if (tabs) {
            headerOffset += tabs.offsetHeight;
        }

        // Add a small buffer
        headerOffset += 10;

        // Set CSS custom property
        document.documentElement.style.setProperty('--unraid-header-offset', headerOffset + 'px');
        var overlay = document.getElementById('editor-modal-overlay');
        if (overlay) {
            overlay.style.setProperty('--unraid-header-offset', headerOffset + 'px');
        }
    }

    // Shared helpers for file-tree picker positioning and scroll tracking are now in common.js
    // Please reference composePositionFileTreeForInput, composeTrackFileTreeForInput, composeBindFileTreeInputs from common.js

    // Initialize editor modal
    function initEditorModal() {
        if (typeof ace === 'undefined') {
            composeLogger('Ace editor not available. Stack editor is unavailable.', null, 'user', 'warn', 'initEditorModal');
            return;
        }
        // Initialize Ace editors for compose and env tabs only
        ['compose', 'env'].forEach(function(type) {
            var editor = ace.edit('editor-' + type);
            editor.setTheme(aceTheme);
            editor.setShowPrintMargin(false);
            editor.setOptions({
                fontSize: '1.1rem',
                tabSize: 2,
                useSoftTabs: true,
                wrap: true
            });

            // Disable workers to avoid loading worker-yaml.js / worker-sh.js —
            // we already validate YAML client-side via js-yaml
            editor.getSession().setUseWorker(false);

            // Set mode based on type
            if (type === 'env') {
                editor.getSession().setMode('ace/mode/sh');
            } else {
                editor.getSession().setMode('ace/mode/yaml');
            }

            // Track modifications
            editor.on('change', function() {
                var currentContent = editor.getValue();
                var originalContent = editorModal.originalContent[type] || '';
                var tabEl = $('#editor-tab-' + type);

                if (currentContent !== originalContent) {
                    editorModal.modifiedTabs.add(type);
                    tabEl.addClass('modified');
                } else {
                    editorModal.modifiedTabs.delete(type);
                    tabEl.removeClass('modified');
                }

                updateSaveButtonState();
                updateTabModifiedState();
                debounceValidation(type, currentContent);
            });

            editorModal.editors[type] = editor;
        });

        // Initialize settings field change tracking
        $('#settings-name, #settings-description, #settings-icon-url, #settings-webui-url, #settings-env-path, #settings-default-profile, #settings-external-compose-path').on('input change', function() {
            var fieldId = this.id.replace('settings-', '');
            var currentValue = $(this).val();
            var originalValue = editorModal.originalSettings[fieldId] || '';

            if (currentValue !== originalValue) {
                editorModal.modifiedSettings.add(fieldId);
            } else {
                editorModal.modifiedSettings.delete(fieldId);
            }

            updateSaveButtonState();
            updateTabModifiedState();
        });

        // Icon preview update with debounce
        var settingsIconDebounce = null;
        $('#settings-icon-url').on('input', function() {
            var $input = $(this);
            clearTimeout(settingsIconDebounce);
            settingsIconDebounce = setTimeout(function() {
                var url = $input.val().trim();
                if (url && isValidIconSrc(url)) {
                    $('#settings-icon-preview-img').attr('src', url);
                    $('#settings-icon-preview').show();
                } else {
                    $('#settings-icon-preview').hide();
                }
            }, 300);
        });

        // External compose path info toggle
        $('#settings-external-compose-path').on('input', function() {
            var path = $(this).val().trim();
            if (path) {
                $('#settings-external-compose-info').show();
            } else {
                $('#settings-external-compose-info').hide();
            }
        });

        // Keyboard shortcuts - use namespaced event to avoid duplicates
        $(document).off('keydown.editorModal').on('keydown.editorModal', function(e) {
            if ($('#editor-modal-overlay').hasClass('active')) {
                // Ctrl+S or Cmd+S to save current
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    saveCurrentTab();
                }
                // Escape to close
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeEditorModal();
                }
                // Arrow key navigation for tabs
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    var $activeTab = $('.editor-tab.active');
                    if ($activeTab.is(':focus') || $activeTab.parent().find(':focus').length) {
                        e.preventDefault();
                        var tabs = ['compose', 'env', 'labels', 'settings'];
                        var currentIdx = tabs.indexOf(editorModal.currentTab);
                        var newIdx;
                        if (e.key === 'ArrowLeft') {
                            newIdx = currentIdx > 0 ? currentIdx - 1 : tabs.length - 1;
                        } else {
                            newIdx = currentIdx < tabs.length - 1 ? currentIdx + 1 : 0;
                        }
                        switchTab(tabs[newIdx]);
                        $('#editor-tab-' + tabs[newIdx]).focus();
                    }
                }
                // Focus trapping
                if (e.key === 'Tab') {
                    var $modal = $('#editor-modal-overlay');
                    var $focusable = $modal.find('a, button, input, textarea, select, [tabindex]:not([tabindex="-1"])').filter(':visible:not(:disabled)');
                    if ($focusable.length === 0) return;
                    var first = $focusable[0];
                    var last = $focusable[$focusable.length - 1];
                    var activeElement = document.activeElement;

                    if (!$.contains($modal[0], activeElement)) {
                        e.preventDefault();
                        first.focus();
                        return;
                    }

                    if (!e.shiftKey && activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    } else if (e.shiftKey && activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    }
                }
            }
        });

        // Close modal when clicking on the overlay background (not the inner modal content)
        $('#editor-modal-overlay').off('click.editorModal').on('click.editorModal', function(e) {
            if (e.target === this) {
                closeEditorModal();
            }
        });
    }

    // Switch between tabs (compose / env / labels / settings)
    function switchTab(tabName) {
        var validTabs = ['compose', 'env', 'labels', 'settings'];
        if (validTabs.indexOf(tabName) === -1) {
            composeLogger('Invalid tab name: ' + tabName, null, 'user', 'error', 'switchTab');
            return;
        }

        // Update tab buttons
        $('.editor-tab').removeClass('active').attr('aria-selected', 'false');
        $('#editor-tab-' + tabName).addClass('active').attr('aria-selected', 'true');

        // Update panels and ensure inline display is correct (inline style may be persisted from tab host)
        $('.editor-panel').each(function() {
            var $panel = $(this);
            if ($panel.attr('id') === 'editor-panel-' + tabName) {
                $panel.addClass('active').css('display', 'flex');
            } else {
                $panel.removeClass('active').css('display', 'none');
            }
        });

        editorModal.currentTab = tabName;

        // Resize and focus editor if switching to compose or env tab
        if ((tabName === 'compose' || tabName === 'env') && editorModal.editors[tabName]) {
            try {
                editorModal.editors[tabName].resize();
                editorModal.editors[tabName].renderer.updateFull();
                editorModal.editors[tabName].focus();
            } catch (e) {
                composeLogger('Editor resize failed', {
                    error: e && e.toString(),
                    tab: type
                }, 'user', 'warn', 'switchTab');
            }
        }

        // Load labels data if switching to labels tab for the first time
        if (tabName === 'labels' && !editorModal.labelsData) {
            loadLabelsData();
        }
    }

    function refreshEditorContents(type) {
        if (!editorModal.editors[type]) return;
        try {
            editorModal.editors[type].resize();
            editorModal.editors[type].renderer.updateFull();
        } catch (e) {
            composeLogger('refreshEditorContents failed for ' + type, {
                error: e && e.toString()
            }, 'user', 'warn', 'refreshEditorContents');
        }
    }

    // Update the modified indicator on tabs
    function updateTabModifiedState() {
        // Compose tab
        if (editorModal.modifiedTabs.has('compose')) {
            $('#editor-tab-compose').addClass('modified');
        } else {
            $('#editor-tab-compose').removeClass('modified');
        }

        // Env tab
        if (editorModal.modifiedTabs.has('env')) {
            $('#editor-tab-env').addClass('modified');
        } else {
            $('#editor-tab-env').removeClass('modified');
        }

        // Labels tab
        if (editorModal.modifiedLabels.size > 0) {
            $('#editor-tab-labels').addClass('modified');
        } else {
            $('#editor-tab-labels').removeClass('modified');
        }

        // Settings tab
        if (editorModal.modifiedSettings.size > 0) {
            $('#editor-tab-settings').addClass('modified');
        } else {
            $('#editor-tab-settings').removeClass('modified');
        }
    }

    // composeEscapeHtml / composeEscapeAttr are provided by common.js

    // Update status cache per stack
    var stackUpdateStatus = {};

    // Load saved update status from server (called on page load)
    // If auto-check is enabled and interval has elapsed, trigger a fresh check
    // Also checks for pending rechecks from recent update operations
    function loadSavedUpdateStatus() {
        $.post(caURL, {
            action: 'getSavedUpdateStatus'
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success' && response.stacks) {
                        stackUpdateStatus = response.stacks;

                        // Update the UI for each stack with saved status
                        for (var stackName in response.stacks) {
                            var stackInfo = response.stacks[stackName];
                            updateStackUpdateUI(stackName, stackInfo);
                        }

                        // Enable/disable Update All button based on saved status
                        updateUpdateAllButton();

                        // Check for pending rechecks first (from recent updates)
                        // This takes priority over auto-check interval
                        checkPendingRechecks(function(hadPendingRechecks) {
                            // Only run auto-check if there were no pending rechecks
                            if (!hadPendingRechecks && autoCheckUpdates) {
                                checkAutoUpdateIfNeeded(response.stacks);
                            }
                        });
                    } else {
                        // No saved status, check for pending rechecks or run auto-check
                        checkPendingRechecks(function(hadPendingRechecks) {
                            if (!hadPendingRechecks && autoCheckUpdates) {
                                checkAllUpdates();
                            }
                        });
                    }
                } catch (e) {
                    composeLogger('Failed to load saved update status', {
                        error: e && e.toString()
                    }, 'user', 'error', 'loadSavedUpdateStatus');
                    checkPendingRechecks(function(hadPendingRechecks) {
                        if (!hadPendingRechecks && autoCheckUpdates) {
                            checkAllUpdates();
                        }
                    });
                }
            } else {
                // No data, check for pending rechecks or run auto-check
                checkPendingRechecks(function(hadPendingRechecks) {
                    if (!hadPendingRechecks && autoCheckUpdates) {
                        checkAllUpdates();
                    }
                });
            }
        });
    }

    // Check for pending rechecks from recent update operations
    // These stacks need to be rechecked regardless of auto-check interval
    function checkPendingRechecks(callback) {
        $.post(caURL, {
            action: 'getPendingRecheckStacks'
        }, function(data) {
            var hadPendingRechecks = false;
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success' && response.pending) {
                        var pendingStacks = Object.keys(response.pending);
                        if (pendingStacks.length > 0) {
                            hadPendingRechecks = true;
                            composeLogger('checkPendingRechecks:found', {
                                pendingStacks: pendingStacks
                            }, 'user', 'info', 'update-check');

                            // Check each pending stack
                            pendingStacks.forEach(function(stackName) {
                                composeLogger('Running recheck for recently updated stack', {
                                    stackName: stackName
                                }, 'user', 'info', 'update-check');
                                checkStackUpdates(stackName);
                            });
                        }
                    }
                } catch (e) {
                    composeLogger('checkPendingRechecks:failed', {
                        error: e
                    }, 'user', 'error', 'update-check');
                }
            }
            if (callback) callback(hadPendingRechecks);
        });
    }

    // Check if auto-update check is needed based on lastChecked timestamp
    function checkAutoUpdateIfNeeded(stacks) {
        if (!autoCheckUpdates) return;

        var now = Math.floor(Date.now() / 1000); // Current time in seconds
        var intervalSeconds = autoCheckDays * 24 * 60 * 60; // Convert days to seconds
        var needsCheck = true;

        // Find the most recent lastChecked timestamp across all stacks
        var latestCheck = 0;
        for (var stackName in stacks) {
            if (stacks[stackName].lastChecked && stacks[stackName].lastChecked > latestCheck) {
                latestCheck = stacks[stackName].lastChecked;
            }
        }

        // If we have a lastChecked time and it's within the interval, don't check
        if (latestCheck > 0 && (now - latestCheck) < intervalSeconds) {
            needsCheck = false;
            composeLogger('Last check was ' + Math.round((now - latestCheck) / 60) + ' minutes ago, interval is ' + Math.round(intervalSeconds / 60) + ' minutes. Skipping.', null, 'user', 'info', 'update-check');
        }

        if (needsCheck) {
            composeLogger('Running automatic update check...', null, 'user', 'info', 'update-check');
            checkAllUpdates();
        }
    }

    // Check for updates for all stacks
    function checkAllUpdates() {
        $('#checkUpdatesBtn').prop('disabled', true).val('Checking...');
        $('#updateAllBtn').prop('disabled', true);

        // Show checking indicator only on running stack update columns (not stopped ones)
        $('#compose_stacks tr.compose-sortable').each(function() {
            var $row = $(this);
            var isRunning = $row.find('.state').text().indexOf('started') !== -1 ||
                $row.find('.state').text().indexOf('partial') !== -1;
            if (isRunning) {
                var $updateCell = $row.find('.compose-updatecolumn');
                $updateCell.html('<span class="compose-status-info"><i class="fa fa-refresh fa-spin"></i> checking...</span>');
            }
        });

        $.post(caURL, {
            action: 'checkAllStacksUpdates'
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success' && response.stacks) {
                        stackUpdateStatus = response.stacks;

                        // Update the UI for each stack
                        for (var stackName in response.stacks) {
                            var stackInfo = response.stacks[stackName];
                            updateStackUpdateUI(stackName, stackInfo);
                        }

                        // Enable/disable Update All button based on available updates
                        updateUpdateAllButton();
                    }
                } catch (e) {
                    composeLogger('Failed to parse update check response', {
                        error: e && e.toString()
                    }, 'user', 'error', 'checkAllUpdates');
                }
            }
            $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
        }).fail(function() {
            $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
            $('#updateAllBtn').prop('disabled', true);
            // Reset update columns to not checked state - scope to compose_stacks
            $('#compose_stacks .compose-updatecolumn').each(function() {
                var $cell = $(this);
                if (!$cell.find('.grey-text').length || $cell.find('.fa-docker').length === 0) {
                    // Only reset running stacks (not the "stopped" ones)
                    $cell.html('<span class="grey-text" style="white-space:nowrap;cursor:default;"><i class="fa fa-exclamation-circle fa-fw"></i> check failed</span>');
                }
            });
        });
    }

    // Check how many stacks have updates and enable/disable the Update All button
    function updateUpdateAllButton() {
        var stacksWithUpdates = 0;
        for (var stackName in stackUpdateStatus) {
            var stackInfo = stackUpdateStatus[stackName];
            if (!stackInfo.hasUpdate) continue;
            // Derive running state from DOM — saved status may be stale
            var $row = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
            if ($row.length === 0) continue;
            var stateText = $row.find('.state').text();
            var isRunning = stateText.indexOf('started') !== -1 || stateText.indexOf('partial') !== -1;
            if (isRunning) stacksWithUpdates++;
        }
        $('#updateAllBtn').prop('disabled', stacksWithUpdates === 0);
    }

    // Update All Stacks - updates all stacks that have pending updates
    function updateAllStacks() {
        var autostartOnly = $('#autostartOnlyToggle').is(':checked');
        var stacks = [];

        // Collect all stacks with updates
        for (var stackName in stackUpdateStatus) {
            var stackInfo = stackUpdateStatus[stackName];
            if (!stackInfo.hasUpdate) continue;
            var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
            if ($stackRow.length === 0) continue;
            // Derive running state from DOM — saved status may be stale
            var rowStateText = $stackRow.find('.state').text();
            if (rowStateText.indexOf('started') === -1 && rowStateText.indexOf('partial') === -1) continue;

            var autostart = $stackRow.find('.auto_start').is(':checked');

            // Skip if autostart only mode and autostart is not enabled
            if (autostartOnly && !autostart) continue;

            var path = $stackRow.data('path');
            var projectName = $stackRow.data('projectname');

            stacks.push({
                project: stackName,
                projectName: projectName,
                path: path
            });
        }

        if (stacks.length === 0) {
            swal({
                title: 'No Updates Available',
                text: autostartOnly ? 'No stacks with Autostart enabled have updates available.' : 'No stacks have updates available.',
                type: 'info'
            });
            return;
        }

        var stackNames = stacks.map(function(s) {
            return composeEscapeHtml(s.projectName);
        }).join('<br>');
        var title = autostartOnly ? 'Update Autostart Stacks?' : 'Update All Stacks?';
        var confirmText = 'Yes, update ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');

        var bgCheckboxHtml = '<div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--dynamix-box-inner-div-border-color);display:flex;align-items:center;gap:8px;">' +
            '<input type="checkbox" id="swal-run-bg-updateall" style="width:16px;height:16px;cursor:pointer;">' +
            '<label for="swal-run-bg-updateall" style="cursor:pointer;user-select:none;margin:0;font-size:0.95em;">Run in background</label>' +
            '</div>';

        getConfig().then(function(pluginCfg) {
            var bgDefault = pluginCfg && pluginCfg.RUN_IN_BACKGROUND_DEFAULT === 'true';
            var disableWarnings = pluginCfg && pluginCfg.DISABLE_ACTION_WARNINGS === 'true';

            if (disableWarnings) {
                executeUpdateAllStacks(stacks, bgDefault, bgDefault);
                return;
            }

            swal({
                title: title,
                html: true,
                text: '<div style="background:var(--alt-background-color);text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be updated:</p><div style="background:var(--background-color);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div><p class="compose-status-warning"><i class="fa fa-warning"></i> This will pull new images and recreate containers.</p></div>' + bgCheckboxHtml,
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: 'Cancel'
            }, function(confirmed) {
                if (confirmed) {
                    var runInBackground = $('#swal-run-bg-updateall').is(':checked');
                    executeUpdateAllStacks(stacks, runInBackground);
                }
            });

            setTimeout(function() {
                var $cb = $('#swal-run-bg-updateall');
                if ($cb.length) {
                    $cb.prop('checked', bgDefault);
                }
            }, 50);
        });
    }

    function executeUpdateAllStacks(stacks, background, suppressBackgroundNotification = false) {
        var height = 800;
        var width = 1200;

        // Create a list of paths to update
        var paths = stacks.map(function(s) {
            return s.path;
        });

        // Track all stacks for update check when dialog closes
        var stackNames = [];
        stacks.forEach(function(s) {
            var stackName = s.project;
            if (pendingUpdateCheckStacks.indexOf(stackName) === -1) {
                pendingUpdateCheckStacks.push(stackName);
            }
            stackNames.push(stackName);
        });

        // Mark stacks for recheck server-side (persists across page reload)
        $.post(caURL, {
            action: 'markStackForRecheck',
            stacks: JSON.stringify(stackNames)
        }, function() {
            performComposeAction({
                actionName: 'update',
                title: 'Update All Stacks',
                requestUrl: compURL,
                payload: {
                    action: 'composeUpdateMultiple',
                    paths: JSON.stringify(paths)
                },
                background: background,
                suppressBackgroundNotification: suppressBackgroundNotification,
                pendingReload: false,
                onComplete: function(parsed, data) {
                    if (parsed && parsed.background) {
                        stacks.forEach(function(s) {
                            pollBackgroundCompletion(s.project);
                        });
                    }
                }
            });
        });
    }

    // Update UI for a single stack's update status
    function updateStackUpdateUI(stackName, stackInfo) {
        // Find the stack row by project name (scoped to compose_stacks to avoid Docker tab conflicts)
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
        if ($stackRow.length === 0) return;

        var stackId = $stackRow.attr('id').replace('stack-row-', '');
        var $updateCell = $stackRow.find('.compose-updatecolumn');

        // Always derive running state from the current DOM rather than the
        // stackInfo payload.  The saved update-status file may contain a stale
        // isRunning value from when the check originally ran (e.g. the stack
        // was stopped then but has since been started).
        var stateText = $stackRow.find('.state').text();
        var isRunning = stateText.indexOf('started') !== -1 || stateText.indexOf('partial') !== -1;

        if (!isRunning) {
            // Stack is not running - show stopped
            $updateCell.html('<span class="grey-text" style="white-space:nowrap;"><i class="fa fa-stop fa-fw"></i> stopped</span>');
            return;
        }

        // Count updates and pinned containers
        var updateCount = 0;
        var pinnedCount = 0;
        var totalContainers = stackInfo.containers ? stackInfo.containers.length : 0;

        if (stackInfo.containers) {
            stackInfo.containers.forEach(function(ct) {
                if (ct.hasUpdate) updateCount++;
                if (ct.isPinned) pinnedCount++;
            });
        }

        // Update the stack row's update column (match Docker tab style)
        if (updateCount > 0) {
            // Updates available - orange "update ready" style with clickable link and SHA info
            var updateHtml = '<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + composeEscapeAttr(stackName) + '\', \'' + composeEscapeAttr(stackId) + '\');">';
            updateHtml += '<span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> ' + updateCount + ' update' + (updateCount > 1 ? 's' : '') + '</span>';
            updateHtml += '</a>';

            // Show first container's SHA diff if only one update, or indicate multiple
            if (stackInfo.containers) {
                var updatesWithSha = stackInfo.containers.filter(function(ct) {
                    return ct.hasUpdate && ct.localSha && ct.remoteSha;
                });
                if (updatesWithSha.length === 1) {
                    // Single update - show the SHA diff inline
                    var ct = updatesWithSha[0];
                    updateHtml += '<div style="font-family:var(--font-bitstream);font-size:0.8em;margin-top:2px;">';
                    updateHtml += '<span class="compose-status-warning" title="' + composeEscapeAttr(ct.localSha) + '">' + composeEscapeHtml(ct.localSha.substring(0, 8)) + '</span>';
                    updateHtml += ' <i class="fa fa-arrow-right compose-status-success" style="margin:0 2px;font-size:0.9em;"></i> ';
                    updateHtml += '<span class="compose-status-success" title="' + composeEscapeAttr(ct.remoteSha) + '">' + composeEscapeHtml(ct.remoteSha.substring(0, 8)) + '</span>';
                    updateHtml += '</div>';
                } else if (updatesWithSha.length > 1) {
                    // Multiple updates - show expand hint
                    updateHtml += '<div class="cm-advanced compose-text-muted" style="font-size:0.8em;margin-top:2px;">Expand for details</div>';
                }
            }

            // Also show pinned count if any containers are pinned
            if (pinnedCount > 0) {
                updateHtml += '<div class="compose-status-info" style="font-size:0.8em;margin-top:2px;"><i class="fa fa-thumb-tack fa-fw"></i> ' + pinnedCount + ' pinned</div>';
            }
            $updateCell.html(updateHtml);
        } else if (totalContainers > 0) {
            // No updates - check if all are pinned or up-to-date
            if (pinnedCount > 0 && pinnedCount === totalContainers) {
                // All containers are pinned
                var html = '<span class="cyan-text" style="white-space:nowrap;"><i class="fa fa-thumb-tack fa-fw"></i> all pinned</span>';
                $updateCell.html(html);
            } else if (pinnedCount > 0) {
                // Some containers pinned, rest up-to-date
                var html = '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
                html += '<div class="cm-advanced compose-status-info" style="font-size:0.8em;margin-top:2px;"><i class="fa fa-thumb-tack fa-fw"></i> ' + pinnedCount + ' pinned</div>';
                html += '<div class="cm-advanced"><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + composeEscapeAttr(stackName) + '\', \'' + composeEscapeAttr(stackId) + '\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
                $updateCell.html(html);
            } else {
                // No updates, no pinned - green "up-to-date" style (like Docker tab)
                // Basic view: just shows up-to-date
                // Advanced view: shows force update link
                var html = '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
                html += '<div class="cm-advanced"><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + composeEscapeAttr(stackName) + '\', \'' + composeEscapeAttr(stackId) + '\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
                $updateCell.html(html);
            }
        } else {
            // No containers found in update data — stack is running but
            // hasn't been checked yet.  Prompt a check rather than an
            // update so the SHA metadata gets populated first.
            $updateCell.html('<a class="exec" style="cursor:pointer;" onclick="checkStackUpdates(\'' + composeEscapeAttr(stackName) + '\');"><i class="fa fa-cloud-download fa-fw"></i> check for updates</a>');
        }

        // Apply current view mode — cm-advanced elements are controlled by
        // the .cm-advanced-view class on #compose_stacks (CSS-only, no need to
        // show/hide individual elements here since CSS handles visibility).

        // Rebuild context menus to reflect update status (only target icon spans with data-stackid, not the row)
        $('[id^="stack-"][data-stackid][data-project="' + stackName + '"]').each(function() {
            addComposeStackContext(this.id);
        });

        // Also update the cached container data with update status and SHA
        if (stackContainersCache[stackId] && stackInfo.containers) {
            stackContainersCache[stackId].forEach(function(cached) {
                stackInfo.containers.forEach(function(updated) {
                    if (cached.name === updated.name) {
                        cached.hasUpdate = updated.hasUpdate;
                        cached.updateStatus = updated.updateStatus;
                        cached.localSha = updated.localSha || '';
                        cached.remoteSha = updated.remoteSha || '';
                        cached.isPinned = updated.isPinned || false;
                        cached.pinnedDigest = updated.pinnedDigest || '';
                    }
                });
            });
        }

        // If details are expanded, refresh them. However, avoid immediate
        // refresh if a load is already in progress or we've just rendered to
        // prevent a render->update->render loop.
        if (expandedStacks[stackId]) {
            if (stackDetailsLoading[stackId] || stackDetailsJustRendered[stackId]) {
                composeLogger('skip-refresh', {
                    stackId: stackId,
                    stackName: stackName,
                    loading: !!stackDetailsLoading[stackId],
                    justRendered: !!stackDetailsJustRendered[stackId]
                }, 'user', 'info', 'update-check');
            } else {
                loadStackContainerDetails(stackId, stackName);
            }
        }
    }

    // Check updates for a single stack
    function checkStackUpdates(stackName) {
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
        if ($stackRow.length === 0) return;

        var $updateCell = $stackRow.find('.compose-updatecolumn');
        $updateCell.html('<span class="compose-status-info"><i class="fa fa-refresh fa-spin"></i> checking...</span>');

        $.post(caURL, {
            action: 'checkStackUpdates',
            script: stackName
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success') {
                        var stackInfo = createStackInfo(stackName, response.updates, {
                            projectName: response.projectName
                        });
                        stackUpdateStatus[stackName] = stackInfo;
                        updateStackUpdateUI(stackName, stackInfo);
                        // Update the Update All button state
                        updateUpdateAllButton();

                        // Clear the pending recheck flag for this stack (if any)
                        $.post(caURL, {
                            action: 'clearStackRecheck',
                            stacks: JSON.stringify([stackName])
                        });
                    }
                } catch (e) {
                    composeLogger('Failed to parse update check response', {
                        error: e && e.toString(),
                        stackName: stackName
                    }, 'user', 'error', 'checkStackUpdates');
                }
            }
        });
    }

    // Validate URL scheme for WebUI links (allows [IP] and [PORT]/[PORT:xxxx] placeholders)
    function isValidWebUIUrl(url) {
        if (!url) return false;
        // Replace placeholders with dummy values for structural validation
        var normalized = url.replace(/\[IP\]/gi, 'localhost')
            .replace(/\[PORT:\d+\]/gi, '8080')
            .replace(/\[PORT\]/gi, '8080');
        try {
            return Boolean(new URL(normalized));
        } catch (e) {
            return false;
        }
    }

    // Validate an icon source: http(s) URL, data URI, or local server path
    function isValidIconSrc(src) {
        if (!src) return false;
        var s = src.trim();
        return s.indexOf('http://') === 0 || s.indexOf('https://') === 0 ||
            s.indexOf('data:image/') === 0 || s.indexOf('/') === 0;
    }

    function loadPersistentContainerCache() {
        return new Promise(function(resolve) {
            $.get('/plugins/compose.manager/containers.cache.json')
                .done(function(data) {
                    try {
                        persistentContainerCache = JSON.parse(data) || {};
                    } catch (e) {
                        persistentContainerCache = {};
                        composeLogger('Failed to parse persistent container cache', {
                            error: e && e.toString()
                        }, 'user', 'warn', 'loadPersistentContainerCache');
                    }
                    resolve(persistentContainerCache);
                })
                .fail(function() {
                    persistentContainerCache = {};
                    resolve(persistentContainerCache);
                });
        });
    }

    function getPersistentContainerInfo(project, service) {
        if (!project || !service || !persistentContainerCache[project]) return null;
        return persistentContainerCache[project][service] || null;
    }

    // Process WebUI URL placeholders for stack-level WebUI (where no container context exists)
    // For container-level WebUI, resolution is done server-side in exec.php
    function processWebUIUrl(url) {
        if (!url) return url;
        // Replace [IP] with the server hostname/IP (stack-level only)
        url = url.replace(/\[IP\]/gi, window.location.hostname);
        // Replace [PORT:xxxx] with the specified port (no container port mapping at stack level)
        url = url.replace(/\[PORT:(\d+)\]/gi, '$1');
        // Replace bare [PORT] — no default port available at stack level, clean up
        // This shouldn't normally be reached (save validation rejects bare [PORT]),
        // but handle gracefully by removing the placeholder and any preceding colon
        url = url.replace(/:?\[PORT\]/gi, '');
        return url;
    }

    function isComposeAdvancedMode() {
        return $.cookie('compose_listview_mode') === 'advanced';
    }

    // Apply advanced/basic view based on cookie (used after async load)
    // Scoped to compose_stacks to avoid affecting Docker tab when tabs are joined.
    // When animate=true (user clicked toggle), run a simple symmetric transition.
    // When false (page load), instant class toggle.
    function applyListView(animate) {
        // Sync the dockerload WebSocket with the view mode.
        if (typeof window.composeDockerLoadToggle === 'function') {
            window.composeDockerLoadToggle(isComposeAdvancedMode());
        }
        var advanced = isComposeAdvancedMode();
        var $table = $('#compose_stacks');
        var $advanced = $table.find('.cm-advanced');

        var setClass = function(enabled) {
            if (enabled) {
                $table.addClass('cm-advanced-view');
            } else {
                $table.removeClass('cm-advanced-view');
            }
        };

        if (!animate) {
            setClass(advanced);
            $table.css({
                height: '',
                overflow: ''
            });
            $advanced.css({
                opacity: '',
                display: ''
            });
        } else {
            if (advanced) {
                // basic -> advanced: enable class first, then fade in advanced cells
                setClass(true);
                $table.css({
                    height: $table.outerHeight(),
                    overflow: 'hidden'
                });
                $advanced.stop(true, true).css({
                    opacity: 0
                }).animate({
                    opacity: 1
                }, 300, function() {
                    $table.css({
                        height: '',
                        overflow: ''
                    });
                    $advanced.css({
                        opacity: '',
                        display: ''
                    });
                });
            } else {
                // advanced -> basic: fade out then disable class to avoid flicker
                $table.css({
                    height: $table.outerHeight(),
                    overflow: 'hidden'
                });
                $advanced.stop(true, true).animate({
                    opacity: 0
                }, 300, function() {
                    setClass(false);
                    $table.css({
                        height: '',
                        overflow: ''
                    });
                    $advanced.css({
                        opacity: '',
                        display: ''
                    });
                });
            }
        }

        // Apply readmore to descriptions — exclude container detail rows; destroy first to avoid nested wrappers
        var $readmoreEls = $('#compose_stacks .docker_readmore').not('.stack-details-container .docker_readmore');
        $readmoreEls.readmore('destroy');
        $readmoreEls.readmore({
            maxHeight: 32,
            moreLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",
            lessLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"
        });
    }

    $(function() {
        $(".tipsterallowed").show();
        $('.ca_nameEdit').tooltipster({
            trigger: 'custom',
            triggerOpen: {
                click: true,
                touchstart: true,
                mouseenter: true
            },
            triggerClose: {
                click: true,
                scroll: false,
                mouseleave: true
            },
            delay: 1000,
            contentAsHTML: true,
            animation: 'grow',
            interactive: true,
            viewportAware: true,
            functionBefore: function(instance, helper) {
                var origin = $(helper.origin);
                var myID = origin.attr('id');
                var name = $("#" + myID).html();
                var disabled = $("#" + myID).attr('data-isup') == "1" ? "disabled" : "";
                var notdisabled = $("#" + myID).attr('data-isup') == "1" ? "" : "disabled";
                var stackName = $("#" + myID).attr("data-scriptname");
                instance.content(composeEscapeHtml(stackName) + "<br> \
                                    <center> \
                                    <input type='button' onclick='editName(&quot;" + myID + "&quot;);' value='Edit Name' " + disabled + "> \
                                    <input type='button' onclick='editDesc(&quot;" + myID + "&quot;);' value='Edit Description' > \
                                    <input type='button' onclick='editStack(&quot;" + myID + "&quot;);' value='Edit Stack'> \
                                    <input type='button' onclick='deleteStack(&quot;" + myID + "&quot;);' value='Delete Stack' " + disabled + "> \
                                    <input type='button' onclick='ComposeLogs(&quot;" + myID + "&quot;);' value='Logs' " + notdisabled + "> \
                                    </center>");
            }
        });

        // Add Advanced View toggle (like Docker tab)
        // Use compose-specific class to avoid conflict with Docker tab's advancedview when tabs are joined
        var toggleHtml = '<span class="status compose-view-toggle"><span><input type="checkbox" class="compose-advancedview"></span></span>';

        // In tabbed mode we must keep the toggle inside the compose content pane
        // so it does not leak into the global tab bar, and in standalone mode it
        // also appears above the compose stacks table.
        var $toggleContainer = $('<div class="ToggleViewMode"></div>').html(toggleHtml);
        var $tableWrapper = $('#compose_stacks').closest('.TableContainer');
        if ($tableWrapper.length) {
            $tableWrapper.before($toggleContainer);
        } else if ($('#compose_stacks').length) {
            $('#compose_stacks').before($toggleContainer);
        } else if ($('.tabs').length) {
            // Fallback for unusual layout: inject into tabs as a last resort
            $('.tabs').append($toggleContainer);
        } else {
            $('body').prepend($toggleContainer);
        }


        // Initialize the Advanced/Basic view toggle.
        // labels_placement:'left' puts both labels to the left of the slider.
        // The plugin shows only the active label: "Basic View" (white) when
        // unchecked, "Advanced View" (blue / class 'on') when checked.
        var isAdvanced = $.cookie('compose_listview_mode') === 'advanced';
        $('.compose-advancedview').switchButton({
            labels_placement: 'left',
            on_label: 'Advanced View',
            off_label: 'Basic View',
            checked: isAdvanced
        });
        // Apply the current cookie state immediately so columns match the toggle.
        applyListView();
        $('.compose-advancedview').change(function() {
            // Persist selection and apply view consistently via applyListView()
            $.cookie('compose_listview_mode', $('.compose-advancedview').is(':checked') ? 'advanced' : 'basic', {
                expires: 3650
            });
            applyListView(true);
        });

        // ebox observer removed; pending update checks are now processed from
        // refreshStackRow and processPendingComposeReloads directly.

        // Gate dockerload socket start until the stack list DOM is ready.
        var composeListReady = false;

        // Load the persistent container cache before the stack list.
        // This ensures dialog merge logic can use last-known container metadata.
        loadPersistentContainerCache().then(function() {
            composeLoadlist().then(function() {
                composeListReady = true;
                composeLogger('composeListReady=true, rows=' + $('#compose_stacks tr.compose-sortable').length, null, 'user', 'debug', 'dockerload');

                // Start the dockerload socket now that the DOM has rows with data-ctids.
                if (typeof window.composeDockerLoadToggle === 'function') {
                    composeLogger('triggering composeDockerLoadToggle, advancedMode=' + isComposeAdvancedMode(), null, 'user', 'debug', 'dockerload');
                    window.composeDockerLoadToggle(isComposeAdvancedMode());
                } else {
                    composeLogger('composeDockerLoadToggle not available yet at composeLoadlist completion', null, 'user', 'debug', 'dockerload');
                }

                getConfig().then(function(config) {
                    if (config['STACKS_DEFAULT_EXPANDED'] == 'true') {
                        // Expand all stacks if the default is set to expanded
                        $('#compose_stacks tr.compose-sortable').each(function() {
                            if ($(this).data('isup') != "1" && config['ONLY_EXPAND_RUNNING_STACKS'] == 'true') {
                                return; // Skip stopped stacks if ONLY_EXPAND_RUNNING_STACKS is true
                            }
                            var stackId = $(this).attr('id').replace('stack-row-', '');
                            toggleStackDetails(stackId);
                        });
                    }
                });
            });
            // ── Cross-widget sync ──────────────────────────────────────────
            // On the Docker page the Compose stacks list is rendered below
            // the built-in Docker containers table (non-tabbed) or in a
            // separate tab (tabbed mode).  When Docker's loadlist() fires
            // (container start/stop/restart/etc.), the compose list must
            // also refresh so state stays in sync.
            (function hookLoadlist() {
                var composeRefreshTimer = null;
                var composeDataStale = false;

                // For tabbed mode: detect if our panel is hidden so we can
                // defer the refresh until the user switches to the compose tab.
                var composeTable = document.getElementById('compose_stacks');
                var tabPanel = composeTable ? composeTable.closest('[role="tabpanel"]') : null;
                var isTabbed = tabPanel !== null;

                function isComposePanelVisible() {
                    if (!isTabbed) return true; // non-tabbed: always visible
                    return tabPanel.style.display !== 'none';
                }

                function wrapLoadlist() {
                    if (typeof window.loadlist === 'function' && !window.loadlist._composeTabHooked) {
                        var originalLoadlist = window.loadlist;
                        window.loadlist = function() {
                            originalLoadlist.apply(this, arguments);

                            // Skip compose reload if refreshStackRow is already handling it
                            if (pendingComposeRefreshCount > 0 || skipNextComposeLoadlist) {
                                composeLogger('suppressed composeLoadlist (pending=' + pendingComposeRefreshCount + ', skip=' + skipNextComposeLoadlist + ')', null, 'user', 'debug', 'hookLoadlist');
                                skipNextComposeLoadlist = false;
                                return;
                            }

                            if (isComposePanelVisible()) {
                                // Visible — refresh with debounce
                                clearTimeout(composeRefreshTimer);
                                composeRefreshTimer = setTimeout(function() {
                                    composeLoadlist();
                                }, 2000);
                            } else {
                                // Hidden tab — mark stale, refresh on tab switch
                                composeDataStale = true;
                            }
                        };
                        window.loadlist._composeTabHooked = true;
                        composeLogger('hooked loadlist() for cross-widget sync, tabbed=' + isTabbed, null, 'user', 'info', 'hookLoadlist');
                        return true;
                    }
                    return false;
                }

                // loadlist may not exist yet — retry every 500ms
                if (!wrapLoadlist()) {
                    var hookInterval = setInterval(function() {
                        if (wrapLoadlist()) clearInterval(hookInterval);
                    }, 500);
                    // Give up after 30s
                    setTimeout(function() {
                        clearInterval(hookInterval);
                    }, 30000);
                }

                // Tabbed mode: watch for tab switches to flush stale data
                if (isTabbed) {
                    var panelObserver = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                            if (mutation.attributeName === 'style' && isComposePanelVisible() && composeDataStale) {
                                composeDataStale = false;
                                composeLoadlist();
                            }
                        });
                    });
                    panelObserver.observe(tabPanel, {
                        attributes: true,
                        attributeFilter: ['style']
                    });
                }
            })();

            // ── CPU & Memory load via dockerload Nchan channel ─────────────
            // Only runs in advanced view (load column is hidden in basic view).
            // composeDockerLoadToggle(true/false) is called from applyListView()
            // so the socket starts/stops whenever the user switches view modes.
            function initComposeDockerLoadSubscriber() {
                if (typeof NchanSubscriber !== 'function') {
                    composeLogger('NchanSubscriber not available yet', null, 'user', 'debug', 'dockerload');
                    return false;
                }

                // Tear down previous subscriber if page was re-navigated (Unraid
                // AJAX navigation preserves window globals but old closures/sockets
                // become stale).  Always create a fresh subscriber.
                if (window._composeDockerLoad) {
                    composeLogger('tearing down previous subscriber', null, 'user', 'info', 'dockerload');
                    try {
                        window._composeDockerLoad.stop();
                    } catch (e) {}
                }
                if (window._composeDockerLoadStaleTimer) {
                    clearInterval(window._composeDockerLoadStaleTimer);
                }
                if (window._composeDockerLoadVisHandler) {
                    document.removeEventListener('visibilitychange', window._composeDockerLoadVisHandler);
                }
                if (window._composeDockerLoadPanelObserver) {
                    window._composeDockerLoadPanelObserver.disconnect();
                }
                $(document).off('composeListRefreshed.dockerload');

                composeLogger('initializing subscriber, composeListReady=' + composeListReady, null, 'user', 'info', 'dockerload');

                var composeDockerLoad = new NchanSubscriber('/sub/dockerload', {
                    subscriber: 'websocket',
                    reconnectTimeout: 5000
                });
                window._composeDockerLoad = composeDockerLoad;
                var composeDockerLoadRunning = false;
                var composeDockerLoadDropped = 0;

                // Cache of { stackId, containerIds[] } built from the DOM once after
                // composeLoadlist() and reused until the row count changes.
                // Avoids O(stacks) DOM traversal + string splits on every stats tick.
                var composeStackIndex = null;
                var composeLoadById = {};
                var composeLoadStaleMs = 15000;

                function isComposeLoadVisible() {
                    if (!isComposeAdvancedMode()) return false;
                    if (document.visibilityState === 'hidden') return false;
                    var $table = $('#compose_stacks');
                    if (!$table.length) return false;
                    var $tabPanel = $table.closest('[role="tabpanel"]');
                    if ($tabPanel.length && $tabPanel[0].style.display === 'none') return false;
                    return true;
                }

                function clearContainerLoad(shortId) {
                    $('.compose-cpu-' + shortId).addClass('compose-text-muted').text('-');
                    $('#compose-cpu-' + shortId).css('width', '0');
                    $('.compose-mem-' + shortId).hide();
                }

                function buildComposeStackIndex() {
                    composeStackIndex = [];
                    $('#compose_stacks tr.compose-sortable').each(function() {
                        var stackId = ($(this).attr('id') || '').replace('stack-row-', '');
                        if (!stackId) return;
                        var ctidsAttr = $(this).attr('data-ctids') || '';
                        composeStackIndex.push({
                            stackId: stackId,
                            containerIds: ctidsAttr ? ctidsAttr.split(',') : []
                        });
                    });
                    composeLogger('buildComposeStackIndex complete, stacks=' + composeStackIndex.length, null, 'user', 'debug', 'dockerload');
                }

                // Invalidate the cache when the list refreshes so that added/removed
                // stacks are picked up on the next stats tick.
                $(document).on('composeListRefreshed.dockerload', function() {
                    composeLogger('composeListRefreshed — invalidating stack index and load cache', null, 'user', 'debug', 'dockerload');
                    composeStackIndex = null;
                    composeLoadById = {};
                });

                window.composeDockerLoadToggle = function(enable) {
                    if (enable && !composeDockerLoadRunning) {
                        composeLogger('starting WebSocket', null, 'user', 'info', 'dockerload');
                        composeDockerLoad.start();
                        composeDockerLoadRunning = true;
                    } else if (!enable && composeDockerLoadRunning) {
                        composeLogger('stopping WebSocket', null, 'user', 'info', 'dockerload');
                        composeDockerLoad.stop();
                        composeDockerLoadRunning = false;
                    }
                };

                function pruneStaleLoadEntries(now) {
                    var staleIds = [];
                    for (var knownId in composeLoadById) {
                        if ((now - composeLoadById[knownId].ts) > composeLoadStaleMs) {
                            staleIds.push(knownId);
                        }
                    }
                    if (staleIds.length > 0) {
                        composeLogger('pruning ' + staleIds.length + ' stale container(s)', {
                            ids: staleIds
                        }, 'user', 'debug', 'dockerload');
                    }
                    staleIds.forEach(function(staleId) {
                        delete composeLoadById[staleId];
                        clearContainerLoad(staleId);
                    });
                    return staleIds.length > 0;
                }

                function renderStackAggregates() {
                    // Aggregate per-stack totals and update stack-level cells.
                    // Build (or reuse) the stack→container index.
                    var currentRowCount = $('#compose_stacks tr.compose-sortable').length;
                    if (!composeStackIndex || composeStackIndex.length !== currentRowCount) {
                        buildComposeStackIndex();
                    }

                    composeStackIndex.forEach(function(entry) {
                        // Primary: short IDs baked into the row by ComposeList.php
                        var idList = entry.containerIds.slice();

                        // Fallback: if the detail panel was expanded, stackContainersCache
                        // may have fresher IDs (e.g. after a compose up added a service)
                        if (idList.length === 0) {
                            var containers = stackContainersCache[entry.stackId];
                            if (containers && containers.length > 0) {
                                containers.forEach(function(ct) {
                                    var ctId = String(ct.id || '').substring(0, 12);
                                    if (ctId) idList.push(ctId);
                                });
                            }
                        }
                        if (idList.length === 0) return;

                        var totalCpu = 0;
                        var totalMemUsedBytes = 0;
                        var totalMemLimitBytes = 0;
                        var matched = 0;
                        idList.forEach(function(ctId) {
                            if (ctId && composeLoadById[ctId]) {
                                totalCpu += composeLoadById[ctId].cpu;
                                totalMemUsedBytes += composeLoadById[ctId].memUsedBytes || 0;
                                totalMemLimitBytes += composeLoadById[ctId].memLimitBytes || 0;
                                matched++;
                            }
                        });

                        if (matched > 0) {
                            var aggCpu = formatCpuPercent(totalCpu);
                            var stackMemTotalBytes = 0;
                            if (totalMemLimitBytes > 0 && composeSystemMemBytes > 0) {
                                stackMemTotalBytes = Math.min(totalMemLimitBytes, composeSystemMemBytes);
                            } else if (totalMemLimitBytes > 0) {
                                stackMemTotalBytes = totalMemLimitBytes;
                            } else if (composeSystemMemBytes > 0) {
                                stackMemTotalBytes = composeSystemMemBytes;
                            }
                            var aggMem = formatMemUsageText(totalMemUsedBytes, stackMemTotalBytes);
                            $('.compose-stack-cpu-' + entry.stackId).removeClass('compose-text-muted').text(aggCpu);
                            $('#compose-stack-cpu-' + entry.stackId).css('width', Math.min(totalCpu, 100).toFixed(2) + '%');
                            $('.compose-stack-mem-' + entry.stackId).show().text(aggMem);
                        } else {
                            $('.compose-stack-cpu-' + entry.stackId).addClass('compose-text-muted').text('-');
                            $('#compose-stack-cpu-' + entry.stackId).css('width', '0');
                            $('.compose-stack-mem-' + entry.stackId).hide();
                        }
                    });
                }

                composeDockerLoad.on('message', function(msg) {
                    var now = Date.now();
                    var data = msg.split('\n');
                    var i = 0;
                    var row = data[i];
                    while (row) {
                        var parts = row.split(';');
                        if (parts.length >= 3) {
                            var cpuRaw = parseFloat(parts[1]) || 0;
                            var cpuNorm = Math.round(Math.min(cpuRaw / Math.max(composeCpuCount, 1), 100) * 100) / 100;
                            var memPair = parseMemUsagePair(parts[2]);
                            composeLoadById[parts[0]] = {
                                cpu: cpuNorm,
                                cpuText: formatCpuPercent(cpuNorm),
                                mem: formatMemUsageText(memPair.used, memPair.limit),
                                memUsedBytes: memPair.used,
                                memLimitBytes: memPair.limit,
                                ts: now
                            };
                        }
                        i++;
                        row = data[i];
                    }

                    pruneStaleLoadEntries(now);

                    // Skip DOM updates when the page isn't visible — the cache
                    // stays warm so we can render instantly on return.
                    if (!isComposeLoadVisible()) {
                        composeDockerLoadDropped++;
                        return;
                    }

                    // Update per-container CPU & MEM elements in expanded detail tables
                    for (var shortId in composeLoadById) {
                        var info = composeLoadById[shortId];
                        $('.compose-cpu-' + shortId).removeClass('compose-text-muted').text(info.cpuText);
                        $('.compose-mem-' + shortId).show().text(info.mem);
                        $('#compose-cpu-' + shortId).css('width', info.cpuText);
                    }

                    renderStackAggregates();
                });

                composeDockerLoad.on('error', function(code, desc) {
                    composeLogger('WebSocket error', {
                        code: code,
                        desc: desc
                    }, 'user', 'warn', 'dockerload');
                });

                // If dockerload pauses/stalls, drop stale values on a timer so the UI
                // falls back to placeholders instead of showing frozen metrics forever.
                window._composeDockerLoadStaleTimer = setInterval(function() {
                    if (!isComposeLoadVisible()) {
                        return;
                    }
                    if (pruneStaleLoadEntries(Date.now())) {
                        renderStackAggregates();
                    }
                }, 3000);

                // When the browser tab becomes visible again, invalidate the
                // stack index so the next WebSocket message rebuilds it from
                // the current DOM.  This prevents permanently stale data when
                // the page loaded or sat in a background tab.
                window._composeDockerLoadVisHandler = function() {
                    if (document.visibilityState === 'visible' && composeDockerLoadRunning) {
                        if (composeDockerLoadDropped > 0) {
                            composeLogger('browser tab became visible — skipped ' + composeDockerLoadDropped + ' messages while hidden, rendering cached data', null, 'user', 'debug', 'dockerload');
                            composeDockerLoadDropped = 0;
                        }
                        composeStackIndex = null;

                        // Immediately render the cached load data so the UI
                        // shows current metrics without waiting for the next tick.
                        for (var shortId in composeLoadById) {
                            var info = composeLoadById[shortId];
                            $('.compose-cpu-' + shortId).removeClass('compose-text-muted').text(info.cpuText);
                            $('.compose-mem-' + shortId).show().text(info.mem);
                            $('#compose-cpu-' + shortId).css('width', info.cpuText);
                        }
                        renderStackAggregates();
                    }
                };
                document.addEventListener('visibilitychange', window._composeDockerLoadVisHandler);

                // In tabbed mode, also invalidate the cache when the compose
                // panel becomes visible (user switches tabs) so stale entries
                // don't linger from when the panel was hidden.
                var $loadTable = $('#compose_stacks');
                var $loadTabPanel = $loadTable.length ? $loadTable.closest('[role="tabpanel"]') : $();
                if ($loadTabPanel.length) {
                    composeLogger('tabbed mode detected — observing panel visibility', {}, 'user', 'debug', 'dockerload');
                    window._composeDockerLoadPanelObserver = new MutationObserver(function() {
                        if ($loadTabPanel[0].style.display !== 'none' && composeDockerLoadRunning) {
                            composeLogger('compose tab became visible — invalidating stack index', {
                                'listReady': composeListReady,
                                'advanced': isComposeAdvancedMode()
                            }, 'user', 'debug', 'dockerload');
                            composeStackIndex = null;
                        }
                    });
                    window._composeDockerLoadPanelObserver.observe($loadTabPanel[0], {
                        attributes: true,
                        attributeFilter: ['style']
                    });
                }

                // Only auto-start the socket if the stack list is already
                // loaded (composeListReady is true).  Otherwise the
                // composeLoadlist().then() callback will start it.
                if (composeListReady && isComposeAdvancedMode()) {
                    composeLogger('auto-starting socket', {
                        'listReady': composeListReady,
                        'advanced': isComposeAdvancedMode()
                    }, 'user', 'info', 'dockerload');
                    composeDockerLoad.start();
                    composeDockerLoadRunning = true;
                } else {
                    composeLogger('deferring socket start', {
                        'listReady': composeListReady,
                        'advanced': isComposeAdvancedMode()
                    }, 'user', 'debug', 'dockerload');
                }
                return true;
            }

            // Standalone compose mode can race script load order; retry briefly
            // so delayed NchanSubscriber availability still initializes dockerload.
            if (!initComposeDockerLoadSubscriber()) {
                composeLogger('subscriber init deferred — will retry every 250ms', null, 'user', 'debug', 'dockerload');
                var composeDockerLoadInitAttempts = 0;
                var composeDockerLoadInitTimer = setInterval(function() {
                    composeDockerLoadInitAttempts++;
                    if (initComposeDockerLoadSubscriber()) {
                        composeLogger('subscriber initialized on retry #' + composeDockerLoadInitAttempts, null, 'user', 'info', 'dockerload');
                        clearInterval(composeDockerLoadInitTimer);
                    } else if (composeDockerLoadInitAttempts >= 40) {
                        composeLogger('subscriber init gave up after ' + composeDockerLoadInitAttempts + ' attempts', null, 'user', 'warn', 'dockerload');
                        clearInterval(composeDockerLoadInitTimer);
                    }
                }, 250);
            }
        });
    });

    function addStack() {
        // Show custom modal for stack creation
        var modalHtml = `
            <div id="compose-stack-modal-overlay" class="compose-modal-overlay" style="display:flex;" onclick="if (event.target === this) closeComposeStackModal();">
                <div class="compose-modal" role="dialog" aria-modal="true" aria-labelledby="compose-stack-modal-title" aria-describedby="compose-stack-modal-desc" tabindex="-1">
                    <div class="compose-modal-header">
                        <span id="compose-stack-modal-title">Add New Compose Stack</span>
                        <button type="button" class="editor-btn editor-btn-cancel" onclick="closeComposeStackModal()" aria-label="Close modal"><i class="fa fa-times"></i></button>
                    </div>
                    <div class="compose-modal-body">
                        <div style="font-weight:bold;margin-bottom:8px;">Stack Name</div>
                        <input type="text" id="compose-stack-name" placeholder="Stack Name" autofocus>
                        <div id="compose-stack-modal-desc" style="font-weight:bold;margin-bottom:8px;">Description (optional)</div>
                        <input type="text" id="compose-stack-desc" placeholder="Description">
                        <div id="compose-stack-modal-error" class="compose-status-danger" style="margin-bottom:8px;display:none;"></div>
                    
                        <details>
                            <summary>Advanced Options</summary></br>
                            <div style="font-weight:bold;margin-bottom:8px;">Indirect Path</div>
                            <input type="text" id="compose-stack-indirect" placeholder="/mnt/user/compose/stackFolder" data-pickroot="/" data-picktop="/mnt" data-pickfolders="true" data-pickcloseonfile="true">
                        </details>
                    
                    </div>
                    <div class="compose-modal-footer">
                        <button class="editor-btn editor-btn-cancel" onclick="closeComposeStackModal()">Cancel</button>
                        <button class="editor-btn editor-btn-save-all" onclick="submitComposeStackModal()">Create</button>
                    </div>
                </div>
            </div>
        `;
        // Remove any existing modal
        var existingOverlay = document.getElementById('compose-stack-modal-overlay');
        if (existingOverlay) {
            existingOverlay.remove();
        }
        // Insert modal into body
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = modalHtml;
        document.body.appendChild(tempDiv.firstElementChild);

        // The add-stack modal is created dynamically, so attach the picker after insertion.
        if ($.fn.fileTreeAttach) {
            var $indirectInput = $('#compose-stack-indirect');
            composeBindFileTreeInputs($indirectInput, {
                zIndex: 100010,
                minWidth: 320,
                addClass: false
            });
        }

        window.closeComposeStackModal = function() {
            var overlay = document.getElementById('compose-stack-modal-overlay');
            if (overlay) {
                overlay.remove();
            }
        };

        window.submitComposeStackModal = function() {
            var name = document.getElementById('compose-stack-name').value.trim();
            var desc = document.getElementById('compose-stack-desc').value.trim();
            var indirect = document.getElementById('compose-stack-indirect').value.trim();
            var errorDiv = document.getElementById('compose-stack-modal-error');
            if (!name) {
                errorDiv.textContent = "Please enter a stack name.";
                errorDiv.style.display = "block";
                return;
            }
            errorDiv.style.display = "none";
            // Disable all buttons in the modal
            var modal = document.getElementById('compose-stack-modal-overlay');
            if (modal) {
                var btns = modal.querySelectorAll('button');
                btns.forEach(function(btn) {
                    btn.disabled = true;
                });
            }
            $.post(
                caURL, {
                    action: 'addStack',
                    stackName: name,
                    stackDesc: desc,
                    stackPath: indirect
                },
                function(data) {
                    window.closeComposeStackModal();
                    if (data) {
                        var response;
                        try {
                            response = JSON.parse(data);
                        } catch (e) {
                            // Handle invalid or unexpected JSON response
                            composeLogger('Failed to parse addStack response', {
                                error: e && e.toString(),
                                data: data
                            }, 'user', 'error', 'addStack');
                            swal({
                                title: "Failed to create stack",
                                text: "Unexpected response from server",
                                type: "error"
                            });
                            return;
                        }
                        if (response.result == "success") {
                            openEditorModalByProject(response.project, response.projectName);
                            composeLoadlist();
                        } else {
                            swal({
                                title: "Failed to create stack",
                                text: response.message || "An error occurred",
                                type: "error"
                            });
                        }
                    } else {
                        swal({
                            title: "Failed to create stack",
                            text: "No response from server",
                            type: "error"
                        });
                    }
                }
            ).fail(function() {
                window.closeComposeStackModal();
                swal({
                    title: "Failed to create stack",
                    text: "Request failed",
                    type: "error"
                });
            });
        };
    }



    // =========================================================================
    // Import Wizard — 5-Stage Flow (Basic / Advanced)
    // =========================================================================

    var importWizard = {
        stage: 1,
        advancedMode: (localStorage.getItem('compose_import_wizard_mode') === 'advanced'),
        containerIds: [],
        containers: [],     // raw container list from getDockerContainersForImport
        services: {},       // per-service metadata from generateImportData
        portConflicts: [],
        availableNetworks: [],
        config: {
            stackName: '',
            stackDesc: '',
            stopContainers: true,
            removeContainers: true,
            startStack: true,
            containerNames: {},
            networkConfig: {
                stackNetwork: { enabled: true, name: '' },
                externalNetworks: [],
                perService: {}
            },
            healthchecks: {},
            dependencies: {}
        },
        result: null        // finalizeImportCompose response
    };

    var iwDepIdCounter = 0;

    var WIZARD_STAGES_ALL = [
        { num: 1, label: 'Select Containers' },
        { num: 2, label: 'Stack Options' },
        { num: 3, label: 'Configure Services' },
        { num: 4, label: 'Dependencies', advancedOnly: true },
        { num: 5, label: 'Review & Import' }
    ];

    function iwGetVisibleStages() {
        if (importWizard.advancedMode) return WIZARD_STAGES_ALL;
        return WIZARD_STAGES_ALL.filter(function(s) { return !s.advancedOnly; });
    }

    function renderWizardStepper(activeStage) {
        var stages = iwGetVisibleStages();
        var html = '<div class="import-wizard-stepper">';
        stages.forEach(function(s, i) {
            var cls = 'import-wizard-step';
            if (s.num < activeStage) cls += ' completed';
            else if (s.num === activeStage) cls += ' active';
            if (i > 0) html += '<div class="import-wizard-step-connector"></div>';
            var displayNum = i + 1;
            var icon = s.num < activeStage ? '<i class="fa fa-check"></i>' : displayNum;
            html += '<div class="' + cls + '"><span class="import-wizard-step-number">' + icon + '</span><span>' + composeEscapeHtml(s.label) + '</span></div>';
        });
        html += '</div>';
        return html;
    }

    function iwSetAdvancedMode(isAdvanced) {
        importWizard.advancedMode = !!isAdvanced;
        localStorage.setItem('compose_import_wizard_mode', importWizard.advancedMode ? 'advanced' : 'basic');

        var stage = importWizard.stage;
        var visible = iwGetVisibleStages();
        var visibleNums = visible.map(function(s) { return s.num; });

        // If current stage is hidden in the new mode, jump to nearest visible stage
        if (visibleNums.indexOf(stage) === -1) {
            // Find the closest visible stage (prefer previous, fall back to next)
            var prev = null, next = null;
            for (var i = 0; i < visibleNums.length; i++) {
                if (visibleNums[i] < stage) prev = visibleNums[i];
                if (visibleNums[i] > stage && next === null) next = visibleNums[i];
            }
            var target = prev || next || 1;
            iwNavigateToStage(target);
            return;
        }

        // Current stage is still visible — re-render it to update fields + stepper
        if (stage === 1) { $('#compose-import-stepper').html(renderWizardStepper(1)); }
        else if (stage === 2) { iwSaveStage2(); renderImportStage2(); }
        else if (stage === 3) { iwSaveStage3(); renderImportStage3(); }
        else if (stage === 4) { renderImportStage4(); }
        else if (stage === 5) { $('#compose-import-stepper').html(renderWizardStepper(5)); }
    }

    function iwNavigateToStage(stageNum) {
        if (stageNum === 1) renderImportStage1();
        else if (stageNum === 2) renderImportStage2();
        else if (stageNum === 3) renderImportStage3();
        else if (stageNum === 4) renderImportStage4();
        else if (stageNum === 5) renderImportStageReview();
    }

    function wizardFooter(opts) {
        var left = '';
        var right = '';
        if (opts.back) {
            left = '<button class="editor-btn editor-btn-cancel" onclick="' + opts.back + '"><i class="fa fa-arrow-left"></i> Back</button>';
        }
        if (opts.cancel !== false) {
            right += '<button class="editor-btn editor-btn-cancel" onclick="closeComposeImportModal()">Cancel</button>';
        }
        if (opts.next) {
            var disabledAttr = opts.nextDisabled ? ' disabled' : '';
            var label = opts.nextLabel || 'Next';
            right += '<button class="editor-btn editor-btn-save-all" id="import-wizard-next-btn" onclick="' + opts.next + '"' + disabledAttr + '>' + composeEscapeHtml(label) + ' <i class="fa fa-arrow-right"></i></button>';
        }
        if (opts.action) {
            var actionDisabledAttr = opts.actionDisabled ? ' disabled' : '';
            right += '<button class="editor-btn editor-btn-save-all" onclick="' + opts.action + '"' + actionDisabledAttr + '>' + composeEscapeHtml(opts.actionLabel || 'Import') + '</button>';
        }
        return '<div style="display:flex;justify-content:space-between;align-items:center;width:100%;">' +
               '<div>' + left + '</div><div style="display:flex;gap:8px;">' + right + '</div></div>';
    }

    function importFromDockerManager() {
        // Reset wizard state
        importWizard = {
            stage: 1,
            advancedMode: (localStorage.getItem('compose_import_wizard_mode') === 'advanced'),
            containerIds: [], containers: [], services: {},
            portConflicts: [], availableNetworks: [],
            config: {
                stackName: '', stackDesc: '',
                stopContainers: true, removeContainers: true, startStack: true, openEditor: false,
                containerNames: {},
                networkConfig: { stackNetwork: { enabled: true, name: '' }, externalNetworks: [], perService: {} },
                healthchecks: {}, dependencies: {}
            },
            result: null
        };
        iwDepIdCounter = 0;

        var modalHtml = '<div id="compose-import-modal-overlay" class="compose-modal-overlay" style="display:flex;">' +
            '<div class="compose-modal import-wizard-modal" role="dialog" aria-modal="true" aria-labelledby="compose-import-modal-title" tabindex="-1">' +
            '<div class="compose-modal-header" id="compose-import-modal-header">' +
            '<span id="compose-import-modal-title">Import from Docker Manager</span>' +
            '<div style="display:flex;align-items:center;gap:10px;margin-left:auto;">' +
            '<input type="checkbox" id="iw-mode-toggle"' + (importWizard.advancedMode ? ' checked' : '') + '>' +
            '<button type="button" class="editor-btn editor-btn-cancel" onclick="closeComposeImportModal()" aria-label="Close modal"><i class="fa fa-times"></i></button>' +
            '</div>' +
            '</div>' +
            '<div id="compose-import-stepper"></div>' +
            '<div class="compose-modal-body" id="compose-import-modal-body">Loading Docker Manager containers...</div>' +
            '<div class="compose-modal-footer" id="compose-import-modal-footer"></div>' +
            '</div></div>';

        var existing = document.getElementById('compose-import-modal-overlay');
        if (existing) existing.remove();
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        window.closeComposeImportModal = function() {
            var overlay = document.getElementById('compose-import-modal-overlay');
            if (overlay) overlay.remove();
        };

        // Initialize Compose-style toggle switch after DOM insertion
        $('#iw-mode-toggle').switchButton({
            labels_placement: 'left',
            on_label: 'Advanced View',
            off_label: 'Basic View',
            checked: importWizard.advancedMode
        });
        $('#iw-mode-toggle').change(function() {
            iwSetAdvancedMode($(this).is(':checked'));
        });

        renderImportStage1();
    }

    // ── Stage 1: Select Containers ──────────────────────────────────────────
    function renderImportStage1() {
        importWizard.stage = 1;
        $('#compose-import-stepper').html(renderWizardStepper(1));
        $('#compose-import-modal-body').html('Loading Docker Manager containers...');
        $('#compose-import-modal-footer').html('');

        $.post(caURL, {action: 'getDockerContainersForImport'}, function(data) {
            var response;
            try { response = JSON.parse(data); } catch (e) { response = { result:'error', message: 'Invalid response' }; }
            if (response.result !== 'success') {
                $('#compose-import-modal-body').html('<div style="color:#f44336">Failed to load containers.</div>');
                return;
            }
            importWizard.containers = response.containers || [];
            if (!importWizard.containers.length) {
                $('#compose-import-modal-body').html('<div>No Docker Manager containers found.</div>');
                return;
            }

            var selectAll = '<label style="display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;cursor:pointer;">' +
                '<input type="checkbox" id="cm-import-select-all"> <strong>Select All</strong></label>';

            var rows = importWizard.containers.map(function(ct) {
                var iconHtml = ct.Icon ? '<img src="' + composeEscapeHtml(ct.Icon) + '" style="height:20px;max-width:20px;" />' : '';
                var urlHtml = (ct.Url && /^https?:\/\//i.test(ct.Url)) ?
                    '<a href="' + composeEscapeHtml(ct.Url) + '" target="_blank" rel="noopener noreferrer">Link</a>' : '';
                return '<tr>' +
                    '<td><input type="checkbox" class="cm-import-container" value="' + composeEscapeHtml(ct.Id) + '"></td>' +
                    '<td>' + composeEscapeHtml(ct.Name) + '</td>' +
                    '<td>' + composeEscapeHtml(ct.Image) + '</td>' +
                    '<td>' + composeEscapeHtml(ct.Status) + '</td>' +
                    '<td>' + iconHtml + '</td>' +
                    '<td>' + urlHtml + '</td></tr>';
            }).join('');
            var table = selectAll + '<div style="max-height:350px;overflow:auto;"><table class="tablesorter" style="width:100%">' +
                '<thead><tr><th></th><th>Name</th><th>Image</th><th>Status</th><th>Icon</th><th>WebUI</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table></div>';
            $('#compose-import-modal-body').html(table);
            $('#compose-import-modal-footer').html(wizardFooter({
                next: 'importWizardStage1Next()',
                nextLabel: 'Next'
            }));

            // Select-all toggle
            $('#cm-import-select-all').on('change', function() {
                $('.cm-import-container').prop('checked', this.checked);
            });
        }).fail(function() {
            $('#compose-import-modal-body').html('<div style="color:#f44336">Failed to contact server.</div>');
        });
    }

    function importWizardStage1Next() {
        var selected = [];
        $('.cm-import-container:checked').each(function() { selected.push(this.value); });
        if (!selected.length) {
            swal('No containers selected', 'Please select at least one container to import.', 'warning');
            return;
        }
        importWizard.containerIds = selected;

        // Fetch rich import data
        $('#compose-import-modal-body').html('<div class="import-generating"><i class="fa fa-spinner fa-spin"></i><span>Analyzing containers...</span></div>');
        $('#compose-import-modal-footer').html('');

        $.post(caURL, {action: 'generateImportData', containerIds: JSON.stringify(selected)}, function(data) {
            var response;
            try { response = JSON.parse(data); } catch (e) { response = { result:'error', message: 'Invalid response' }; }
            if (response.result !== 'success') {
                $('#compose-import-modal-body').html('<div style="color:#f44336">' + composeEscapeHtml(response.message || 'Failed to analyze containers') + '</div>');
                $('#compose-import-modal-footer').html(wizardFooter({ back: 'renderImportStage1()' }));
                return;
            }
            importWizard.services = response.services || {};
            importWizard.portConflicts = response.portConflicts || [];
            importWizard.availableNetworks = response.networks || [];

            // Initialize defaults for wizard config from service data
            var svcKeys = Object.keys(importWizard.services);
            svcKeys.forEach(function(key) {
                var meta = importWizard.services[key];
                if (!importWizard.config.containerNames[key]) {
                    importWizard.config.containerNames[key] = meta.containerName || key;
                }
                if (!importWizard.config.networkConfig.perService[key]) {
                    importWizard.config.networkConfig.perService[key] = {
                        networkMode: meta.networkMode || 'default',
                        attachStackNet: !meta.networkMode || meta.networkMode === 'default',
                        externalNets: meta.networks || [],
                        ipv4Addresses: meta.networkIPs || {}
                    };
                }
                // Auto-seed discovered service networks into global externalNetworks list
                var svcNets = meta.networks || [];
                svcNets.forEach(function(netName) {
                    if (importWizard.config.networkConfig.externalNetworks.indexOf(netName) === -1) {
                        importWizard.config.networkConfig.externalNetworks.push(netName);
                    }
                });
                // Initialize healthcheck from service data
                if (!importWizard.config.healthchecks.hasOwnProperty(key)) {
                    var hcData = null;
                    if (meta.healthcheck) {
                        hcData = meta.healthcheck;
                    } else if (meta.guessedHealthcheck) {
                        hcData = meta.guessedHealthcheck;
                    }
                    if (hcData) {
                        // Store original test type (CMD/CMD-SHELL) and command text for comparison on save
                        var origType = 'CMD-SHELL';
                        var origCmd = '';
                        if (Array.isArray(hcData.test) && hcData.test.length > 0) {
                            origType = hcData.test[0];
                            origCmd = hcData.test.slice(1).join(' ');
                        } else if (typeof hcData.test === 'string') {
                            origCmd = hcData.test;
                        }
                        hcData.__originalTestType = origType;
                        hcData.__originalTestCmd = origCmd;
                        importWizard.config.healthchecks[key] = hcData;
                    } else {
                        importWizard.config.healthchecks[key] = null;
                    }
                }
                if (!importWizard.config.dependencies[key]) {
                    importWizard.config.dependencies[key] = [];
                }
            });

            renderImportStage2();
        }).fail(function() {
            $('#compose-import-modal-body').html('<div style="color:#f44336">Failed to contact server.</div>');
            $('#compose-import-modal-footer').html(wizardFooter({ back: 'renderImportStage1()' }));
        });
    }

    // ── Stage 2: Select Options ─────────────────────────────────────────────
    function renderImportStage2() {
        importWizard.stage = 2;
        $('#compose-import-stepper').html(renderWizardStepper(2));

        var cfg = importWizard.config;
        var netCfg = cfg.networkConfig;

        // Sanitize stack name preview helper
        function sanitizeName(name) {
            return name.toLowerCase().replace(/[^a-z0-9_-]/g, '_').replace(/^_+|_+$/g, '');
        }

        var html = '';

        // Stack name
        html += '<div class="import-field" style="margin-bottom:16px;">' +
            '<label><strong>Stack Name</strong> <span style="color:var(--status-error);">*</span></label>' +
            '<input type="text" id="iw-stack-name" value="' + composeEscapeHtml(cfg.stackName) + '" placeholder="my-stack">' +
            '<div id="iw-stack-name-preview" style="font-size:0.82rem;color:var(--alt-text-color);margin-top:4px;"></div>' +
            '</div>';

        // Stack Network
        var stackNetChecked = netCfg.stackNetwork.enabled ? ' checked' : '';
        html += '<div style="margin-bottom:16px;padding:14px;background:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:6px;">' +
            '<label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:10px;cursor:pointer;">' +
            '<input type="checkbox" id="iw-stack-net-enabled"' + stackNetChecked + '> Create Stack Network</label>' +
            '<div id="iw-stack-net-details"' + (netCfg.stackNetwork.enabled ? '' : ' style="display:none;"') + '>' +
            '<div class="import-field-row">' +
            '<div class="import-field iw-advanced-section"><label>Network Name</label>' +
            '<input type="text" id="iw-stack-net-name" value="' + composeEscapeHtml(netCfg.stackNetwork.name) + '" placeholder="stack_net"></div>' +
            '<div class="import-field iw-advanced-section"><label>Driver</label>' +
            '<input type="text" value="bridge" disabled style="opacity:0.6;"></div>' +
            '</div></div></div>';

        // External Networks (Advanced)
        var existingNets = importWizard.availableNetworks.filter(function(n) {
            return n.name !== 'bridge' && n.name !== 'host' && n.name !== 'none';
        });
        var selectedExt = netCfg.externalNetworks || [];
        html += '<div class="iw-advanced-section" style="margin-bottom:16px;padding:14px;background:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:6px;">' +
            '<div style="font-weight:600;margin-bottom:10px;">External Networks</div>';
        if (existingNets.length) {
            html += '<div class="import-net-checks" id="iw-ext-nets">';
            existingNets.forEach(function(net) {
                var checked = selectedExt.indexOf(net.name) >= 0 ? ' checked' : '';
                html += '<label><input type="checkbox" class="iw-ext-net-cb" value="' + composeEscapeHtml(net.name) + '"' + checked + '> ' +
                    composeEscapeHtml(net.name) + ' <small>(' + composeEscapeHtml(net.driver) + ')</small></label>';
            });
            html += '</div>';
        } else {
            html += '<div style="color:var(--alt-text-color);font-size:0.9rem;">No custom Docker networks found.</div>';
        }
        html += '<div class="import-ext-net-add">' +
            '<input type="text" id="iw-ext-net-custom" placeholder="Custom network name">' +
            '<button type="button" onclick="iwAddCustomNetwork()">Add</button>' +
            '</div></div>';

        $('#compose-import-modal-body').html(html);
        // Apply advanced/basic visibility
        if (!importWizard.advancedMode) $('.iw-advanced-section').hide();
        $('#compose-import-modal-footer').html(wizardFooter({
            back: 'renderImportStage1()',
            next: 'importWizardStage2Next()'
        }));

        // Wire up events
        $('#iw-stack-name').on('input', function() {
            var val = this.value;
            var san = sanitizeName(val);
            $('#iw-stack-name-preview').text(san ? 'Folder: ' + san : '');
            // Update default stack net name
            if ($('#iw-stack-net-name').val() === '' || $('#iw-stack-net-name').data('auto')) {
                $('#iw-stack-net-name').val(san ? san + '_net' : '').data('auto', true);
            }
        }).trigger('input');
        $('#iw-stack-net-name').on('input', function() { $(this).data('auto', false); });
        $('#iw-stack-net-enabled').on('change', function() {
            $('#iw-stack-net-details').toggle(this.checked);
        });
    }

    function iwSaveStage2() {
        var cfg = importWizard.config;
        var el = document.getElementById('iw-stack-name');
        if (el) cfg.stackName = el.value.trim();
        var netCfg = cfg.networkConfig;
        var netEl = document.getElementById('iw-stack-net-enabled');
        if (netEl) netCfg.stackNetwork.enabled = netEl.checked;
        var nameEl = document.getElementById('iw-stack-net-name');
        if (nameEl) netCfg.stackNetwork.name = nameEl.value.trim();
        var extNets = [];
        $('.iw-ext-net-cb:checked').each(function() { extNets.push(this.value); });
        netCfg.externalNetworks = extNets;
    }

    function iwAddCustomNetwork() {
        var name = document.getElementById('iw-ext-net-custom').value.trim();
        if (!name) return;
        // Check if already exists
        var exists = false;
        $('.iw-ext-net-cb').each(function() { if (this.value === name) exists = true; });
        if (exists) { document.getElementById('iw-ext-net-custom').value = ''; return; }
        var label = '<label><input type="checkbox" class="iw-ext-net-cb" value="' + composeEscapeHtml(name) + '" checked> ' +
            composeEscapeHtml(name) + ' <small>(custom)</small></label>';
        if ($('#iw-ext-nets').length) {
            $('#iw-ext-nets').append(label);
        } else {
            // Create the container if it didn't exist
            var container = '<div class="import-net-checks" id="iw-ext-nets">' + label + '</div>';
            $('.import-ext-net-add').before(container);
        }
        document.getElementById('iw-ext-net-custom').value = '';
    }

    function importWizardStage2Next() {
        iwSaveStage2();
        if (!importWizard.config.stackName) {
            swal('Stack name required', 'Please enter a stack name.', 'warning');
            return;
        }
        renderImportStage3();
    }

    // ── Stage 3: Configure Containers ───────────────────────────────────────
    function renderImportStage3() {
        importWizard.stage = 3;
        $('#compose-import-stepper').html(renderWizardStepper(3));

        var cfg = importWizard.config;
        var services = importWizard.services;
        var svcKeys = Object.keys(services);
        var netCfg = cfg.networkConfig;
        var html = '';

        // Port conflict banner
        if (importWizard.portConflicts.length) {
            html += '<div class="import-conflict-banner"><i class="fa fa-exclamation-triangle"></i><div>';
            importWizard.portConflicts.forEach(function(c) {
                html += '<div>Host port <strong>' + composeEscapeHtml(c.hostPort + '/' + c.protocol) +
                    '</strong> is mapped by: ' + c.services.map(composeEscapeHtml).join(', ') + '</div>';
            });
            html += '</div></div>';
        }

        // Build conflict lookup for ports
        var portConflictMap = {};
        importWizard.portConflicts.forEach(function(c) {
            c.services.forEach(function(svc) {
                if (!portConflictMap[svc]) portConflictMap[svc] = [];
                portConflictMap[svc].push(c);
            });
        });

        svcKeys.forEach(function(key, idx) {
            var meta = services[key];
            var cName = cfg.containerNames[key] || meta.containerName || key;
            var perSvc = netCfg.perService[key] || {};
            var netMode = perSvc.networkMode || 'default';
            var isNetModeRestricted = (netMode === 'host' || netMode === 'none' || netMode === 'bridge');
            var attachStackNet = perSvc.attachStackNet !== false;
            var svcExtNets = perSvc.externalNets || [];
            var hc = cfg.healthchecks[key];
            var hcSource = meta.healthcheckSource || 'none';
            if (hc && hcSource === 'none') hcSource = 'auto';

            // Card
            var expanded = idx === 0 ? ' expanded' : '';
            html += '<div class="import-service-card' + expanded + '" data-service="' + composeEscapeHtml(key) + '">';

            // Header
            var iconImg = meta.icon ? '<img class="import-service-card-icon" src="' + composeEscapeHtml(meta.icon) + '">' : '';
            html += '<div class="import-service-card-header" onclick="iwToggleCard(this)">' +
                iconImg +
                '<span class="import-service-card-title">' + composeEscapeHtml(key) + '</span>' +
                '<span class="import-service-card-image">' + composeEscapeHtml(meta.image) + '</span>' +
                '<i class="import-service-card-toggle fa fa-chevron-right"></i>' +
                '</div>';

            // Body
            html += '<div class="import-service-card-body">';

            // Row 1: Container name + network mode
            html += '<div class="import-field-row">';
            html += '<div class="import-field"><label>Container Name</label>' +
                '<input type="text" class="iw-container-name" data-service="' + composeEscapeHtml(key) + '" value="' + composeEscapeHtml(cName) + '">' +
                '<div class="import-field-error" id="iw-name-error-' + composeEscapeHtml(key) + '"></div></div>';
            html += '<div class="import-field iw-advanced-section"><label>Network Mode</label>' +
                '<select class="iw-net-mode" data-service="' + composeEscapeHtml(key) + '">' +
                '<option value="default"' + (netMode === 'default' ? ' selected' : '') + '>Default (compose managed)</option>' +
                '<option value="bridge"' + (netMode === 'bridge' ? ' selected' : '') + '>Bridge</option>' +
                '<option value="host"' + (netMode === 'host' ? ' selected' : '') + '>Host</option>' +
                '<option value="none"' + (netMode === 'none' ? ' selected' : '') + '>None</option>' +
                '</select></div>';
            html += '</div>';

            // Row 2: Network attachments (Advanced)
            // Build union of global external networks and per-service networks
            var allExtNets = (netCfg.externalNetworks || []).slice();
            svcExtNets.forEach(function(n) {
                if (allExtNets.indexOf(n) === -1) allExtNets.push(n);
            });
            var stackNetAvailable = netCfg.stackNetwork.enabled && netCfg.stackNetwork.name;
            var hasExtNets = allExtNets.length > 0;
            if (stackNetAvailable || hasExtNets) {
                html += '<div class="import-field iw-advanced-section" style="margin-bottom:14px;"><label>Network Attachments</label>';
                html += '<div class="import-net-checks iw-svc-nets" data-service="' + composeEscapeHtml(key) + '">';
                if (isNetModeRestricted) {
                    html += '<div style="font-size:0.85rem;color:var(--alt-text-color);margin-bottom:6px;"><i class="fa fa-info-circle"></i> Network attachments unavailable in ' + composeEscapeHtml(netMode) + ' mode</div>';
                }
                if (stackNetAvailable) {
                    var stackChecked = (attachStackNet && !isNetModeRestricted) ? ' checked' : '';
                    var stackDisabled = isNetModeRestricted ? ' disabled' : '';
                    var stackDisabledCls = isNetModeRestricted ? ' class="disabled"' : '';
                    html += '<label' + stackDisabledCls + '><input type="checkbox" class="iw-attach-stack" data-service="' + composeEscapeHtml(key) + '"' + stackChecked + stackDisabled + '> ' +
                        composeEscapeHtml(netCfg.stackNetwork.name) + ' <small>(stack)</small></label>';
                }
                allExtNets.forEach(function(en) {
                    var extChecked = (svcExtNets.indexOf(en) >= 0 && !isNetModeRestricted) ? ' checked' : '';
                    var extDisabled = isNetModeRestricted ? ' disabled' : '';
                    var extDisabledCls = isNetModeRestricted ? ' class="disabled"' : '';
                    html += '<label' + extDisabledCls + '><input type="checkbox" class="iw-attach-ext" data-service="' + composeEscapeHtml(key) + '" value="' + composeEscapeHtml(en) + '"' + extChecked + extDisabled + '> ' +
                        composeEscapeHtml(en) + ' <small>(external)</small></label>';
                });
                html += '</div>';

                // Static IP fields for attached networks
                var svcIPs = perSvc.ipv4Addresses || {};
                var attachedNets = [];
                if (stackNetAvailable && attachStackNet && !isNetModeRestricted) attachedNets.push(netCfg.stackNetwork.name);
                svcExtNets.forEach(function(n) { if (!isNetModeRestricted) attachedNets.push(n); });
                if (attachedNets.length > 0) {
                    html += '<div class="import-ip-fields" data-service="' + composeEscapeHtml(key) + '" style="margin-top:8px;">';
                    attachedNets.forEach(function(netName) {
                        var ipVal = svcIPs[netName] || '';
                        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">' +
                            '<label style="min-width:120px;font-size:0.85rem;">' + composeEscapeHtml(netName) + ' IP:</label>' +
                            '<input type="text" class="iw-ipv4-addr" data-service="' + composeEscapeHtml(key) + '" data-network="' + composeEscapeHtml(netName) + '" ' +
                            'value="' + composeEscapeHtml(ipVal) + '" placeholder="(auto)" style="max-width:180px;font-size:0.85rem;">' +
                            '</div>';
                    });
                    html += '</div>';
                }
                html += '</div>';
            }

            // Ports (read-only)
            if (meta.ports && meta.ports.length) {
                var conflicts = portConflictMap[key] || [];
                html += '<div class="import-field" style="margin-bottom:14px;"><label>Ports</label>';
                html += '<div class="import-port-list">';
                meta.ports.forEach(function(p) {
                    var displayIp = (p.hostIp && p.hostIp !== '0.0.0.0') ? (p.hostIp.indexOf(':') !== -1 ? '[' + p.hostIp + ']' : p.hostIp) : '';
                    var portStr = (displayIp ? displayIp + ':' : '') + (p.hostPort ? p.hostPort + ':' : '') + p.containerPort + '/' + p.protocol;
                    var isConflict = false;
                    conflicts.forEach(function(c) {
                        if (c.hostPort === p.hostPort && c.protocol === p.protocol) isConflict = true;
                    });
                    html += '<span class="import-port-tag' + (isConflict ? ' conflict' : '') + '">' + composeEscapeHtml(portStr);
                    if (isConflict) {
                        var otherSvcs = [];
                        conflicts.forEach(function(c) {
                            if (c.hostPort === p.hostPort && c.protocol === p.protocol) {
                                c.services.forEach(function(s) { if (s !== key) otherSvcs.push(s); });
                            }
                        });
                        html += ' <span class="import-conflict-badge" title="Conflicts with: ' + otherSvcs.map(composeEscapeHtml).join(', ') + '"><i class="fa fa-exclamation-triangle"></i></span>';
                    }
                    html += '</span>';
                });
                html += '</div></div>';
            }

            // Healthcheck section (Advanced)
            var hcSourceLabel = hcSource === 'image' ? 'From Image' : (hcSource === 'auto' ? 'Auto-Detected' : 'None');
            var hcSourceClass = hcSource === 'image' ? 'from-image' : (hcSource === 'auto' ? 'auto-detected' : 'none');
            var hcExpanded = hc ? ' expanded' : '';
            html += '<div class="import-healthcheck-section iw-advanced-section' + hcExpanded + '" data-service="' + composeEscapeHtml(key) + '">';
            html += '<div class="import-healthcheck-header" onclick="iwToggleHealthcheck(this)">' +
                '<span><i class="fa fa-heartbeat"></i> Healthcheck <span class="import-healthcheck-source ' + hcSourceClass + '">' + hcSourceLabel + '</span></span>' +
                '<i class="fa fa-chevron-right import-service-card-toggle"></i></div>';

            html += '<div class="import-healthcheck-fields">';

            var testCmd = '';
            if (hc && hc.test) {
                testCmd = Array.isArray(hc.test) ? (hc.test.length > 1 ? hc.test.slice(1).join(' ') : hc.test[0]) : hc.test;
            }
            var origTestCmd = (hc && hc.__originalTestCmd) || '';
            html += '<div class="import-field full-width"><label>Test Command</label>' +
                '<input type="text" class="iw-hc-test" data-service="' + composeEscapeHtml(key) + '" data-original-cmd="' + composeEscapeHtml(origTestCmd) + '" value="' + composeEscapeHtml(testCmd) + '" placeholder="curl -f http://localhost/ || exit 1"></div>';
            html += '<div class="import-field"><label>Interval</label>' +
                '<input type="text" class="iw-hc-interval" data-service="' + composeEscapeHtml(key) + '" value="' + composeEscapeHtml(hc ? (hc.interval || '30s') : '30s') + '"></div>';
            html += '<div class="import-field"><label>Timeout</label>' +
                '<input type="text" class="iw-hc-timeout" data-service="' + composeEscapeHtml(key) + '" value="' + composeEscapeHtml(hc ? (hc.timeout || '10s') : '10s') + '"></div>';
            html += '<div class="import-field"><label>Retries</label>' +
                '<input type="number" class="iw-hc-retries" data-service="' + composeEscapeHtml(key) + '" value="' + (hc ? (hc.retries || 3) : 3) + '" min="1" max="20"></div>';
            html += '<div class="import-field"><label>Start Period</label>' +
                '<input type="text" class="iw-hc-start-period" data-service="' + composeEscapeHtml(key) + '" value="' + composeEscapeHtml(hc ? (hc.start_period || '10s') : '10s') + '"></div>';
            html += '<div class="import-field"><label>&nbsp;</label>' +
                '<button type="button" class="editor-btn editor-btn-cancel iw-remove-hc-btn" style="padding:6px 14px;font-size:0.85rem;" data-svc="' + composeEscapeHtml(key) + '"><i class="fa fa-trash"></i> Remove</button></div>';
            html += '</div></div>'; // healthcheck section

            html += '</div></div>'; // card body + card
        });

        $('#compose-import-modal-body').html(html);
        // Apply advanced/basic visibility
        if (!importWizard.advancedMode) $('.iw-advanced-section').hide();
        $('#compose-import-modal-footer').html(wizardFooter({
            back: 'iwSaveStage3(); renderImportStage2()',
            next: 'importWizardStage3Next()',
            nextDisabled: false
        }));

        // Wire events
        $('#compose-import-modal-body').off('click.iwHcRm').on('click.iwHcRm', '.iw-remove-hc-btn', function() {
            iwRemoveHealthcheck($(this).data('svc'));
        });
        $('.iw-container-name').on('input', iwValidateContainerNames);
        $('.iw-net-mode').on('change', function() {
            var svc = $(this).data('service');
            var mode = this.value;
            var restricted = (mode === 'host' || mode === 'none' || mode === 'bridge');
            var $nets = $('.iw-svc-nets[data-service="' + svc + '"]');
            $nets.find('input[type="checkbox"]').prop('disabled', restricted);
            if (restricted) {
                $nets.find('input[type="checkbox"]').prop('checked', false);
                $nets.find('label').addClass('disabled');
            } else {
                $nets.find('label').removeClass('disabled');
            }
            iwRefreshIpFields(svc);
        });
        $('.iw-attach-stack, .iw-attach-ext').on('change', function() {
            iwRefreshIpFields($(this).data('service'));
        });
        iwValidateContainerNames();
    }

    function iwRefreshIpFields(svc) {
        var $container = $('.import-ip-fields[data-service="' + svc + '"]');
        if (!$container.length) return;
        // Gather currently checked networks
        var nets = [];
        var $card = $container.closest('.import-service-card');
        var mode = $card.find('.iw-net-mode').val();
        var restricted = (mode === 'host' || mode === 'none' || mode === 'bridge');
        if (!restricted) {
            $card.find('.iw-attach-stack:checked').each(function() {
                var netCfg = importWizard.config.networkConfig;
                if (netCfg.stackNetwork.enabled && netCfg.stackNetwork.name) {
                    nets.push(netCfg.stackNetwork.name);
                }
            });
            $card.find('.iw-attach-ext:checked').each(function() { nets.push(this.value); });
        }
        // Save existing IP values
        var existingIPs = {};
        $container.find('.iw-ipv4-addr').each(function() {
            existingIPs[$(this).data('network')] = this.value;
        });
        // Also check saved config
        var savedIPs = (importWizard.config.networkConfig.perService[svc] || {}).ipv4Addresses || {};
        // Rebuild IP fields
        var html = '';
        nets.forEach(function(netName) {
            var ipVal = existingIPs[netName] || savedIPs[netName] || '';
            html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">' +
                '<label style="min-width:120px;font-size:0.85rem;">' + composeEscapeHtml(netName) + ' IP:</label>' +
                '<input type="text" class="iw-ipv4-addr" data-service="' + composeEscapeHtml(svc) + '" data-network="' + composeEscapeHtml(netName) + '" ' +
                'value="' + composeEscapeHtml(ipVal) + '" placeholder="(auto)" style="max-width:180px;font-size:0.85rem;">' +
                '</div>';
        });
        $container.html(html);
    }

    function iwToggleCard(headerEl) {
        $(headerEl).closest('.import-service-card').toggleClass('expanded');
    }

    function iwToggleHealthcheck(headerEl) {
        $(headerEl).closest('.import-healthcheck-section').toggleClass('expanded');
    }

    function iwRemoveHealthcheck(svc) {
        importWizard.config.healthchecks[svc] = null;
        var $section = $('.import-healthcheck-section[data-service="' + svc + '"]');
        $section.removeClass('expanded');
        $section.find('.iw-hc-test').val('');
        $section.find('.import-healthcheck-source').text('None').attr('class', 'import-healthcheck-source none');
    }

    function iwValidateContainerNames() {
        var names = {};
        var hasDuplicates = false;
        $('.iw-container-name').each(function() {
            var svc = $(this).data('service');
            var val = this.value.trim();
            if (!names[val]) names[val] = [];
            names[val].push(svc);
        });
        $('.iw-container-name').each(function() {
            var svc = $(this).data('service');
            var val = this.value.trim();
            var $err = $('#iw-name-error-' + svc);
            if (val === '') {
                $(this).addClass('import-name-error');
                $err.text('Container name cannot be empty');
                hasDuplicates = true;
            } else if (names[val].length > 1) {
                $(this).addClass('import-name-error');
                $err.text('Duplicate name — conflicts with: ' + names[val].filter(function(s) { return s !== svc; }).join(', '));
                hasDuplicates = true;
            } else {
                $(this).removeClass('import-name-error');
                $err.text('');
            }
        });
        $('#import-wizard-next-btn').prop('disabled', hasDuplicates);
    }

    function iwSaveStage3() {
        var cfg = importWizard.config;
        // Save container names
        $('.iw-container-name').each(function() {
            cfg.containerNames[$(this).data('service')] = this.value.trim();
        });
        // Save network modes & attachments
        $('.iw-net-mode').each(function() {
            var svc = $(this).data('service');
            if (!cfg.networkConfig.perService[svc]) cfg.networkConfig.perService[svc] = {};
            cfg.networkConfig.perService[svc].networkMode = this.value;
        });
        $('.iw-attach-stack').each(function() {
            var svc = $(this).data('service');
            if (!cfg.networkConfig.perService[svc]) cfg.networkConfig.perService[svc] = {};
            cfg.networkConfig.perService[svc].attachStackNet = this.checked;
        });
        Object.keys(importWizard.services).forEach(function(svc) {
            var extNets = [];
            $('.iw-attach-ext[data-service="' + svc + '"]:checked').each(function() { extNets.push(this.value); });
            if (!cfg.networkConfig.perService[svc]) cfg.networkConfig.perService[svc] = {};
            cfg.networkConfig.perService[svc].externalNets = extNets;
            // Save static IP addresses
            var ips = {};
            $('.iw-ipv4-addr[data-service="' + svc + '"]').each(function() {
                var net = $(this).data('network');
                var ip = this.value.trim();
                if (net && ip) ips[net] = ip;
            });
            cfg.networkConfig.perService[svc].ipv4Addresses = ips;
        });
        // Save healthchecks
        Object.keys(importWizard.services).forEach(function(svc) {
            var $input = $('.iw-hc-test[data-service="' + svc + '"]');
            var testCmd = $input.val();
            if (testCmd && testCmd.trim()) {
                // Preserve original test type (CMD/CMD-SHELL) if command text unchanged
                var origCmd = $input.data('original-cmd') || '';
                var existingHc = cfg.healthchecks[svc];
                var testType = 'CMD-SHELL';
                if (testCmd.trim() === origCmd && existingHc && existingHc.__originalTestType) {
                    testType = existingHc.__originalTestType;
                }
                cfg.healthchecks[svc] = {
                    test: [testType, testCmd.trim()],
                    interval: $('.iw-hc-interval[data-service="' + svc + '"]').val() || '30s',
                    timeout: $('.iw-hc-timeout[data-service="' + svc + '"]').val() || '10s',
                    retries: parseInt($('.iw-hc-retries[data-service="' + svc + '"]').val()) || 3,
                    start_period: $('.iw-hc-start-period[data-service="' + svc + '"]').val() || '10s'
                };
            } else if (cfg.healthchecks[svc] !== null) {
                // Only nullify if not already explicitly removed
                cfg.healthchecks[svc] = null;
            }
        });
    }

    function importWizardStage3Next() {
        iwSaveStage3();
        // Check for name validation
        var hasDuplicates = false;
        var names = {};
        Object.keys(importWizard.config.containerNames).forEach(function(k) {
            var n = importWizard.config.containerNames[k];
            if (!n || names[n]) hasDuplicates = true;
            names[n] = true;
        });
        if (hasDuplicates) {
            swal('Name conflicts', 'Please resolve container name conflicts before continuing.', 'warning');
            return;
        }
        // In basic mode, skip dependencies and go straight to review
        if (!importWizard.advancedMode) {
            renderImportStageReview();
        } else {
            renderImportStage4();
        }
    }

    // ── Stage 4: Configure Dependencies ─────────────────────────────────────
    function renderImportStage4() {
        importWizard.stage = 4;
        $('#compose-import-stepper').html(renderWizardStepper(4));

        var svcKeys = Object.keys(importWizard.services);
        var deps = importWizard.config.dependencies;
        var html = '';

        if (svcKeys.length < 2) {
            html += '<div style="text-align:center;padding:40px 20px;color:var(--alt-text-color);font-size:1.05rem;">' +
                '<i class="fa fa-link" style="font-size:2rem;margin-bottom:12px;display:block;opacity:0.4;"></i>' +
                'Single container — no dependencies to configure.</div>';
            $('#compose-import-modal-body').html(html);
            $('#compose-import-modal-footer').html(wizardFooter({
                back: 'renderImportStage3()',
                next: 'importWizardStage4Next()',
                nextLabel: 'Next'
            }));
            return;
        }

        html += '<div id="iw-dep-cycle-error" class="import-dep-cycle-error" style="display:none;"></div>';

        html += '<table class="import-deps-table"><thead><tr><th>Service</th><th>Depends On</th><th></th></tr></thead><tbody>';
        svcKeys.forEach(function(key) {
            var svcDeps = deps[key] || [];
            html += '<tr><td style="font-weight:600;">' + composeEscapeHtml(key) + '</td>';
            html += '<td><div class="iw-dep-entries" data-service="' + composeEscapeHtml(key) + '">';
            if (svcDeps.length) {
                svcDeps.forEach(function(d) {
                    html += iwRenderDepRow(key, svcKeys, d, iwDepIdCounter++);
                });
            }
            html += '</div>';
            html += '<button type="button" class="import-dep-add" data-svc="' + composeEscapeHtml(key) + '"><i class="fa fa-plus"></i> Add Dependency</button>';
            html += '</td><td></td></tr>';
        });
        html += '</tbody></table>';

        html += '<div id="iw-startup-order" class="import-startup-order" style="display:none;"></div>';

        $('#compose-import-modal-body').html(html);
        $('#compose-import-modal-footer').html(wizardFooter({
            back: 'iwSaveDeps(); renderImportStage3()',
            next: 'importWizardStage4Next()'
        }));

        iwWireDepEvents($('#compose-import-modal-body'));
        iwCheckDependencyCycles();
    }

    function iwRenderDepRow(svc, allKeys, dep, index) {
        var targetSvc = dep ? dep.service : '';
        var condition = dep ? dep.condition : 'service_started';
        var otherKeys = allKeys.filter(function(k) { return k !== svc; });
        var html = '<div class="import-dep-row" data-index="' + index + '">';
        html += '<select class="iw-dep-target" data-service="' + composeEscapeHtml(svc) + '" data-index="' + index + '">';
        html += '<option value="">-- Select --</option>';
        otherKeys.forEach(function(k) {
            html += '<option value="' + composeEscapeHtml(k) + '"' + (k === targetSvc ? ' selected' : '') + '>' + composeEscapeHtml(k) + '</option>';
        });
        html += '</select>';

        var hasHC = !!importWizard.config.healthchecks[targetSvc];
        html += '<select class="iw-dep-condition" data-service="' + composeEscapeHtml(svc) + '" data-index="' + index + '">';
        html += '<option value="service_started"' + (condition === 'service_started' ? ' selected' : '') + '>service_started</option>';
        html += '<option value="service_healthy"' + (condition === 'service_healthy' ? ' selected' : '') + (!hasHC && condition !== 'service_healthy' ? ' disabled' : '') + '>service_healthy' + (!hasHC ? ' (no healthcheck)' : '') + '</option>';
        html += '</select>';

        html += '<button type="button" class="import-dep-remove" data-svc="' + composeEscapeHtml(svc) + '" data-index="' + index + '"><i class="fa fa-times"></i></button>';
        html += '</div>';
        return html;
    }

    function iwUpdateConditionDropdown($row) {
        var targetSvc = $row.find('.iw-dep-target').val();
        var $cond = $row.find('.iw-dep-condition');
        var hasHC = !!(targetSvc && importWizard.config.healthchecks[targetSvc]);
        var $healthy = $cond.find('option[value="service_healthy"]');
        if (hasHC) {
            $healthy.prop('disabled', false).text('service_healthy');
        } else {
            $healthy.prop('disabled', true).text('service_healthy (no healthcheck)');
            if ($cond.val() === 'service_healthy') {
                $cond.val('service_started');
            }
        }
    }

    function iwWireDepEvents($container) {
        $container.find('.iw-dep-target').off('change.iw').on('change.iw', function() {
            iwUpdateConditionDropdown($(this).closest('.import-dep-row'));
            iwCheckDependencyCycles();
        });
        $container.find('.iw-dep-condition').off('change.iw').on('change.iw', function() {
            iwCheckDependencyCycles();
        });
        $container.off('click.iwAdd').on('click.iwAdd', '.import-dep-add', function() {
            iwAddDep($(this).data('svc'));
        });
        $container.off('click.iwRm').on('click.iwRm', '.import-dep-remove', function() {
            iwRemoveDep($(this).data('svc'), $(this).data('index'));
        });
    }

    function iwAddDep(svc) {
        var $entries = $('.iw-dep-entries[data-service="' + svc + '"]');
        var allKeys = Object.keys(importWizard.services);
        var index = iwDepIdCounter++;
        $entries.append(iwRenderDepRow(svc, allKeys, null, index));
        iwWireDepEvents($entries);
    }

    function iwRemoveDep(svc, index) {
        var $entries = $('.iw-dep-entries[data-service="' + svc + '"]');
        $entries.find('.import-dep-row[data-index="' + index + '"]').remove();
        iwCheckDependencyCycles();
    }

    function iwCollectDeps() {
        var deps = {};
        Object.keys(importWizard.services).forEach(function(svc) {
            deps[svc] = [];
            $('.iw-dep-entries[data-service="' + svc + '"] .import-dep-row').each(function() {
                var target = $(this).find('.iw-dep-target').val();
                var condition = $(this).find('.iw-dep-condition').val();
                if (target) {
                    deps[svc].push({ service: target, condition: condition || 'service_started' });
                }
            });
        });
        return deps;
    }

    function iwSaveDeps() {
        importWizard.config.dependencies = iwCollectDeps();
    }

    function iwCheckDependencyCycles() {
        var deps = iwCollectDeps();
        var cycle = iwDetectCycle(deps);
        var $err = $('#iw-dep-cycle-error');
        var $order = $('#iw-startup-order');
        var $next = $('#import-wizard-next-btn');

        if (cycle) {
            $err.text('Dependency cycle detected: ' + cycle.join(' → ')).show();
            $next.prop('disabled', true);
            $order.hide();
        } else {
            $err.hide();
            $next.prop('disabled', false);
            // Show startup order via topological sort
            var order = iwTopologicalSort(deps);
            if (order.length > 1) {
                $order.html('<span>Startup order:</span> ' + order.map(composeEscapeHtml).join(' → ')).show();
            } else {
                $order.hide();
            }
        }
    }

    /**
     * Detect cycles in the dependency graph using DFS.
     * Returns the cycle path as an array, or null if no cycle.
     */
    function iwDetectCycle(deps) {
        var WHITE = 0, GRAY = 1, BLACK = 2;
        var color = {};
        var parent = {};
        var allNodes = Object.keys(deps);
        allNodes.forEach(function(n) { color[n] = WHITE; });

        for (var i = 0; i < allNodes.length; i++) {
            if (color[allNodes[i]] === WHITE) {
                var result = dfs(allNodes[i]);
                if (result) return result;
            }
        }
        return null;

        function dfs(u) {
            color[u] = GRAY;
            var edges = (deps[u] || []).map(function(d) { return d.service; }).filter(function(s) { return s; });
            for (var j = 0; j < edges.length; j++) {
                var v = edges[j];
                if (color[v] === GRAY) {
                    // Found cycle — reconstruct path
                    var path = [v, u];
                    var cur = u;
                    while (cur !== v && parent[cur]) {
                        cur = parent[cur];
                        path.push(cur);
                    }
                    return path.reverse();
                }
                if (color[v] === WHITE) {
                    parent[v] = u;
                    var res = dfs(v);
                    if (res) return res;
                }
            }
            color[u] = BLACK;
            return null;
        }
    }

    /**
     * Topological sort via Kahn's algorithm. Returns ordered array of service names.
     */
    function iwTopologicalSort(deps) {
        var inDegree = {};
        var adjList = {};
        var allNodes = Object.keys(deps);
        allNodes.forEach(function(n) { inDegree[n] = 0; adjList[n] = []; });
        allNodes.forEach(function(n) {
            (deps[n] || []).forEach(function(d) {
                if (d.service && adjList[d.service]) {
                    adjList[d.service].push(n);
                    inDegree[n] = (inDegree[n] || 0) + 1;
                }
            });
        });
        var queue = allNodes.filter(function(n) { return inDegree[n] === 0; });
        var order = [];
        while (queue.length) {
            var node = queue.shift();
            order.push(node);
            (adjList[node] || []).forEach(function(neighbor) {
                inDegree[neighbor]--;
                if (inDegree[neighbor] === 0) queue.push(neighbor);
            });
        }
        return order;
    }

    function importWizardStage4Next() {
        iwSaveDeps();
        var cycle = iwDetectCycle(importWizard.config.dependencies);
        if (cycle) {
            swal('Dependency cycle', 'Please resolve the dependency cycle before continuing.', 'warning');
            return;
        }
        renderImportStageReview();
    }

    // ── Stage 5: Review & Import (merged generating + validation) ───────────
    function renderImportStageReview() {
        importWizard.stage = 5;
        $('#compose-import-stepper').html(renderWizardStepper(5));
        $('#compose-import-modal-body').html(
            '<div class="import-generating"><i class="fa fa-spinner fa-spin"></i><span>Generating compose configuration...</span></div>'
        );
        $('#compose-import-modal-footer').html('');

        var cfg = importWizard.config;
        // Strip __-prefixed metadata keys from healthchecks before sending
        var cleanHC = {};
        Object.keys(cfg.healthchecks).forEach(function(svc) {
            var hc = cfg.healthchecks[svc];
            if (hc && typeof hc === 'object') {
                var clean = {};
                Object.keys(hc).forEach(function(k) { if (k.indexOf('__') !== 0) clean[k] = hc[k]; });
                cleanHC[svc] = clean;
            } else {
                cleanHC[svc] = hc;
            }
        });
        $.post(caURL, {
            action: 'finalizeImportCompose',
            containerIds: JSON.stringify(importWizard.containerIds),
            containerNames: JSON.stringify(cfg.containerNames),
            networkConfig: JSON.stringify(cfg.networkConfig),
            healthchecks: JSON.stringify(cleanHC),
            dependencies: JSON.stringify(cfg.dependencies)
        }, function(data) {
            var response;
            try { response = JSON.parse(data); } catch (e) { response = { result:'error', message: 'Invalid response' }; }
            if (response.result !== 'success') {
                $('#compose-import-modal-body').html('<div style="color:#f44336;padding:20px;">' +
                    composeEscapeHtml(response.message || 'Failed to generate compose configuration') + '</div>');
                var backTarget = importWizard.advancedMode ? 'renderImportStage4()' : 'renderImportStage3()';
                $('#compose-import-modal-footer').html(wizardFooter({ back: backTarget }));
                return;
            }
            importWizard.result = response;
            iwRenderReviewContent();
        }).fail(function() {
            $('#compose-import-modal-body').html('<div style="color:#f44336;padding:20px;">Communication error</div>');
            var backTarget = importWizard.advancedMode ? 'renderImportStage4()' : 'renderImportStage3()';
            $('#compose-import-modal-footer').html(wizardFooter({ back: backTarget }));
        });
    }

    function iwRenderReviewContent() {
        var result = importWizard.result;
        var validation = result.validation || { valid: true, errors: [] };
        var cfg = importWizard.config;
        var svcKeys = Object.keys(importWizard.services);
        var html = '';

        // Validation bar
        if (validation.valid) {
            html += '<div class="import-validation-bar valid"><i class="fa fa-check-circle"></i> Compose configuration is valid</div>';
        } else {
            html += '<div class="import-validation-bar error"><i class="fa fa-times-circle"></i> Validation errors found</div>';
            html += '<ul style="color:var(--status-error);margin:0 0 12px 20px;font-size:0.9rem;">';
            validation.errors.forEach(function(e) {
                html += '<li>' + composeEscapeHtml(e) + '</li>';
            });
            html += '</ul>';
        }

        // Summary panel
        var hcCount = 0;
        var depCount = 0;
        Object.keys(cfg.healthchecks).forEach(function(k) { if (cfg.healthchecks[k]) hcCount++; });
        Object.keys(cfg.dependencies).forEach(function(k) { if (cfg.dependencies[k] && cfg.dependencies[k].length) depCount++; });

        html += '<div class="import-summary-panel">' +
            '<div class="import-summary-item"><strong>Stack</strong>' + composeEscapeHtml(cfg.stackName) + '</div>' +
            '<div class="import-summary-item"><strong>Services</strong>' + svcKeys.length + '</div>' +
            '<div class="import-summary-item"><strong>Networks</strong>' +
                (cfg.networkConfig.stackNetwork.enabled ? composeEscapeHtml(cfg.networkConfig.stackNetwork.name) : 'None') +
                (cfg.networkConfig.externalNetworks.length ? ' + ' + cfg.networkConfig.externalNetworks.length + ' external' : '') +
            '</div>' +
            '<div class="import-summary-item"><strong>Healthchecks</strong>' + hcCount + '</div>' +
            '<div class="import-summary-item"><strong>Dependencies</strong>' + depCount + '</div>' +
            '</div>';

        // Port conflict warnings
        if (importWizard.portConflicts.length) {
            html += '<div class="import-conflict-banner" style="margin-bottom:12px;"><i class="fa fa-exclamation-triangle"></i><div>';
            importWizard.portConflicts.forEach(function(c) {
                html += '<div>Port <strong>' + composeEscapeHtml(c.hostPort + '/' + c.protocol) +
                    '</strong>: ' + c.services.map(composeEscapeHtml).join(', ') + '</div>';
            });
            html += '</div></div>';
        }

        // YAML preview
        html += '<div class="import-preview-section">' +
            '<div class="import-preview-section-title">compose.yaml</div>' +
            '<pre class="import-preview-pre" id="iw-yaml-preview">' + composeEscapeHtml(result.composeYml || '') + '</pre>' +
            '</div>';

        // Env preview
        if (result.env) {
            html += '<details class="import-preview-section"><summary class="import-preview-section-title" style="cursor:pointer;">.env file</summary>' +
                '<pre class="import-preview-pre">' + composeEscapeHtml(result.env) + '</pre></details>';
        }

        // Override preview
        if (result.override) {
            html += '<details class="import-preview-section"><summary class="import-preview-section-title" style="cursor:pointer;">Override file (icons/labels)</summary>' +
                '<pre class="import-preview-pre">' + composeEscapeHtml(result.override) + '</pre></details>';
        }

        // After Import actions — always visible
        html += '<div style="margin-top:16px;padding:14px;background:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:6px;">' +
            '<div style="font-weight:600;margin-bottom:10px;">After Import</div>' +
            '<label style="display:block;margin-bottom:6px;cursor:pointer;"><input type="checkbox" id="iw-stop-containers"' + (cfg.stopContainers ? ' checked' : '') + '> Stop original containers</label>' +
            '<label style="display:block;margin-bottom:6px;cursor:pointer;"><input type="checkbox" id="iw-remove-containers"' + (cfg.removeContainers ? ' checked' : '') + '> Remove original containers</label>' +
            '<label style="display:block;margin-bottom:6px;cursor:pointer;"><input type="checkbox" id="iw-start-stack"' + (cfg.startStack ? ' checked' : '') + '> Start imported stack</label>' +
            '<label style="display:block;cursor:pointer;"><input type="checkbox" id="iw-open-editor"' + (cfg.openEditor ? ' checked' : '') + '> Open editor after import</label>' +
            '</div>';

        $('#compose-import-modal-body').html(html);

        var backTarget = importWizard.advancedMode ? 'renderImportStage4()' : 'renderImportStage3()';
        $('#compose-import-modal-footer').html(wizardFooter({
            back: backTarget,
            action: 'importWizardConfirmAndPerform()',
            actionLabel: 'Import',
            actionDisabled: !validation.valid
        }));

        // Wire after-import checkbox constraints
        $('#iw-remove-containers').on('change', function() {
            if (this.checked) $('#iw-stop-containers').prop('checked', true);
            if (!this.checked) $('#iw-start-stack').prop('checked', false);
        });
        $('#iw-stop-containers').on('change', function() {
            if (!this.checked) {
                $('#iw-remove-containers').prop('checked', false);
                $('#iw-start-stack').prop('checked', false);
            }
        });
        $('#iw-start-stack').on('change', function() {
            if (this.checked) {
                $('#iw-stop-containers').prop('checked', true);
                $('#iw-remove-containers').prop('checked', true);
            }
        });
        $('#iw-open-editor').on('change', function() {
            importWizard.config.openEditor = this.checked;
        });
    }

    // ── Final: Confirm & Perform Import ─────────────────────────────────────
    function importWizardConfirmAndPerform() {
        // Save after-import options from the review stage
        var cfg = importWizard.config;
        cfg.stopContainers = document.getElementById('iw-stop-containers').checked;
        cfg.removeContainers = document.getElementById('iw-remove-containers').checked;
        cfg.startStack = document.getElementById('iw-start-stack').checked;

        var containerCount = importWizard.containerIds.length;

        // Build confirmation message for destructive actions
        if (cfg.stopContainers || cfg.removeContainers) {
            var actions = [];
            if (cfg.stopContainers) actions.push('stop');
            if (cfg.removeContainers) actions.push('remove');
            var actionText = actions.join(' and ');
            var msg = 'This will ' + actionText + ' ' + containerCount + ' original container' + (containerCount !== 1 ? 's' : '');
            if (cfg.startStack) msg += ', then start the imported stack';
            msg += '. Continue?';

            swal({
                title: 'Confirm Import',
                text: msg,
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, import',
                cancelButtonText: 'Cancel'
            }, function(confirmed) {
                if (confirmed) importWizardPerform();
            });
        } else {
            importWizardPerform();
        }
    }

    function importWizardPerform() {
        var cfg = importWizard.config;
        var result = importWizard.result;

        closeComposeImportModal();
        showComposeSpinner();

        $.post(caURL, {
            action: 'performImportTransfer',
            stackName: cfg.stackName,
            stackDesc: cfg.stackDesc,
            stopContainers: cfg.stopContainers ? '1' : '0',
            removeContainers: cfg.removeContainers ? '1' : '0',
            startStack: cfg.startStack ? '1' : '0',
            composeYml: result.composeYml || '',
            env: result.env || '',
            override: result.override || '',
            containerIds: JSON.stringify(importWizard.containerIds)
        }, function(data) {
            hideComposeSpinner();

            var response;
            try { response = JSON.parse(data); } catch (e) { response = { result:'error', message: 'Invalid response' }; }
            if (response.result === 'success') {
                composeLoadlist().then(function() {
                    if (cfg.openEditor) {
                        openEditorModalByProject(response.project, response.projectName);
                    }
                    if (response.startStack === 1 && response.projectPath) {
                        ComposeUpConfirmed(response.projectPath, "", true, true);
                    }
                    if (!cfg.openEditor) {
                        swal('Imported', 'Stack imported successfully.', 'success');
                    }
                }).catch(function() {
                    if (!cfg.openEditor) {
                        swal('Imported', 'Stack imported successfully.', 'success');
                    }
                });
            } else {
                swal('Import failed', response.message || 'Unknown error', 'error');
            }
        }).fail(function() {
            hideComposeSpinner();
            swal('Import failed', 'Communication error', 'error');
        });
    }

    // Keep legacy function names working (old callers)
    function composeImportPreview() { importWizardStage1Next(); }
    function composeImportPerform() { importWizardPerform(); }
    // Keep old stage names as aliases for any callers
    function renderImportStage5() { renderImportStageReview(); }
    function renderImportStage6() { iwRenderReviewContent(); }

    function stripTags(string) {
        return string.replace(/(<([^>]+)>)/ig, "");
    }

    function editName(myID) {
        var currentName = $("#" + myID).attr("data-namename");
        $("#" + myID).attr("data-originalName", currentName);
        var $el = $("#" + myID);
        $el.empty();
        var $input = $("<input type='text'>").attr('id', 'newName' + myID).val(currentName);
        var $cancel = $("<i class='fa fa-times' aria-hidden='true' style='cursor:pointer;color:red;font-size:1.2em'></i>").on('click', function() {
            cancelName(myID);
        });
        var $apply = $("<i class='fa fa-check' aria-hidden='true' style='cursor:pointer;color:green;font-size:1.2em'></i>").on('click', function() {
            applyName(myID);
        });
        $el.append($input).append($("<br>")).append($cancel).append("&nbsp;&nbsp;").append($apply);
        $el.tooltipster("close");
        $el.tooltipster("disable");
    }

    function editDesc(myID) {
        var origID = myID;
        $("#" + myID).tooltipster("close");
        myID = myID.replace("name", "desc");
        var currentDesc = $("#" + myID).text();
        $("#" + myID).attr("data-originaldescription", currentDesc);
        var $el = $("#" + myID);
        $el.empty();
        var $textarea = $("<textarea cols='40' rows='5'></textarea>").attr('id', 'newDesc' + myID).val(currentDesc);
        var $cancel = $("<i class='fa fa-times' aria-hidden='true' style='cursor:pointer;color:red;font-size:1.2em'></i>").on('click', function() {
            cancelDesc(myID);
        });
        var $apply = $("<i class='fa fa-check' aria-hidden='true' style='cursor:pointer;color:green;font-size:1.2em'></i>").on('click', function() {
            applyDesc(myID);
        });
        $el.append($textarea).append($("<br>")).append($cancel).append("&nbsp;&nbsp;").append($apply);
        $("#" + origID).tooltipster("enable");
    }

    function applyName(myID) {
        var newName = $("#newName" + myID).val();
        var project = $("#" + myID).attr("data-scriptname");
        $("#" + myID).text(newName);
        $("#" + myID).tooltipster("enable");
        $("#" + myID).tooltipster("close");
        $.post(caURL, {
            action: 'changeName',
            script: project,
            newName: newName
        }, function(data) {
            refreshStackByProject(project);
        });
    }

    function cancelName(myID) {
        var oldName = $("#" + myID).attr("data-originalName");
        $("#" + myID).text(oldName);
        $("#" + myID).tooltipster("enable");
        $("#" + myID).tooltipster("close");
    }

    function cancelDesc(myID) {
        var oldName = $("#" + myID).attr("data-originaldescription");
        $("#" + myID).text(oldName);
        $("#" + myID).tooltipster("enable");
        $("#" + myID).tooltipster("close");
    }

    var composeYamlSchemaCache = null;
    var composeYamlCustomTagPattern = /(^|[\s:[{,\-])!(override|reset|merge)\b/m;

    function getComposeYamlLibrary() {
        if (typeof jsyaml !== 'undefined') {
            return jsyaml;
        }

        if (typeof window !== 'undefined' && window.jsyaml) {
            return window.jsyaml;
        }

        return null;
    }

    function composeYamlContainsCustomTags(content) {
        return composeYamlCustomTagPattern.test(content || '');
    }

    function buildComposeYamlSchema() {
        var yamlLib = getComposeYamlLibrary();
        if (!yamlLib || typeof yamlLib.Type !== 'function' || !yamlLib.DEFAULT_SCHEMA || typeof yamlLib.DEFAULT_SCHEMA.extend !== 'function') {
            return null;
        }

        var customTags = ['!override', '!reset', '!merge'];
        var kinds = ['scalar', 'sequence', 'mapping'];
        var types = [];

        customTags.forEach(function(tag) {
            kinds.forEach(function(kind) {
                types.push(new yamlLib.Type(tag, {
                    kind: kind,
                    resolve: function() {
                        return true;
                    },
                    construct: function(data) {
                        if (data === null || data === undefined) {
                            if (kind === 'sequence') return [];
                            if (kind === 'mapping') return {};
                            return '';
                        }
                        return data;
                    }
                }));
            });
        });

        return yamlLib.DEFAULT_SCHEMA.extend(types);
    }

    function loadComposeYaml(content) {
        var input = content || '';
        var yamlLib = getComposeYamlLibrary();

        if (!yamlLib || typeof yamlLib.load !== 'function') {
            throw new Error('YAML parser is unavailable. Please reload the page and try again.');
        }

        if (!composeYamlSchemaCache) {
            composeYamlSchemaCache = buildComposeYamlSchema();
        }

        if (composeYamlSchemaCache) {
            return yamlLib.load(input, {
                schema: composeYamlSchemaCache
            });
        }
        return yamlLib.load(input);
    }

    function applyDesc(myID) {
        var newDesc = $("#newDesc" + myID).val();
        var project = $("#" + myID).attr("data-scriptname");
        // Use .text() with CSS white-space to avoid .html() XSS risk
        $("#" + myID).text(newDesc).css('white-space', 'pre-line');
        $.post(caURL, {
            action: 'changeDesc',
            script: project,
            newDesc: newDesc
        });
    }

    // Opens editor modal directly using myID element (from tooltipster)
    function editStack(myID) {
        $("#" + myID).tooltipster("close");
        var project = $("#" + myID).attr("data-scriptname");
        var projectName = $("#" + myID).attr("data-namename");
        openEditorModalByProject(project, projectName);
    }

    function generateProfiles(myID, myProject = null) {
        var project = myProject;
        if (myID) {
            $("#" + myID).tooltipster("close");
            project = $("#" + myID).attr("data-scriptname");
        }

        $.post(caURL, {
            action: 'getYml',
            script: project
        }, function(rawComposefile) {
            var project_profiles = new Set();
            if (rawComposefile) {
                var rawComposefile = JSON.parse(rawComposefile);

                if ((rawComposefile.result == 'success')) {
                    var main_doc = loadComposeYaml(rawComposefile.content);

                    for (var service_key in main_doc.services) {
                        var service = main_doc.services[service_key];
                        if (service.hasOwnProperty("profiles")) {
                            for (const profile of service.profiles) {
                                project_profiles.add(profile);
                            }
                        }
                    }

                    var rawProfiles = JSON.stringify(Array.from(project_profiles));
                    $.post(caURL, {
                        action: "saveProfiles",
                        script: project,
                        scriptContents: rawProfiles
                    }, function(data) {
                        if (!data) {
                            swal({
                                title: "Failed to update profiles.",
                                type: "error"
                            });
                            composeLogger('Failed to update profiles', {
                                project: project,
                                rawProfiles: rawProfiles,
                                response: data
                            }, 'user', 'error', 'stack-action');
                        }
                    });
                }
            }
        });
    }

    function editStackSettings(myID) {
        var project = $("#" + myID).attr("data-scriptname");

        $.post(caURL, {
            action: 'getEnvPath',
            script: project
        }, function(rawEnvPath) {
            if (rawEnvPath) {
                var rawEnvPath = JSON.parse(rawEnvPath);
                if (rawEnvPath.result == 'success') {
                    var formHtml = `<div class="swal-text" style="font-weight: bold; padding-left: 0px; margin-top: 0px;">ENV File Path</div>`;
                    formHtml += `<br>`;
                    formHtml += `<input type='text' id='env_path' class='swal-content__input' pattern="(\/mnt\/.*\/.+)" oninput="this.reportValidity()" title="A path under /mnt/user/ or /mnt/cache/ or /mnt/pool/" placeholder=Default value='${rawEnvPath.content}'>`;
                    swal({
                        title: "Stack Settings",
                        text: formHtml,
                        html: true,
                        showCancelButton: true,
                        confirmButtonText: "Save",
                        closeOnConfirm: false
                    }, function(confirmed) {
                        if (confirmed) {
                            var new_env_path = document.getElementById("env_path").value;
                            $.post(caURL, {
                                action: 'setEnvPath',
                                envPath: new_env_path,
                                script: project
                            }, function(data) {
                                var title = "Failed to set stack settings.";
                                var message = "";
                                var type = "error";
                                if (data) {
                                    try {
                                        var response = JSON.parse(data);
                                        if (response.result == "success") {
                                            title = "Success";
                                        }
                                        message = response.message;
                                        type = response.result;
                                    } catch (e) {
                                        message = "Invalid server response.";
                                    }
                                }
                                swal({
                                    title: title,
                                    text: message,
                                    type: type
                                }, function() {
                                    refreshStackByProject(project);
                                });
                            });
                        }
                    });
                }
            }
        });
    }

    // Unified update warning dialog - called from stack row and container table
    function showUpdateWarning(project, stackId) {
        var path = compose_root + '/' + project;
        // Use the existing UpdateStack function which already has the warning dialog
        UpdateStack(path, "");
    }

    // Show a brief swal when a background command is dispatched
    function notifyBackgroundStarted(label, shouldNotify = true) {
        if (!shouldNotify) return;

        swal({
            title: 'Running in background',
            text: label + ' has been started in the background.\nYou will receive a notification when it completes.',
            type: 'info',
            timer: 3000,
            showConfirmButton: false
        });
    }

    // Poll for background operation completion by checking if stack lock is released
    // Once lock is released, refresh the stack and clear the checking state
    function pollBackgroundCompletion(stackName, refreshDelayMs = 0) {
        // Poll with adaptive frequency to handle both fast and slow operations:
        //   0–60s:   every 2s   (30 checks)
        //   60s–5m:  every 5s   (48 checks)
        //   5m–30m:  every 15s  (100 checks)
        // Total coverage: ~30 minutes before giving up
        var elapsed = 0; // seconds
        var timeoutHandle;

        function getInterval() {
            if (elapsed < 60) return 2000;
            if (elapsed < 300) return 5000;
            return 15000;
        }

        function check() {
            $.post(caURL, {
                action: 'checkStackLock',
                script: stackName
            }, function(response) {
                try {
                    var parsed = JSON.parse(response);
                    if (parsed.result === 'success' && !parsed.locked) {
                        // Lock is released — clear spinner first, then fetch fresh data
                        // so refreshStackByProject always wins and overwrites the restored state
                        setStackActionInProgress(stackName, false);
                        setTimeout(function() {
                            refreshStackByProject(stackName);
                            processPendingUpdateChecks();
                        }, refreshDelayMs);
                        return; // stop scheduling
                    }
                } catch (e) {
                    composeLogger('Parse error', e, 'user', 'error', 'poll');
                }

                // Schedule next check if still within timeout (30 minutes)
                var interval = getInterval();
                elapsed += interval / 1000;
                if (elapsed < 1800) {
                    timeoutHandle = setTimeout(check, interval);
                } else {
                    composeLogger('Timeout after 30m', {
                        stack: stackName
                    }, 'user', 'warn', 'poll');
                    setStackActionInProgress(stackName, false);
                }
            });
        }

        // Start first check after 2 seconds
        elapsed += 2;
        timeoutHandle = setTimeout(check, 2000);
    }

    function composeActionStateText(actionName) {
        var map = {
            up: 'starting...',
            down: 'stopping...',
            stop: 'stopping...',
            restart: 'restarting...',
            pull: 'pulling...',
            update: 'updating...',
            forceUpdate: 'updating...',
            composeUpPullBuild: 'pulling and rebuilding...'
        };
        return map[actionName] || 'checking...';
    }

    function performComposeAction(opts) {
        opts = opts || {};
        var stackName = opts.stackName;
        var actionName = opts.actionName || '';
        var title = opts.title || (actionName ? actionName.replace(/([A-Z])/g, ' $1').trim() : 'Compose Action');
        var requestUrl = opts.requestUrl || compURL;
        var payload = opts.payload || {};
        var background = opts.background || false;
        var suppressBackgroundNotification = opts.suppressBackgroundNotification || false;
        var pendingReload = opts.pendingReload || false;
        var actionStateText = opts.actionStateText || composeActionStateText(actionName);
        var onComplete = opts.onComplete;

        if (pendingReload && stackName) {
            if (pendingComposeReloadStacks.indexOf(stackName) === -1) {
                pendingComposeReloadStacks.push(stackName);
                schedulePendingComposeReloads();
            }
        }

        if (stackName) {
            setStackActionInProgress(stackName, true, actionStateText);
        }

        payload.background = background ? 1 : 0;

        $.post(requestUrl, payload, function(data) {
            var parsed = tryParseJson(data);
            if (parsed && parsed.background) {
                if (stackName && !pendingReload) {
                    setStackActionInProgress(stackName, true, actionStateText);
                }
                if (!suppressBackgroundNotification) {
                    notifyBackgroundStarted(title, true);
                }
                if (stackName) {
                    pollBackgroundCompletion(stackName, opts.refreshDelayMs || 0);
                }
            } else if (data) {
                if (stackName && !pendingReload) {
                    setStackActionInProgress(stackName, false);
                }
                openBox(data, title, 800, 1200, true);
            }
            if (typeof onComplete === 'function') {
                onComplete(parsed, data);
            }
        }).fail(function() {
            if (stackName && !pendingReload) {
                setStackActionInProgress(stackName, false);
            }
            if (typeof onComplete === 'function') {
                onComplete(null, null);
            }
        });
    }

    function confirmedComposeAction(path, opts) {
        opts = opts || {};
        var stackName = basename(path);
        opts = $.extend(true, {
            actionName: '',
            titlePrefix: '',
            requestUrl: compURL,
            payload: {
                path: path,
                profile: opts.profile || ''
            },
            background: false,
            suppressBackgroundNotification: false,
            pendingReload: true,
            refreshDelayMs: 0,
            actionStateText: null,
            onComplete: null,
            preAction: null
        }, opts);

        if (opts.preAction) {
            opts.preAction(function() {
                var nextOpts = $.extend({}, opts);
                nextOpts.preAction = null;
                confirmedComposeAction(path, nextOpts);
            });
            return;
        }

        performComposeAction({
            stackName: stackName,
            actionName: opts.actionName,
            title: (opts.titlePrefix ? opts.titlePrefix + ': ' : '') + stackName,
            requestUrl: opts.requestUrl,
            payload: opts.payload,
            background: opts.background,
            suppressBackgroundNotification: opts.suppressBackgroundNotification,
            pendingReload: opts.pendingReload,
            refreshDelayMs: opts.refreshDelayMs,
            actionStateText: opts.actionStateText,
            onComplete: opts.onComplete
        });
    }

    // Confirmed action handlers (no dialog, just execute)
    function ComposeUpConfirmed(path, profile = "", background = false, suppressBackgroundNotification = false) {
        confirmedComposeAction(path, {
            actionName: 'up',
            titlePrefix: 'Compose Up',
            requestUrl: compURL,
            payload: {
                action: 'composeUp',
                path: path,
                profile: profile
            },
            background: background,
            suppressBackgroundNotification: suppressBackgroundNotification,
            pendingReload: true
        });
    }

    // Recreate containers without pulling (for label changes)
    function ComposeRecreateConfirmed(path, profile = "") {
        var height = 800;
        var width = 1200;

        $.post(compURL, {
            action: 'composeUpRecreate',
            path: path,
            profile: profile
        }, function(data) {
            if (data) {
                openBox(data, "Compose Recreate: " + basename(path), height, width, true);
            }
        })
    }

    function ComposeUp(path, profile = "") {
        showStackActionDialog('up', path, profile);
    }

    function ComposeDownConfirmed(path, profile = "", background = false, suppressBackgroundNotification = false) {
        confirmedComposeAction(path, {
            actionName: 'down',
            titlePrefix: 'Compose Down',
            requestUrl: compURL,
            payload: {
                action: 'composeDown',
                path: path,
                profile: profile
            },
            background: background,
            suppressBackgroundNotification: suppressBackgroundNotification,
            pendingReload: true
        });
    }

    function ComposeDown(path, profile = "") {
        showStackActionDialog('down', path, profile);
    }

    // Stop stack without removing containers
    function ComposeStopConfirmed(path, profile = "", background = false, suppressBackgroundNotification = false) {
        confirmedComposeAction(path, {
            actionName: 'stop',
            titlePrefix: 'Compose Stop',
            requestUrl: compURL,
            payload: {
                action: 'composeStop',
                path: path,
                profile: profile
            },
            background: background,
            suppressBackgroundNotification: suppressBackgroundNotification,
            pendingReload: true
        });
    }

    function ComposeStop(path, profile = "") {
        showStackActionDialog('stop', path, profile);
    }

    // Restart stack (recreate containers without pulling)
    function ComposeRestartConfirmed(path, profile = "", background = false, suppressBackgroundNotification = false) {
        confirmedComposeAction(path, {
            actionName: 'restart',
            titlePrefix: 'Compose Restart',
            requestUrl: compURL,
            payload: {
                action: 'composeUpRecreate',
                path: path,
                profile: profile
            },
            background: background,
            suppressBackgroundNotification: suppressBackgroundNotification,
            pendingReload: true,
            refreshDelayMs: 1000
        });
    }

    function ComposeRestart(path, profile = "") {
        showStackActionDialog('restart', path, profile);
    }

    // Force update stack (pull and rebuild even without detected updates)
    function ForceUpdateStackConfirmed(path, profile = "", background = false, suppressBackgroundNotification = false) {
        var stackName = basename(path);
        if (pendingUpdateCheckStacks.indexOf(stackName) === -1) {
            pendingUpdateCheckStacks.push(stackName);
        }

        confirmedComposeAction(path, {
            preAction: function(done) {
                $.post(caURL, {
                    action: 'markStackForRecheck',
                    stacks: JSON.stringify([stackName])
                }, done);
            },
            actionName: 'forceUpdate',
            titlePrefix: 'Force Update',
            requestUrl: compURL,
            payload: {
                action: 'composeUpPullBuild',
                path: path,
                profile: profile
            },
            background: background,
            suppressBackgroundNotification: suppressBackgroundNotification,
            pendingReload: true
        });
    }

    function ForceUpdateStack(path, profile = "") {
        showStackActionDialog('forceUpdate', path, profile);
    }

    // Prompt user to recreate containers after label changes
    function promptRecreateContainers() {
        var project = editorModal.currentProject;
        if (!project) {
            swal({
                title: "Saved!",
                text: "All changes have been saved.",
                type: "success",
                timer: 1500,
                showConfirmButton: false
            });
            return;
        }

        // Find the stack row and check if it's running
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
        var isUp = $stackRow.length > 0 && $stackRow.data('isup') == "1";

        if (!isUp) {
            // Stack is not running, close editor and show saved message
            doCloseEditorModal();
            swal({
                title: "Saved!",
                text: "All changes have been saved. Container labels will take effect when you start the stack.",
                type: "success"
            }, function() {
                refreshStackByProject(project);
            });
            return;
        }

        // Stack is running, ask if user wants to recreate
        var path = compose_root + '/' + project;
        swal({
            title: "Recreate Containers?",
            text: '<div style="text-align:left;max-width:400px;margin:0 auto;">' +
                '<p>Container labels (icon, WebUI) have been saved.</p>' +
                '<p class="compose-status-warning"><i class="fa fa-exclamation-triangle"></i> <strong>Containers must be recreated</strong> for these changes to take effect.</p>' +
                '<p class="compose-text-muted" style="font-size:0.9em;">This will briefly restart the affected containers. Your data will be preserved.</p>' +
                '</div>',
            html: true,
            type: "warning",
            showCancelButton: true,
            confirmButtonText: "Recreate Now",
            cancelButtonText: "Later",
            closeOnConfirm: true
        }, function(confirmed) {
            doCloseEditorModal();
            if (confirmed) {
                // Use setTimeout to ensure swal is fully closed before opening ttyd dialog
                setTimeout(function() {
                    ComposeRecreateConfirmed(path, "");
                }, 300);
            } else {
                swal({
                    title: "Saved!",
                    text: "Changes saved. Remember to restart or recreate containers to apply label changes.",
                    type: "info",
                    timer: 2000,
                    showConfirmButton: false
                }, function() {
                    refreshStackByProject(project);
                });
            }
        });
    }

    // Track stacks that need update check after operation completes
    // Using array to support Update All Stacks operation
    var pendingUpdateCheckStacks = [];

    // Process the queued stacks from pending update checks.
    // This is called from refreshStackRow and processPendingComposeReloads.
    function processPendingUpdateChecks() {
        if (!pendingUpdateCheckStacks || pendingUpdateCheckStacks.length === 0) {
            return;
        }

        var stacksToCheck = pendingUpdateCheckStacks.slice();
        pendingUpdateCheckStacks = [];
        var deferredStacks = [];

        // Delay slightly to let the UI settle after row refresh
        setTimeout(function() {
            composeLogger('Processing pending update checks', {
                stacks: stacksToCheck
            }, 'user', 'debug', 'update-check');

            stacksToCheck.forEach(function(stackName) {
                if (composeStackActionInProgress[stackName]) {
                    // Update action still in progress; defer until completion.
                    deferredStacks.push(stackName);
                    composeLogger('Deferring pending update check while action is in progress', {
                        stack: stackName
                    }, 'user', 'info', 'update-check');
                } else {
                    checkStackUpdates(stackName);
                }
            });

            if (deferredStacks.length > 0) {
                pendingUpdateCheckStacks = pendingUpdateCheckStacks.concat(deferredStacks);
            }
        }, 1000);
    }

    // >0 while refreshStackRow AJAX calls are in-flight; the loadlist
    // hook skips composeLoadlist until all pending refreshes complete.
    var pendingComposeRefreshCount = 0;

    // One-shot flag: consumed by the loadlist hook so the very next
    // loadlist call skips composeLoadlist even if refreshStackRow
    // already completed before loadlist fires.
    var skipNextComposeLoadlist = false;

    // Track stacks that need a full compose list reload after start/stop operations
    var pendingComposeReloadStacks = [];
    // Track stacks currently in progress (e.g. background start/stop/update)
    var composeStackActionInProgress = {};
    // Timer for batching compose reloads to avoid duplicate refreshes
    var pendingComposeReloadTimer = null;

    // Schedule processing of pending compose reloads (debounced)
    function schedulePendingComposeReloads(ms) {
        ms = ms || 500;
        if (pendingComposeReloadTimer) {
            clearTimeout(pendingComposeReloadTimer);
        }
        pendingComposeReloadTimer = setTimeout(function() {
            processPendingComposeReloads();
        }, ms);
    }

    // Process pending compose reloads: update parent rows from cache where possible,
    // otherwise fall back to a full composeLoadlist(). This centralizes reloads
    // so multiple triggers collapse into a single update.

    function processPendingComposeReloads() {
        if (pendingComposeReloadTimer) {
            clearTimeout(pendingComposeReloadTimer);
            pendingComposeReloadTimer = null;
        }
        if (!pendingComposeReloadStacks || pendingComposeReloadStacks.length === 0) return;
        var reloadStacks = pendingComposeReloadStacks.slice();
        // Clear queue immediately to avoid re-entrancy
        pendingComposeReloadStacks = [];
        composeLogger('processPendingComposeReloads', {
            stacks: reloadStacks.slice()
        }, 'user', 'info', 'ui-render');

        // If any of the target rows are missing from DOM, fallback to full reload
        var anyMissing = reloadStacks.some(function(project) {
            return $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]').length === 0;
        });
        if (anyMissing) {
            // Give docker a moment to settle then reload whole list
            setTimeout(function() {
                composeLoadlist();
            }, 400);
            return;
        }

        // Fetch fresh container data from server, then update each parent row.
        // The cache is stale after compose up/down so we must re-fetch.
        reloadStacks.forEach(function(project) {
            try {
                var stackId = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]').attr('id').replace('stack-row-', '');
                refreshStackRow(stackId, project);
            } catch (e) {
                composeLogger('update-failed', {
                    project: project,
                    err: e.toString()
                }, 'user', 'error', 'ui-render');
            }
        });

        // After reload sequence, process any pending update checks.
        processPendingUpdateChecks();
    }

    // Helper to refresh a single stack by project name (wrapper for refreshStackRow)
    function refreshStackByProject(project) {
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
        if ($stackRow.length > 0) {
            var stackId = $stackRow.attr('id').replace('stack-row-', '');
            refreshStackRow(stackId, project);
        }
    }

    // Fetch fresh container data from server and update the parent stack row.
    // Unlike updateParentStackFromContainers() which uses stale cache, this
    // always makes an AJAX call to get current container states.
    function refreshStackRow(stackId, project) {
        pendingComposeRefreshCount++;
        $.post(caURL, {
            action: 'getStackContainers',
            script: project
        }, function(data) {
            if (data) {
                try {
                    composeLogger('response', {
                        project: project,
                        data: data
                    }, 'user', 'info', 'refreshStackRow');
                    var response = JSON.parse(data);
                    if (response.result === 'success') {
                        var containers = response.containers || [];
                        // Normalize all containers via factory function (PascalCase→camelCase)
                        containers = containers.map(createContainerInfo).filter(Boolean);
                        // Merge saved update status so we don't lose checked info
                        mergeUpdateStatus(containers, project);
                        // Update cache with fresh data
                        stackContainersCache[stackId] = containers;
                        if (response.startedAt) stackStartedAtCache[stackId] = response.startedAt;
                        // Now update the row using the fresh cache
                        updateParentStackFromContainers(stackId, project);
                        // If details are expanded, refresh them too
                        // If details are expanded (by JS state or DOM visibility), refresh them
                        var $detailsRow = $('#details-row-' + stackId);
                        if (expandedStacks[stackId] || $detailsRow.is(':visible')) {
                            expandedStacks[stackId] = true; // re-sync JS state with DOM
                            renderContainerDetails(stackId, containers, project);
                            if (!$detailsRow.is(':visible')) {
                                $detailsRow.slideDown(200);
                            }
                        }
                    }
                } catch (e) {
                    composeLogger('parse-error', {
                        project: project,
                        err: e.toString()
                    }, 'user', 'error', 'refreshStackRow');
                    // Fallback: update from whatever cache we have
                    updateParentStackFromContainers(stackId, project);
                }
            }
            pendingComposeRefreshCount = Math.max(0, pendingComposeRefreshCount - 1);
            processPendingUpdateChecks();
        }).fail(function() {
            // On network failure, fall back to cache-based update
            updateParentStackFromContainers(stackId, project);
            pendingComposeRefreshCount = Math.max(0, pendingComposeRefreshCount - 1);
            processPendingUpdateChecks();
        });
    }

    // Toggle per-stack action-in-progress UI (replace status icon with spinner)
    function setStackActionInProgress(stackName, inProgress, text) {
        composeLogger('setStackActionInProgress', {
            stack: stackName,
            inProgress: inProgress,
            text: text
        }, 'user', 'info', 'stack-action');

        if (inProgress) {
            composeStackActionInProgress[stackName] = true;
        } else {
            delete composeStackActionInProgress[stackName];
        }

        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
        if ($stackRow.length === 0) return;
        $stackRow.data('action-in-progress', inProgress ? true : null);
        if (!inProgress) {
            $stackRow.removeData('action-in-progress');
        }
        var $icon = $stackRow.find('.compose-status-icon');
        var $state = $stackRow.find('.state');
        if (inProgress) {
            // Save original icon classes so we can restore them later
            if (!$icon.data('orig-class')) {
                $icon.data('orig-class', $icon.attr('class'));
            }
            // Use same spinner as containers to keep UI consistent
            $icon.removeClass().addClass('fa fa-refresh fa-spin compose-status-spinner compose-status-icon');
            $state.data('orig-text', $state.text());
            if (text) {
                $state.text(text);
            } else {
                $state.text('checking...');
            }
        } else {
            // Restore original icon classes if we saved them
            if ($icon.data('orig-class')) {
                $icon.removeClass().addClass($icon.data('orig-class'));
                $icon.removeData('orig-class');
            }
            // Restore original state text if present
            if ($state.data('orig-text')) {
                $state.text($state.data('orig-text'));
                $state.removeData('orig-text');
            }
        }
    }

    function UpdateStackConfirmed(path, profile = "", background = false, suppressBackgroundNotification = false) {
        var stackName = basename(path);
        if (pendingUpdateCheckStacks.indexOf(stackName) === -1) {
            pendingUpdateCheckStacks.push(stackName);
        }

        confirmedComposeAction(path, {
            preAction: function(done) {
                $.post(caURL, {
                    action: 'markStackForRecheck',
                    stacks: JSON.stringify([stackName])
                }, done);
            },
            actionName: 'update',
            titlePrefix: 'Update',
            requestUrl: compURL,
            payload: {
                action: 'composeUpPullBuild',
                path: path,
                profile: profile
            },
            background: background,
            suppressBackgroundNotification: suppressBackgroundNotification,
            pendingReload: true
        });
    }

    function UpdateStack(path, profile = "") {
        showStackActionDialog('update', path, profile);
    }

    // Start All Stacks function
    function startAllStacks() {
        var autostartOnly = $('#autostartOnlyToggle').is(':checked');
        var stacks = [];

        // Collect all stacks from the table
        $('#compose_stacks tr.compose-sortable').each(function() {
            var $row = $(this);
            var project = $row.data('project');
            var projectName = $row.data('projectname');
            var path = $row.data('path');
            var isUp = $row.data('isup');
            var autostart = $row.find('.auto_start').is(':checked');

            // Skip if autostart only mode and autostart is not enabled
            if (autostartOnly && !autostart) return;

            // Only include stopped stacks
            var $stateEl = $row.find('.state');
            var stateText = $stateEl.text();
            if (stateText === 'stopped' || !isUp) {
                stacks.push({
                    project: project,
                    projectName: projectName,
                    path: path
                });
            }
        });

        if (stacks.length === 0) {
            swal({
                title: 'No Stacks to Start',
                text: autostartOnly ? 'No stopped stacks with Autostart enabled found.' : 'No stopped stacks found.',
                type: 'info'
            });
            return;
        }

        var stackNames = stacks.map(function(s) {
            return composeEscapeHtml(s.projectName);
        }).join('<br>');
        var title = autostartOnly ? 'Start Autostart Stacks?' : 'Start All Stacks?';
        var confirmText = autostartOnly ? 'Yes, start ' + stacks.length + ' autostart stack' + (stacks.length > 1 ? 's' : '') : 'Yes, start ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');

        var bgCheckboxHtml = '<div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--dynamix-box-inner-div-border-color);display:flex;align-items:center;gap:8px;">' +
            '<input type="checkbox" id="swal-run-bg-startall" style="width:16px;height:16px;cursor:pointer;">' +
            '<label for="swal-run-bg-startall" style="cursor:pointer;user-select:none;margin:0;font-size:0.95em;">Run in background</label>' +
            '</div>';

        getConfig().then(function(pluginCfg) {
            var bgDefault = pluginCfg && pluginCfg.RUN_IN_BACKGROUND_DEFAULT === 'true';
            var disableWarnings = pluginCfg && pluginCfg.DISABLE_ACTION_WARNINGS === 'true';

            if (disableWarnings) {
                executeStartAllStacks(stacks, bgDefault, bgDefault);
                return;
            }

            swal({
                title: title,
                html: true,
                text: '<div style="background:var(--alt-background-color);text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be started:</p><div style="background:var(--background-color);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div>' + bgCheckboxHtml + '</div>',
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: 'Cancel'
            }, function(confirmed) {
                if (confirmed) {
                    var runInBackground = $('#swal-run-bg-startall').is(':checked');
                    executeStartAllStacks(stacks, runInBackground);
                }
            });

            setTimeout(function() {
                var $cb = $('#swal-run-bg-startall');
                if ($cb.length) $cb.prop('checked', bgDefault);
            }, 50);
        });
    }

    function executeStartAllStacks(stacks, background, suppressBackgroundNotification = false) {
        var height = 800;
        var width = 1200;

        // Create a list of paths to start
        var paths = stacks.map(function(s) {
            return s.path;
        });

        // Mark stacks for local reload and show per-row spinners
        stacks.forEach(function(s) {
            var stackName = s.project;
            if (pendingComposeReloadStacks.indexOf(stackName) === -1) pendingComposeReloadStacks.push(stackName);
            composeLogger('queued', {
                stack: stackName,
                pending: pendingComposeReloadStacks.slice()
            }, 'user', 'info', 'startAllStacks');
            setStackActionInProgress(stackName, true);
        });

        $.post(compURL, {
            action: 'composeUpMultiple',
            paths: JSON.stringify(paths),
            background: background ? 1 : 0
        }, function(data) {
            var parsed = tryParseJson(data);
            if (parsed && parsed.background) {
                notifyBackgroundStarted('Start All Stacks', !suppressBackgroundNotification);
                stacks.forEach(function(s) {
                    pollBackgroundCompletion(s.project);
                });
            } else if (data) {
                openBox(data, 'Start All Stacks', height, width, true);
            }
        });
    }

    // Stop All Stacks function
    function stopAllStacks() {
        var autostartOnly = $('#autostartOnlyToggle').is(':checked');
        var stacks = [];

        // Collect all stacks from the table
        $('#compose_stacks tr.compose-sortable').each(function() {
            var $row = $(this);
            var project = $row.data('project');
            var projectName = $row.data('projectname');
            var path = $row.data('path');
            var isUp = $row.data('isup');
            var autostart = $row.find('.auto_start').is(':checked');

            // Skip if autostart only mode and autostart is not enabled
            if (autostartOnly && !autostart) return;

            // Only include running stacks
            var $stateEl = $row.find('.state');
            var stateText = $stateEl.text();
            if (stateText !== 'stopped' && isUp) {
                stacks.push({
                    project: project,
                    projectName: projectName,
                    path: path
                });
            }
        });

        if (stacks.length === 0) {
            swal({
                title: 'No Stacks to Stop',
                text: autostartOnly ? 'No running stacks with Autostart enabled found.' : 'No running stacks found.',
                type: 'info'
            });
            return;
        }

        var stackNames = stacks.map(function(s) {
            return composeEscapeHtml(s.projectName);
        }).join('<br>');
        var title = autostartOnly ? 'Stop Autostart Stacks?' : 'Stop All Stacks?';
        var confirmText = autostartOnly ? 'Yes, stop ' + stacks.length + ' autostart stack' + (stacks.length > 1 ? 's' : '') : 'Yes, stop ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');

        var bgCheckboxHtml = '<div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--dynamix-box-inner-div-border-color);display:flex;align-items:center;gap:8px;">' +
            '<input type="checkbox" id="swal-run-bg-stopall" style="width:16px;height:16px;cursor:pointer;">' +
            '<label for="swal-run-bg-stopall" style="cursor:pointer;user-select:none;margin:0;font-size:0.95em;">Run in background</label>' +
            '</div>';

        getConfig().then(function(pluginCfg) {
            var bgDefault = pluginCfg && pluginCfg.RUN_IN_BACKGROUND_DEFAULT === 'true';
            var disableWarnings = pluginCfg && pluginCfg.DISABLE_ACTION_WARNINGS === 'true';

            if (disableWarnings) {
                executeStopAllStacks(stacks, bgDefault, bgDefault);
                return;
            }

            swal({
                title: title,
                html: true,
                text: '<div style="background:var(--alt-background-color);text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be stopped:</p><div style="background:var(--background-color);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div><p class="compose-status-warning" style="margin-top:10px;"><i class="fa fa-exclamation-triangle"></i> Containers will be stopped and removed. Data in volumes will be preserved.</p>' + bgCheckboxHtml + '</div>',
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: 'Cancel'
            }, function(confirmed) {
                if (confirmed) {
                    var runInBackground = $('#swal-run-bg-stopall').is(':checked');
                    executeStopAllStacks(stacks, runInBackground);
                }
            });

            setTimeout(function() {
                var $cb = $('#swal-run-bg-stopall');
                if ($cb.length) $cb.prop('checked', bgDefault);
            }, 50);
        });
    }

    function executeStopAllStacks(stacks, background, suppressBackgroundNotification = false) {
        var height = 800;
        var width = 1200;

        // Create a list of paths to stop
        var paths = stacks.map(function(s) {
            return s.path;
        });

        // Mark stacks for local reload and show per-row spinners
        stacks.forEach(function(s) {
            var stackName = s.project;
            if (pendingComposeReloadStacks.indexOf(stackName) === -1) pendingComposeReloadStacks.push(stackName);
            composeLogger('queued', {
                stack: stackName,
                pending: pendingComposeReloadStacks.slice()
            }, 'user', 'info', 'stopAllStacks');
            setStackActionInProgress(stackName, true);
        });

        $.post(compURL, {
            action: 'composeDownMultiple',
            paths: JSON.stringify(paths),
            background: background ? 1 : 0
        }, function(data) {
            var parsed = tryParseJson(data);
            if (parsed && parsed.background) {
                notifyBackgroundStarted('Stop All Stacks', !suppressBackgroundNotification);
                stacks.forEach(function(s) {
                    pollBackgroundCompletion(s.project);
                });
            } else if (data) {
                openBox(data, 'Stop All Stacks', height, width, true);
            }
        });
    }

    // Helper to merge update status into containers array
    // Uses createContainerInfo for consistent name resolution
    function mergeUpdateStatus(containers, project) {
        if (!containers || !stackUpdateStatus[project] || !stackUpdateStatus[project].containers) {
            return containers;
        }
        containers.forEach(function(container) {
            var cInfo = createContainerInfo(container);
            stackUpdateStatus[project].containers.forEach(function(update) {
                var uInfo = createContainerInfo(update);
                if (cInfo.name === uInfo.name) {
                    container.hasUpdate = uInfo.hasUpdate;
                    container.updateStatus = uInfo.updateStatus;
                    container.localSha = uInfo.localSha;
                    container.remoteSha = uInfo.remoteSha;
                }
            });
        });
        return containers;
    }

    // Unified stack action dialog - handles up, down, and update actions
    function showStackActionDialog(action, path, profile) {
        var stackName = basename(path);
        var project = stackName;

        // Find the stack row (scoped to compose_stacks)
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
        var stackId = '';
        var displayName = stackName; // Default to folder name
        var hasBuild = false;
        if ($stackRow.length > 0) {
            stackId = $stackRow.attr('id').replace('stack-row-', '');
            // Get display name from data attribute
            displayName = $stackRow.data('projectname') || stackName;
            hasBuild = $stackRow.data('hasbuild') == "1";
        }

        // Always use getProfileServices for both profile and no-profile cases
        var cachedContainers = (stackId && stackContainersCache[stackId]) ? stackContainersCache[stackId] : [];
        composeLogger('profile/unified AJAX start', {
            action,
            path,
            profile,
            stackId,
            cachedContainers
        }, 'user', 'debug', 'showStackActionDialog');
        $.post(caURL, {
            action: 'getProfileServices',
            script: project,
            profiles: profile || ''
        }, function(data) {
            var profileServices = [];
            try {
                var response = JSON.parse(data);
                if (response.result === 'success') {
                    profileServices = response.services || [];
                } else {
                    composeLogger('getProfileServices result!=success', {
                        response,
                        data
                    }, 'user', 'error', 'showStackActionDialog');
                }
            } catch (e) {
                composeLogger('getProfileServices JSON parse error', {
                    e,
                    data
                }, 'user', 'error', 'showStackActionDialog');
            }

            // Build a lookup of running containers by service name
            var containersByService = {};
            cachedContainers.forEach(function(ct) {
                var svc = (ct.service || ct.Service || '').toString();
                if (svc) containersByService[svc] = ct;
            });

            // Build the container list: prefer live/runtime container data,
            // then fall back to persistent cache, then to a minimal placeholder.
            var containers = [];
            profileServices.forEach(function(svc) {
                var container = containersByService[svc];
                if (!container) {
                    var persisted = getPersistentContainerInfo(project, svc);
                    if (persisted) {
                        container = createContainerInfo(persisted);
                    }
                }
                if (!container) {
                    container = {
                        service: svc,
                        name: svc,
                        state: 'stopped',
                        image: ''
                    };
                }
                containers.push(container);
            });

            composeLogger('profile/unified AJAX done', {
                profileServices,
                containers,
                cachedContainers
            }, 'user', 'debug', 'showStackActionDialog');
            containers = mergeUpdateStatus(containers, project);
            renderStackActionDialog(action, displayName, path, profile, containers, hasBuild);
        }).fail(function(xhr, status, error) {
            // Fallback: if profile resolution fails, show cached container metadata
            composeLogger('getProfileServices AJAX fail', {
                xhr,
                status,
                error,
                cachedContainers
            }, 'user', 'error', 'showStackActionDialog');
            var containers = [];
            if (cachedContainers.length > 0) {
                containers = mergeUpdateStatus(cachedContainers, project);
            } else if (persistentContainerCache[project]) {
                containers = Object.keys(persistentContainerCache[project]).map(function(key) {
                    return createContainerInfo(persistentContainerCache[project][key]);
                });
                containers = mergeUpdateStatus(containers, project);
            }
            renderStackActionDialog(action, displayName, path, profile, containers, hasBuild);
        });
        return;
    }

    function renderStackActionDialog(action, displayName, path, profile, containers, hasBuild) {
        hasBuild = hasBuild || false;
        // Action-specific configuration
        var pullLabel = hasBuild ? 'Build' : 'Pull';
        var config = {
            'up': {
                title: 'Compose Up: ' + composeEscapeHtml(displayName),
                description: 'This will create and start all containers in <b>' + composeEscapeHtml(displayName) + '</b>.',
                listTitle: 'CONTAINERS',
                warning: 'Images will be pulled if not present locally.',
                warningIcon: 'info-circle',
                warningColor: window.getComputedStyle(document.documentElement).getPropertyValue('--dynamix-ui-dropdownchecklist-color'),
                confirmText: 'Compose Up',
                showVersionArrow: false,
                confirmedFn: ComposeUpConfirmed
            },
            'down': {
                title: 'Compose Down: ' + composeEscapeHtml(displayName),
                description: 'This will stop and remove all containers in <b>' + composeEscapeHtml(displayName) + '</b>.',
                listTitle: 'CONTAINERS',
                warning: 'Containers will be removed but data in volumes is preserved.',
                warningIcon: 'exclamation-triangle',
                warningColor: window.getComputedStyle(document.documentElement).getPropertyValue('--dynamix-sb-message-link-color'),
                confirmText: 'Compose Down',
                showVersionArrow: false,
                confirmedFn: ComposeDownConfirmed
            },
            'stop': {
                title: 'Compose Stop: ' + composeEscapeHtml(displayName),
                description: 'This will stop all containers in <b>' + composeEscapeHtml(displayName) + '</b> without removing them.',
                listTitle: 'CONTAINERS',
                warning: 'Containers will be stopped but not removed. Use Compose Up to start them again.',
                warningIcon: 'info-circle',
                warningColor: window.getComputedStyle(document.documentElement).getPropertyValue('--dynamix-ui-dropdownchecklist-color'),
                confirmText: 'Compose Stop',
                showVersionArrow: false,
                confirmedFn: ComposeStopConfirmed
            },
            'restart': {
                title: 'Compose Restart: ' + composeEscapeHtml(displayName),
                description: 'This will restart all containers in <b>' + composeEscapeHtml(displayName) + '</b>.',
                listTitle: 'CONTAINERS',
                warning: 'Containers will be recreated. Data in volumes is preserved.',
                warningIcon: 'info-circle',
                warningColor: window.getComputedStyle(document.documentElement).getPropertyValue('--dynamix-ui-dropdownchecklist-color'),
                confirmText: 'Compose Restart',
                showVersionArrow: false,
                confirmedFn: ComposeRestartConfirmed
            },
            'pull': {
                title: pullLabel + ': ' + composeEscapeHtml(displayName),
                description: 'This will ' + (hasBuild ? 'build images for' : 'pull the latest images for') + ' <b>' + composeEscapeHtml(displayName) + '</b> without starting containers.',
                listTitle: 'CONTAINERS',
                warning: hasBuild ? 'Images will be built from Dockerfile.' : 'Images will be pulled from the registry.',
                warningIcon: 'info-circle',
                warningColor: window.getComputedStyle(document.documentElement).getPropertyValue('--dynamix-ui-dropdownchecklist-color'),
                confirmText: pullLabel,
                showVersionArrow: false,
                confirmedFn: ComposePullConfirmed
            },
            'update': {
                title: 'Update: ' + composeEscapeHtml(displayName),
                description: 'This will pull the latest images and recreate containers in <b>' + composeEscapeHtml(displayName) + '</b>.',
                listTitle: 'CONTAINERS',
                warning: 'Running containers will be recreated with the latest images.',
                warningIcon: 'exclamation-triangle',
                warningColor: window.getComputedStyle(document.documentElement).getPropertyValue('--brand-orange') || window.getComputedStyle(document.documentElement).getPropertyValue('--dynamix-sb-message-link-color'),
                confirmText: 'Update',
                showVersionArrow: true,
                confirmedFn: UpdateStackConfirmed
            },
            'forceUpdate': {
                title: 'Force Update: ' + composeEscapeHtml(displayName),
                description: 'This will pull the latest images and rebuild containers in <b>' + composeEscapeHtml(displayName) + '</b>, even if no updates are detected.',
                listTitle: 'CONTAINERS',
                warning: 'All containers will be recreated with freshly pulled images.',
                warningIcon: 'exclamation-triangle',
                warningColor: window.getComputedStyle(document.documentElement).getPropertyValue('--brand-orange') || window.getComputedStyle(document.documentElement).getPropertyValue('--dynamix-sb-message-link-color'),
                confirmText: 'Force Update',
                showVersionArrow: false,
                confirmedFn: ForceUpdateStackConfirmed
            }
        };
        var cfg = config[action];
        if (!cfg) return;

        // Build HTML content for the dialog
        var html = '<div style="text-align:left;max-width:450px;margin:0 auto;color:var(--text-color, var(--dynamix-sb-body-text-color));">';
        html += '<div style="margin-bottom:18px;">' + cfg.description + '</div>';

        // Container list with icons
        if (containers && containers.length > 0) {
            html += '<div style="background:var(--alt-background-color);border-radius:6px;padding:12px 14px;margin:12px 0;">';
            html += '<div class="compose-text-muted" style="font-weight:bold;margin-bottom:10px;font-size:0.9em;"><i class="fa fa-cubes"></i> ' + cfg.listTitle + '</div>';

            containers.forEach(function(container, index) {
                var containerName = container.name || container.service || 'Unknown';
                var shortName = container.service || containerName.replace(/^[^-]+-/, '');
                var image = container.image || '';
                var imageParts = image.split(':');
                var imageName = imageParts[0].split('/').pop();
                var imageTag = imageParts[1] || 'latest';
                var state = container.state || 'unknown';
                var stateClass = state === 'running' ? 'compose-status-success' : (state === 'paused' ? 'compose-status-warning' : 'compose-text-muted');
                var stateIcon = state === 'running' ? 'play' : (state === 'paused' ? 'pause' : 'square');

                // Check if this container has an update available
                var hasUpdate = container.hasUpdate || false;
                var updateStatus = container.updateStatus || 'unknown';
                var localSha = container.localSha || '';
                var remoteSha = container.remoteSha || '';

                var iconSrc = (container.icon && isValidIconSrc(container.icon)) ?
                    composeEscapeAttr(container.icon) :
                    '/plugins/dynamix.docker.manager/images/question.png';

                // Grey out containers without updates when showing update dialog
                var rowOpacity = (cfg.showVersionArrow && !hasUpdate && updateStatus === 'up-to-date') ? '0.5' : '1';
                var isLast = (index === containers.length - 1);
                var borderStyle = isLast ? '' : 'border-bottom:1px solid var(--dynamix-box-inner-div-border-color);';

                html += '<div style="display:flex;align-items:center;padding:8px 4px;' + borderStyle + 'opacity:' + rowOpacity + ';">';
                html += '<img src="' + iconSrc + '" style="width:28px;height:28px;margin-right:10px;border-radius:4px;" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
                html += '<div style="flex:1;">';
                html += '<div style="font-weight:bold;">' + composeEscapeHtml(shortName);
                // Show update badge if update is available (for update action)
                if (cfg.showVersionArrow && hasUpdate) {
                    html += ' <span class="compose-status-warning" style="font-size:0.7em;padding:2px 6px;border-radius:3px;margin-left:6px;">UPDATE</span>';
                } else if (cfg.showVersionArrow && updateStatus === 'up-to-date') {
                    html += ' <span class="compose-status-success" style="font-size:0.8em;margin-left:6px;"><i class="fa fa-check"></i></span>';
                }
                html += '</div>';
                html += '<div class="compose-text-muted" style="font-size:0.85em;margin-top:2px;">';
                html += '<i class="fa fa-' + stateIcon + ' ' + stateClass + '" style="margin-right:4px;"></i>';
                html += composeEscapeHtml(imageName) + ' : <span class="compose-status-info">' + composeEscapeHtml(imageTag) + '</span>';

                // Show SHA info for update action
                if (cfg.showVersionArrow) {
                    if (hasUpdate && localSha && remoteSha) {
                        // Has update - show current SHA → new SHA
                        html += '<div style="font-family:var(--font-bitstream);font-size:0.9em;margin-top:2px;">';
                        html += '<span class="compose-status-warning" title="' + composeEscapeAttr(localSha) + '">' + composeEscapeHtml(localSha.substring(0, 8)) + '</span>';
                        html += ' <i class="fa fa-arrow-right compose-status-success" style="margin:0 4px;"></i> ';
                        html += '<span class="compose-status-success" title="' + composeEscapeAttr(remoteSha) + '">' + composeEscapeHtml(remoteSha.substring(0, 8)) + '</span>';
                        html += '</div>';
                    } else if (localSha) {
                        // No update - just show current SHA (greyed)
                        html += '<div style="font-family:var(--font-bitstream);font-size:0.9em;margin-top:2px;" title="' + composeEscapeAttr(localSha) + '"><span class="compose-text-muted">' + composeEscapeHtml(localSha.substring(0, 8)) + '</span></div>';
                    }
                }
                html += '</div></div></div>';
            });

            html += '</div>';
        }

        // Warning/info text
        html += '<div style="color:' + cfg.warningColor + ';margin-top:14px;font-size:0.9em;"><i class="fa fa-' + cfg.warningIcon + '"></i> ' + cfg.warning + '</div>';

        // Run-in-background checkbox (appended after config is fetched below)
        var bgCheckboxHtml = '<div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--dynamix-box-inner-div-border-color);display:flex;align-items:center;gap:8px;">' +
            '<input type="checkbox" id="swal-run-bg-checkbox" style="width:16px;height:16px;cursor:pointer;">' +
            '<label for="swal-run-bg-checkbox" style="cursor:pointer;user-select:none;margin:0;font-size:0.95em;">Run in background</label>' +
            '</div>';
        html += bgCheckboxHtml;
        html += '</div>';

        // Fetch config to determine default checkbox state, then show swal (or skip warnings)
        getConfig().then(function(pluginCfg) {
            var bgDefault = pluginCfg && pluginCfg.RUN_IN_BACKGROUND_DEFAULT === 'true';
            var disableWarnings = pluginCfg && pluginCfg.DISABLE_ACTION_WARNINGS === 'true';

            if (disableWarnings) {
                // In default background mode (warnings disabled and background enabled), don't show toast if background is used
                cfg.confirmedFn(path, profile, bgDefault, bgDefault);
                return;
            }

            // Use native swal (SweetAlert 1.x) with callback style
            swal({
                title: cfg.title,
                text: html,
                html: true,
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: cfg.confirmText,
                cancelButtonText: 'Cancel'
            }, function(confirmed) {
                if (confirmed) {
                    // Capture checkbox state before swal destroys the DOM
                    var runInBackground = $('#swal-run-bg-checkbox').is(':checked');
                    // when running in background, suppress the extra notifyBackgroundStarted popup
                    cfg.confirmedFn(path, profile, runInBackground, runInBackground);
                }
            });

            // Set checkbox default state after swal renders (small delay for DOM)
            setTimeout(function() {
                var $cb = $('#swal-run-bg-checkbox');
                if ($cb.length) {
                    $cb.prop('checked', bgDefault);
                }
            }, 50);
        });
    }

    function ViewLastCmdLog(project, displayName) {
        $.post(caURL, {
            action: 'getLastCmdLog',
            script: project
        }, function(response) {
            var parsed = tryParseJson(response);
            if (!parsed || parsed.result !== 'success') {
                swal({
                    title: 'Error',
                    text: 'Could not retrieve the log.',
                    type: 'error'
                });
                return;
            }
            if (!parsed.log) {
                swal({
                    title: 'No log available',
                    text: 'No command log has been saved for ' + composeEscapeHtml(displayName) + ' yet.\nRun a command to generate one.',
                    type: 'info'
                });
                return;
            }
            // Show log in a swal with a scrollable pre block
            var logHtml = '<div style="text-align:left;">' +
                '<pre style="background:var(--background-color, var(--dynamix-sb-body-bg-color)); color:var(--text-color, var(--dynamix-sb-body-text-color, var(--black))); border:1px solid var(--table-border-color, var(--dynamix-box-inner-div-border-color)); border-radius:4px;padding:12px;max-height:400px;overflow-y:auto;' +
                'font-size:0.82em;line-height:1.4;white-space:pre-wrap;word-break:break-all;">' +
                composeEscapeHtml(parsed.log) + '</pre></div>';
            swal({
                title: 'Last Cmd Log: ' + composeEscapeHtml(displayName),
                text: logHtml,
                html: true,
                type: null,
                confirmButtonText: 'Close'
            });
        });
    }

    function ComposePullConfirmed(path, profile = "", background = false, suppressBackgroundNotification = false) {
        var stackName = basename(path);
        performComposeAction({
            stackName: stackName,
            actionName: 'pull',
            title: 'Compose Pull: ' + stackName,
            requestUrl: compURL,
            payload: {
                action: 'composePull',
                path: path,
                profile: profile
            },
            background: background,
            suppressBackgroundNotification: suppressBackgroundNotification,
            pendingReload: true
        });
    }

    function ComposePull(path, profile = "") {
        showStackActionDialog('pull', path, profile);
    }

    function ComposeLogs(pathOrProject, profile = "") {
        var height = Math.min(screen.availHeight, 800);
        var width = Math.min(screen.availWidth, 1200);
        // Support both project name (legacy) and path
        var path = pathOrProject.includes('/') ? pathOrProject : compose_root + "/" + pathOrProject;
        $.post(compURL, {
            action: 'composeLogs',
            path: path,
            profile: profile
        }, function(data) {
            if (data) {
                window.open(data, 'Logs_' + basename(path),
                    'height=' + height + ',width=' + width + ',resizable=yes,scrollbars=yes');
            }
        })
    }

    // ============================================
    // Stack Actions Menu Functions
    // ============================================
    var currentStackId = null;
    var expandedStacks = {};
    var stackContainersCache = {};
    var persistentContainerCache = {}; // Persistent cache loaded from disk
    var stackStartedAtCache = {}; // Cache for stack-level started_at timestamps
    // Track stacks currently loading details to prevent concurrent reloads
    var stackDetailsLoading = {};
    // Suppress immediate refresh after a render to avoid loops
    var stackDetailsJustRendered = {};

    function openStackActionsMenu(event, stackId) {
        event.stopPropagation();
        currentStackId = stackId;

        var $row = $('#stack-row-' + stackId);
        var projectName = $row.data('projectname');
        var isUp = $row.data('isup') == "1";

        // Update modal title
        $('.stack-actions-modal-title').text(projectName);

        // Show/hide certain actions based on state
        // Delete is disabled when stack is running
        var $deleteBtn = $('.stack-action-item:contains("Delete Stack")');
        if (isUp) {
            $deleteBtn.addClass('disabled').prop('disabled', true);
        } else {
            $deleteBtn.removeClass('disabled').prop('disabled', false);
        }

        // Position and show modal
        var $modal = $('#stack-actions-modal');
        var $overlay = $('#stack-actions-overlay');

        // Get button position for modal placement
        var $btn = $('#kebab-' + stackId);
        var btnOffset = $btn.offset();
        var btnHeight = $btn.outerHeight();

        // Position modal near the button
        $modal.css({
            top: btnOffset.top + btnHeight + 5,
            right: $(window).width() - btnOffset.left - $btn.outerWidth()
        });

        $overlay.show();
        $modal.show();
    }

    function closeStackActionsMenu() {
        $('#stack-actions-modal').hide();
        $('#stack-actions-overlay').hide();
        currentStackId = null;
    }

    function executeStackAction(action) {
        if (!currentStackId) return;

        var $row = $('#stack-row-' + currentStackId);
        var project = $row.data('project');
        var projectName = $row.data('projectname');
        var path = $row.data('path');
        var profiles = $row.data('profiles') || [];
        var isUp = $row.data('isup') == "1";

        closeStackActionsMenu();

        // Handle profile selection if profiles exist and action supports it
        var profileSupportedActions = ['up', 'down', 'update', 'pull', 'logs'];
        if (profiles.length > 0 && profileSupportedActions.includes(action)) {
            showProfileSelector(action, path, profiles);
            return;
        }

        switch (action) {
            case 'up':
                ComposeUp(path);
                break;
            case 'down':
                ComposeDown(path);
                break;
            case 'update':
                UpdateStack(path);
                break;
            case 'pull':
                ComposePull(path);
                break;
            case 'logs':
                ComposeLogs(path);
                break;
            case 'viewCmdLog':
                ViewLastCmdLog(project, projectName);
                break;
            case 'edit':
                openEditorModalByProject(project, projectName);
                break;
            case 'delete':
                if (!isUp) {
                    deleteStackByProject(project, projectName);
                }
                break;
        }
    }

    function showProfileSelector(action, path, profiles) {
        var actionNames = {
            'up': 'Compose Up',
            'down': 'Compose Down',
            'stop': 'Compose Stop',
            'restart': 'Compose Restart',
            'update': 'Update',
            'forceUpdate': 'Force Update',
            'pull': 'Compose Pull',
            'logs': 'Compose Logs'
        };

        // Build profile selection UI:
        // - Default services (no profile) are always included and non-toggleable.
        // - "All profile-based services" enables every profile via "*".
        // - Individual profiles can be multi-selected when all-profiles is off.
        var profileHtml = '<div style="text-align: left;">';
        profileHtml += '<div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--dynamix-box-inner-div-border-color);">';
        profileHtml += '<label style="font-weight:bold;"><input type="checkbox" id="profile_default" checked disabled> Default services (no profile)</label>';
        profileHtml += '</div>';
        profileHtml += '<div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--dynamix-box-inner-div-border-color);">';
        profileHtml += '<label style="font-weight:bold;"><input type="checkbox" id="profile_all_profiles" checked onchange="toggleAllProfiles(this)"> All profile-based services (*)</label>';
        profileHtml += '</div>';
        profileHtml += '<div id="profile_list">';
        profiles.forEach(function(profile) {
            profileHtml += '<label style="display:block;margin:5px 0;"><input type="checkbox" class="profile_checkbox" value="' + composeEscapeHtml(profile) + '" disabled> ' + composeEscapeHtml(profile) + '</label>';
        });
        profileHtml += '</div>';
        profileHtml += '<div class="compose-text-muted" style="margin-top:10px;font-size:0.9em;"><i class="fa fa-info-circle"></i> Default services are always included. Select multiple profiles to include profile-based services.</div>';
        profileHtml += '</div>';

        swal({
            title: "Select Profiles",
            text: "Choose which profiles to use for " + actionNames[action] + "<br><br>" + profileHtml,
            html: true,
            showCancelButton: true,
            confirmButtonText: "Continue",
            cancelButtonText: "Cancel"
        }, function(confirmed) {
            if (confirmed) {
                var selectedProfiles = [];
                if (!$('#profile_all_profiles').is(':checked')) {
                    $('.profile_checkbox:checked').each(function() {
                        selectedProfiles.push($(this).val());
                    });
                }
                // Use "*" when all profile-based services are requested.
                // Empty profile string means default services only.
                var profileStr = $('#profile_all_profiles').is(':checked') ? '*' : selectedProfiles.join(',');
                switch (action) {
                    case 'up':
                        ComposeUp(path, profileStr);
                        break;
                    case 'down':
                        ComposeDown(path, profileStr);
                        break;
                    case 'stop':
                        ComposeStop(path, profileStr);
                        break;
                    case 'restart':
                        ComposeRestart(path, profileStr);
                        break;
                    case 'update':
                        UpdateStack(path, profileStr);
                        break;
                    case 'forceUpdate':
                        ForceUpdateStack(path, profileStr);
                        break;
                    case 'pull':
                        ComposePull(path, profileStr);
                        break;
                    case 'logs':
                        ComposeLogs(path, profileStr);
                        break;
                }
            }
        });
    }

    // Toggle individual profile checkboxes when all-profile scope is enabled/disabled
    function toggleAllProfiles(checkbox) {
        var disabled = checkbox.checked;
        $('.profile_checkbox').prop('disabled', disabled).prop('checked', false);
    }

    function openEditorModalByProject(project, projectName, initialTab) {
        if (typeof ace === 'undefined') {
            swal({
                title: 'Editor Unavailable',
                text: 'The Ace editor library could not be loaded. Please reload the page or verify the plugin installation.',
                type: 'error'
            });
            return;
        }

        editorModal.currentProject = project;
        editorModal.modifiedTabs = new Set();
        editorModal.modifiedSettings = new Set();
        editorModal.modifiedLabels = new Set();
        editorModal.originalContent = {};
        editorModal.originalSettings = {};
        editorModal.originalLabels = {};
        editorModal.labelsData = null;

        // Reset all tabs to unmodified state
        $('.editor-tab').removeClass('modified active');
        $('.editor-main-tab').removeClass('modified active');
        $('.editor-container').removeClass('active');
        $('.editor-panel').removeClass('active');

        // Set modal title
        $('#editor-modal-title').text('Editing: ' + projectName);
        $('#editor-file-info').text(compose_root + '/' + project);

        // Ensure overlay is in top-level document layer for tabbed mode
        // Appending to body makes the modal full-screen and avoids nested overflow limitations.
        $('#editor-modal-overlay').appendTo('body').addClass('active');
        $('#editor-validation-compose').html('<i class="fa fa-spinner fa-spin editor-validation-icon"></i> Loading files...').removeClass('valid error warning');

        // Load all files and settings
        loadEditorFiles(project);
        loadSettingsData(project, projectName);

        // Switch to appropriate initial tab (default to 'compose')
        var targetTab = initialTab || 'compose';
        switchTab(targetTab);
        setTimeout(function() {
            refreshEditorContents(targetTab);
            refreshEditorContents('compose');
            refreshEditorContents('env');
        }, 100);
    }

    function loadEditorFiles(project) {
        var loadPromises = [];

        // Load compose file
        loadPromises.push(
            $.post(caURL, {
                action: 'getYml',
                script: project
            }).then(function(data) {
                if (data) {
                    var response = jQuery.parseJSON(data);
                    editorModal.originalContent['compose'] = response.content || '';
                    if (editorModal.editors['compose']) editorModal.editors['compose'].setValue(response.content || '', -1);
                }
            }).fail(function() {
                var errorContent = '# Error loading file';
                editorModal.originalContent['compose'] = errorContent;
                if (editorModal.editors['compose']) editorModal.editors['compose'].setValue(errorContent, -1);
            })
        );

        // Load env file
        loadPromises.push(
            $.post(caURL, {
                action: 'getEnv',
                script: project
            }).then(function(data) {
                if (data) {
                    var response = jQuery.parseJSON(data);
                    editorModal.originalContent['env'] = response.content || '';
                    if (editorModal.editors['env']) editorModal.editors['env'].setValue(response.content || '', -1);
                }
            }).fail(function() {
                var errorContent = '# Error loading file';
                editorModal.originalContent['env'] = errorContent;
                if (editorModal.editors['env']) editorModal.editors['env'].setValue(errorContent, -1);
            })
        );

        // When all files are loaded
        $.when.apply($, loadPromises).then(function() {
            // Run validation on compose file
            var composeContent = editorModal.editors['compose'] ? editorModal.editors['compose'].getValue() : (editorModal.originalContent['compose'] || '');
            validateYaml('compose', composeContent);
        }).fail(function() {
            $('#editor-validation-compose').html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Error loading some files').removeClass('valid').addClass('error');
        });
    }

    // Load settings data into the settings panel
    function loadSettingsData(project, projectName) {
        // Set the name from projectName (display name)
        $('#settings-name').val(projectName || '');
        editorModal.originalSettings['name'] = projectName || '';

        // Load description
        $.post(caURL, {
            action: 'getDescription',
            script: project
        }).then(function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    var desc = (response.content || '').replace(/<br>/g, '\n');
                    $('#settings-description').val(desc);
                    editorModal.originalSettings['description'] = desc;
                } catch (e) {
                    $('#settings-description').val('');
                    editorModal.originalSettings['description'] = '';
                }
            }
        }).fail(function() {
            $('#settings-description').val('');
            editorModal.originalSettings['description'] = '';
        });

        // Load stack settings (icon URL and env path)
        $.post(caURL, {
            action: 'getStackSettings',
            script: project
        }).then(function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                } catch (e) {
                    return;
                }
                if (response.result === 'success') {
                    // Icon URL
                    var iconUrl = response.iconUrl || '';
                    $('#settings-icon-url').val(iconUrl);
                    editorModal.originalSettings['icon-url'] = iconUrl;
                    if (iconUrl && isValidIconSrc(iconUrl)) {
                        $('#settings-icon-preview-img').attr('src', iconUrl);
                        $('#settings-icon-preview').show();
                    } else {
                        $('#settings-icon-preview').hide();
                    }

                    // WebUI URL
                    var webuiUrl = response.webuiUrl || '';
                    $('#settings-webui-url').val(webuiUrl);
                    editorModal.originalSettings['webui-url'] = webuiUrl;

                    // Detect suggested WebUI URL
                    $('#settings-webui-suggestion').hide();
                    $.post(caURL, {
                        action: 'detectWebui',
                        script: project
                    }).then(function(detectData) {
                        try {
                            var dr = JSON.parse(detectData);
                        } catch (e) {
                            return;
                        }
                        if (dr.result === 'success' && dr.detected && dr.detected.url) {
                            var currentVal = $('#settings-webui-url').val();
                            if (currentVal === dr.detected.url) return; // already set to detected value
                            $('#settings-webui-detected-url').text(dr.detected.url);
                            $('#settings-webui-detected-source').text('(from ' + dr.detected.source + ')');
                            $('#settings-webui-suggestion').show();
                            $('#settings-webui-use-btn').off('click').on('click', function() {
                                $('#settings-webui-url').val(dr.detected.url).trigger('input');
                                $('#settings-webui-suggestion').hide();
                            });
                        }
                    });

                    // ENV path
                    var envPath = response.envPath || '';
                    $('#settings-env-path').val(envPath);
                    editorModal.originalSettings['env-path'] = envPath;

                    // External compose path
                    var externalComposePath = response.externalComposePath || '';
                    var invalidIndirectPath = response.invalidIndirectPath || '';
                    if (!externalComposePath && invalidIndirectPath) {
                        // Pre-populate with the broken path so the user can fix it
                        $('#settings-external-compose-path').val(invalidIndirectPath);
                        editorModal.originalSettings['external-compose-path'] = '';
                        editorModal.modifiedSettings.add('external-compose-path');
                        $('#settings-invalid-indirect-warning').show();
                        $('#settings-external-compose-info').hide();
                    } else {
                        $('#settings-external-compose-path').val(externalComposePath);
                        editorModal.originalSettings['external-compose-path'] = externalComposePath;
                        $('#settings-invalid-indirect-warning').hide();
                        if (externalComposePath) {
                            $('#settings-external-compose-info').show();
                        } else {
                            $('#settings-external-compose-info').hide();
                        }
                    }

                    // Default profile
                    var defaultProfile = response.defaultProfile || '';
                    $('#settings-default-profile').val(defaultProfile);
                    editorModal.originalSettings['default-profile'] = defaultProfile;

                    // Available profiles (from the profiles file)
                    var availableProfiles = response.availableProfiles || [];
                    if (availableProfiles.length > 0) {
                        $('#settings-profiles-list').text(availableProfiles.join(', '));
                        $('#settings-available-profiles').show();
                    } else {
                        $('#settings-available-profiles').hide();
                    }
                }
            }
        }).fail(function() {
            $('#settings-icon-url').val('');
            $('#settings-webui-url').val('');
            $('#settings-env-path').val('');
            $('#settings-default-profile').val('');
            $('#settings-external-compose-path').val('');
            editorModal.originalSettings['icon-url'] = '';
            editorModal.originalSettings['webui-url'] = '';
            editorModal.originalSettings['env-path'] = '';
            editorModal.originalSettings['default-profile'] = '';
            editorModal.originalSettings['external-compose-path'] = '';
            $('#settings-icon-preview').hide();
            $('#settings-available-profiles').hide();
            $('#settings-external-compose-info').hide();
            $('#settings-invalid-indirect-warning').hide();
        });
    }

    // Load labels data for the WebUI Labels panel
    function loadLabelsData() {
        var project = editorModal.currentProject;
        if (!project) return;

        $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-spinner fa-spin"></i> Loading services...</div>');

        // Load both compose file and override file to build the labels UI
        var composePromise = $.post(caURL, {
            action: 'getYml',
            script: project
        });
        var overridePromise = $.post(caURL, {
            action: 'getOverride',
            script: project
        });

        $.when(composePromise, overridePromise).then(function(composeResult, overrideResult) {
            try {
                var composeData = JSON.parse(composeResult[0]);
                var overrideData = JSON.parse(overrideResult[0]);

                if (composeData.result !== 'success') {
                    throw new Error('Failed to load compose file');
                }

                var mainDoc = loadComposeYaml(composeData.content) || {
                    services: {}
                };
                var overrideDoc = loadComposeYaml(overrideData.content || '') || {
                    services: {}
                };

                // Ensure override has services object
                if (!overrideDoc.services) {
                    overrideDoc.services = {};
                }

                editorModal.labelsData = {
                    mainDoc: mainDoc,
                    overrideDoc: overrideDoc,
                    overrideContent: overrideData.content || '',
                    overrideHasCustomTags: composeYamlContainsCustomTags(overrideData.content || '')
                };

                renderLabelsUI(mainDoc, overrideDoc);

            } catch (e) {
                composeLogger('Failed to parse compose files for labels', {
                    error: e && e.toString()
                }, 'user', 'error', 'loadLabelsData');
                $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-exclamation-triangle"></i> Error loading services: ' + composeEscapeHtml(e.message) + '</div>');
            }
        }).fail(function() {
            $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-exclamation-triangle"></i> Failed to load compose files</div>');
        });
    }

    // Render the WebUI Labels UI
    function renderLabelsUI(mainDoc, overrideDoc) {
        var html = '';
        var deletedHtml = '';
        var hasServices = false;
        var hasDeletedServices = false;

        // Process services from main compose file
        for (var serviceKey in mainDoc.services) {
            hasServices = true;
            var service = mainDoc.services[serviceKey];
            var overrideService = overrideDoc.services[serviceKey] || {
                labels: {}
            };

            // Ensure override service has proper structure
            if (!overrideService.labels) {
                overrideDoc.services[serviceKey] = overrideDoc.services[serviceKey] || {};
                overrideDoc.services[serviceKey].labels = overrideDoc.services[serviceKey].labels || {};
                overrideDoc.services[serviceKey].labels[<?php echo json_encode($docker_label_managed); ?>] = <?php echo json_encode($docker_label_managed_name); ?>;
                overrideService = overrideDoc.services[serviceKey];
            }

            var containerName = service.container_name || serviceKey;
            var iconValue = findLabelValue(overrideService, service, icon_label);
            var webuiValue = findLabelValue(overrideService, service, webui_label);
            var shellValue = findLabelValue(overrideService, service, shell_label);

            // Store original values
            editorModal.originalLabels[serviceKey + '_icon'] = iconValue;
            editorModal.originalLabels[serviceKey + '_webui'] = webuiValue;
            editorModal.originalLabels[serviceKey + '_shell'] = shellValue;

            var iconSrc = iconValue || '/plugins/dynamix.docker.manager/images/question.png';
            html += '<div class="labels-service" data-service="' + composeEscapeAttr(serviceKey) + '">';
            html += '<div class="labels-service-header">';
            html += '<img class="labels-service-icon" id="label-icon-preview-' + composeEscapeAttr(serviceKey) + '" src="' + composeEscapeAttr(iconSrc) + '" alt="" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
            html += '<span class="labels-service-name">' + composeEscapeHtml(containerName) + '</span>';
            html += '</div>';
            html += '<div class="labels-service-fields">';
            html += '<div class="labels-field">';
            html += '<label><i class="fa fa-picture-o"></i> Icon URL / Path</label>';
            html += '<input type="text" id="label-' + composeEscapeAttr(serviceKey) + '-icon" value="' + composeEscapeAttr(iconValue) + '" placeholder="https://example.com/icon.png or /path/to/icon.png" data-service="' + composeEscapeAttr(serviceKey) + '" data-field="icon" data-pickroot="/" data-picktop="/boot/config/plugins/compose.manager/projects" data-pickcloseonfile="true" data-pickfilter="png,jpg,jpeg,gif,svg,ico,webp">';
            html += '</div>';
            html += '<div class="labels-field">';
            html += '<label><i class="fa fa-globe"></i> WebUI URL</label>';
            html += '<input type="text" id="label-' + composeEscapeAttr(serviceKey) + '-webui" value="' + composeEscapeAttr(webuiValue) + '" placeholder="http://[IP]:[PORT:8080]/" data-service="' + composeEscapeAttr(serviceKey) + '" data-field="webui">';
            html += '</div>';
            html += '<div class="labels-field">';
            html += '<label><i class="fa fa-terminal"></i> Shell</label>';
            html += '<input type="text" id="label-' + composeEscapeAttr(serviceKey) + '-shell" value="' + composeEscapeAttr(shellValue) + '" placeholder="/bin/bash" data-service="' + composeEscapeAttr(serviceKey) + '" data-field="shell">';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        }

        // Check for orphaned services in override that aren't in main (e.g., after rename)
        for (var serviceKey in overrideDoc.services) {
            if (!(serviceKey in mainDoc.services)) {
                hasDeletedServices = true;
                var overrideService = overrideDoc.services[serviceKey];
                var containerName = (overrideService && overrideService.container_name) || serviceKey;
                var iconValue = findLabelValue(overrideService, {}, icon_label);
                var webuiValue = findLabelValue(overrideService, {}, webui_label);
                var shellValue = findLabelValue(overrideService, {}, shell_label);

                var deletedIconSrc = iconValue || '/plugins/dynamix.docker.manager/images/question.png';
                deletedHtml += '<div class="labels-service deleted" data-service="' + composeEscapeAttr(serviceKey) + '" data-deleted="true">';
                deletedHtml += '<div class="labels-service-header">';
                deletedHtml += '<img class="labels-service-icon" src="' + composeEscapeAttr(deletedIconSrc) + '" alt="" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
                deletedHtml += '<span class="labels-service-name">' + composeEscapeHtml(containerName) + ' <span class="compose-status-danger" style="font-size:0.8em;">(will be removed on save)</span></span>';
                deletedHtml += '</div>';
                deletedHtml += '<div class="labels-service-fields">';
                deletedHtml += '<div class="labels-field"><label><i class="fa fa-picture-o"></i> Icon</label><input type="text" id="orphan-' + composeEscapeAttr(serviceKey) + '-icon" value="' + composeEscapeAttr(iconValue) + '" readonly></div>';
                deletedHtml += '<div class="labels-field"><label><i class="fa fa-globe"></i> WebUI</label><input type="text" id="orphan-' + composeEscapeAttr(serviceKey) + '-webui" value="' + composeEscapeAttr(webuiValue) + '" readonly></div>';
                deletedHtml += '<div class="labels-field"><label><i class="fa fa-terminal"></i> Shell</label><input type="text" id="orphan-' + composeEscapeAttr(serviceKey) + '-shell" value="' + composeEscapeAttr(shellValue) + '" readonly></div>';
                deletedHtml += '</div>';
                deletedHtml += '</div>';
            }
        }

        if (!hasServices) {
            html = '<div class="labels-empty-state"><i class="fa fa-cubes"></i> No services defined in compose file</div>';
        }

        if (hasDeletedServices) {
            html += '<div class="labels-deleted-section">';
            html += '<div class="labels-deleted-title" onclick="toggleDeletedServices(this)"><i class="fa fa-chevron-right"></i> Orphaned Services (copy values before saving)</div>';
            html += '<div class="labels-deleted-services">' + deletedHtml + '</div>';
            html += '</div>';
        }

        $('#labels-services-container').html(html);

        // Attach file tree picker to container icon inputs
        if ($.fn.fileTreeAttach && typeof composeBindFileTreeInputs === 'function') {
            var $iconInputs = $('#labels-services-container').find('input[data-pickroot]');
            composeBindFileTreeInputs($iconInputs, {
                zIndex: 100010,
                minWidth: 320,
                addClass: true
            });
        }

        // Attach change handlers to label inputs
        $('#labels-services-container').find('input[data-service]').on('input', function() {
            var service = $(this).data('service');
            var field = $(this).data('field');
            var key = service + '_' + field;
            var currentValue = $(this).val();
            var originalValue = editorModal.originalLabels[key] || '';

            if (currentValue !== originalValue) {
                editorModal.modifiedLabels.add(key);
            } else {
                editorModal.modifiedLabels.delete(key);
            }

            // Live icon preview with debounce
            if (field === 'icon') {
                var $input = $(this);
                clearTimeout($input.data('iconDebounce'));
                $input.data('iconDebounce', setTimeout(function() {
                    var iconUrl = $input.val().trim();
                    var $preview = $('#label-icon-preview-' + service);
                    if (iconUrl) {
                        $preview.attr('src', iconUrl);
                    } else {
                        $preview.attr('src', '/plugins/dynamix.docker.manager/images/question.png');
                    }
                }, 300));
            }

            updateSaveButtonState();
            updateTabModifiedState();
        });
    }

    // Helper to find label value from override or main service
    function findLabelValue(overrideService, mainService, labelKey) {
        if (overrideService && overrideService.labels && overrideService.labels[labelKey]) {
            return overrideService.labels[labelKey];
        }
        if (mainService && mainService.labels && mainService.labels[labelKey]) {
            return mainService.labels[labelKey];
        }
        return '';
    }

    // Toggle deleted services visibility
    function toggleDeletedServices(el) {
        var $title = $(el);
        var $services = $title.next('.labels-deleted-services');
        $title.toggleClass('expanded');
        $services.toggleClass('visible');
    }

    function validateYaml(type, content) {
        if (type === 'env') {
            // Basic validation for env files
            updateValidation(type, content);
            return;
        }

        try {
            if (content.trim()) {
                loadComposeYaml(content);
            }
            updateValidation(type, content, true);
        } catch (e) {
            updateValidation(type, content, false, e.message);
        }
    }

    function updateValidation(type, content, isValid, errorMsg) {
        var validationEl = $('#editor-validation-' + type);

        // Handle env files separately (no YAML validation needed)
        if (type === 'env') {
            var lines = content.split('\n').filter(l => l.trim() && !l.trim().startsWith('#'));
            validationEl.html('<i class="fa fa-info-circle editor-validation-icon"></i> ' + lines.length + ' environment variable(s)');
            validationEl.removeClass('error warning').addClass('valid');
            return;
        }

        // If isValid is undefined, run actual YAML validation
        if (isValid === undefined) {
            validateYaml(type, content);
            return;
        }

        if (isValid) {
            validationEl.html('<i class="fa fa-check editor-validation-icon"></i> YAML syntax is valid');
            validationEl.removeClass('error warning').addClass('valid');
        } else {
            // Truncate error message to first line for cleaner display
            var shortError = errorMsg.split('\n')[0].substring(0, 100);
            if (errorMsg.length > 100) shortError += '...';
            // Use text node to prevent XSS from malicious YAML content
            validationEl.empty()
                .append('<i class="fa fa-times editor-validation-icon"></i> YAML Error: ')
                .append(document.createTextNode(shortError));
            validationEl.removeClass('valid warning').addClass('error');
        }
    }

    function updateSaveButtonState() {
        var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
        var hasChanges = totalChanges > 0;
        $('#editor-btn-save-all').prop('disabled', !hasChanges);

        if (hasChanges) {
            $('#editor-btn-save-all').text('Save All (' + totalChanges + ')');
        } else {
            $('#editor-btn-save-all').text('Save All');
        }
    }

    function saveCurrentTab() {
        var currentTab = editorModal.currentTab;
        if (!currentTab) return;

        // Only save editor tabs
        if (currentTab !== 'compose' && currentTab !== 'env') return;
        if (!editorModal.modifiedTabs.has(currentTab)) return;

        saveTab(currentTab).then(function(result) {
            if (result === true) {
                // Brief feedback in validation panel
                $('#editor-validation-' + currentTab).html('<i class="fa fa-check editor-validation-icon"></i> Saved!').removeClass('error warning').addClass('valid');
                setTimeout(function() {
                    if (editorModal.editors[currentTab]) validateYaml(currentTab, editorModal.editors[currentTab].getValue());
                }, 1500);
            } else {
                $('#editor-validation-' + currentTab).html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Save failed').removeClass('valid warning').addClass('error');
            }
        }).catch(function() {
            $('#editor-validation-' + currentTab).html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Save failed').removeClass('valid warning').addClass('error');
        });
    }

    function saveTab(tabName, saveErrors) {
        if (!editorModal.editors[tabName]) return Promise.reject('Editor not available');
        var content = editorModal.editors[tabName].getValue();
        var project = editorModal.currentProject;
        var actionStr = null;

        switch (tabName) {
            case 'compose':
                actionStr = 'saveYml';
                break;
            case 'env':
                actionStr = 'saveEnv';
                break;
            default:
                return Promise.reject('Unknown tab');
        }

        return $.post(caURL, {
            action: actionStr,
            script: project,
            scriptContents: content
        }).then(function(data) {
            if (data) {
                editorModal.originalContent[tabName] = content;
                editorModal.modifiedTabs.delete(tabName);
                $('#editor-tab-' + tabName).removeClass('modified');
                updateSaveButtonState();
                updateTabModifiedState();

                // Regenerate profiles if compose file was saved
                if (tabName === 'compose') {
                    generateProfiles(null, project);
                }

                return true;
            }
            return false;
        }).fail(function() {
            if (saveErrors) saveErrors.push('Failed to save ' + tabName + ' file.');
            return false;
        });
    }

    // Save all modified changes (files, settings, and labels)
    function saveAllChanges() {
        var savePromises = [];
        var saveErrors = [];
        var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;

        if (totalChanges === 0) {
            return;
        }

        // Track if labels are being modified (need to offer recreate)
        var labelsWereModified = editorModal.modifiedLabels.size > 0;

        // Save modified file tabs
        editorModal.modifiedTabs.forEach(function(tabName) {
            savePromises.push(saveTab(tabName, saveErrors));
        });

        // Save settings if modified
        if (editorModal.modifiedSettings.size > 0) {
            savePromises.push(saveSettings(saveErrors));
        }

        // Save labels if modified
        if (editorModal.modifiedLabels.size > 0) {
            savePromises.push(saveLabels(saveErrors));
        }

        $.when.apply($, savePromises).then(function() {
            var results = Array.prototype.slice.call(arguments);
            var allSucceeded = results.every(function(result) {
                return result === true;
            });

            if (allSucceeded) {
                // Check if we should offer to recreate containers
                if (labelsWereModified) {
                    promptRecreateContainers();
                } else {
                    // Close editor and refresh stack locally
                    doCloseEditorModal();
                    swal({
                        title: "Saved!",
                        text: "All changes have been saved.",
                        type: "success",
                        timer: 1500,
                        showConfirmButton: false
                    });
                    setTimeout(function() {
                        refreshStackByProject(editorModal.currentProject);
                    }, 1600);
                }
            } else {
                var errorText = saveErrors.length > 0 ?
                    saveErrors.join('\n') :
                    'Some items could not be saved. Please try again.';
                swal({
                    title: "Save Failed",
                    text: errorText,
                    type: "error"
                });
            }
        }).fail(function() {
            swal({
                title: "Save Failed",
                text: "An error occurred while saving. Please try again.",
                type: "error"
            });
        });
    }

    // Save settings
    function saveSettings(saveErrors) {
        var project = editorModal.currentProject;
        var savePromises = [];
        var needsReload = false;

        // Save name if modified
        if (editorModal.modifiedSettings.has('name')) {
            var newName = $('#settings-name').val();
            savePromises.push(
                $.post(caURL, {
                    action: 'changeName',
                    script: project,
                    newName: newName
                }).then(function() {
                    editorModal.originalSettings['name'] = newName;
                    editorModal.modifiedSettings.delete('name');
                    needsReload = true;
                    return true;
                }).fail(function() {
                    if (saveErrors) saveErrors.push('Failed to save stack name.');
                    return false;
                })
            );
        }

        // Save description if modified
        if (editorModal.modifiedSettings.has('description')) {
            var newDesc = $('#settings-description').val().replace(/\n/g, '<br>');
            savePromises.push(
                $.post(caURL, {
                    action: 'changeDesc',
                    script: project,
                    newDesc: newDesc
                }).then(function() {
                    editorModal.originalSettings['description'] = $('#settings-description').val();
                    editorModal.modifiedSettings.delete('description');
                    return true;
                }).fail(function() {
                    if (saveErrors) saveErrors.push('Failed to save description.');
                    return false;
                })
            );
        }

        // Save icon URL, webui URL, env path, default profile, and external compose path if any are modified
        if (editorModal.modifiedSettings.has('icon-url') || editorModal.modifiedSettings.has('webui-url') || editorModal.modifiedSettings.has('env-path') || editorModal.modifiedSettings.has('default-profile') || editorModal.modifiedSettings.has('external-compose-path')) {
            var iconUrl = $('#settings-icon-url').val();
            var webuiUrl = $('#settings-webui-url').val();
            if (webuiUrl && !isValidWebUIUrl(webuiUrl)) {
                swal({
                    type: 'error',
                    title: 'Save Failed',
                    text: 'Invalid WebUI URL. Must be http:// or https:// (supports [IP] and [PORT:xxxx] placeholders).'
                });
                return;
            }
            if (webuiUrl && /\[PORT\]/i.test(webuiUrl)) {
                swal({
                    type: 'error',
                    title: 'Save Failed',
                    text: 'Bare [PORT] placeholder is not supported at stack level. Use [PORT:xxxx] with a default port instead (e.g. [PORT:8080]).'
                });
                return;
            }
            var envPath = $('#settings-env-path').val();
            var defaultProfile = $('#settings-default-profile').val();
            var externalComposePath = $('#settings-external-compose-path').val();
            savePromises.push(
                $.post(caURL, {
                    action: 'setStackSettings',
                    script: project,
                    iconUrl: iconUrl,
                    webuiUrl: webuiUrl,
                    envPath: envPath,
                    defaultProfile: defaultProfile,
                    externalComposePath: externalComposePath
                }).then(function(data) {
                    if (data) {
                        try {
                            var response = JSON.parse(data);
                        } catch (e) {
                            if (saveErrors) saveErrors.push('Invalid server response when saving settings.');
                            return false;
                        }
                        if (response.result === 'success') {
                            editorModal.originalSettings['icon-url'] = iconUrl;
                            editorModal.originalSettings['webui-url'] = webuiUrl;
                            editorModal.originalSettings['env-path'] = envPath;
                            editorModal.originalSettings['default-profile'] = defaultProfile;
                            editorModal.originalSettings['external-compose-path'] = externalComposePath;
                            editorModal.modifiedSettings.delete('icon-url');
                            editorModal.modifiedSettings.delete('webui-url');
                            editorModal.modifiedSettings.delete('env-path');
                            editorModal.modifiedSettings.delete('default-profile');
                            editorModal.modifiedSettings.delete('external-compose-path');
                            needsReload = true;
                            return true;
                        } else {
                            // Collect error message from server
                            if (saveErrors) saveErrors.push(response.message || 'Failed to save stack settings.');
                        }
                    }
                    return false;
                }).fail(function() {
                    if (saveErrors) saveErrors.push('Failed to save stack settings (network error).');
                    return false;
                })
            );
        }

        return $.when.apply($, savePromises).then(function() {
            var results = Array.prototype.slice.call(arguments);
            var allSucceeded = savePromises.length === 0 || results.every(function(result) {
                return result === true;
            });

            updateTabModifiedState();
            updateSaveButtonState();
            return allSucceeded;
        });
    }

    // Save labels to override file
    function saveLabels(saveErrors) {
        var project = editorModal.currentProject;

        if (!editorModal.labelsData) {
            return $.Deferred().reject().promise();
        }

        if (editorModal.labelsData.overrideHasCustomTags) {
            if (saveErrors) {
                saveErrors.push('WebUI labels cannot be saved because compose.override.yaml uses !override, !reset, or !merge tags. Edit the override file directly to preserve those tags.');
            }
            return $.Deferred().resolve(false).promise();
        }

        var mainDoc = editorModal.labelsData.mainDoc;
        var overrideDoc = editorModal.labelsData.overrideDoc;

        // Update override doc with values from the form
        for (var serviceKey in mainDoc.services) {
            if (!(serviceKey in overrideDoc.services)) {
                overrideDoc.services[serviceKey] = {
                    labels: {}
                };
                overrideDoc.services[serviceKey].labels[<?php echo json_encode($docker_label_managed); ?>] = <?php echo json_encode($docker_label_managed_name); ?>;
            }

            var iconValue = $('#label-' + serviceKey + '-icon').val() || '';
            var webuiValue = $('#label-' + serviceKey + '-webui').val() || '';
            var shellValue = $('#label-' + serviceKey + '-shell').val() || '';

            if (!overrideDoc.services[serviceKey].labels) {
                overrideDoc.services[serviceKey].labels = {};
            }

            overrideDoc.services[serviceKey].labels[icon_label] = iconValue;
            overrideDoc.services[serviceKey].labels[webui_label] = webuiValue;
            overrideDoc.services[serviceKey].labels[shell_label] = shellValue;
        }

        // Remove services from override that are no longer in main.
        // This is required because docker compose will fail if override has
        // services that don't exist in the main compose file (no image defined).
        for (var serviceKey in overrideDoc.services) {
            if (!(serviceKey in mainDoc.services)) {
                delete overrideDoc.services[serviceKey];
            }
        }

        // Convert to YAML and save
        var rawOverride = jsyaml.dump(overrideDoc, {
            'forceQuotes': true
        });

        return $.post(caURL, {
            action: 'saveOverride',
            script: project,
            scriptContents: rawOverride
        }).then(function(data) {
            if (data) {
                // Collect services whose icon changed, then clear webgui icon cache
                var changedIconServices = [];
                for (var serviceKey in mainDoc.services) {
                    var oldIcon = editorModal.originalLabels[serviceKey + '_icon'] || '';
                    var newIcon = $('#label-' + serviceKey + '-icon').val() || '';
                    if (oldIcon !== newIcon) {
                        changedIconServices.push(serviceKey);
                    }
                }
                if (changedIconServices.length > 0) {
                    $.post(caURL, {
                        action: 'clearIconCache',
                        script: project,
                        services: JSON.stringify(changedIconServices)
                    });
                }

                // Update original labels to match current values
                for (var serviceKey in mainDoc.services) {
                    editorModal.originalLabels[serviceKey + '_icon'] = $('#label-' + serviceKey + '-icon').val() || '';
                    editorModal.originalLabels[serviceKey + '_webui'] = $('#label-' + serviceKey + '-webui').val() || '';
                    editorModal.originalLabels[serviceKey + '_shell'] = $('#label-' + serviceKey + '-shell').val() || '';
                }
                editorModal.labelsData.overrideContent = rawOverride;
                editorModal.labelsData.overrideHasCustomTags = false;
                editorModal.modifiedLabels.clear();
                updateTabModifiedState();
                updateSaveButtonState();
                return true;
            }
            return false;
        }).fail(function() {
            swal({
                title: "Save Failed",
                text: "Failed to save WebUI labels. Please try again.",
                type: "error"
            });
            return false;
        });
    }

    // Keep saveAllTabs for backwards compatibility
    function saveAllTabs() {
        saveAllChanges();
    }

    function closeEditorModal() {
        var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
        if (totalChanges > 0) {
            swal({
                title: "Unsaved Changes",
                text: "You have unsaved changes. Are you sure you want to close?",
                type: "warning",
                showCancelButton: true,
                confirmButtonText: "Discard Changes",
                cancelButtonText: "Cancel"
            }, function(confirmed) {
                if (confirmed) {
                    doCloseEditorModal();
                }
            });
        } else {
            doCloseEditorModal();
        }
    }

    function doCloseEditorModal() {
        $('#editor-modal-overlay').removeClass('active').appendTo('body');
        editorModal.currentProject = null;
        editorModal.currentTab = 'compose';
        editorModal.modifiedTabs = new Set();
        editorModal.modifiedSettings = new Set();
        editorModal.modifiedLabels = new Set();
        editorModal.originalContent = {};
        editorModal.originalSettings = {};
        editorModal.originalLabels = {};
        editorModal.labelsData = null;

        // Clear editor content to avoid showing stale content on next open
        ['compose', 'env'].forEach(function(type) {
            if (editorModal.editors[type]) {
                editorModal.editors[type].setValue('', -1);
            }
        });

        // Reset settings fields
        $('#settings-name').val('');
        $('#settings-description').val('');
        $('#settings-icon-url').val('');
        $('#settings-webui-url').val('');
        $('#settings-env-path').val('');
        $('#settings-default-profile').val('');
        $('#settings-external-compose-path').val('');
        $('#settings-icon-preview').hide();
        $('#settings-available-profiles').hide();
        $('#settings-external-compose-info').hide();
        $('#settings-invalid-indirect-warning').hide();

        // Hide any open file-tree pickers (so they don't float outside the modal)
        $('.fileTree').slideUp('fast');

        // Clear labels container
        $('#labels-services-container').html('');

        // Reset tab states
        $('.editor-tab').removeClass('modified');
    }

    function deleteStackByProject(project, projectName) {
        var msgHtml = "Are you sure you want to delete <font color='red'><b>" + composeEscapeHtml(projectName) + "</b></font> (<font color='green'>" + composeEscapeHtml(compose_root) + "/" + composeEscapeHtml(project) + "</font>)?";
        swal({
            title: "Delete Stack?",
            text: msgHtml,
            html: true,
            type: "warning",
            showCancelButton: true,
            confirmButtonText: "Delete",
            cancelButtonText: "Cancel"
        }, function(confirmed) {
            if (confirmed) {
                setStackActionInProgress(project, true, 'Deleting stack...');
                $.post(caURL, {
                    action: 'deleteStack',
                    stackName: project
                }, function(data) {
                    try {
                        if (data) {
                            var response = JSON.parse(data);
                            if (response.result == "warning") {
                                setTimeout(function() {
                                    swal({
                                        title: "Files remain on disk.",
                                        text: response.message,
                                        type: "warning"
                                    }, function() {
                                        composeLoadlist();
                                    });
                                }, 100);
                                return;
                            }
                        }
                    } catch (e) {
                        composeLogger('Delete response parse error', {
                            project: project,
                            error: e
                        }, 'user', 'error', 'stack-action');
                    }
                }).fail(function() {
                    composeLogger('Delete request failed for project', {
                        project: project
                    }, 'user', 'error', 'stack-action');
                });
                composeLoadlist();
            }
        });
    }

    // ============================================
    // Expandable Stack Details Functions
    // ============================================
    function toggleStackDetails(stackId) {
        var $row = $('#stack-row-' + stackId);
        var $detailsRow = $('#details-row-' + stackId);
        var $expandIcon = $('#expand-icon-' + stackId);
        var project = $row.data('project');

        if (expandedStacks[stackId]) {
            // Collapse
            $detailsRow.slideUp(200);
            $expandIcon.removeClass('expanded');
            expandedStacks[stackId] = false;
        } else {
            $expandIcon.addClass('expanded');
            expandedStacks[stackId] = true;

            if (stackContainersCache[stackId]) {
                // Cached — always re-render from cache before showing.
                // This prevents stale hidden DOM content when a stack changed
                // state while details were collapsed.
                renderContainerDetails(stackId, stackContainersCache[stackId], project);
                $detailsRow.slideDown(200);
            } else {
                // First load: fetch data, row stays hidden until render completes
                loadStackContainerDetails(stackId, project);
            }
        }
    }

    function loadStackContainerDetails(stackId, project) {
        var $container = $('#details-container-' + stackId);

        // Prevent parallel loads for same stack
        if (stackDetailsLoading[stackId]) {
            composeLogger('already-loading', {
                stackId: stackId,
                project: project
            }, 'user', 'warning', 'container-details');
            return;
        }
        stackDetailsLoading[stackId] = true;
        composeLogger('start', {
            stackId: stackId,
            project: project
        }, 'user', 'info', 'container-details');

        // Show loading state
        $container.html('<div class="stack-details-loading"><i class="fa fa-spinner fa-spin"></i> Loading container details...</div>');

        $.post(caURL, {
            action: 'getStackContainers',
            script: project
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success') {
                        var containers = response.containers;

                        // Normalize all containers via factory function (PascalCase→camelCase)
                        containers = containers.map(createContainerInfo).filter(Boolean);

                        // Merge update status from stackUpdateStatus if available
                        mergeUpdateStatus(containers, project);

                        stackContainersCache[stackId] = containers;
                        if (response.startedAt) stackStartedAtCache[stackId] = response.startedAt;
                        composeLogger('success', {
                            stackId: stackId,
                            project: project,
                            containers: containers.length
                        }, 'user', 'info', 'container-details');
                        renderContainerDetails(stackId, containers, project);
                        // Slide down details row now that content is rendered
                        $('#details-row-' + stackId).slideDown(200);
                    } else {
                        // Escape error message to prevent XSS
                        var errorMsg = composeEscapeHtml(response.message || 'Failed to load container details');
                        $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> ' + errorMsg + '</div>');
                        $('#details-row-' + stackId).slideDown(200);
                        composeLogger('error', {
                            stackId: stackId,
                            project: project,
                            message: errorMsg
                        }, 'user', 'error', 'container-details');
                    }
                } catch (e) {
                    $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> Failed to parse container details response</div>');
                    $('#details-row-' + stackId).slideDown(200);
                    composeLogger('parse-error', {
                        stackId: stackId,
                        project: project,
                        err: e.toString()
                    }, 'user', 'error', 'container-details');
                }
            } else {
                $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> Failed to load container details</div>');
                $('#details-row-' + stackId).slideDown(200);
                composeLogger('empty-response', {
                    stackId: stackId,
                    project: project
                }, 'user', 'warning', 'container-details');
            }
            stackDetailsLoading[stackId] = false;
        }).fail(function() {
            $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> Failed to load container details</div>');
            $('#details-row-' + stackId).slideDown(200);
            stackDetailsLoading[stackId] = false;
            composeLogger('failed', {
                stackId: stackId,
                project: project
            }, 'user', 'error', 'container-details');
        });
    }

    function renderContainerDetails(stackId, containers, project) {
        var $container = $('#details-container-' + stackId);

        if (!containers || containers.length === 0) {
            $container.html('<div class="stack-details-empty"><i class="fa fa-info-circle"></i> No containers found. Stack may not be running.</div>');
            return;
        }

        // Mini Docker table - matches Docker tab columns
        var html = '<table class="tablesorter shift compose-ct-table">';
        html += '<thead><tr>';
        html += '<th class="ct-col-name">Container</th>';
        html += '<th class="ct-col-update">Update</th>';
        html += '<th class="cm-advanced ct-col-source">Source</th>';
        html += '<th class="cm-advanced ct-col-tag">Tag</th>';
        html += '<th class="cm-advanced ct-col-net">Network</th>';
        html += '<th class="cm-advanced ct-col-ip">Container IP</th>';
        html += '<th class="cm-advanced ct-col-load">CPU &amp; Memory load</th>';
        html += '<th class="ct-col-cport">Container Port</th>';
        html += '<th class="ct-col-lport">LAN IP:Port</th>';
        html += '</tr></thead>';
        html += '<tbody>';

        containers.forEach(function(container, idx) {
            var containerName = container.name || container.service || 'Unknown';
            var shortName = container.service || containerName.replace(/^[^-]+-/, ''); // Prefer service name; fall back to stripping project prefix
            var image = container.image || '';

            // Parse image - handle docker.io/ prefix and @sha256: digest
            // Format could be: docker.io/library/redis:6.2-alpine@sha256:abc123...
            var imageForParsing = image;
            if (imageForParsing.indexOf('docker.io/') === 0) {
                imageForParsing = imageForParsing.substring(10);
            }

            // Check for @sha256: digest suffix
            var digestSuffix = '';
            var digestPos = imageForParsing.indexOf('@sha256:');
            if (digestPos !== -1) {
                digestSuffix = '@' + imageForParsing.substring(digestPos + 1, digestPos + 20); // @sha256:xxxx (first 12 chars of digest)
                imageForParsing = imageForParsing.substring(0, digestPos);
            }

            // Now split by : for tag
            var imageParts = imageForParsing.split(':');
            var imageSource = imageParts[0] || ''; // Image name without tag
            var imageTag = (imageParts[1] || 'latest') + digestSuffix; // Include digest suffix if present
            var state = container.state || 'unknown';
            var containerId = String(container.id || containerName || '').substring(0, 12);
            var uniqueId = 'ct-' + stackId + '-' + idx;

            // Status like Docker tab
            var shape = state === 'running' ? 'play' : (state === 'paused' ? 'pause' : 'square');
            var statusText = state === 'running' ? 'started' : (state === 'paused' ? 'paused' : 'stopped');
            var color = state === 'running' ? 'green-text' : (state === 'paused' ? 'orange-text' : 'grey-text');
            var outerClass = state === 'running' ? 'started' : (state === 'paused' ? 'paused' : 'stopped');

            // Get networks and IPs
            var networkNames = [];
            var ipAddresses = [];
            if (container.networks && container.networks.length > 0) {
                container.networks.forEach(function(net) {
                    networkNames.push(net.name || '-');
                    ipAddresses.push(net.ip || '-');
                });
            }
            if (networkNames.length === 0) {
                networkNames.push('-');
                ipAddresses.push('-');
            }

            // Format ports - separate container ports and mapped ports
            var containerPorts = [];
            var lanPorts = [];
            if (container.ports && container.ports.length > 0) {
                container.ports.forEach(function(p) {
                    // Format: "192.168.1.10:8080->80/tcp" or "80/tcp"
                    var parts = p.split('->');
                    if (parts.length === 2) {
                        lanPorts.push(parts[0]);
                        containerPorts.push(parts[1]);
                    } else {
                        containerPorts.push(p);
                    }
                });
            }
            if (containerPorts.length === 0) containerPorts.push('-');
            if (lanPorts.length === 0) lanPorts.push('-');

            // WebUI
            // WebUI — already resolved server-side by exec.php
            var webui = '';
            if (container.webUI) {
                webui = container.webUI;
                if (!isValidWebUIUrl(webui)) webui = '';
            }

            html += '<tr data-container="' + composeEscapeAttr(containerName) + '" data-state="' + composeEscapeAttr(state) + '" data-stackid="' + composeEscapeAttr(stackId) + '">';

            // Container name column - matches Docker tab exactly
            html += '<td class="ct-name">';
            html += '<span class="outer ' + outerClass + '">';
            var containerShell = container.shell || '/bin/sh';
            html += '<span id="' + uniqueId + '" class="hand" data-name="' + composeEscapeAttr(containerName) + '" data-state="' + composeEscapeAttr(state) + '" data-webui="' + composeEscapeAttr(webui) + '" data-stackid="' + composeEscapeAttr(stackId) + '" data-shell="' + composeEscapeAttr(containerShell) + '">';
            // Use actual image like Docker tab - either container icon or default question.png
            var iconSrc = (container.icon && isValidIconSrc(container.icon)) ?
                container.icon :
                '/plugins/dynamix.docker.manager/images/question.png';
            html += '<img src="' + composeEscapeAttr(iconSrc) + '" class="img" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
            html += '</span>';
            html += '<span class="inner"><span class="appname">' + composeEscapeHtml(shortName) + '</span><br>';
            html += '<i class="fa fa-' + shape + ' ' + statusText + ' ' + color + '"></i><span class="state">' + statusText + '</span>';
            html += '</span></span>';
            html += '</td>';

            // Update column - shows update status for this container (like Docker tab)
            html += '<td class="ct-updatecolumn">';
            var ctHasUpdate = container.hasUpdate || false;
            var ctUpdateStatus = container.updateStatus || '';
            var ctLocalSha = container.localSha || '';
            var ctRemoteSha = container.remoteSha || '';
            var ctIsPinned = container.isPinned || false;
            var ctPinnedDigest = container.pinnedDigest || '';

            if (ctIsPinned) {
                // Image is pinned with SHA256 digest - show pinned status
                html += '<span class="cyan-text" style="white-space:nowrap;"><i class="fa fa-thumb-tack fa-fw"></i> pinned</span>';
                if (ctPinnedDigest) {
                    html += '<div style="font-family:var(--font-bitstream);font-size:0.85em;margin-top:2px;"><span class="compose-status-info">' + composeEscapeHtml(ctPinnedDigest.substring(0, 12)) + '</span></div>';
                }
            } else if (ctHasUpdate) {
                // Update available - orange "update ready" style with SHA diff
                html += '<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + composeEscapeAttr(project) + '\', \'' + composeEscapeAttr(stackId) + '\');">';
                html += '<span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> update ready</span>';
                html += '</a>';
                if (ctLocalSha && ctRemoteSha) {
                    // Always show SHA diff (not just in advanced view)
                    html += '<div style="font-family:var(--font-bitstream);font-size:0.85em;margin-top:2px;">';
                    html += '<span class="compose-status-warning" title="' + composeEscapeAttr(ctLocalSha) + '">' + composeEscapeHtml(ctLocalSha.substring(0, 8)) + '</span>';
                    html += ' <i class="fa fa-arrow-right compose-status-success" style="margin:0 4px;"></i> ';
                    html += '<span class="compose-status-success" title="' + composeEscapeAttr(ctRemoteSha) + '">' + composeEscapeHtml(ctRemoteSha.substring(0, 8)) + '</span>';
                    html += '</div>';
                }
            } else if (ctUpdateStatus === 'up-to-date') {
                // No update - green "up-to-date" style
                html += '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
                if (ctLocalSha) {
                    // Show SHA in advanced view only for up-to-date containers (15 chars)
                    html += '<div class="cm-advanced" style="font-family:var(--font-bitstream);font-size:0.85em;" title="' + composeEscapeAttr(ctLocalSha) + '"><span class="compose-text-muted">' + composeEscapeHtml(ctLocalSha.substring(0, 15)) + '</span></div>';
                }
            } else {
                // Unknown/not checked
                html += '<span class="compose-text-muted" style="white-space:nowrap;"><i class="fa fa-question-circle fa-fw"></i> not checked</span>';
            }
            html += '</td>';

            // Source (image name without tag)
            html += '<td class="cm-advanced"><span class="docker_readmore compose-text-muted">' + composeEscapeHtml(imageSource) + '</span></td>';

            // Tag (image tag) — truncated with ellipsis via CSS if too long
            html += '<td class="cm-advanced ct-col-tag-cell"><span class="ct-tag" title="' + composeEscapeAttr(imageTag) + '">' + composeEscapeHtml(imageTag) + '</span></td>';

            // Network
            html += '<td class="cm-advanced" style="white-space:nowrap;"><span class="docker_readmore">' + networkNames.map(composeEscapeHtml).join('<br>') + '</span></td>';

            // Container IP
            html += '<td class="cm-advanced" style="white-space:nowrap;"><span class="docker_readmore">' + ipAddresses.map(composeEscapeHtml).join('<br>') + '</span></td>';

            // CPU & Memory load (advanced only) — populated by dockerload WebSocket
            html += '<td class="cm-advanced compose-load-cell">';
            if (state === 'running') {
                html += '<span class="compose-cpu-' + containerId + '">0%</span>';
                html += '<div class="usage-disk mm"><span id="compose-cpu-' + containerId + '" style="width:0"></span><span></span></div>';
                html += '<br><span class="compose-mem-' + containerId + ' compose-text-muted">0B / 0B</span>';
            } else {
                html += '<span class="compose-cpu-' + containerId + ' compose-text-muted">-</span>';
                html += '<span class="compose-mem-' + containerId + '" style="display:none"></span>';
            }
            html += '</td>';

            // Container Port
            html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + containerPorts.map(composeEscapeHtml).join('<br>') + '</span></td>';

            // LAN IP:Port
            html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + lanPorts.map(composeEscapeHtml).join('<br>') + '</span></td>';

            html += '</tr>';
        });

        html += '</tbody></table>';

        $container.html(html);

        // Update the parent stack row shortly after rendering so counts and status
        // reflect the latest state. Use a short timeout to avoid racing with other
        // DOM updates (e.g. a full list reload) that may remove the row.
        try {
            setTimeout(function() {
                // Mark as just rendered before any parent-row update so
                // updateStackUpdateUI can suppress re-entrant detail reloads.
                try {
                    stackDetailsJustRendered[stackId] = true;
                } catch (ex) {
                    composeLogger('set-just-rendered-failed', {
                        err: ex.toString(),
                        stackId: stackId,
                        project: project
                    }, 'user', 'error', 'container-details');
                }
                try {
                    updateParentStackFromContainers(stackId, project);
                } catch (e) {
                    composeLogger('update-parent-failed', {
                        err: e.toString(),
                        stackId: stackId,
                        project: project
                    }, 'user', 'error', 'container-details');
                }
                composeLogger('just-rendered', {
                    stackId: stackId,
                    project: project
                }, 'user', 'info', 'container-details');
                // Clear the flag after a short window
                setTimeout(function() {
                    try {
                        stackDetailsJustRendered[stackId] = false;
                    } catch (ex) {}
                }, 1000);
                // Clear loading flag now that render finished
                try {
                    stackDetailsLoading[stackId] = false;
                } catch (ex) {}
            }, 120);
        } catch (e) {}

        // Apply readmore to container details — destroy first to avoid nesting wrappers
        $container.find('.docker_readmore').readmore('destroy');
        $container.find('.docker_readmore').readmore({
            maxHeight: 32,
            moreLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",
            lessLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"
        });

        // Attach context menus to each container icon (like Docker tab)
        containers.forEach(function(container, idx) {
            var uniqueId = 'ct-' + stackId + '-' + idx;
            addComposeContainerContext(uniqueId);
        });

        // If this stack was queued for a compose-list reload (due to containerAction),
        // process it now: remove from pending list and reload the compose list so
        // the parent stack row (status icon, counts) is refreshed.
        // NOTE: avoid scheduling a parent-list reload here. Reloads should only be
        // queued from actions that change container state (e.g. containerAction()).
        // Scheduling a reload from a pure render path can cause repeated cycles
        // when the parent row update triggers re-renders. Container actions will
        // queue the stack reload explicitly.
    }

    // Build a condensed stackInfo object from the stackContainersCache for a stack
    function buildStackInfoFromCache(stackId, project) {
        var containers = stackContainersCache[stackId] || [];
        return createStackInfo(project, containers);
    }

    // Update only the parent stack row using cached container details
    function updateParentStackFromContainers(stackId, project) {
        try {
            var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
            if ($stackRow.length === 0) {
                // If the row isn't present, fall back to a full reload
                composeLoadlist();
                return;
            }

            // Detect if an update check is currently in progress (spinner visible)
            var $updateCell = $stackRow.find('td.compose-updatecolumn');
            var isChecking = $updateCell.find('.fa-refresh.fa-spin').length > 0;

            // Update the update-column using existing helper (expects stackInfo)
            var stackInfo = buildStackInfoFromCache(stackId, project);
            // Merge any previously saved update status so we don't lose 'checked' state
            mergeStackUpdateStatus(stackInfo, stackUpdateStatus[project] || {});

            // Cache the merged update status and apply UI update
            stackUpdateStatus[project] = stackInfo;
            // Skip updating the update column if a check is currently in progress
            if (!isChecking) {
                updateStackUpdateUI(project, stackInfo);
            }

            // If the stack has an in-progress action, keep the temporary status icon/text until completion.
            if (composeStackActionInProgress[project]) {
                composeLogger('skipping icon/state update while action in progress', {
                    stackId: stackId,
                    project: project
                }, 'user', 'debug', 'container-details');
                return;
            }

            // Update the stack row status icon and state text based on container states
            var $stateEl = $stackRow.find('.state');
            var origText = $stateEl.data('orig-text') || $stateEl.text();
            // Derive state from containers using centralized helper
            var stateInfo = deriveStackState(stackInfo.containers);
            var runningCount = stateInfo.runningCount;
            var totalCount = stateInfo.totalCount;
            var anyRunning = runningCount > 0;
            var newState = stateInfo.state;
            $stateEl.text(stateInfo.label);

            // Update the containers count cell to reflect cached values
            try {
                var $containersCell = $stackRow.find('td.col-containers');
                var containersClass = stateInfo.colorClass;
                $containersCell.html('<span class="' + containersClass + '">' + runningCount + ' / ' + totalCount + '</span>');
            } catch (e) {}

            // Update the status icon to match the new state and color
            var $icon = $stackRow.find('.compose-status-icon');
            if ($icon.length) {
                var shape = stateInfo.shape;
                var colorClass = stateInfo.colorClass;

                // Remove spinner / temporary classes and any previous fa-<name> classes
                $icon.removeClass('fa-refresh fa-spin compose-status-spinner');
                // Use a regex that matches the full fa-<name> (including hyphens) to ensure
                // icons like fa-exclamation-circle are removed completely.
                $icon.removeClass(function(i, cls) {
                    return (cls.match(/fa-[^\s]+/g) || []).join(' ');
                });

                // Remove any previous color classes
                $icon.removeClass('green-text orange-text grey-text cyan-text');

                // Apply the new shape and color
                $icon.addClass('fa fa-' + shape + ' ' + colorClass + ' compose-status-icon');

                // Clear any saved orig-class since we've now applied the new state
                if ($icon.data('orig-class')) {
                    $icon.removeData('orig-class');
                }
            }

            // Update data-isup and data-running so context menu reflects new state
            var newIsUp = anyRunning ? '1' : '0';
            $stackRow.data('isup', newIsUp).attr('data-isup', newIsUp);
            var $iconSpan = $stackRow.find('span[data-stackid]');
            $iconSpan.data('isup', newIsUp).attr('data-isup', newIsUp);
            $iconSpan.data('running', runningCount).attr('data-running', runningCount);

            // Rebind stack context menu so options (Up/Down/Stop/etc.) match new state
            var stackElementId = 'stack-' + stackId;
            if ($('#' + stackElementId).length) {
                addComposeStackContext(stackElementId);
            }

            // Update the uptime column using stack-level started_at (same
            // source as the initial PHP render in compose_list.php) so the
            // displayed value doesn't jump when details are expanded.
            try {
                var $uptimeCell = $stackRow.find('td.col-uptime');
                var uptimeText = 'stopped';
                var uptimeClass = 'grey-text';
                if (anyRunning) {
                    var stackStarted = stackStartedAtCache[stackId] || null;
                    if (stackStarted) {
                        var t = new Date(stackStarted).getTime();
                        if (!isNaN(t)) {
                            var secs = Math.max(0, Math.floor((Date.now() - t) / 1000));
                            var mins = Math.floor(secs / 60);
                            var hours = Math.floor(secs / 3600);
                            var days = Math.floor(secs / 86400);
                            var weeks = Math.floor(days / 7);
                            var months = Math.floor(days / 30);
                            if (mins < 120) uptimeText = mins + ' min' + (mins !== 1 ? 's' : '');
                            else if (hours < 48) uptimeText = hours + ' hour' + (hours !== 1 ? 's' : '');
                            else if (days < 14) uptimeText = days + ' day' + (days !== 1 ? 's' : '');
                            else if (weeks < 8) uptimeText = weeks + ' week' + (weeks !== 1 ? 's' : '');
                            else if (months < 24) uptimeText = months + ' month' + (months !== 1 ? 's' : '');
                            else {
                                var years = Math.floor(days / 365);
                                uptimeText = years + ' year' + (years !== 1 ? 's' : '');
                            }
                        } else {
                            uptimeText = 'running';
                        }
                    } else {
                        uptimeText = 'running';
                    }
                    uptimeClass = 'green-text';
                }
                $uptimeCell.html('<span class="' + uptimeClass + '">' + uptimeText + '</span>');
            } catch (e) {}

            // Re-apply view mode (advanced/basic) to ensure column content visibility
            applyListView();
        } catch (e) {
            composeLogger('updateParentStackFromContainers error', {
                err: e.toString(),
                stackId: stackId,
                project: project
            }, 'user', 'error', 'container-details');
            // If anything goes wrong, fallback to full reload
            composeLoadlist();
        }
    }

    // Attach context menu to container icon (like Docker tab's addDockerContainerContext)
    function addComposeContainerContext(elementId) {
        var $el = $('#' + elementId);
        var containerName = $el.data('name');
        var state = $el.data('state');
        var webui = $el.data('webui');
        var stackId = $el.data('stackid');
        var shell = $el.data('shell') || '/bin/bash';
        var running = state === 'running';
        var paused = state === 'paused';

        var opts = [];
        context.settings({
            right: false,
            above: 'auto'
        });

        // WebUI (if running)
        if (running && webui) {
            opts.push({
                text: 'WebUI',
                icon: 'fa-globe',
                action: function(e) {
                    e.preventDefault();
                    window.open(webui, '_blank');
                }
            });
            opts.push({
                divider: true
            });
        }

        // Console (if running) — start writable ttyd, open in new window
        if (running) {
            opts.push({
                text: 'Console',
                icon: 'fa-terminal',
                action: function(e) {
                    e.preventDefault();
                    $.post(compURL, {
                        action: 'containerConsole',
                        container: containerName,
                        shell: shell
                    }, function(data) {
                        if (data) {
                            var height = Math.min(screen.availHeight, 800);
                            var width = Math.min(screen.availWidth, 1200);
                            window.open(data, 'Console_' + containerName.replace(/[^a-zA-Z0-9]/g, '_'),
                                'height=' + height + ',width=' + width + ',resizable=yes,scrollbars=yes');
                        }
                    });
                }
            });
            opts.push({
                divider: true
            });
        }

        // Start/Stop/Pause/Resume
        if (running) {
            opts.push({
                text: 'Stop',
                icon: 'fa-stop',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'stop', stackId);
                }
            });
            opts.push({
                text: 'Pause',
                icon: 'fa-pause',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'pause', stackId);
                }
            });
            opts.push({
                text: 'Restart',
                icon: 'fa-refresh',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'restart', stackId);
                }
            });
        } else if (paused) {
            opts.push({
                text: 'Resume',
                icon: 'fa-play',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'unpause', stackId);
                }
            });
        } else {
            opts.push({
                text: 'Start',
                icon: 'fa-play',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'start', stackId);
                }
            });
        }

        opts.push({
            divider: true
        });

        // Logs — start ttyd via plugin, open in new window (same as stack logs)
        opts.push({
            text: 'Logs',
            icon: 'fa-navicon',
            action: function(e) {
                e.preventDefault();
                $.post(compURL, {
                    action: 'containerLogs',
                    container: containerName
                }, function(data) {
                    if (data) {
                        var height = Math.min(screen.availHeight, 800);
                        var width = Math.min(screen.availWidth, 1200);
                        window.open(data, 'Logs_' + containerName.replace(/[^a-zA-Z0-9]/g, '_'),
                            'height=' + height + ',width=' + width + ',resizable=yes,scrollbars=yes');
                    }
                });
            }
        });

        // Ensure stale menu bindings don't persist across state transitions
        $el.off('contextmenu');
        context.attach('#' + elementId, opts);
    }

    function containerAction(containerName, action, stackId) {
        // Show spinner by replacing the status icon (play/stop) in-place
        var $iconWrap = $('[data-name="' + containerName + '"]').first();
        // The status icon lives in the sibling '.inner' span next to the '.hand' wrapper
        var $statusIcon = $iconWrap.closest('td').find('.inner i').first();
        var statusOrigClass = null;
        var __spinnerInserted = false;
        // Also preserve/modify the state text (e.g. 'starting', 'stopping') while action runs
        var $stateTextEl = $iconWrap.closest('td').find('.inner .state').first();
        var stateOrigText = null;
        var actionStatusTextMap = {
            start: 'starting',
            stop: 'stopping',
            restart: 'restarting',
            pause: 'pausing',
            unpause: 'resuming'
        };
        var actionStatusText = actionStatusTextMap[action] || 'working';
        if ($statusIcon.length) {
            try {
                statusOrigClass = $statusIcon.attr('class') || '';
                $statusIcon.attr('data-orig-class', statusOrigClass);
                $statusIcon.removeClass().addClass('fa fa-refresh fa-spin compose-status-spinner');
                __spinnerInserted = true;
            } catch (e) {
                __spinnerInserted = false;
            }
        } else {
            // Fallback to previous behavior: if there's an <i> or <img> inside the hand, use that
            var $icon = $iconWrap.find('i,img').first();
            var originalClass = $icon.attr('class');
            if ($icon.is('i')) {
                $icon.removeClass().addClass('fa fa-refresh fa-spin');
                __spinnerInserted = true;
            } else if ($icon.is('img')) {
                // As a last resort, overlay a spinner on the image (should be rare now)
                try {
                    $iconWrap.css('position', 'relative');
                    $icon.css('opacity', 0.35);
                    $iconWrap.append('<i class="fa fa-refresh fa-spin compose-container-spinner" aria-hidden="true"></i>');
                    __spinnerInserted = true;
                } catch (e) {
                    __spinnerInserted = false;
                }
            }
        }

        // If we inserted a spinner, set temporary state text and save original so we can restore on failure
        if (__spinnerInserted && $stateTextEl.length) {
            try {
                stateOrigText = $stateTextEl.text();
                $stateTextEl.attr('data-orig-text', stateOrigText);
                $stateTextEl.text(actionStatusText);
                composeLogger('set-status', {
                    container: containerName,
                    action: action,
                    stackId: stackId,
                    statusText: actionStatusText
                }, 'user', 'info', 'container-action');
            } catch (e) {}
        }

        $.post(caURL, {
            action: 'containerAction',
            container: containerName,
            containerAction: action
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                } catch (e) {
                    return;
                }
                if (response.result === 'success') {
                    // Refresh the container details
                    // Also mark the parent stack for a compose-list reload so the stack-level
                    // status (play/stop icon, running count) is refreshed after the container action.
                    try {
                        var project = $('#stack-row-' + stackId).data('project');
                        if (project) {
                            if (pendingComposeReloadStacks.indexOf(project) === -1) pendingComposeReloadStacks.push(project);
                            composeLogger('queued-stack-reload', {
                                container: containerName,
                                action: action,
                                stack: project,
                                pending: pendingComposeReloadStacks.slice()
                            }, 'user', 'info', 'container-action');
                            // Show per-stack spinner immediately
                            try {
                                setStackActionInProgress(project, true);
                            } catch (e) {}
                        }
                    } catch (e) {}

                    // Refresh the container details after a short delay to let docker settle
                    setTimeout(function() {
                        var project = $('#stack-row-' + stackId).data('project');
                        loadStackContainerDetails(stackId, project);
                    }, 1000);
                    // Schedule a debounced parent-row update so multiple signals collapse
                    try {
                        schedulePendingComposeReloads(1200);
                    } catch (e) {}
                    // Also refresh Unraid's Docker containers widget
                    if (typeof window.loadlist === 'function') {
                        setTimeout(function() {
                            window.loadlist();
                        }, 1500);
                    }
                } else {
                    // Restore status icon or remove overlay spinner
                    if (__spinnerInserted) {
                        // Restore status icon if we replaced it
                        var $restStatus = $iconWrap.closest('td').find('.inner i').first();
                        if ($restStatus.length && $restStatus.attr('data-orig-class')) {
                            $restStatus.removeClass().addClass($restStatus.attr('data-orig-class'));
                            $restStatus.removeAttr('data-orig-class');
                        } else {
                            // Fallback: restore any overlay on the image
                            try {
                                $icon.css('opacity', 1);
                                $iconWrap.find('.compose-container-spinner').remove();
                            } catch (e) {}
                        }
                        // Restore state text if we changed it
                        try {
                            var $stateEl = $iconWrap.closest('td').find('.inner .state').first();
                            if ($stateEl.length && $stateEl.attr('data-orig-text')) {
                                $stateEl.text($stateEl.attr('data-orig-text'));
                                $stateEl.removeAttr('data-orig-text');
                            }
                        } catch (e) {}
                    }
                    swal({
                        title: 'Action Failed',
                        text: composeEscapeHtml(response.message) || 'Failed to ' + action + ' container',
                        type: 'error'
                    });
                }
            }
        }).fail(function() {
            // Restore status icon or remove overlay spinner
            if (__spinnerInserted) {
                var $restStatus = $iconWrap.closest('td').find('.inner i').first();
                if ($restStatus.length && $restStatus.attr('data-orig-class')) {
                    $restStatus.removeClass().addClass($restStatus.attr('data-orig-class'));
                    $restStatus.removeAttr('data-orig-class');
                } else {
                    try {
                        $icon.css('opacity', 1);
                        $iconWrap.find('.compose-container-spinner').remove();
                    } catch (e) {}
                }
                // Restore state text if we changed it
                try {
                    var $stateEl = $iconWrap.closest('td').find('.inner .state').first();
                    if ($stateEl.length && $stateEl.attr('data-orig-text')) {
                        $stateEl.text($stateEl.attr('data-orig-text'));
                        $stateEl.removeAttr('data-orig-text');
                    }
                } catch (e) {}
            }
            swal({
                title: 'Action Failed',
                text: 'Failed to ' + action + ' container',
                type: 'error'
            });
        });
    }

    // Attach context menu to stack icon (like Docker tab's container context menu)
    function addComposeStackContext(elementId) {
        var $el = $('#' + elementId);
        var stackId = $el.data('stackid');
        var project = $el.data('project');
        var projectName = $el.data('projectname');
        var isUp = $el.data('isup') == "1";
        var running = parseInt($el.data('running') || 0);

        var $row = $('#stack-row-' + stackId);
        var path = $row.data('path');
        var profiles = $row.data('profiles') || [];
        var webuiUrl = $row.data('webui') || '';
        var hasBuild = $row.data('hasbuild') == "1";
        var hasExistingContainers = false;
        var hasKnownNetworks = false;

        // Prefer the rendered row data first; this reflects current known stack containers.
        try {
            var rowContainers = JSON.parse($row.attr('data-containers') || '[]');
            hasExistingContainers = Array.isArray(rowContainers) && rowContainers.length > 0;
        } catch (e) {
            hasExistingContainers = false;
        }

        // Fallback to cached short IDs if data-containers is empty/unavailable.
        if (!hasExistingContainers) {
            var ctidsAttr = ($row.attr('data-ctids') || '').trim();
            hasExistingContainers = ctidsAttr.length > 0;
        }

        // If details were loaded, use container network attachments as an additional signal.
        // This also keeps behavior resilient during in-page state transitions.
        try {
            var cachedContainers = stackContainersCache[stackId] || [];
            hasKnownNetworks = cachedContainers.some(function(c) {
                return Array.isArray(c.networks) && c.networks.length > 0;
            });
        } catch (e) {
            hasKnownNetworks = false;
        }

        var canComposeDownStopped = hasExistingContainers || hasKnownNetworks;

        // Check if updates are available for this stack
        var hasUpdates = false;
        if (stackUpdateStatus[project] && stackUpdateStatus[project].hasUpdate) {
            hasUpdates = true;
        }

        var opts = [];
        context.settings({
            right: false,
            above: 'auto'
        });

        // ===== STACK IS RUNNING =====
        if (isUp) {
            // WebUI link (if configured)
            if (webuiUrl) {
                opts.push({
                    text: 'WebUI',
                    icon: 'fa-globe',
                    action: function(e) {
                        e.preventDefault();
                        var url = processWebUIUrl(webuiUrl);
                        if (isValidWebUIUrl(url)) {
                            window.open(url, '_blank');
                        }
                    }
                });
                opts.push({
                    divider: true
                });
            }

            // Compose Up (allows starting additional profile-scoped services)
            opts.push({
                text: 'Compose Up',
                icon: 'fa-play',
                action: function(e) {
                    e.preventDefault();
                    if (profiles.length > 0) {
                        showProfileSelector('up', path, profiles);
                    } else {
                        ComposeUp(path);
                    }
                }
            });

            // Compose Down (stop and remove containers)
            opts.push({
                text: 'Compose Down',
                icon: 'fa-stop',
                action: function(e) {
                    e.preventDefault();
                    if (profiles.length > 0) {
                        showProfileSelector('down', path, profiles);
                    } else {
                        ComposeDown(path);
                    }
                }
            });

            // Compose Stop (stop without removing)
            opts.push({
                text: 'Compose Stop',
                icon: 'fa-pause',
                action: function(e) {
                    e.preventDefault();
                    if (profiles.length > 0) {
                        showProfileSelector('stop', path, profiles);
                    } else {
                        ComposeStop(path);
                    }
                }
            });

            // Compose Restart
            opts.push({
                text: 'Compose Restart',
                icon: 'fa-refresh',
                action: function(e) {
                    e.preventDefault();
                    if (profiles.length > 0) {
                        showProfileSelector('restart', path, profiles);
                    } else {
                        ComposeRestart(path);
                    }
                }
            });

            opts.push({
                divider: true
            });

            // Update options based on whether updates are available
            if (hasUpdates) {
                // Update (when updates are available)
                var updateLabel = hasBuild ? 'Update & Rebuild' : 'Update';
                opts.push({
                    text: updateLabel,
                    icon: 'fa-cloud-download',
                    action: function(e) {
                        e.preventDefault();
                        if (profiles.length > 0) {
                            showProfileSelector('update', path, profiles);
                        } else {
                            UpdateStack(path);
                        }
                    }
                });
            } else {
                // Force Update (when no updates detected)
                var forceLabel = hasBuild ? 'Force Update & Rebuild' : 'Force Update';
                opts.push({
                    text: forceLabel,
                    icon: 'fa-cloud-download',
                    action: function(e) {
                        e.preventDefault();
                        if (profiles.length > 0) {
                            showProfileSelector('forceUpdate', path, profiles);
                        } else {
                            ForceUpdateStack(path);
                        }
                    }
                });
            }

            // ===== STACK IS STOPPED =====
        } else {
            // Compose Up
            opts.push({
                text: 'Compose Up',
                icon: 'fa-play',
                action: function(e) {
                    e.preventDefault();
                    if (profiles.length > 0) {
                        showProfileSelector('up', path, profiles);
                    } else {
                        ComposeUp(path);
                    }
                }
            });

            // Compose Down (only when there are existing resources to remove)
            if (canComposeDownStopped) {
                opts.push({
                    text: 'Compose Down',
                    icon: 'fa-stop',
                    action: function(e) {
                        e.preventDefault();
                        if (profiles.length > 0) {
                            showProfileSelector('down', path, profiles);
                        } else {
                            ComposeDown(path);
                        }
                    }
                });
            }

            opts.push({
                divider: true
            });

            // Pull/Build only (without starting)
            var pullOnlyLabel = hasBuild ? 'Build' : 'Pull';
            opts.push({
                text: pullOnlyLabel,
                icon: 'fa-download',
                action: function(e) {
                    e.preventDefault();
                    if (profiles.length > 0) {
                        showProfileSelector('pull', path, profiles);
                    } else {
                        ComposePull(path);
                    }
                }
            });

            // Update (pull/build and start)
            var updateLabel = hasBuild ? 'Build & Up' : 'Pull & Up';
            opts.push({
                text: updateLabel,
                icon: 'fa-cloud-download',
                action: function(e) {
                    e.preventDefault();
                    if (profiles.length > 0) {
                        showProfileSelector('update', path, profiles);
                    } else {
                        UpdateStack(path);
                    }
                }
            });
        }

        opts.push({
            divider: true
        });

        // Check for Updates (always available)
        opts.push({
            text: 'Check for Updates',
            icon: 'fa-search',
            action: function(e) {
                e.preventDefault();
                checkStackUpdates(project);
            }
        });

        // Edit Stack (always available)
        opts.push({
            text: 'Edit Stack',
            icon: 'fa-edit',
            action: function(e) {
                e.preventDefault();
                openEditorModalByProject(project, projectName);
            }
        });

        opts.push({
            divider: true
        });

        // View Logs (always available)
        opts.push({
            text: 'View Logs',
            icon: 'fa-navicon',
            action: function(e) {
                e.preventDefault();
                ComposeLogs(project);
            }
        });

        // View Last Cmd Log (always available)
        opts.push({
            text: 'View Last Cmd Log',
            icon: 'fa-list-alt',
            action: function(e) {
                e.preventDefault();
                ViewLastCmdLog(project, projectName);
            }
        });

        opts.push({
            divider: true
        });

        // Delete Stack
        if (!isUp) {
            opts.push({
                text: 'Delete Stack',
                icon: 'fa-trash',
                action: function(e) {
                    e.preventDefault();
                    deleteStackByProject(project, projectName);
                }
            });
        } else {
            opts.push({
                text: 'Delete Stack (Stop first)',
                icon: 'fa-trash',
                disabled: true
            });
        }

        context.destroy('#' + elementId);
        context.attach('#' + elementId, opts);
    }

    // Event delegation for docker-style container actions
    $(document).on('click', '.docker-action[data-action]', function(e) {
        e.preventDefault();
        var $action = $(this);
        var $row = $action.closest('.docker-row');
        var containerName = $row.data('container');
        var action = $action.data('action');
        if (containerName && action) {
            containerAction(containerName, action);
        }
    });

    // Row click handler - expand/collapse stack details
    $(document).on('click', 'tr.compose-sortable[id^="stack-row-"]', function(e) {
        if (isComposeSortModeEnabled()) {
            return;
        }

        var $target = $(e.target);

        // Don't expand if clicking on interactive elements
        if ($target.closest('[data-stackid]').length || // Stack icon (context menu)
            $target.closest('.expand-icon').length || // Expand arrow
            $target.closest('.compose-updatecolumn a').length || // Update links
            $target.closest('.compose-updatecolumn .exec').length || // Update actions
            $target.closest('.auto_start').length || // Autostart toggle
            $target.closest('.switchButton').length || // Switch button wrapper
            $target.closest('a').length || // Any link
            $target.closest('button').length || // Any button
            $target.closest('input').length) { // Any input
            return;
        }

        var stackId = this.id.replace('stack-row-', '');
        if (stackId) {
            toggleStackDetails(stackId);
        }
    });

    // Right-click anywhere on a stack row opens the stack context menu
    $(document).on('contextmenu', 'tr.compose-sortable[id^="stack-row-"]', function(e) {
        if (isComposeSortModeEnabled()) {
            return;
        }

        var $icon = $(this).find('[data-stackid]').first();
        if ($icon.length) {
            e.preventDefault();
            $icon.trigger($.Event('click', {
                pageX: e.pageX,
                pageY: e.pageY
            }));
        }
    });

    // Right-click anywhere on a container detail row opens the container context menu
    $(document).on('contextmenu', '#compose_stacks tr[data-container][data-stackid]', function(e) {
        var $icon = $(this).find('.hand[id^="ct-"]').first();
        if ($icon.length) {
            e.preventDefault();
            $icon.trigger($.Event('click', {
                pageX: e.pageX,
                pageY: e.pageY
            }));
        }
    });

    // Close actions menu when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#stack-actions-modal, .stack-kebab-btn').length) {
            closeStackActionsMenu();
        }
    });

    // Close actions menu on escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            closeStackActionsMenu();
        }
    });

    // Event delegation for container refresh button
    $(document).on('click', '.container-refresh-btn[data-stack-id]', function(e) {
        e.preventDefault();
        var stackId = $(this).data('stack-id');
        var project = $('#stack-row-' + stackId).data('project');
        if (stackId && project) {
            loadStackContainerDetails(stackId, project);
        }
    });

    // Keyboard support for expand toggle (Enter/Space)
    $(document).on('keydown', '.stack-expand-toggle', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).click();
        }
    });
</script>

<HTML>

<HEAD>
</HEAD>

<BODY>

    <span class='tipsterallowed' hidden></span>
    <div class="TableContainer">
        <table id="compose_stacks" class="tablesorter shift" style="table-layout:fixed;width:100%">
            <thead>
                <tr>
                    <th class="col-arrow"></th>
                    <th class="col-icon"></th>
                    <th class="col-name">Stack</th>
                    <th class="col-update">Update</th>
                    <th class="col-containers">Containers</th>
                    <th class="col-uptime">Uptime</th>
                    <th class="cm-advanced col-load">CPU &amp; Memory load</th>
                    <th class="cm-advanced col-description">Description</th>
                    <th class="cm-advanced col-path">Path</th>
                    <th class="nine col-autostart">Autostart</th>
                </tr>
            </thead>
            <tbody id="compose_list">
                <tr>
                    <td colspan='10'></td>
                </tr>
            </tbody>
        </table>
    </div>
    <span class='tipsterallowed' hidden>
        <input type='button' value='Add New Stack' onclick='addStack();'>
        <input type='button' value='Import from Docker Manager' onclick='importFromDockerManager();'>
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
                    WebUI Labels
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
                    <div class="editor-container active" id="editor-container-env">
                        <div id="editor-env" style="width: 100%; height: 100%;"></div>
                    </div>
                </div>
                <div class="editor-validation" id="editor-validation-env">
                    <i class="fa fa-check editor-validation-icon"></i> Ready
                </div>
            </div>

            <!-- ========== WEBUI LABELS PANEL ========== -->
            <div class="editor-panel" id="editor-panel-labels" role="tabpanel" aria-labelledby="editor-tab-labels">
                <div class="labels-panel">
                    <div class="labels-panel-header">
                        <p>Configure icons, WebUI links, and shell commands for each service. These labels integrate your containers with the unRAID Docker UI.</p>
                    </div>
                    <div id="labels-services-container">
                        <div class="labels-empty-state">
                            <i class="fa fa-spinner fa-spin"></i>
                            Loading services...
                        </div>
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
                            <label for="settings-env-path">External ENV File Path</label>
                            <input type="text" id="settings-env-path" placeholder="Default (uses .env in project folder)" data-pickroot="/" data-picktop="/mnt" data-pickcloseonfile="true">
                            <div class="settings-field-help">Path to an external .env file (e.g., /mnt/user/appdata/myapp/.env). Leave empty to use the default .env file in the project folder.</div>
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
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="editor-modal-footer">
                <div class="editor-footer-left">
                    <span class="editor-file-info" id="editor-file-info"></span>
                    <span class="editor-shortcuts">
                        <kbd>Ctrl+S</kbd> Save &nbsp; <kbd>Esc</kbd> Close
                    </span>
                </div>
                <div class="editor-footer-right">
                    <button class="editor-btn editor-btn-cancel" onclick="closeEditorModal()">Cancel</button>
                    <button class="editor-btn editor-btn-save-all" id="editor-btn-save-all" onclick="saveAllChanges()" disabled>Save All</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize editor modal after DOM is fully loaded
        $(function() {
            try {
                updateModalOffset();
                $(window).on('resize', updateModalOffset);
                initEditorModal();
            } catch (e) {
                composeLogger('Editor init error (non-fatal)', {
                    error: e && e.toString()
                }, 'user', 'warn', 'editorInit');
            }
            // Attach Unraid folder/file browser to all path inputs on the page.
            // The picker popup needs manual positioning for modals and overlay stacks.
            if ($.fn.fileTreeAttach) {
                var $pathInputs = $('input[data-pickroot]');
                composeBindFileTreeInputs($pathInputs, {
                    zIndex: 100010,
                    minWidth: 320,
                    addClass: true
                });
            }
        });

        // Reorder Compose section above Docker Containers if configured
        // Runs in its own $(function) to avoid being blocked by editor init errors
        $(function() {
            (function reorderComposeAboveDocker() {
                if (!showComposeOnTop) return;
                // In tabbed mode the sections live in separate tabs; reordering is not applicable
                if ($('.tabs').length) return;

                var $content = $('div.content');
                if (!$content.length) return;

                // Locate the two title divs by their text content
                // Use find() with a filter instead of children() to handle any nesting edge-cases
                var $dockerTitle = null;
                var $composeTitle = null;
                $content.children('div.title').each(function() {
                    var txt = $(this).text().trim();
                    if (!$dockerTitle && /Docker\s*Containers/i.test(txt)) $dockerTitle = $(this);
                    if (!$composeTitle && /Compose/i.test(txt) && !/Docker/i.test(txt)) $composeTitle = $(this);
                });

                if (!$dockerTitle || !$composeTitle) {
                    composeLogger('Reorder Compose above Docker skipped', {
                        dockerTitle: !!$dockerTitle,
                        composeTitle: !!$composeTitle
                    }, 'user', 'warn', 'reorderComposeAboveDocker');
                    return;
                }

                // Collect all nodes from the Compose title to the end of .content
                var composeNodes = [];
                var found = false;
                $content.contents().each(function() {
                    if (this === $composeTitle[0]) found = true;
                    if (found) composeNodes.push(this);
                });

                // Move them before the Docker title
                composeNodes.forEach(function(node) {
                    $content[0].insertBefore(node, $dockerTitle[0]);
                });
            })();
        });

        // Hide compose-managed containers from Docker Containers table if configured
        // Runs in its own $(function) to avoid being blocked by other init errors
        $(function() {
            (function hideComposeContainersFromDocker() {
                if (!hideComposeFromDocker) return;

                function getComposeContainerNames() {
                    var names = {};
                    // Primary source: data-containers attribute on stack rows
                    // (populated by PHP at list-load time — always available)
                    $('#compose_stacks .compose-sortable[data-containers]').each(function() {
                        try {
                            var list = JSON.parse($(this).attr('data-containers') || '[]');
                            for (var i = 0; i < list.length; i++) {
                                if (list[i]) names[list[i].toLowerCase()] = true;
                            }
                        } catch (e) {}
                    });
                    // Fallback: stack detail rows (if any stacks have been expanded)
                    $('#compose_stacks .stack-details-row').each(function() {
                        $(this).find('tr[data-container]').each(function() {
                            var name = $(this).attr('data-container');
                            if (name) names[name.toLowerCase()] = true;
                        });
                    });
                    // Fallback: stackUpdateStatus (populated after update checks)
                    if (typeof stackUpdateStatus !== 'undefined') {
                        for (var stackName in stackUpdateStatus) {
                            var info = stackUpdateStatus[stackName];
                            if (info.containers) {
                                for (var i = 0; i < info.containers.length; i++) {
                                    var n = info.containers[i].name;
                                    if (n) names[n.toLowerCase()] = true;
                                }
                            }
                        }
                    }
                    return names;
                }

                function doHide() {
                    var $dockerTable = $('#docker_list');
                    if (!$dockerTable.length) return;

                    var composeNames = getComposeContainerNames();
                    if (Object.keys(composeNames).length === 0) return;

                    $dockerTable.find('tr.sortable').each(function() {
                        var $row = $(this);
                        // Use a broad selector — just find the appname span anywhere in the first cell
                        var rowName = $row.find('td:first span.appname').first().text().trim();
                        if (!rowName) rowName = $row.find('td:first').text().trim();

                        if (composeNames[rowName.toLowerCase()]) {
                            $row.hide();
                        }
                    });
                }

                // Re-run whenever compose list reloads (event fired by composeLoadlist)
                $(document).on('compose-list-loaded', doHide);

                // Watch for Docker table changes (rows load asynchronously via AJAX)
                // Use polling until #docker_list exists, then attach MutationObserver
                function attachDockerObserver() {
                    var dockerTable = document.getElementById('docker_list');
                    if (dockerTable) {
                        var obs = new MutationObserver(function() {
                            setTimeout(doHide, 300);
                        });
                        obs.observe(dockerTable, {
                            childList: true,
                            subtree: true
                        });
                        // Initial run now that docker table exists
                        setTimeout(doHide, 500);
                    } else {
                        // docker_list not in DOM yet — retry
                        setTimeout(attachDockerObserver, 500);
                    }
                }
                attachDockerObserver();

                // Also run after a generous delay as final fallback
                setTimeout(doHide, 4000);
            })();
        });
    </script>

</BODY>

</HTML>