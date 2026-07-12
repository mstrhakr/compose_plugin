
// Parse a single memory value (for example "123.4MiB" or "512MB") to bytes.
// Supports both IEC (KiB, MiB, GiB, TiB) and SI (kB, MB, GB, TB) suffixes.
function parseMemValueToBytes(memVal) {
    if (!memVal) return 0;
    var cleaned = sanitizeStatsValue(memVal);
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

// Remove terminal escape residue from docker stats fields (for example trailing "[K").
function sanitizeStatsValue(raw) {
    var value = String(raw || '');
    value = value.replace(/\u001b\[[0-9;?]*[ -/]*[@-~]/g, '');
    value = value.replace(/[\x00-\x1F\x7F-\x9F]/g, '');
    value = value.replace(/\[[A-Za-z]$/g, '');
    return value.trim();
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

// Remove terminal control bytes and normalize docker IDs used as DOM keys.
function composeNormalizeContainerKey(rawId) {
    var value = String(rawId || '');
    // Strip ANSI CSI sequences and remaining control bytes.
    value = value.replace(/\u001b\[[0-9;?]*[ -/]*[@-~]/g, '');
    value = value.replace(/[\x00-\x1F\x7F-\x9F]/g, '');
    value = value.trim();

    // Prefer canonical hex ID if present.
    var hexMatch = value.match(/[a-f0-9]{12,64}/i);
    if (hexMatch && hexMatch[0]) {
        return hexMatch[0].toLowerCase();
    }
    return value;
}

// Safe fragment for jQuery/CSS selector concatenation.
function composeEscapeSelectorFragment(value) {
    var normalized = composeNormalizeContainerKey(value);
    if (typeof CSS !== 'undefined' && CSS && typeof CSS.escape === 'function') {
        return CSS.escape(normalized);
    }
    return normalized.replace(/([!"#$%&'()*+,./:;<=>?@[\\\]^`{|}~])/g, '\\$1');
}
    function updateAddStackDefaultComposeDiscoveryState() {
        var $checkbox = $('#compose-stack-use-default-compose-files');
        var $notice = $('#compose-stack-default-compose-files-disabled-note');
        if (!$checkbox.length) return;

        var hasIndirectFile = ($('#compose-stack-indirect-file').val() || '').trim() !== '';
        var hasEnvPath = ($('#compose-stack-env-path').val() || '').trim() !== '';
        if (hasIndirectFile || hasEnvPath) {
            if ($checkbox.is(':checked')) {
                $checkbox.prop('checked', false);
            }
            $checkbox.prop('disabled', true);
            if ($notice.length) {
                var reason = hasIndirectFile && hasEnvPath
                    ? 'Default discovery disabled because Indirect Compose File and Env File Path are set.'
                    : (hasIndirectFile
                        ? 'Default discovery disabled because Indirect Compose File is set.'
                        : 'Default discovery disabled because Env File Path is set.');
                $notice.text(reason).show();
            }
        } else {
            $checkbox.prop('disabled', false);
            if ($notice.length) {
                $notice.hide();
            }
        }
    }

    function updateSettingsDefaultComposeDiscoveryState(suppressChangeTracking) {
        var $checkbox = $('#settings-use-default-compose-files');
        if (!$checkbox.length) return;

        var $notice = $('#settings-default-compose-files-disabled-note');
        if (!$notice.length) {
            $notice = $('<div id="settings-default-compose-files-disabled-note" class="compose-status-warning" style="display:none;margin-top:8px;"></div>');
            var $anchor = $checkbox.closest('label');
            if ($anchor.length) {
                $anchor.after($notice);
            } else {
                $checkbox.parent().append($notice);
            }
        }

        var manualOverrideReasons = [];
        if (($('#settings-env-path').val() || '').trim() !== '') manualOverrideReasons.push('Env File Path');
        if (($('#settings-external-compose-file').val() || '').trim() !== '') manualOverrideReasons.push('External Compose File');
        if (getExtraComposeFilesValue() !== '') manualOverrideReasons.push('Additional Compose Files');

        if (manualOverrideReasons.length > 0) {
            var reason = 'Default discovery disabled because ' + manualOverrideReasons.join(' and ')
                + (manualOverrideReasons.length > 1 ? ' are set.' : ' is set.');

            if ($checkbox.is(':checked')) {
                $checkbox.prop('checked', false);
                if (!suppressChangeTracking) {
                    $checkbox.trigger('change');
                }
            }
            $checkbox.prop('disabled', true);
            $notice.text(reason).show();
        } else {
            $checkbox.prop('disabled', false);
            $notice.hide();
        }
    }

// ---- Additional Compose Files settings UI ----

// Combine checked folder candidates and external lines into the
// newline-separated value stored in the extra_compose_files metadata file.
function getExtraComposeFilesValue() {
    var lines = [];
    $('#settings-extra-compose-candidates input[type="checkbox"]:checked').each(function() {
        lines.push($(this).val());
    });
    ($('#settings-extra-compose-external').val() || '').split('\n').forEach(function(line) {
        line = line.trim();
        if (line) lines.push(line);
    });
    return lines.join('\n');
}

// Render the candidate checkbox list and route non-candidate entries
// (external/absolute paths) into the advanced textarea.
function renderExtraComposeFiles(candidates, rawValue) {
    var $list = $('#settings-extra-compose-candidates');
    var $none = $('#settings-extra-compose-none');
    var $external = $('#settings-extra-compose-external');

    var selected = [];
    (rawValue || '').split('\n').forEach(function(line) {
        line = line.trim();
        if (line && line.indexOf('#') !== 0) selected.push(line);
    });

    candidates = candidates || [];
    var externalLines = selected.filter(function(entry) {
        return candidates.indexOf(entry) === -1;
    });

    $list.empty();
    candidates.forEach(function(name) {
        var $label = $('<label>').css({ display: 'flex', 'align-items': 'center', gap: '8px', 'font-weight': 'normal' });
        var $cb = $('<input type="checkbox">').val(name).prop('checked', selected.indexOf(name) !== -1);
        $label.append($cb).append($('<span>').text(name));
        $list.append($label);
    });
    $list.toggle(candidates.length > 0);
    $none.toggle(candidates.length === 0);
    $external.val(externalLines.join('\n'));
    if (externalLines.length > 0) {
        $('#settings-extra-compose-external-wrap').attr('open', '');
    }
}

function onExtraComposeFilesChanged() {
    var currentValue = getExtraComposeFilesValue();
    var originalValue = editorModal.originalSettings['extra-compose-files'] || '';

    if (currentValue !== originalValue) {
        editorModal.modifiedSettings.add('extra-compose-files');
    } else {
        editorModal.modifiedSettings.delete('extra-compose-files');
    }

    updateSaveButtonState();
    updateTabModifiedState();
    updateSettingsDefaultComposeDiscoveryState();
}

function resetExtraComposeFilesUI() {
    $('#settings-extra-compose-candidates').empty().hide();
    $('#settings-extra-compose-none').hide();
    $('#settings-extra-compose-external').val('');
    $('#settings-extra-compose-external-wrap').removeAttr('open');
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

function formatInOutHtml(inVal, outVal, inLabel, outLabel) {
    var inTitle = composeEscapeAttr(inLabel || 'in');
    var outTitle = composeEscapeAttr(outLabel || 'out');
    var inText = composeEscapeHtml(inVal || '-');
    var outText = composeEscapeHtml(outVal || '-');
    return '<span class="compose-io-in"><i class="fa fa-arrow-down compose-io-icon compose-io-in-icon" title="' + inTitle + '"></i> ' + inText + '</span>' +
        '<span class="compose-io-out"><i class="fa fa-arrow-up compose-io-icon compose-io-out-icon" title="' + outTitle + '"></i> ' + outText + '</span>';
}

function composeNormalizeHealthStatus(rawHealth, state) {
    var health = String(rawHealth || '').toLowerCase().trim();
    var normalizedState = String(state || '').toLowerCase().trim();

    if (health === 'healthy' || health === 'unhealthy' || health === 'starting') {
        return health;
    }
    if (normalizedState !== 'running') {
        return 'stopped';
    }
    return 'none';
}

function composeHealthMeta(status) {
    var normalized = composeNormalizeHealthStatus(status, 'running');
    switch (normalized) {
        case 'healthy':
            return { icon: 'check-circle', textClass: 'green-text', label: 'healthy' };
        case 'unhealthy':
            return { icon: 'times-circle', textClass: 'red-text', label: 'unhealthy' };
        case 'starting':
            return { icon: 'refresh fa-spin', textClass: 'orange-text', label: 'starting' };
        case 'stopped':
            return { icon: 'stop-circle', textClass: 'grey-text', label: 'stopped' };
        case 'none':
            return { icon: 'minus-circle', textClass: 'compose-text-muted', label: 'n/a' };
        default:
            return { icon: 'question-circle', textClass: 'compose-text-muted', label: 'unknown' };
    }
}

function composeRenderHealthBadge(status, state) {
    var normalized = composeNormalizeHealthStatus(status, state);
    var meta = composeHealthMeta(normalized);
    return '<span class="' + meta.textClass + '" style="white-space:nowrap;"><i class="fa fa-' + meta.icon + ' fa-fw"></i> ' + meta.label + '</span>';
}

function composeAggregateStackHealth(containers) {
    var list = Array.isArray(containers) ? containers : [];
    if (list.length === 0) {
        return 'stopped';
    }

    var runningStates = [];
    list.forEach(function(ct) {
        var state = String((ct && ct.state) || '').toLowerCase().trim();
        if (state === 'running') {
            runningStates.push(composeNormalizeHealthStatus(ct ? ct.health : '', state));
        }
    });

    if (runningStates.length === 0) {
        return 'stopped';
    }
    if (runningStates.indexOf('unhealthy') !== -1) {
        return 'unhealthy';
    }
    if (runningStates.indexOf('starting') !== -1) {
        return 'starting';
    }
    if (runningStates.indexOf('healthy') !== -1) {
        return 'healthy';
    }
    return 'none';
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
        health: raw.health || raw.Health || '',
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
var composeListRefreshedDebounceTimer = null;

function triggerComposeListRefreshedDebounced(delayMs) {
    var waitMs = typeof delayMs === 'number' ? delayMs : 150;
    if (composeListRefreshedDebounceTimer) {
        clearTimeout(composeListRefreshedDebounceTimer);
    }
    composeListRefreshedDebounceTimer = setTimeout(function() {
        composeListRefreshedDebounceTimer = null;
        $(document).trigger('composeListRefreshed');
    }, waitMs);
}

function flushComposeListRefreshedDebounce() {
    if (composeListRefreshedDebounceTimer) {
        clearTimeout(composeListRefreshedDebounceTimer);
        composeListRefreshedDebounceTimer = null;
    }
    $(document).trigger('composeListRefreshed');
}

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
        var composeLoadRequestTs = Date.now();

        // Bind autostart change handler early, before any rows are rendered or become interactive
        // during progressive loading. Use delegated event handler so it works for dynamically added rows.
        $('#compose_list').off('change', '.auto_start').on('change', '.auto_start', function() {
            var script = $(this).attr("data-scriptname");
            var auto = $(this).prop('checked');
            $.post(caURL, {
                action: 'updateAutostart',
                script: script,
                autostart: auto
            });
        });

        composeTimers.load = setTimeout(function() {
            showComposeSpinner('Loading stack list...');
        }, 500);

        function finalizeComposeLoadlist(resolvePayload) {
            clearTimeout(composeTimers.load);

            // Signal load subscribers (e.g. dockerload cache) that the list changed
            flushComposeListRefreshedDebounce();

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

            try {
                resolve(resolvePayload);
            } catch (e) {
                resolve();
            }
        }

        $.get('/plugins/compose.manager/include/ComposeList.php', {
            mode: 'list',
            _: composeLoadRequestTs
            })
            .done(function(metaRaw) {
                var meta = tryParseJson(metaRaw);
                if (!meta || meta.result !== 'success' || !Array.isArray(meta.projects)) {
                    composeLogger('Invalid stack meta response', {
                        response: metaRaw
                    }, 'user', 'error', 'composeLoadlist');
                    $('#compose_list').html('<tr><td colspan="14" class="compose-status-danger" style="text-align:center;padding:20px;">Failed to load stack list. Please refresh the page.</td></tr>');
                    clearTimeout(composeTimers.load);
                    hideComposeSpinner();
                    reject(new Error('Invalid stack list response'));
                    return;
                }

                var projects = meta.projects;
                if (projects.length === 0) {
                    $('#compose_list').html('<tr><td colspan="14" style="text-align:center;padding:20px;color:var(--alt-text-color);">No Docker Compose stacks found. Click \"Add New Stack\" to create one.</td></tr>');
                    finalizeComposeLoadlist('');
                    return;
                }

                // Ensure hidden-column classes and table geometry are applied
                // before progressive rows begin rendering.
                if (window.composeColCustomizer && typeof window.composeColCustomizer.reapply === 'function') {
                    window.composeColCustomizer.reapply();
                }

                // Warm expansion settings once so progressive row insertions can expand immediately.
                ensureComposeDefaultExpandSettings();

                // Start loading stack container details in the background immediately.
                // UI rendering stays top-down; this just gets data ahead of the visual pipeline.
                prefetchStackDetailsInBackground(projects);

                var progressHtml = '<tr id="compose-load-progress-row">' +
                    '<td colspan="14" style="text-align:center;padding:12px;border:none;background:transparent;">' +
                    '<span class="compose-text-muted" style="display:inline-flex;align-items:center;gap:8px;">' +
                    '<i class="fa fa-refresh fa-spin"></i>' +
                    '<span>Loading stacks... <span id="compose-load-progress-count">0/' + projects.length + '</span></span>' +
                    '</span>' +
                    '</td>' +
                    '</tr>';
                $('#compose_list').html(progressHtml);

                // The progress row uses a static full-width colspan; clamp it to the
                // live column count so hidden columns don't reserve width.
                if (window.composeColCustomizer && typeof window.composeColCustomizer.syncColspans === 'function') {
                    window.composeColCustomizer.syncColspans();
                }

                // Prime cached update status early so rows can render status as soon as they land.
                if (!savedUpdateStatusLoaded) {
                    loadSavedUpdateStatus();
                }

                // Progressive mode shows in-table placeholders, so remove global overlay spinner.
                clearTimeout(composeTimers.load);
                hideComposeSpinner();

                var completed = 0;
                var queueIndex = 0;
                var commitIndex = 0;
                var activeRequests = 0;
                var maxParallelRequests = 4;
                var pendingRowsByIndex = {};
                var commitInProgress = false;
                var stackLoadTimers = {};

                function updateProgress() {
                    $('#compose-load-progress-count').text(completed + '/' + projects.length);
                    if (completed >= projects.length) {
                        $('#compose-load-progress-row').remove();
                        finalizeComposeLoadlist('progressive');
                    }
                }

                function queueProjectRequest(index) {
                    var project = projects[index];
                    stackLoadTimers[project] = Date.now();

                    composeLogger('progressive stack load start', {
                        project: project,
                        position: index + 1,
                        total: projects.length
                    }, 'user', 'debug', 'composeLoadlist');

                    activeRequests++;
                        $.get('/plugins/compose.manager/include/ComposeList.php', {
                            mode: 'row',
                            project: project,
                            _: composeLoadRequestTs + '-' + index
                        })
                        .done(function(rowRaw) {
                            var rowResp = tryParseJson(rowRaw);
                            var elapsedMs = Date.now() - (stackLoadTimers[project] || Date.now());
                            pendingRowsByIndex[index] = {
                                ok: !!(rowResp && rowResp.result === 'success' && rowResp.html),
                                rowResp: rowResp,
                                project: project,
                                position: index + 1,
                                elapsedMs: elapsedMs
                            };
                        })
                        .fail(function() {
                            var failElapsedMs = Date.now() - (stackLoadTimers[project] || Date.now());
                            pendingRowsByIndex[index] = {
                                ok: false,
                                rowResp: null,
                                project: project,
                                position: index + 1,
                                elapsedMs: failElapsedMs
                            };
                        })
                        .always(function() {
                            activeRequests--;
                            delete stackLoadTimers[project];
                            flushCommittedRowsInOrder();
                            dispatchProjectRequests();
                        });
                }

                function dispatchProjectRequests() {
                    while (activeRequests < maxParallelRequests && queueIndex < projects.length) {
                        queueProjectRequest(queueIndex);
                        queueIndex++;
                    }
                }

                function flushCommittedRowsInOrder() {
                    if (commitInProgress) {
                        return;
                    }

                    if (!Object.prototype.hasOwnProperty.call(pendingRowsByIndex, commitIndex)) {
                        return;
                    }

                    var entry = pendingRowsByIndex[commitIndex];
                    delete pendingRowsByIndex[commitIndex];
                    commitInProgress = true;

                    var waitForExpansion = Promise.resolve();
                    if (entry.ok) {
                        composeLogger('progressive stack load success', {
                            project: entry.project,
                            position: entry.position,
                            total: projects.length,
                            elapsedMs: entry.elapsedMs
                        }, 'user', 'debug', 'composeLoadlist');

                        var $rowChunk = $(entry.rowResp.html);
                        $('#compose-load-progress-row').before($rowChunk);
                        initializeProgressiveLoadedRows($rowChunk);
                        if (typeof window.composeDockerLoadRenderCached === 'function' && composeShouldEnableDockerLoad()) {
                            window.composeDockerLoadRenderCached();
                        }
                        waitForExpansion = composeDefaultExpandQueue;
                    } else {
                        composeLogger('progressive stack load failed', {
                            project: entry.project,
                            position: entry.position,
                            total: projects.length,
                            elapsedMs: entry.elapsedMs
                        }, 'user', 'warn', 'composeLoadlist');
                        $('#compose-load-progress-row').before('<tr><td colspan="14" class="compose-status-danger" style="padding:8px 12px;">Failed to load ' + composeEscapeHtml(entry.project) + '.</td></tr>');
                    }

                    waitForExpansion.finally(function() {
                        commitIndex++;
                        completed++;
                        commitInProgress = false;
                        updateProgress();
                        flushCommittedRowsInOrder();
                    });
                }

                dispatchProjectRequests();
            })
            .fail(function(xhr, status, error) {
                composeLogger('failed', {
                    status: status,
                    error: error
                }, 'user', 'error', 'composeLoadlist');
                clearTimeout(composeTimers.load);
                hideComposeSpinner();
                $('#compose_list').html('<tr><td colspan="14" class="compose-status-danger" style="text-align:center;padding:20px;">Failed to load stack list. Please refresh the page.</td></tr>');

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
    // Note: change handler for .auto_start is now bound early in composeLoadlist()
    // before any rows are added, so it's ready for both progressive and final renders.

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

    // Apply list enhancements driven by current column visibility.
    applyListEnhancements(false);

            // Recompute stack-row striping after all stack rows are present so
            // hidden detail rows do not skew alternating backgrounds.
            syncComposeStackRowStriping();

    // Seed expandedStacks from any rows rendered expanded server-side
    $('.stack-details-row:visible').each(function() {
        var stackId = this.id.replace('details-row-', '');
        expandedStacks[stackId] = true;
    });

    // Load saved update status after list is loaded (skip if already primed)
    if (!savedUpdateStatusLoaded) {
        loadSavedUpdateStatus();
    }

    syncComposeSortModeUI();
}

function initializeProgressiveLoadedRows($rowChunk) {
    if (!$rowChunk || !$rowChunk.length) return;

    var $rows = $rowChunk.filter('tr.compose-sortable');
    if (!$rows.length) {
        $rows = $rowChunk.find('tr.compose-sortable');
    }
    if (!$rows.length) return;

    // Initialize autostart toggles only for newly appended rows.
    $rows.find('.auto_start').each(function() {
        var $el = $(this);
        if ($el.data('switchbutton-initialized')) return;
        $el.switchButton({
            labels_placement: 'right',
            on_label: 'On',
            off_label: 'Off',
            clear: false
        });
        $el.data('switchbutton-initialized', true);
    });

    // Initialize context menus for only newly added stack icons.
    $rows.find('[id^="stack-"][data-stackid]').each(function() {
        addComposeStackContext(this.id);
    });

    // Keep description truncation behavior consistent for new rows.
    $rows.find('.docker_readmore').not('.stack-details-container .docker_readmore').each(function() {
        var $el = $(this);
        $el.readmore('destroy');
        $el.readmore({
            maxHeight: 32,
            moreLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",
            lessLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"
        });
    });

    // Skip full-table helper refresh here; new rows already get row-local
    // readmore/context/toggle setup above, and global apply runs at finalize.

    // Apply cached update status immediately for new rows when available.
    $rows.each(function() {
        var project = $(this).data('project');
        if (project && stackUpdateStatus[project]) {
            updateStackUpdateUI(project, stackUpdateStatus[project]);
        }
    });
    updateUpdateAllButton();

    // If background prefetch already has this stack, hydrate parent-row summary
    // immediately (health, containers, uptime/state) without waiting for expansion.
    $rows.each(function() {
        var $row = $(this);
        var project = $row.data('project');
        if (!project || !stackDetailsPrefetchCache[project]) return;
        applyStackSummaryFromResponse(project, stackDetailsPrefetchCache[project]);
    });

    // Apply default expansion policy per row as soon as it is inserted.
    applyDefaultExpansionToRows($rows);

    // Keep stack striping stable while chunks stream in.
    syncComposeStackRowStriping();

    // Newly inserted rows include detail rows with a static full-width colspan;
    // clamp it to the live column count so hidden columns don't reserve width.
    if (window.composeColCustomizer && typeof window.composeColCustomizer.syncColspans === 'function') {
        window.composeColCustomizer.syncColspans();
    }

    // Notify dockerload/hide-from-docker listeners; debounce to avoid
    // repeated expensive invalidation work during progressive append bursts.
    triggerComposeListRefreshedDebounced(150);
    if (typeof window.composeDockerLoadToggle === 'function') {
        window.composeDockerLoadToggle(composeShouldEnableDockerLoad());
    }
    if (typeof window.composeDockerLoadRenderCached === 'function' && composeShouldEnableDockerLoad()) {
        window.composeDockerLoadRenderCached();
    }

    updateStackToggleAllButtonState();
}

function applyStackSummaryFromResponse(project, response) {
    if (!project || !response || response.result !== 'success') return;

    var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
    if (!$stackRow.length) return;

    var rowId = String($stackRow.attr('id') || '');
    var stackId = rowId.indexOf('stack-row-') === 0 ? rowId.substring('stack-row-'.length) : '';
    if (!stackId) return;

    var containers = (response.containers || []).map(createContainerInfo).filter(Boolean);
    mergeUpdateStatus(containers, project);
    stackContainersCache[stackId] = containers;
    if (response.startedAt) {
        stackStartedAtCache[stackId] = response.startedAt;
    }

    updateParentStackFromContainers(stackId, project);
}

// Load external stylesheets (non-critical styles — critical ones are inline above)
(function() {
    var base = composeBootstrap.comboButtonCss || '';
    var editor = composeBootstrap.editorModalCss || '';
    if (base && !$('link[href="' + base + '"]').length)
        $('head').append($('<link rel="stylesheet" type="text/css" />').attr('href', base));
    if (editor && !$('link[href="' + editor + '"]').length) {
        $('head').append($('<link rel="stylesheet" type="text/css" />').attr('href', editor));
    }
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

function syncComposeStackRowStriping() {
    var $rows = $('#compose_stacks tr.compose-sortable');
    if (!$rows.length) return;

    $rows.each(function(index) {
        $(this).toggleClass('compose-stack-row-alt', index % 2 === 0);
    });
}

// Editor modal state
var editorModal = {
    editors: {},
    currentTab: 'compose',
    originalContent: {},
    modifiedTabs: new Set(),
    currentProject: null,
    currentProjectName: null,
    validationTimeout: null,
    // Settings state
    originalSettings: {},
    modifiedSettings: new Set(),
    // Labels state
    originalLabels: {},
    modifiedLabels: new Set(),
    labelsData: null, // Stores the parsed compose and override data
    labelsViewMode: 'basic', // Applied mode: 'basic' (form UI) or 'advanced' (override editor)
    pendingLabelsViewMode: 'basic', // Pending settings value; applied only after save
    filePaths: {
        stackMeta: '',
        compose: '',
        env: '',
        projectOverride: '',
        effectiveOverride: ''
    }
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

    // Initialize override editor (for labels tab advanced mode)
    var overrideEditor = ace.edit('editor-override');
    overrideEditor.setTheme(aceTheme);
    overrideEditor.setShowPrintMargin(false);
    overrideEditor.setOptions({
        fontSize: '1.1rem',
        tabSize: 2,
        useSoftTabs: true,
        wrap: true
    });
    overrideEditor.getSession().setUseWorker(false);
    overrideEditor.getSession().setMode('ace/mode/yaml');
    overrideEditor.on('change', function() {
        var currentContent = overrideEditor.getValue();
        var originalContent = editorModal.originalContent['override'] || '';
        if (currentContent !== originalContent) {
            editorModal.modifiedTabs.add('override');
        } else {
            editorModal.modifiedTabs.delete('override');
        }
        updateSaveButtonState();
        updateTabModifiedState();
        debounceValidation('override', currentContent);
    });
    editorModal.editors['override'] = overrideEditor;

    // Initialize settings field change tracking
    $('#settings-name, #settings-description, #settings-icon-url, #settings-webui-url, #settings-env-path, #settings-default-profile, #settings-external-compose-path, #settings-external-compose-file, #settings-use-default-compose-files').on('input change', function() {
        var fieldId = this.id.replace('settings-', '');
        var isCheckbox = this.type === 'checkbox';
        var currentValue = isCheckbox ? ($(this).is(':checked') ? 'true' : 'false') : $(this).val();
        var originalValue = editorModal.originalSettings[fieldId] || '';

        if (currentValue !== originalValue) {
            editorModal.modifiedSettings.add(fieldId);
        } else {
            editorModal.modifiedSettings.delete(fieldId);
        }

        updateSaveButtonState();
        updateTabModifiedState();

        if (fieldId === 'env-path' || fieldId === 'external-compose-file') {
            updateSettingsDefaultComposeDiscoveryState();
        }
    });

    // Additional compose files: combined change tracking for the candidate
    // checkboxes (dynamic) and the external paths textarea.
    $('#settings-extra-compose-candidates').on('change', 'input[type="checkbox"]', onExtraComposeFilesChanged);
    $('#settings-extra-compose-external').on('input change', onExtraComposeFilesChanged);

    // Override management mode is a saveable setting (deferred persist).
    $('#settings-override-management').on('change', function() {
        var mode = $(this).is(':checked') ? 'basic' : 'advanced';
        var originalMode = editorModal.originalSettings['labels-view-mode'] || 'basic';

        // Defer labels tab mode switch until save; only stage pending setting locally.
        editorModal.pendingLabelsViewMode = mode;
        $('#settings-override-management-label').text(mode === 'advanced' ? 'Manual' : 'Automatic');

        if (mode !== originalMode) {
            editorModal.modifiedSettings.add('labels-view-mode');
        } else {
            editorModal.modifiedSettings.delete('labels-view-mode');
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
        var filePath = $('#settings-external-compose-file').val().trim();
        if (path || filePath) {
            $('#settings-external-compose-info').show();
        } else {
            $('#settings-external-compose-info').hide();
        }
    });
    $('#settings-external-compose-file').on('input', function() {
        var path = $('#settings-external-compose-path').val().trim();
        var filePath = $(this).val().trim();
        if (path || filePath) {
            $('#settings-external-compose-info').show();
        } else {
            $('#settings-external-compose-info').hide();
        }

        // Warn if the selected file lives inside the stack project folder
        var stackPath = (editorModal.filePaths.stackMeta || '').replace(/\/$/, '');
        var $warning = $('#settings-external-compose-file-warning');
        if (filePath && stackPath && filePath.startsWith(stackPath + '/')) {
            if (!$warning.length) {
                $warning = $('<div id="settings-external-compose-file-warning" class="compose-status-error" style="margin-top:6px;"></div>');
                $(this).after($warning);
            }
            $warning.text('This file is inside the stack project folder. The path must be external to this stack.').show();
        } else if ($warning.length) {
            $warning.hide();
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

    if (tabName !== 'settings' && hasPathSensitiveSettingsChanges()) {
        enforcePathSettingsExclusivity('opening other tabs');
        tabName = 'settings';
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
    updateEditorFileInfo();

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

    if (tabName === 'labels') {
        // Apply stack-specific mode every time labels tab opens.
        var labelsAdvanced = editorModal.labelsViewMode === 'advanced';
        toggleLabelsViewMode(labelsAdvanced, true);

        // If in advanced mode, ensure override editor is populated + resized.
        if (labelsAdvanced) {
            if (!editorModal.labelsData) {
                loadLabelsData(function() { _populateOverrideEditor(); });
            } else {
                _populateOverrideEditor();
            }
        } else if (!editorModal.labelsData) {
            // Basic mode still needs labels service/form data.
            loadLabelsData();
        }
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

function getEditorSaveTargetPath() {
    switch (editorModal.currentTab) {
        case 'compose':
            return editorModal.filePaths.compose || editorModal.filePaths.stackMeta;
        case 'env':
            return editorModal.filePaths.env || editorModal.filePaths.stackMeta;
        case 'labels':
            if (editorModal.labelsViewMode === 'advanced') {
                return editorModal.filePaths.effectiveOverride || editorModal.filePaths.projectOverride || editorModal.filePaths.stackMeta;
            }
            return editorModal.filePaths.projectOverride || editorModal.filePaths.stackMeta;
        case 'settings':
        default:
            return editorModal.filePaths.stackMeta;
    }
}

function getEditorActiveFilePath() {
    switch (editorModal.currentTab) {
        case 'compose':
            return editorModal.filePaths.compose || '';
        case 'env':
            return editorModal.filePaths.env || '';
        case 'labels':
            if (editorModal.labelsViewMode === 'advanced') {
                return editorModal.filePaths.effectiveOverride || editorModal.filePaths.projectOverride || '';
            }
            return '';
        case 'settings':
        default:
            return '';
    }
}

function setEditorPathText(selector, value, emptyText) {
    var text = value || emptyText;
    var isEmpty = !value;
    $(selector)
        .text(text)
        .attr('title', text)
        .attr('data-empty', isEmpty ? 'true' : 'false');
}

function updateEditorFileInfo() {
    var stackPath = editorModal.filePaths.stackMeta || '';
    var activeFilePath = getEditorActiveFilePath();
    var savePath = getEditorSaveTargetPath();
    var fallbackText = savePath && savePath !== stackPath
        ? 'No raw file in current tab; changes save via ' + savePath
        : 'No raw file in current tab';

    setEditorPathText('#editor-project-dir', stackPath, 'Project directory unavailable');
    setEditorPathText('#editor-edit-file', activeFilePath, fallbackText);
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

    // Labels tab — modified if labels form OR override editor has changes
    if (editorModal.modifiedLabels.size > 0 || editorModal.modifiedTabs.has('override')) {
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
var savedUpdateStatusLoaded = false;

var composeDefaultExpandSettings = null;
var composeDefaultExpandSettingsPromise = null;
var composeDefaultExpandQueue = Promise.resolve();

function ensureComposeDefaultExpandSettings() {
    if (composeDefaultExpandSettings !== null) {
        return Promise.resolve(composeDefaultExpandSettings);
    }
    if (composeDefaultExpandSettingsPromise) {
        return composeDefaultExpandSettingsPromise;
    }

    composeDefaultExpandSettingsPromise = getConfig().then(function(config) {
        composeDefaultExpandSettings = {
            enabled: config && config['STACKS_DEFAULT_EXPANDED'] == 'true',
            onlyRunning: config && config['ONLY_EXPAND_RUNNING_STACKS'] == 'true'
        };
        return composeDefaultExpandSettings;
    }).catch(function() {
        composeDefaultExpandSettings = {
            enabled: false,
            onlyRunning: false
        };
        return composeDefaultExpandSettings;
    });

    return composeDefaultExpandSettingsPromise;
}

function applyDefaultExpansionToRows($rows) {
    if (!$rows || !$rows.length) return;

    ensureComposeDefaultExpandSettings().then(function(settings) {
        if (!settings || !settings.enabled) return;

        $rows.each(function() {
            var $row = $(this);
            var stackId = ($row.attr('id') || '').replace('stack-row-', '');
            if (!stackId) return;

            if (settings.onlyRunning && $row.data('isup') != '1') {
                return;
            }

            if (expandedStacks[stackId] || $('#details-row-' + stackId).is(':visible')) {
                return;
            }

            composeDefaultExpandQueue = composeDefaultExpandQueue.then(function() {
                return expandStackDetailsSequential(stackId);
            }).catch(function() {
                return null;
            });
        });

        composeDefaultExpandQueue = composeDefaultExpandQueue.then(function() {
            updateStackToggleAllButtonState();
        });
    });
}

function expandStackDetailsSequential(stackId) {
    var $row = $('#stack-row-' + stackId);
    var $detailsRow = $('#details-row-' + stackId);
    var $expandIcon = $('#expand-icon-' + stackId);
    var project = $row.data('project');

    if (!$row.length || !$detailsRow.length) {
        return Promise.resolve();
    }

    // Already expanded/visible: if a load is in progress, wait for it; otherwise skip.
    if (expandedStacks[stackId] || $detailsRow.is(':visible')) {
        if (stackDetailsLoading[stackId]) {
            return loadStackContainerDetails(stackId, project);
        }
        return Promise.resolve();
    }

    stackDetailsDesiredExpanded[stackId] = true;
    $expandIcon.addClass('expanded');
    expandedStacks[stackId] = true;

    if (stackContainersCache[stackId]) {
        renderContainerDetails(stackId, stackContainersCache[stackId], project);
        return new Promise(function(resolve) {
            $detailsRow.stop(true, true).slideDown(200, resolve);
        });
    }

    return loadStackContainerDetails(stackId, project);
}

// Set to true when docker.versions plugin is detected (populated from getSavedUpdateStatus response)
var dockerVersionsInstalled = false;

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
                    if (response.dockerVersionsInstalled) dockerVersionsInstalled = true;

                    // Mark as loaded only after successful callback
                    savedUpdateStatusLoaded = true;

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
    }).fail(function(xhr, status, error) {
        // Reset flag on transport failure so retries can work
        savedUpdateStatusLoaded = false;
        composeLogger('Failed to load saved update status', { status: status, error: error }, 'user', 'error', 'update-check');
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
                        }, 'user', 'debug', 'update-check');

                        // Check each pending stack
                        pendingStacks.forEach(function(stackName) {
                            composeLogger('Running recheck for recently updated stack', {
                                stackName: stackName
                            }, 'user', 'debug', 'update-check');
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
        composeLogger('Last check was ' + Math.round((now - latestCheck) / 60) + ' minutes ago, interval is ' + Math.round(intervalSeconds / 60) + ' minutes. Skipping.', null, 'user', 'debug', 'update-check');
    }

    if (needsCheck) {
        composeLogger('Running automatic update check...', null, 'user', 'debug', 'update-check');
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
        var updateHtml = '<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + composeEscapeAttr(stackName) + '\', \'' + composeEscapeAttr(stackId) + '\', \'update\');">';
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
                updateHtml += '<div class="compose-text-muted" style="font-size:0.8em;margin-top:2px;">Expand for details</div>';
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
            html += '<div class="compose-status-info" style="font-size:0.8em;margin-top:2px;"><i class="fa fa-thumb-tack fa-fw"></i> ' + pinnedCount + ' pinned</div>';
            html += '<div><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + composeEscapeAttr(stackName) + '\', \'' + composeEscapeAttr(stackId) + '\', \'forceUpdate\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
            $updateCell.html(html);
        } else {
            // No updates, no pinned - green "up-to-date" style (like Docker tab)
            // Additional row details are conditionally shown by column layout CSS.
            var html = '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
            html += '<div><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + composeEscapeAttr(stackName) + '\', \'' + composeEscapeAttr(stackId) + '\', \'forceUpdate\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
            $updateCell.html(html);
        }
    } else {
        // No containers found in update data — stack is running but
        // hasn't been checked yet.  Prompt a check rather than an
        // update so the SHA metadata gets populated first.
        $updateCell.html('<a class="exec" style="cursor:pointer;" onclick="checkStackUpdates(\'' + composeEscapeAttr(stackName) + '\');"><i class="fa fa-cloud-download fa-fw"></i> check for updates</a>');
    }

    // Extra detail elements are controlled by table column visibility classes.
    // No per-cell show/hide work is needed here.

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
            }, 'user', 'debug', 'update-check');
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
        $.ajax({
            url: caURL,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getPersistentContainerCache'
            }
        })
            .done(function(response) {
                composeLogger('Loaded persistent container cache', response, 'user', 'debug', 'load-cache');
                persistentContainerCache = (response && response.cache) ? response.cache : {};
                resolve(persistentContainerCache);
            })
            .fail(function() {
                composeLogger('Failed to load persistent container cache', null, 'user', 'error', 'load-cache');
                persistentContainerCache = {};
                resolve(persistentContainerCache);
            });
    });
}

function loadComposeLoadSnapshot() {
    return new Promise(function(resolve) {
        $.ajax({
            url: caURL,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getComposeLoadSnapshot'
            }
        })
            .done(function(response) {
                composeLogger('Loaded compose load snapshot', response, 'user', 'debug', 'load-snapshot');
                var snapshot = (response && response.snapshot && typeof response.snapshot === 'object') ? response.snapshot : {};
                window.composeLoadSnapshotRaw = typeof snapshot.raw === 'string' ? snapshot.raw : '';
                window.composeLoadSnapshotTs = Number(snapshot.ts || 0);
                if (typeof window.composeDockerLoadSeedSnapshot === 'function' && window.composeLoadSnapshotRaw) {
                    window.composeDockerLoadSeedSnapshot(window.composeLoadSnapshotRaw, window.composeLoadSnapshotTs);
                }
                resolve(snapshot);
            })
            .fail(function() {
                composeLogger('Failed to load compose load snapshot', null, 'user', 'error', 'load-snapshot');
                window.composeLoadSnapshotRaw = '';
                window.composeLoadSnapshotTs = 0;
                resolve({});
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

function composeHasVisibleLoadColumns() {
    var selector = [
        '#compose_stacks td.col-cpu',
        '#compose_stacks td.col-memory',
        '#compose_stacks td.col-net_io',
        '#compose_stacks td.col-block_io',
        '#compose_stacks td.ct-col-cpu',
        '#compose_stacks td.ct-col-memory',
        '#compose_stacks td.ct-col-net_io',
        '#compose_stacks td.ct-col-block_io'
    ].join(',');
    return $(selector).filter(':visible').length > 0;
}

function composeShouldEnableDockerLoad() {
    if (document.visibilityState === 'hidden') {
        return false;
    }
    return composeHasVisibleLoadColumns();
}

// Apply list-level helpers that depend on the current column layout.
function applyListEnhancements(animate) {
    if (typeof window.composeDockerLoadToggle === 'function') {
        window.composeDockerLoadToggle(composeShouldEnableDockerLoad());
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

    // Refresh per-row UI helpers after initial DOM is ready.
    applyListEnhancements();

    // ebox observer removed; pending update checks are now processed from
    // refreshStackRow and processPendingComposeReloads directly.

    // Gate dockerload socket start until the stack list DOM is ready.
    var composeListReady = false;
    var composeListLoadStartedAt = Date.now();

    // Load the persistent container cache before the stack list.
    // This ensures dialog merge logic can use last-known container metadata.
    loadPersistentContainerCache().then(function() {
        return loadComposeLoadSnapshot();
    }).then(function() {
        composeListLoadStartedAt = Date.now();
        composeLoadlist().then(function(loadMode) {
            composeListReady = true;
            composeLogger('compose list ready', {
                rows: $('#compose_stacks tr.compose-sortable').length,
                elapsedMs: Date.now() - composeListLoadStartedAt,
                mode: loadMode || 'standard',
                composeListReady: true
            }, 'user', 'info', 'composeLoadlist');

            // Start the dockerload socket now that the DOM has rows with data-ctids.
            if (typeof window.composeDockerLoadToggle === 'function') {
                var shouldRunComposeStats = composeShouldEnableDockerLoad();
                composeLogger('triggering composeDockerLoadToggle, enabled=' + shouldRunComposeStats, null, 'user', 'debug', 'composeLiveStats');
                window.composeDockerLoadToggle(shouldRunComposeStats);
            } else {
                composeLogger('composeDockerLoadToggle not available yet at composeLoadlist completion', null, 'user', 'debug', 'composeLiveStats');
            }

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
                    composeLogger('hooked loadlist() for cross-widget sync, tabbed=' + isTabbed, null, 'user', 'debug', 'hookLoadlist');
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
        // composeDockerLoadToggle(true/false) is called from applyListEnhancements()
        // and column visibility handlers so the socket follows visible load columns.
        function initComposeDockerLoadSubscriber() {
            if (typeof NchanSubscriber !== 'function') {
                composeLogger('NchanSubscriber not available yet', null, 'user', 'debug', 'dockerload');
                return false;
            }

            // Tear down previous subscriber if page was re-navigated (Unraid
            // AJAX navigation preserves window globals but old closures/sockets
            // become stale).  Always create a fresh subscriber.
            if (window._composeDockerLoad) {
                composeLogger('tearing down previous subscriber', null, 'user', 'debug', 'dockerload');
                try {
                    window._composeDockerLoad.stop();
                } catch (e) {}
            }
            if (window._composeDockerLoadStaleTimer) {
                clearInterval(window._composeDockerLoadStaleTimer);
            }
            if (window._composeDockerLoadRenderTimer) {
                clearTimeout(window._composeDockerLoadRenderTimer);
                window._composeDockerLoadRenderTimer = null;
            }
            if (window._composeDockerLoadVisHandler) {
                document.removeEventListener('visibilitychange', window._composeDockerLoadVisHandler);
            }
            if (window._composeDockerLoadPanelObserver) {
                window._composeDockerLoadPanelObserver.disconnect();
            }
            $(document).off('composeListRefreshed.dockerload');

            composeLogger('initializing subscriber, composeListReady=' + composeListReady, null, 'user', 'debug', 'dockerload');

            var composeDockerLoad = new NchanSubscriber('/sub/composeinfo', {
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
            var composeStackSignature = '';
            var composeLoadById = {};
            var composeLoadStaleMs = 15000;
            var composeLoadRenderMinIntervalMs = 120;
            var composeLoadLastRenderMs = 0;

            function isComposeLoadVisible() {
                if (!composeHasVisibleLoadColumns()) return false;
                if (document.visibilityState === 'hidden') return false;
                var $table = $('#compose_stacks');
                if (!$table.length) return false;
                var $tabPanel = $table.closest('[role="tabpanel"]');
                if ($tabPanel.length && $tabPanel[0].style.display === 'none') return false;
                return true;
            }

            function clearContainerLoad(shortId) {
                var selectorId = composeEscapeSelectorFragment(shortId);
                $('.compose-cpu-' + selectorId).addClass('compose-text-muted').text('-');
                $('#compose-cpu-bar-' + selectorId).css('width', '0');
                $('.compose-mem-' + selectorId).addClass('compose-text-muted').text('-');
                $('#compose-mem-bar-' + selectorId).css('width', '0');
                $('.compose-netio-' + selectorId).addClass('compose-text-muted').html('-');
                $('.compose-blockio-' + selectorId).addClass('compose-text-muted').html('-');
            }

            // Build a lightweight fingerprint of stack rows + their container ids.
            // Used to avoid invalidating caches when composeListRefreshed fires but
            // the actual stack structure did not change.
            function getComposeStackSignatureFromDom() {
                var parts = [];
                $('#compose_stacks tr.compose-sortable').each(function() {
                    var stackId = ($(this).attr('id') || '').replace('stack-row-', '');
                    if (!stackId) return;
                    var ctidsAttr = $(this).attr('data-ctids') || '';
                    parts.push(stackId + ':' + ctidsAttr);
                });
                return parts.join('|');
            }

            function getComposeContainerIdSetFromDom() {
                var idSet = {};
                $('#compose_stacks tr.compose-sortable').each(function() {
                    var ctidsAttr = $(this).attr('data-ctids') || '';
                    if (!ctidsAttr) return;
                    ctidsAttr.split(',').forEach(function(id) {
                        if (id) idSet[id] = true;
                    });
                });
                return idSet;
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
                composeStackSignature = getComposeStackSignatureFromDom();
            }

            // Invalidate only when stack/container structure changed.
            // composeListRefreshed may fire for UI-only updates where cache can be reused.
            $(document).on('composeListRefreshed.dockerload', function() {
                var newSignature = getComposeStackSignatureFromDom();
                var structureChanged = (newSignature !== composeStackSignature);

                // No structural change: keep index/cache intact.
                if (!structureChanged) {
                    if (composeListReady && composeStackIndex) {
                        composeLogger('composeListRefreshed — structure unchanged, keeping cache', null, 'user', 'debug', 'dockerload');
                    }
                    return;
                }

                var hadIndex = !!composeStackIndex;
                var hadLoadCache = Object.keys(composeLoadById).length > 0;
                if (composeListReady && (hadIndex || hadLoadCache)) {
                    composeLogger('composeListRefreshed — structure changed, invalidating stack index', null, 'user', 'debug', 'dockerload');
                }

                // Rebuild index lazily on next render tick.
                composeStackIndex = null;
                composeStackSignature = newSignature;

                // During progressive initial load, the DOM only contains a prefix of
                // the total stack list. Preserve preloaded snapshot/SSE cache entries
                // for rows that have not been inserted yet; otherwise later rows lose
                // their warm-start metrics before they ever render.
                if (!composeListReady) {
                    return;
                }

                // Keep live load values for unchanged containers; drop removed ids.
                var currentIds = getComposeContainerIdSetFromDom();
                Object.keys(composeLoadById).forEach(function(id) {
                    if (!currentIds[id]) {
                        delete composeLoadById[id];
                        clearContainerLoad(id);
                    }
                });
            });

            window.composeDockerLoadToggle = function(enable) {
                if (enable && !composeDockerLoadRunning) {
                    composeLogger('starting WebSocket', null, 'user', 'debug', 'dockerload');
                    composeDockerLoad.start();
                    composeDockerLoadRunning = true;
                } else if (!enable && composeDockerLoadRunning) {
                    composeLogger('stopping WebSocket', null, 'user', 'debug', 'dockerload');
                    composeDockerLoad.stop();
                    composeDockerLoadRunning = false;
                }
            };

            function renderComposeLoadCache() {
                if (!isComposeLoadVisible()) {
                    return;
                }

                for (var shortId in composeLoadById) {
                    var info = composeLoadById[shortId];
                    var selectorId = composeEscapeSelectorFragment(shortId);
                    $('.compose-cpu-' + selectorId).removeClass('compose-text-muted').text(info.cpuText + ' / 100%');
                    $('.compose-mem-' + selectorId).removeClass('compose-text-muted').text(info.mem);
                    $('.compose-netio-' + selectorId).removeClass('compose-text-muted').html(formatInOutHtml(info.netInput, info.netOutput, 'in', 'out'));
                    $('.compose-blockio-' + selectorId).removeClass('compose-text-muted').html(formatInOutHtml(info.blockInput, info.blockOutput, 'read', 'write'));
                    $('#compose-cpu-bar-' + selectorId).css('width', info.cpuText);
                    $('#compose-mem-bar-' + selectorId).css('width', Math.min(info.memPercent || 0, 100).toFixed(2) + '%');
                }

                renderStackAggregates();
                composeLoadLastRenderMs = Date.now();
            }

            function scheduleComposeLoadRender(forceImmediate) {
                if (!isComposeLoadVisible()) {
                    return;
                }
                if (forceImmediate) {
                    if (window._composeDockerLoadRenderTimer) {
                        clearTimeout(window._composeDockerLoadRenderTimer);
                        window._composeDockerLoadRenderTimer = null;
                    }
                    renderComposeLoadCache();
                    return;
                }
                if (window._composeDockerLoadRenderTimer) {
                    return;
                }

                var now = Date.now();
                var elapsed = now - composeLoadLastRenderMs;
                var delay = Math.max(0, composeLoadRenderMinIntervalMs - elapsed);
                window._composeDockerLoadRenderTimer = setTimeout(function() {
                    window._composeDockerLoadRenderTimer = null;
                    renderComposeLoadCache();
                }, delay);
            }

            window.composeDockerLoadRenderCached = renderComposeLoadCache;

            function ingestComposeLoadPayload(msg, now) {
                var data = String(msg || '').split('\n');
                var i = 0;
                var row = data[i];
                while (row) {
                    var parts = row.split(';');
                    if (parts.length >= 3) {
                        var normalizedId = composeNormalizeContainerKey(parts[0]);
                        if (!normalizedId) {
                            i++;
                            row = data[i];
                            continue;
                        }
                        var cpuRaw = parseFloat(parts[1]) || 0;
                        var cpuNorm = Math.round(Math.min(cpuRaw / Math.max(composeCpuCount, 1), 100) * 100) / 100;
                        var memPair = parseMemUsagePair(parts[2]);

                        var memPercent = 0;
                        if (parts.length > 3) {
                            memPercent = parseFloat(parts[3]) || 0;
                        }
                        if (memPercent <= 0 && memPair.limit > 0) {
                            memPercent = (memPair.used / memPair.limit) * 100;
                        }
                        var netInput = null;
                        var netOutput = null;
                        var blockInput = null;
                        var blockOutput = null;

                        if (parts.length > 7) {
                            netInput = sanitizeStatsValue(parts[4]);
                            netOutput = sanitizeStatsValue(parts[5]);
                            blockInput = sanitizeStatsValue(parts[6]);
                            blockOutput = sanitizeStatsValue(parts[7]);
                        } else {
                            if (parts.length > 4) {
                                var netPair = String(parts[4] || '').split('/');
                                netInput = sanitizeStatsValue(netPair[0] || '');
                                netOutput = sanitizeStatsValue(netPair[1] || '');
                            }
                            if (parts.length > 5) {
                                var blockPair = String(parts[5] || '').split('/');
                                blockInput = sanitizeStatsValue(blockPair[0] || '');
                                blockOutput = sanitizeStatsValue(blockPair[1] || '');
                            }
                        }
                        var netInputBytes = parseMemValueToBytes(netInput || '0');
                        var netOutputBytes = parseMemValueToBytes(netOutput || '0');
                        var blockInputBytes = parseMemValueToBytes(blockInput || '0');
                        var blockOutputBytes = parseMemValueToBytes(blockOutput || '0');

                        composeLoadById[normalizedId] = {
                            cpu: cpuNorm,
                            cpuText: formatCpuPercent(cpuNorm),
                            mem: formatMemUsageText(memPair.used, memPair.limit),
                            memUsedBytes: memPair.used,
                            memLimitBytes: memPair.limit,
                            memPercent: memPercent,
                            memPercentText: memPercent > 0 ? memPercent.toFixed(2) + '%' : '-',
                            netInput: netInput || '-',
                            netOutput: netOutput || '-',
                            blockInput: blockInput || '-',
                            blockOutput: blockOutput || '-',
                            netInputBytes: netInputBytes,
                            netOutputBytes: netOutputBytes,
                            blockInputBytes: blockInputBytes,
                            blockOutputBytes: blockOutputBytes,
                            ts: now
                        };
                    }
                    i++;
                    row = data[i];
                }
            }

            window.composeDockerLoadSeedSnapshot = function(raw, ts) {
                if (!raw) return;
                ingestComposeLoadPayload(raw, ts ? ts * 1000 : Date.now());
                if (isComposeLoadVisible()) {
                    scheduleComposeLoadRender(true);
                }
            };

            if (window.composeLoadSnapshotRaw) {
                window.composeDockerLoadSeedSnapshot(window.composeLoadSnapshotRaw, window.composeLoadSnapshotTs);
            }

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
                    var totalNetInBytes = 0;
                    var totalNetOutBytes = 0;
                    var totalBlockInBytes = 0;
                    var totalBlockOutBytes = 0;
                    var matched = 0;
                    idList.forEach(function(ctId) {
                        if (ctId && composeLoadById[ctId]) {
                            totalCpu += composeLoadById[ctId].cpu;
                            totalMemUsedBytes += composeLoadById[ctId].memUsedBytes || 0;
                            totalMemLimitBytes += composeLoadById[ctId].memLimitBytes || 0;
                            totalNetInBytes += composeLoadById[ctId].netInputBytes || 0;
                            totalNetOutBytes += composeLoadById[ctId].netOutputBytes || 0;
                            totalBlockInBytes += composeLoadById[ctId].blockInputBytes || 0;
                            totalBlockOutBytes += composeLoadById[ctId].blockOutputBytes || 0;
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
                        var aggMemPct = stackMemTotalBytes > 0 ? ((totalMemUsedBytes / stackMemTotalBytes) * 100).toFixed(2) + '%' : '-';
                        $('.compose-stack-cpu-' + entry.stackId).removeClass('compose-text-muted').text(aggCpu + ' / 100%');
                        $('#compose-stack-cpu-bar-' + entry.stackId).css('width', Math.min(totalCpu, 100).toFixed(2) + '%');
                        $('.compose-stack-mem-' + entry.stackId).removeClass('compose-text-muted').text(aggMem);
                        $('#compose-stack-mem-bar-' + entry.stackId).css('width', stackMemTotalBytes > 0 ? Math.min((totalMemUsedBytes / stackMemTotalBytes) * 100, 100).toFixed(2) + '%' : '0');
                        $('.compose-stack-netio-' + entry.stackId).removeClass('compose-text-muted').html(formatInOutHtml(formatBytes(totalNetInBytes), formatBytes(totalNetOutBytes), 'in', 'out'));
                        $('.compose-stack-blockio-' + entry.stackId).removeClass('compose-text-muted').html(formatInOutHtml(formatBytes(totalBlockInBytes), formatBytes(totalBlockOutBytes), 'read', 'write'));
                    } else {
                        $('.compose-stack-cpu-' + entry.stackId).addClass('compose-text-muted').text('-');
                        $('#compose-stack-cpu-bar-' + entry.stackId).css('width', '0');
                        $('.compose-stack-mem-' + entry.stackId).addClass('compose-text-muted').text('-');
                        $('#compose-stack-mem-bar-' + entry.stackId).css('width', '0');
                        $('.compose-stack-netio-' + entry.stackId).addClass('compose-text-muted').html('-');
                        $('.compose-stack-blockio-' + entry.stackId).addClass('compose-text-muted').html('-');
                    }
                });
            }

            composeDockerLoad.on('message', function(msg) {
                var now = Date.now();
                ingestComposeLoadPayload(msg, now);

                window.composeLoadSnapshotRaw = msg;
                window.composeLoadSnapshotTs = Math.floor(now / 1000);

                pruneStaleLoadEntries(now);

                // Skip DOM updates when the page isn't visible — the cache
                // stays warm so we can render instantly on return.
                if (!isComposeLoadVisible()) {
                    composeDockerLoadDropped++;
                    return;
                }

                scheduleComposeLoadRender(false);
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
                    scheduleComposeLoadRender(true);
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
                            'loadColumnsVisible': composeHasVisibleLoadColumns()
                        }, 'user', 'debug', 'dockerload');
                        composeStackIndex = null;
                    }
                });
                window._composeDockerLoadPanelObserver.observe($loadTabPanel[0], {
                    attributes: true,
                    attributeFilter: ['style']
                });
            }

            // Auto-start the socket immediately when load columns are visible so the
            // cache can warm while progressive row loading is still in flight.
            if (composeShouldEnableDockerLoad()) {
                composeLogger('auto-starting socket', {
                    'listReady': composeListReady,
                    'loadColumnsVisible': composeHasVisibleLoadColumns()
                }, 'user', 'debug', 'dockerload');
                composeDockerLoad.start();
                composeDockerLoadRunning = true;
            } else {
                composeLogger('deferring socket start', {
                    'listReady': composeListReady,
                    'loadColumnsVisible': composeHasVisibleLoadColumns()
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
                    composeLogger('subscriber initialized on retry #' + composeDockerLoadInitAttempts, null, 'user', 'debug', 'dockerload');
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
            <div class="compose-modal" role="dialog" aria-modal="true" aria-labelledby="compose-stack-modal-title" aria-describedby="compose-stack-modal-desc" tabindex="-1" style="max-width:560px;">
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
                        <div style="font-weight:bold;margin-bottom:8px;">External ENV File Path</div>
                        <input type="text" id="compose-stack-env-path" placeholder="Default (.env in project or indirect folder)" data-pickroot="/" data-picktop="/mnt" data-pickcloseonfile="true">

                        <div style="font-weight:bold;margin:14px 0 8px;">Indirect Path</div>
                        <input type="text" id="compose-stack-indirect" placeholder="/mnt/user/compose/stackFolder" data-pickroot="/" data-picktop="/mnt" data-pickfolders="true" data-pickcloseonfile="true">

                        <div style="font-weight:bold;margin:10px 0 8px;">Indirect Compose File</div>
                        <input type="text" id="compose-stack-indirect-file" placeholder="/mnt/user/compose/stackFolder/custom.compose.yml" data-pickroot="/" data-picktop="/mnt" data-pickcloseonfile="true" data-pickfilter="yml,yaml">

                        <div style="margin-top:14px;font-weight:bold;margin-bottom:8px;">Compose File Selection</div>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                            <input type="checkbox" id="compose-stack-use-default-compose-files">
                            Use Docker Compose default file discovery (no explicit -f flags)
                        </label>
                        <div id="compose-stack-default-compose-files-disabled-note" class="compose-status-warning" style="display:none;margin-top:8px;"></div>

                        <div style="margin-top:14px;font-weight:bold;margin-bottom:8px;">Override File Management</div>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                            <input type="checkbox" id="compose-stack-override-management-automatic" checked>
                            Automatic (disable for Manual/raw override mode)
                        </label>
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

    getConfig().then(function(pluginCfg) {
        var defaultUseDefaultComposeFiles = pluginCfg && pluginCfg.NEW_STACK_USE_DEFAULT_COMPOSE_FILES === 'true';
        var defaultOverrideAutomatic = !(pluginCfg && pluginCfg.NEW_STACK_OVERRIDE_MANAGEMENT_AUTOMATIC === 'false');
        $('#compose-stack-use-default-compose-files').prop('checked', defaultUseDefaultComposeFiles);
        $('#compose-stack-override-management-automatic').prop('checked', defaultOverrideAutomatic);
        updateAddStackDefaultComposeDiscoveryState();
    });

    // The add-stack modal is created dynamically, so attach the picker after insertion.
    if ($.fn.fileTreeAttach) {
        var $indirectInputs = $('#compose-stack-indirect, #compose-stack-indirect-file, #compose-stack-env-path');
        composeBindFileTreeInputs($indirectInputs, {
            zIndex: 100010,
            minWidth: 320,
            addClass: false
        });
    }

    $('#compose-stack-indirect, #compose-stack-indirect-file, #compose-stack-env-path').off('input.defaultComposeDiscovery').on('input.defaultComposeDiscovery', function() {
        updateAddStackDefaultComposeDiscoveryState();
    });

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
        var indirectFile = document.getElementById('compose-stack-indirect-file').value.trim();
        var envPath = document.getElementById('compose-stack-env-path').value.trim();
        var useDefaultComposeFiles = document.getElementById('compose-stack-use-default-compose-files').checked ? 'true' : 'false';
        var overrideManagementAutomatic = document.getElementById('compose-stack-override-management-automatic').checked ? 'true' : 'false';
        var errorDiv = document.getElementById('compose-stack-modal-error');
        if (!name) {
            errorDiv.textContent = "Please enter a stack name.";
            errorDiv.style.display = "block";
            return;
        }
        if (indirect && indirectFile) {
            errorDiv.textContent = "Set either Indirect Path or Indirect Compose File, not both.";
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
                stackPath: indirect,
                stackFilePath: indirectFile,
                envPath: envPath,
                useDefaultComposeFiles: useDefaultComposeFiles,
                overrideManagementAutomatic: overrideManagementAutomatic
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

function getComposeServiceNamesFromContent(content) {
    if (!content || !content.trim()) {
        return [];
    }

    try {
        var doc = loadComposeYaml(content) || {};
        var services = doc.services || {};
        return Object.keys(services);
    } catch (e) {
        return [];
    }
}

function getRemovedComposeServices() {
    var originalContent = editorModal.originalContent['compose'] || '';
    var currentEditor = editorModal.editors['compose'];

    if (!originalContent || !currentEditor) {
        return [];
    }

    var originalServices = getComposeServiceNamesFromContent(originalContent);
    var currentServices = getComposeServiceNamesFromContent(currentEditor.getValue());

    if (originalServices.length === 0 || currentServices.length === 0) {
        return [];
    }

    var currentServiceSet = {};
    currentServices.forEach(function(serviceName) {
        currentServiceSet[serviceName] = true;
    });

    return originalServices.filter(function(serviceName) {
        return !currentServiceSet[serviceName];
    });
}

function isStackRunning(project) {
    var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
    return $stackRow.length > 0 && $stackRow.data('isup') == '1';
}

function buildRemoveOrphansCheckboxHtml(checkboxId) {
    return '<div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--dynamix-box-inner-div-border-color);display:flex;align-items:center;gap:8px;">' +
        '<input type="checkbox" id="' + checkboxId + '" style="width:16px;height:16px;cursor:pointer;">' +
        '<label for="' + checkboxId + '" style="cursor:pointer;user-select:none;margin:0;font-size:0.95em;">Remove orphans</label>' +
        '</div>';
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
function showUpdateWarning(project, stackId, updateAction) {
    var path = compose_root + '/' + project;
    // Use row metadata so profile selection behavior matches context menu actions.
    var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
    var profiles = [];
    var runningProfile = '';
    var defaultProfile = '';
    updateAction = updateAction || 'update';

    if ($stackRow.length > 0) {
        profiles = $stackRow.data('profiles') || [];
        runningProfile = $stackRow.attr('data-running-profile') || '';
        defaultProfile = $stackRow.attr('data-default-profile') || '';
    }

    if (profiles.length > 0) {
        showProfileSelector(updateAction, path, profiles, runningProfile, defaultProfile);
    } else if (updateAction === 'forceUpdate') {
        ForceUpdateStack(path);
    } else {
        UpdateStack(path);
    }
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
    if (Object.prototype.hasOwnProperty.call(payload, 'removeOrphans')) {
        payload.removeOrphans = payload.removeOrphans ? 1 : 0;
    }

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
function ComposeUpConfirmed(path, opts) {
    opts = opts || {};
    confirmedComposeAction(path, {
        actionName: 'up',
        titlePrefix: 'Compose Up',
        requestUrl: compURL,
        payload: {
            action: 'composeUp',
            path: path,
            profile: opts.profile || '',
            removeOrphans: !!opts.removeOrphans
        },
        background: !!opts.background,
        suppressBackgroundNotification: !!opts.suppressBackgroundNotification,
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

function ComposeDownConfirmed(path, opts) {
    opts = opts || {};
    confirmedComposeAction(path, {
        actionName: 'down',
        titlePrefix: 'Compose Down',
        requestUrl: compURL,
        payload: {
            action: 'composeDown',
            path: path,
            profile: opts.profile || '',
            removeOrphans: !!opts.removeOrphans
        },
        background: !!opts.background,
        suppressBackgroundNotification: !!opts.suppressBackgroundNotification,
        pendingReload: true
    });
}

function ComposeDown(path, profile = "") {
    showStackActionDialog('down', path, profile);
}

// Stop stack without removing containers
function ComposeStopConfirmed(path, opts) {
    opts = opts || {};
    confirmedComposeAction(path, {
        actionName: 'stop',
        titlePrefix: 'Compose Stop',
        requestUrl: compURL,
        payload: {
            action: 'composeStop',
            path: path,
            profile: opts.profile || ''
        },
        background: !!opts.background,
        suppressBackgroundNotification: !!opts.suppressBackgroundNotification,
        pendingReload: true
    });
}

function ComposeStop(path, profile = "") {
    showStackActionDialog('stop', path, profile);
}

// Restart stack (recreate containers without pulling)
function ComposeRestartConfirmed(path, opts) {
    opts = opts || {};
    confirmedComposeAction(path, {
        actionName: 'restart',
        titlePrefix: 'Compose Restart',
        requestUrl: compURL,
        payload: {
            action: 'composeUpRecreate',
            path: path,
            profile: opts.profile || ''
        },
        background: !!opts.background,
        suppressBackgroundNotification: !!opts.suppressBackgroundNotification,
        pendingReload: true,
        refreshDelayMs: 1000
    });
}

function ComposeRestart(path, profile = "") {
    showStackActionDialog('restart', path, profile);
}

// Force update stack (pull and rebuild even without detected updates)
function ForceUpdateStackConfirmed(path, opts) {
    opts = opts || {};
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
            profile: opts.profile || ''
        },
        background: !!opts.background,
        suppressBackgroundNotification: !!opts.suppressBackgroundNotification,
        pendingReload: true
    });
}

function ForceUpdateStack(path, profile = "") {
    showStackActionDialog('forceUpdate', path, profile);
}

// Prompt user to recreate containers after label changes
function promptRecreateContainers(closeAfterSave) {
    if (typeof closeAfterSave === 'undefined') {
        closeAfterSave = true;
    }

    var project = editorModal.currentProject;
    if (!project) {
        swal({
            title: "Saved!",
            text: "All changes have been saved.",
            type: "success",
            showConfirmButton: false
        });
        setTimeout(function() { swal.close(); }, 1500);
        return;
    }

    // Find the stack row and check if it's running
    var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
    var isUp = $stackRow.length > 0 && $stackRow.data('isup') == "1";

    if (!isUp) {
        // Stack is not running; optionally close editor and show saved message
        if (closeAfterSave) {
            doCloseEditorModal();
        }
        swal({
            title: "Saved!",
            text: "All changes have been saved. Container labels will take effect when you start the stack.",
            type: "success"
        }, function() {
            refreshStackByProject(project);
        });
        return;
    }

    if (!closeAfterSave) {
        swal({
            title: "Saved!",
            text: "Container labels were saved. Recreate or restart containers to apply the changes.",
            type: "info",
            showConfirmButton: false
        });
        setTimeout(function() {
            swal.close();
            refreshStackByProject(project);
        }, 2000);
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
showConfirmButton: false
        });
        setTimeout(function() {
            swal.close();
            refreshStackByProject(project);
        }, 2000);
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
                }, 'user', 'debug', 'update-check');
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

function UpdateStackConfirmed(path, opts) {
    opts = opts || {};
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
            profile: opts.profile || ''
        },
        background: !!opts.background,
        suppressBackgroundNotification: !!opts.suppressBackgroundNotification,
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
    var removeOrphansHtml = buildRemoveOrphansCheckboxHtml('swal-remove-orphans-startall');

    getConfig().then(function(pluginCfg) {
        var bgDefault = pluginCfg && pluginCfg.RUN_IN_BACKGROUND_DEFAULT === 'true';
        var removeOrphansDefault = pluginCfg && pluginCfg.REMOVE_ORPHANS_DEFAULT === 'true';
        var disableWarnings = pluginCfg && pluginCfg.DISABLE_ACTION_WARNINGS === 'true';

        if (disableWarnings) {
            executeStartAllStacks({
                stacks: stacks,
                background: bgDefault,
                suppressBackgroundNotification: bgDefault,
                removeOrphans: removeOrphansDefault
            });
            return;
        }

        swal({
            title: title,
            html: true,
            text: '<div style="background:var(--alt-background-color);text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be started:</p><div style="background:var(--background-color);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div>' + removeOrphansHtml + bgCheckboxHtml + '</div>',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                var runInBackground = $('#swal-run-bg-startall').is(':checked');
                var removeOrphans = $('#swal-remove-orphans-startall').is(':checked');
                executeStartAllStacks({
                    stacks: stacks,
                    background: runInBackground,
                    suppressBackgroundNotification: false,
                    removeOrphans: removeOrphans
                });
            }
        });

        setTimeout(function() {
            var $cb = $('#swal-run-bg-startall');
            if ($cb.length) $cb.prop('checked', bgDefault);
            var $removeCb = $('#swal-remove-orphans-startall');
            if ($removeCb.length) $removeCb.prop('checked', removeOrphansDefault);
        }, 50);
    });
}

function executeStartAllStacks(opts) {
    opts = opts || {};
    var stacks = opts.stacks || [];
    var background = !!opts.background;
    var suppressBackgroundNotification = !!opts.suppressBackgroundNotification;
    var removeOrphans = !!opts.removeOrphans;
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
        background: background ? 1 : 0,
        removeOrphans: removeOrphans ? 1 : 0
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
    var removeOrphansHtml = buildRemoveOrphansCheckboxHtml('swal-remove-orphans-stopall');

    getConfig().then(function(pluginCfg) {
        var bgDefault = pluginCfg && pluginCfg.RUN_IN_BACKGROUND_DEFAULT === 'true';
        var removeOrphansDefault = pluginCfg && pluginCfg.REMOVE_ORPHANS_DEFAULT === 'true';
        var disableWarnings = pluginCfg && pluginCfg.DISABLE_ACTION_WARNINGS === 'true';

        if (disableWarnings) {
            executeStopAllStacks({
                stacks: stacks,
                background: bgDefault,
                suppressBackgroundNotification: bgDefault,
                removeOrphans: removeOrphansDefault
            });
            return;
        }

        swal({
            title: title,
            html: true,
            text: '<div style="background:var(--alt-background-color);text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be stopped:</p><div style="background:var(--background-color);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div><p class="compose-status-warning" style="margin-top:10px;"><i class="fa fa-exclamation-triangle"></i> Containers will be stopped and removed. Data in volumes will be preserved.</p>' + removeOrphansHtml + bgCheckboxHtml + '</div>',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                var runInBackground = $('#swal-run-bg-stopall').is(':checked');
                var removeOrphans = $('#swal-remove-orphans-stopall').is(':checked');
                executeStopAllStacks({
                    stacks: stacks,
                    background: runInBackground,
                    suppressBackgroundNotification: false,
                    removeOrphans: removeOrphans
                });
            }
        });

        setTimeout(function() {
            var $cb = $('#swal-run-bg-stopall');
            if ($cb.length) $cb.prop('checked', bgDefault);
            var $removeCb = $('#swal-remove-orphans-stopall');
            if ($removeCb.length) $removeCb.prop('checked', removeOrphansDefault);
        }, 50);
    });
}

function executeStopAllStacks(opts) {
    opts = opts || {};
    var stacks = opts.stacks || [];
    var background = !!opts.background;
    var suppressBackgroundNotification = !!opts.suppressBackgroundNotification;
    var removeOrphans = !!opts.removeOrphans;
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
        background: background ? 1 : 0,
        removeOrphans: removeOrphans ? 1 : 0
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
            var sameName = cInfo.name && uInfo.name && cInfo.name === uInfo.name;
            var sameService = cInfo.service && uInfo.service && cInfo.service === uInfo.service;
            if (sameName || sameService) {
                container.hasUpdate = uInfo.hasUpdate;
                container.updateStatus = uInfo.updateStatus;
                container.localSha = uInfo.localSha;
                container.remoteSha = uInfo.remoteSha;
                if ((!container.icon || !isValidIconSrc(container.icon)) && uInfo.icon) {
                    container.icon = uInfo.icon;
                }
            }
        });
    });
    return containers;
}

// Show docker.versions changelog for a single container.
// Delegates entirely to docker.versions' own showChangeLog() so that display
// logic, Nchan subscription management, and message routing stay in one place.
//
// Coupling point: showChangeLog(containerName) — global function from
// docker.versions/scripts/changelog.js.  If that function is renamed or
// removed, this feature silently does nothing.
function showComposeChangelog(containerName, path, profile) {
    if (typeof showChangeLog !== 'function') return;
    showChangeLog(containerName);
    // docker.versions' OK button calls updateContainer(), which bypasses
    // compose_plugin's update mechanism.  Hide it so the only exit is Cancel.
    setTimeout(function() { $('.sweet-alert .confirm').hide(); }, 0);
    if (!path) return;
    // Reopen the Update Stack dialog when the changelog dialog closes.
    // SweetAlert 1.x has no close event; poll the showSweetAlert class instead.
    // Cap at 300 ticks (30 s) so the interval self-cleans if the dialog never opens.
    var appeared = false;
    var ticks = 0;
    var poll = setInterval(function() {
        if (++ticks > 300) { clearInterval(poll); return; }
        var open = $('.sweet-alert').hasClass('showSweetAlert');
        if (!appeared) { if (open) appeared = true; return; }
        if (!open) {
            clearInterval(poll);
            // Skip reopening when DISABLE_ACTION_WARNINGS is true: renderStackActionDialog
            // has a fast-path that calls UpdateStackConfirmed directly in that mode,
            // so reopening would trigger an immediate update rather than a dialog.
            getConfig().then(function(cfg) {
                if (cfg && cfg.DISABLE_ACTION_WARNINGS === 'true') return;
                showStackActionDialog('update', path, profile || '');
            });
        }
    }, 100);
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

        var detectedMismatch = cachedContainers.length !== profileServices.length;

        composeLogger('profile/unified AJAX done', {
            profileServices,
            containers,
            cachedContainers,
            detectedMismatch
        }, 'user', 'debug', 'showStackActionDialog');
        containers = mergeUpdateStatus(containers, project);
        renderStackActionDialog(action, displayName, path, profile, containers, hasBuild, detectedMismatch);
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
        renderStackActionDialog(action, displayName, path, profile, containers, hasBuild, cachedContainers.length > 0);
    });
    return;
}

function renderStackActionDialog(action, displayName, path, profile, containers, hasBuild, showRemoveOrphans) {
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
            showRemoveOrphans: true,
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
            showRemoveOrphans: true,
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
                    if (dockerVersionsInstalled) {
                        html += '<div style="margin-top:4px;"><a href="#" data-changelog-container="' + composeEscapeAttr(containerName) + '" data-changelog-path="' + composeEscapeAttr(path) + '" data-changelog-profile="' + composeEscapeAttr(profile || '') + '" onclick="showComposeChangelog(this.dataset.changelogContainer,this.dataset.changelogPath,this.dataset.changelogProfile);return false;" style="font-size:0.85em;"><i class="fa fa-list" style="margin-right:3px;"></i>Changelog</a></div>';
                    }
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

    var removeOrphansDefault = false;

    if (cfg.showRemoveOrphans) {
        html += '<div id="swal-remove-orphans-wrap" style="display:none;">' +
            buildRemoveOrphansCheckboxHtml('swal-remove-orphans-checkbox') +
            '<div class="compose-text-muted" style="margin-top:6px;font-size:0.85em;margin-left:24px;">Adds --remove-orphans to the compose command.</div>' +
            '</div>';
    }

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
        removeOrphansDefault = pluginCfg && pluginCfg.REMOVE_ORPHANS_DEFAULT === 'true';
        var disableWarnings = pluginCfg && pluginCfg.DISABLE_ACTION_WARNINGS === 'true';

        if (disableWarnings) {
            // In default background mode (warnings disabled and background enabled), don't show toast if background is used
            cfg.confirmedFn(path, {
                profile: profile,
                background: bgDefault,
                suppressBackgroundNotification: bgDefault,
                removeOrphans: removeOrphansDefault
            });
            return;
        }

        var removeOrphansChecked = removeOrphansDefault || showRemoveOrphans;
        var showRemoveOrphansOption = cfg.showRemoveOrphans && (removeOrphansChecked || showRemoveOrphans);

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
                var removeOrphans = $('#swal-remove-orphans-checkbox').is(':checked');
                // when running in background, suppress the extra notifyBackgroundStarted popup
                cfg.confirmedFn(path, {
                    profile: profile,
                    background: runInBackground,
                    suppressBackgroundNotification: runInBackground,
                    removeOrphans: removeOrphans
                });
            }
        });

        // Set checkbox default state after swal renders (small delay for DOM)
        setTimeout(function() {
            var $cb = $('#swal-run-bg-checkbox');
            if ($cb.length) {
                $cb.prop('checked', bgDefault);
            }
            var $orphanCb = $('#swal-remove-orphans-checkbox');
            if ($orphanCb.length) {
                $orphanCb.prop('checked', removeOrphansChecked);
                $('#swal-remove-orphans-wrap').toggle(showRemoveOrphansOption);
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

function ComposePullConfirmed(path, opts) {
    opts = opts || {};
    confirmedComposeAction(path, {
        actionName: 'pull',
        titlePrefix: 'Compose Pull',
        requestUrl: compURL,
        payload: {
            action: 'composePull',
            path: path,
            profile: opts.profile || ''
        },
        background: !!opts.background,
        suppressBackgroundNotification: !!opts.suppressBackgroundNotification,
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
var stackDetailsLoadPromises = {};
var stackDetailsPrefetchPromises = {};
var stackDetailsPrefetchCache = {};
// Suppress immediate refresh after a render to avoid loops
var stackDetailsJustRendered = {};
// Track user intent during async loads so late responses do not reopen collapsed rows.
var stackDetailsDesiredExpanded = {};
// Batch/safeguard toggle-all updates under rapid clicking.
var stackToggleAllBatching = false;
var stackToggleAllBusy = false;
var stackToggleAllQueuedForceExpand = null;
var stackToggleAllQueuedToggle = false;

function prefetchStackDetailsInBackground(projects) {
    if (!Array.isArray(projects) || projects.length === 0) return;

    var maxParallel = 4;
    var next = 0;
    var active = 0;

    function schedule() {
        while (active < maxParallel && next < projects.length) {
            (function(project) {
                next++;

                if (!project || stackDetailsPrefetchPromises[project] || stackDetailsPrefetchCache[project]) {
                    schedule();
                    return;
                }

                active++;
                stackDetailsPrefetchPromises[project] = new Promise(function(resolve) {
                    $.post(caURL, {
                        action: 'getStackContainers',
                        script: project
                    }, function(data) {
                        var parsed = tryParseJson(data);
                        if (parsed && parsed.result === 'success') {
                            stackDetailsPrefetchCache[project] = parsed;
                            // If the row already exists, bubble prefetched summary
                            // data into parent columns during initial progressive load.
                            applyStackSummaryFromResponse(project, parsed);
                        }
                        resolve();
                    }).fail(function() {
                        resolve();
                    }).always(function() {
                        active--;
                        delete stackDetailsPrefetchPromises[project];
                        schedule();
                    });
                });
            })(projects[next]);
        }
    }

    schedule();
}

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
    var runningProfile = $row.data('running-profile') || '';
    var defaultProfile = $row.data('default-profile') || '';
    var isUp = $row.data('isup') == "1";

    closeStackActionsMenu();

    // Handle profile selection if profiles exist and action supports it
    var profileSupportedActions = ['up', 'down', 'update', 'pull', 'logs'];
    if (profiles.length > 0 && profileSupportedActions.includes(action)) {
        showProfileSelector(action, path, profiles, runningProfile, defaultProfile);
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

function showProfileSelector(action, path, profiles, runningProfile, defaultProfile) {
    if (typeof runningProfile === 'undefined') {
        runningProfile = '';
    }
    if (typeof defaultProfile === 'undefined') {
        defaultProfile = '';
    }
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
    
    function parseProfileList(rawValue) {
        if (!rawValue) return [];
        return rawValue.split(',').map(function(p) {
            return p.trim();
        }).filter(function(p) {
            return p !== '';
        });
    }

    // Selection priority: running profiles -> default profiles -> all (*)
    var runningProfileSet = parseProfileList(runningProfile);
    var defaultProfileSet = parseProfileList(defaultProfile);
    var preselectedProfiles = [];
    var showAllProfilesChecked = false;

    if (runningProfileSet.indexOf('*') !== -1) {
        showAllProfilesChecked = true;
    } else if (runningProfileSet.length > 0) {
        preselectedProfiles = runningProfileSet;
    } else if (defaultProfileSet.indexOf('*') !== -1) {
        showAllProfilesChecked = true;
    } else if (defaultProfileSet.length > 0) {
        preselectedProfiles = defaultProfileSet;
    } else {
        showAllProfilesChecked = true;
    }
    var profileCheckboxDisabled = showAllProfilesChecked ? 'disabled' : '';
    
    profileHtml += '<div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--dynamix-box-inner-div-border-color);">';
    profileHtml += '<label style="font-weight:bold;"><input type="checkbox" id="profile_all_profiles" ' + (showAllProfilesChecked ? 'checked' : '') + ' onchange="toggleAllProfiles(this)"> All profile-based services (*)</label>';
    profileHtml += '</div>';
    profileHtml += '<div id="profile_list">';
    profiles.forEach(function(profile) {
        var isChecked = !showAllProfilesChecked && preselectedProfiles.indexOf(profile) >= 0;
        profileHtml += '<label style="display:block;margin:5px 0;"><input type="checkbox" class="profile_checkbox" value="' + composeEscapeHtml(profile) + '" ' + profileCheckboxDisabled + ' ' + (isChecked ? 'checked' : '') + ' onchange="updateProfileCheckboxes()"> ' + composeEscapeHtml(profile) + '</label>';
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

function updateProfileCheckboxes() {
    // If any individual profile is checked, uncheck "all profiles"
    var anyIndividualChecked = $('.profile_checkbox:checked').length > 0;
    if (anyIndividualChecked) {
        $('#profile_all_profiles').prop('checked', false);
    }
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
    editorModal.currentProjectName = projectName || project;
    editorModal.modifiedTabs = new Set();
    editorModal.modifiedSettings = new Set();
    editorModal.modifiedLabels = new Set();
    editorModal.originalContent = {};
    editorModal.originalSettings = {};
    editorModal.originalLabels = {};
    editorModal.labelsData = null;
    editorModal.labelsViewMode = 'basic';
    editorModal.pendingLabelsViewMode = 'basic';
    editorModal.filePaths = {
        stackMeta: compose_root + '/' + project,
        compose: '',
        env: '',
        projectOverride: compose_root + '/' + project + '/compose.override.yaml',
        effectiveOverride: ''
    };
    $('#settings-override-management').prop('checked', true);
    $('#settings-override-management-label').text('Automatic');

    // Reset all tabs to unmodified state
    $('.editor-tab').removeClass('modified active');
    $('.editor-main-tab').removeClass('modified active');
    $('.editor-container').removeClass('active');
    $('.editor-panel').removeClass('active');

    // Reset labels tab to basic immediately; stack-specific mode arrives from getStackSettings.
    toggleLabelsViewMode(false, true);
    if (editorModal.editors['override']) {
        editorModal.editors['override'].setValue('', -1);
    }
    $('#labels-override-empty-state').hide();
    $('#labels-override-editor-wrap').show();
    $('#editor-validation-override').html('<i class="fa fa-check editor-validation-icon"></i> Ready').removeClass('valid error warning');
    $('#env-empty-state').hide();
    $('#env-editor-wrap').show();

    // Set modal title
    $('#editor-modal-title').text('Editing: ' + projectName);
    updateEditorFileInfo();

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
                editorModal.filePaths.compose = response.fileName || '';
                editorModal.originalContent['compose'] = response.content || '';
                if (editorModal.editors['compose']) editorModal.editors['compose'].setValue(response.content || '', -1);
                updateEditorFileInfo();
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
                editorModal.filePaths.env = response.fileName || '';
                editorModal.envExists = response.exists === true;
                if (editorModal.envExists) {
                    $('#env-empty-state').hide();
                    $('#env-editor-wrap').show();
                    editorModal.originalContent['env'] = response.content || '';
                    if (editorModal.editors['env']) editorModal.editors['env'].setValue(response.content || '', -1);
                } else {
                    $('#env-empty-state').css('display', 'flex');
                    $('#env-editor-wrap').hide();
                    editorModal.originalContent['env'] = '';
                    if (editorModal.editors['env']) editorModal.editors['env'].setValue('', -1);
                }
                updateEditorFileInfo();
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

// ---- Compose file switcher (main + additional compose files) ----

// Populate the Compose tab file selector; hidden unless the stack has
// more than one editable compose file.
function populateComposeFileSelector(files) {
    var $wrap = $('#compose-file-selector-wrap');
    var $sel = $('#compose-file-selector');
    editorModal.composeFiles = files || [];

    $sel.empty();
    if (!files || files.length < 2) {
        $wrap.hide();
        return;
    }
    files.forEach(function(path) {
        var base = path.split('/').pop();
        $sel.append($('<option>').val(path).text(base).attr('title', path));
    });
    $sel.val(editorModal.filePaths.compose || files[0]);
    $wrap.css('display', 'flex');
}

// Switch the Compose tab editor to another file of the stack.
function switchComposeFile(path) {
    if (!path || path === editorModal.filePaths.compose) return;

    var doSwitch = function() {
        $.post(caURL, {
            action: 'getYml',
            script: editorModal.currentProject,
            file: path
        }).then(function(data) {
            if (!data) return;
            var response = jQuery.parseJSON(data);
            if (response.result !== 'success') {
                swal({ type: 'error', title: 'Load Failed', text: response.message || 'Unable to load file.' });
                $('#compose-file-selector').val(editorModal.filePaths.compose);
                return;
            }
            editorModal.filePaths.compose = response.fileName || path;
            editorModal.originalContent['compose'] = response.content || '';
            if (editorModal.editors['compose']) editorModal.editors['compose'].setValue(response.content || '', -1);
            editorModal.modifiedTabs.delete('compose');
            updateSaveButtonState();
            updateTabModifiedState();
            updateEditorFileInfo();
            validateYaml('compose', response.content || '');
        }).fail(function() {
            swal({ type: 'error', title: 'Load Failed', text: 'Unable to load file (network error).' });
            $('#compose-file-selector').val(editorModal.filePaths.compose);
        });
    };

    if (editorModal.modifiedTabs.has('compose')) {
        swal({
            title: 'Discard changes?',
            text: 'The current compose file has unsaved changes that will be lost when switching files.',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Discard',
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                doSwitch();
            } else {
                $('#compose-file-selector').val(editorModal.filePaths.compose);
            }
        });
    } else {
        doSwitch();
    }
}

// Load settings data into the settings panel
function loadSettingsData(project, projectName) {
    // Set the name from projectName (display name)
    $('#settings-name').val(projectName || '');
    editorModal.originalSettings['name'] = projectName || '';
    editorModal.currentProjectName = projectName || project;

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

                // Additional compose files
                renderExtraComposeFiles(response.composeFileCandidates || [], response.extraComposeFiles || '');
                // Store the normalized (comment-free) value so change tracking compares like-for-like
                editorModal.originalSettings['extra-compose-files'] = getExtraComposeFilesValue();

                // Compose file selector (main + additional files)
                populateComposeFileSelector(response.editableComposeFiles || []);

                // External compose path
                var externalComposePath = response.externalComposePath || '';
                var externalComposeFilePath = response.externalComposeFilePath || '';
                var invalidIndirectPath = response.invalidIndirectPath || '';
                var indirectMode = response.indirectMode || '';
                if (!externalComposePath && !externalComposeFilePath && invalidIndirectPath) {
                    composeLogger('settings-invalid-indirect-warning-show', {
                        project: project,
                        invalidIndirectPath: invalidIndirectPath,
                        indirectMode: indirectMode,
                        externalComposePath: externalComposePath,
                        externalComposeFilePath: externalComposeFilePath
                    }, 'user', 'debug', 'stack-settings');
                    // Pre-populate with the broken path so the user can fix it
                    if (indirectMode === 'file') {
                        $('#settings-external-compose-path').val('');
                        $('#settings-external-compose-file').val(invalidIndirectPath);
                        editorModal.originalSettings['external-compose-path'] = '';
                        editorModal.originalSettings['external-compose-file'] = '';
                        editorModal.modifiedSettings.add('external-compose-file');
                    } else {
                        $('#settings-external-compose-path').val(invalidIndirectPath);
                        $('#settings-external-compose-file').val('');
                        editorModal.originalSettings['external-compose-path'] = '';
                        editorModal.originalSettings['external-compose-file'] = '';
                        editorModal.modifiedSettings.add('external-compose-path');
                    }
                    $('#settings-invalid-indirect-warning').show();
                    $('#settings-external-compose-info').hide();
                } else {
                    if ($('#settings-invalid-indirect-warning').is(':visible')) {
                        composeLogger('settings-invalid-indirect-warning-hide', {
                            project: project,
                            invalidIndirectPath: invalidIndirectPath,
                            externalComposePath: externalComposePath,
                            externalComposeFilePath: externalComposeFilePath
                        }, 'user', 'debug', 'stack-settings');
                    }
                    $('#settings-external-compose-path').val(externalComposePath);
                    $('#settings-external-compose-file').val(externalComposeFilePath);
                    editorModal.originalSettings['external-compose-path'] = externalComposePath;
                    editorModal.originalSettings['external-compose-file'] = externalComposeFilePath;
                    $('#settings-invalid-indirect-warning').hide();
                    if (externalComposePath || externalComposeFilePath) {
                        $('#settings-external-compose-info').show();
                    } else {
                        $('#settings-external-compose-info').hide();
                    }
                }

                // Default profile
                var defaultProfile = response.defaultProfile || '';
                $('#settings-default-profile').val(defaultProfile);
                editorModal.originalSettings['default-profile'] = defaultProfile;

                // Compose file discovery mode
                var useDefaultComposeFiles = response.useDefaultComposeFiles === true;
                $('#settings-use-default-compose-files').prop('checked', useDefaultComposeFiles);
                editorModal.originalSettings['use-default-compose-files'] = useDefaultComposeFiles ? 'true' : 'false';
                updateSettingsDefaultComposeDiscoveryState(true);

                editorModal.filePaths.stackMeta = response.projectPath || (compose_root + '/' + project);
                editorModal.filePaths.projectOverride = response.projectOverridePath || (compose_root + '/' + project + '/compose.override.yaml');
                editorModal.filePaths.effectiveOverride = response.effectiveOverridePath || editorModal.filePaths.projectOverride;
                updateEditorFileInfo();

                // Labels editor mode (per-stack)
                var labelsViewMode = response.labelsViewMode === 'advanced' ? 'advanced' : 'basic';
                editorModal.labelsViewMode = labelsViewMode;
                editorModal.pendingLabelsViewMode = labelsViewMode;
                editorModal.originalSettings['labels-view-mode'] = labelsViewMode;
                $('#editor-tab-labels-text').text(labelsViewMode === 'advanced' ? 'Override' : 'Labels');

                // Always sync settings control state, even when Labels tab is not active.
                $('#settings-override-management').prop('checked', labelsViewMode !== 'advanced');
                $('#settings-override-management-label').text(labelsViewMode === 'advanced' ? 'Manual' : 'Automatic');

                if (editorModal.currentProject === project && editorModal.currentTab === 'labels') {
                    toggleLabelsViewMode(labelsViewMode === 'advanced', true);
                }

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
        resetExtraComposeFilesUI();
        $('#settings-default-profile').val('');
        $('#settings-external-compose-path').val('');
        $('#settings-external-compose-file').val('');
        $('#settings-use-default-compose-files').prop('checked', false);
        updateSettingsDefaultComposeDiscoveryState(true);
        editorModal.originalSettings['icon-url'] = '';
        editorModal.originalSettings['webui-url'] = '';
        editorModal.originalSettings['env-path'] = '';
        editorModal.originalSettings['extra-compose-files'] = '';
        editorModal.originalSettings['default-profile'] = '';
        editorModal.originalSettings['external-compose-path'] = '';
        editorModal.originalSettings['external-compose-file'] = '';
        editorModal.originalSettings['use-default-compose-files'] = 'false';
        editorModal.originalSettings['labels-view-mode'] = 'basic';
        editorModal.labelsViewMode = 'basic';
        editorModal.pendingLabelsViewMode = 'basic';
        editorModal.filePaths.stackMeta = compose_root + '/' + project;
        editorModal.filePaths.projectOverride = compose_root + '/' + project + '/compose.override.yaml';
        editorModal.filePaths.effectiveOverride = editorModal.filePaths.projectOverride;
        $('#editor-tab-labels-text').text('Labels');
        $('#settings-override-management').prop('checked', true);
        $('#settings-override-management-label').text('Automatic');
        $('#settings-icon-preview').hide();
        $('#settings-available-profiles').hide();
        updateEditorFileInfo();
        $('#settings-external-compose-info').hide();
        $('#settings-invalid-indirect-warning').hide();
    });
}

// Toggle labels tab between basic (form) and advanced (override editor) modes
function toggleLabelsViewMode(isAdvanced, skipPersist) {
    editorModal.labelsViewMode = isAdvanced ? 'advanced' : 'basic';
    updateEditorFileInfo();

    if (isAdvanced) {
        $('#labels-basic-view').hide();
        $('#labels-advanced-view').show();
        $('#editor-tab-labels-text').text('Override');

        // Pre-populate override editor if labelsData is available (loaded via loadLabelsData)
        // If not yet loaded, load it now
        if (!editorModal.labelsData) {
            loadLabelsData(function() {
                _populateOverrideEditor();
            });
        } else {
            _populateOverrideEditor();
        }
    } else {
        $('#labels-advanced-view').hide();
        $('#labels-basic-view').show();
        $('#editor-tab-labels-text').text('Labels');

        // If the override editor has unsaved changes, warn before switching back
        // (already tracked in modifiedTabs so save button will prompt)
    }
}

// Populate the override Ace editor with the current override file content
function _populateOverrideEditor() {
    if (!editorModal.editors['override']) return;
    var hasOverride = !!(editorModal.labelsData && editorModal.labelsData.overrideExists);
    var content = (editorModal.labelsData && editorModal.labelsData.overrideContent) || '';

    if (!hasOverride) {
        $('#labels-override-empty-state').css('display', 'flex');
        $('#labels-override-editor-wrap').hide();
        if (!editorModal.modifiedTabs.has('override')) {
            editorModal.originalContent['override'] = '';
            editorModal.editors['override'].setValue('', -1);
        }
        return;
    }

    $('#labels-override-empty-state').hide();
    $('#labels-override-editor-wrap').show();

    // Hydrate editor from override file unless there are unsaved override edits.
    if (!editorModal.modifiedTabs.has('override')) {
        editorModal.originalContent['override'] = content;
        editorModal.editors['override'].setValue(content, -1);
    }
    setTimeout(function() {
        try {
            editorModal.editors['override'].resize();
            editorModal.editors['override'].renderer.updateFull();
            editorModal.editors['override'].focus();
        } catch(e) {}
    }, 50);
}

// Load labels data for the WebUI Labels panel
function loadLabelsData(callback) {
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
                overrideHasCustomTags: composeYamlContainsCustomTags(overrideData.content || ''),
                overrideExists: overrideData.exists === true
            };
            editorModal.filePaths.compose = composeData.fileName || editorModal.filePaths.compose;
            editorModal.filePaths.effectiveOverride = overrideData.fileName || editorModal.filePaths.effectiveOverride;
            updateEditorFileInfo();

            // Pre-cache override content for the override editor (only if not yet set for this project)
            if (editorModal.originalContent['override'] === undefined) {
                editorModal.originalContent['override'] = overrideData.content || '';
            }

            renderLabelsUI(mainDoc, overrideDoc);

            if (typeof callback === 'function') {
                callback();
            }

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

function createOverrideTemplate() {
    var project = editorModal.currentProject;
    if (!project) return;

    if (enforcePathSettingsExclusivity('creating override templates')) {
        return;
    }

    $('#labels-override-empty-state button').prop('disabled', true);

    $.post(caURL, {
        action: 'createOverrideTemplate',
        script: project,
        expectedPath: editorModal.filePaths.effectiveOverride || editorModal.filePaths.projectOverride || ''
    }).done(function(data) {
        try {
            if (!data) {
                throw new Error('Empty server response');
            }

            var response = JSON.parse(data);
            if (response.result !== 'success') {
                if (handleStaleEditorResponse(response, 'Override file location changed while this dialog was open. Reloading the editor.')) {
                    return;
                }
                throw new Error(response.message || 'Failed to create override template.');
            }

            editorModal.labelsData = editorModal.labelsData || {};
            editorModal.labelsData.overrideExists = true;
            editorModal.labelsData.overrideContent = response.content || '';
            editorModal.filePaths.effectiveOverride = response.fileName || editorModal.filePaths.effectiveOverride;
            editorModal.originalContent['override'] = response.content || '';
            editorModal.modifiedTabs.delete('override');

            if (editorModal.editors['override']) {
                editorModal.editors['override'].setValue(response.content || '', -1);
            }

            _populateOverrideEditor();
            updateEditorFileInfo();
            validateYaml('override', response.content || '');
            updateTabModifiedState();
            updateSaveButtonState();
        } catch (error) {
            swal({
                title: 'Create Failed',
                text: error && error.message ? error.message : 'Failed to create override template.',
                type: 'error'
            });
        }
    }).fail(function() {
        swal({
            title: 'Create Failed',
            text: 'Failed to create override template.',
            type: 'error'
        });
    }).always(function() {
        $('#labels-override-empty-state button').prop('disabled', false);
    });
}

function createEnvTemplate() {
    var project = editorModal.currentProject;
    if (!project) return;

    if (enforcePathSettingsExclusivity('creating .env templates')) {
        return;
    }

    $('#env-empty-state button').prop('disabled', true);

    $.post(caURL, {
        action: 'createEnvTemplate',
        script: project,
        expectedPath: editorModal.filePaths.env || ''
    }).done(function(data) {
        try {
            if (!data) throw new Error('Empty server response');
            var response = JSON.parse(data);
            if (response.result !== 'success') {
                if (handleStaleEditorResponse(response, '.env file location changed while this dialog was open. Reloading the editor.')) {
                    return;
                }
                throw new Error(response.message || 'Failed to create .env template.');
            }

            editorModal.envExists = true;
            editorModal.filePaths.env = response.fileName || editorModal.filePaths.env;
            editorModal.originalContent['env'] = response.content || '';
            editorModal.modifiedTabs.delete('env');

            if (editorModal.editors['env']) {
                editorModal.editors['env'].setValue(response.content || '', -1);
            }

            $('#env-empty-state').hide();
            $('#env-editor-wrap').show();
            updateEditorFileInfo();
            updateTabModifiedState();
            updateSaveButtonState();
        } catch (error) {
            swal({
                title: 'Create Failed',
                text: error && error.message ? error.message : 'Failed to create .env template.',
                type: 'error'
            });
        }
    }).fail(function() {
        swal({
            title: 'Create Failed',
            text: 'Failed to create .env template.',
            type: 'error'
        });
    }).always(function() {
        $('#env-empty-state button').prop('disabled', false);
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
            overrideDoc.services[serviceKey].labels[managed_label] = managed_label_name;
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
                if (iconUrl && isValidIconSrc(iconUrl)) {
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
    $('#editor-btn-apply').prop('disabled', !hasChanges);
    $('#editor-change-count').text(totalChanges + (totalChanges === 1 ? ' change' : ' changes'));
}

function hasPathSensitiveSettingsChanges() {
    return editorModal.modifiedSettings.has('env-path') ||
        editorModal.modifiedSettings.has('external-compose-path') ||
        editorModal.modifiedSettings.has('external-compose-file') ||
        editorModal.modifiedSettings.has('extra-compose-files') ||
        editorModal.modifiedSettings.has('use-default-compose-files');
}

function hasPathDependentEdits() {
    return editorModal.modifiedTabs.has('compose') ||
        editorModal.modifiedTabs.has('env') ||
        editorModal.modifiedTabs.has('override') ||
        editorModal.modifiedLabels.size > 0;
}

function enforcePathSettingsExclusivity(actionLabel) {
    if (!hasPathSensitiveSettingsChanges()) {
        return false;
    }

    swal({
        title: 'Save Settings First',
        text: 'Path/discovery settings changed. Save settings and reload the editor before ' + actionLabel + '.',
        type: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Save Settings & Reload',
        cancelButtonText: 'Cancel'
    }, function(confirmed) {
        if (confirmed) {
            saveSettingsWithOptionalReload(false);
        }
    });

    return true;
}

function getExpectedSavePathForTab(tabName) {
    switch (tabName) {
        case 'compose':
            return editorModal.filePaths.compose || '';
        case 'env':
            return editorModal.filePaths.env || '';
        case 'override':
            if (editorModal.labelsViewMode === 'basic') {
                return editorModal.filePaths.projectOverride || '';
            }
            return editorModal.filePaths.effectiveOverride || editorModal.filePaths.projectOverride || '';
        default:
            return '';
    }
}

function reloadEditorModal(targetTab, message) {
    var project = editorModal.currentProject;
    var projectName = editorModal.currentProjectName || $('#settings-name').val().trim() || project;
    var nextTab = targetTab || editorModal.currentTab || 'compose';

    if (!project) {
        return;
    }

    doCloseEditorModal();
    openEditorModalByProject(project, projectName, nextTab);

    if (message) {
        swal({
            title: 'Editor Reloaded',
            text: message,
            type: 'info',
            timer: 1800,
            showConfirmButton: false
        });
    }
}

function handleStaleEditorResponse(response, message) {
    if (!response || response.errorCode !== 'stale_path') {
        return false;
    }

    reloadEditorModal(editorModal.currentTab, message || response.message || 'Stack paths changed while this dialog was open.');
    return true;
}

function saveSettingsWithOptionalReload(closeAfterSave) {
    var saveErrors = [];
    var project = editorModal.currentProject;
    var reloadTab = editorModal.currentTab || 'settings';

    return saveSettings(saveErrors).then(function(result) {
        if (result === true) {
            if (closeAfterSave) {
                doCloseEditorModal();
            } else {
                reloadEditorModal(reloadTab, 'Stack paths changed. Files were reloaded from the current target.');
            }
            if (project) {
                setTimeout(function() {
                    refreshStackByProject(project);
                }, 100);
            }
            return true;
        }

        var errorText = saveErrors.length > 0 ? saveErrors.join('\n') : 'Failed to save stack settings.';
        swal({
            title: 'Save Failed',
            text: errorText,
            type: 'error'
        });
        return false;
    }).fail(function() {
        swal({
            title: 'Save Failed',
            text: 'Failed to save stack settings.',
            type: 'error'
        });
        return false;
    });
}

function handleOkayAction() {
    var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
    if (totalChanges > 0) {
        saveAllChanges(true);
    } else {
        doCloseEditorModal();
    }
}

function saveCurrentTab() {
    var currentTab = editorModal.currentTab;
    if (!currentTab) return;
    var saveErrors = [];

    if (currentTab !== 'settings' && enforcePathSettingsExclusivity('saving files')) {
        return;
    }

    // Handle override editor save (labels tab in advanced mode)
    if (currentTab === 'labels' && editorModal.labelsViewMode === 'advanced') {
        if (!editorModal.modifiedTabs.has('override')) return;
        saveTab('override', saveErrors).then(function(result) {
            if (result === true) {
                $('#editor-validation-override').html('<i class="fa fa-check editor-validation-icon"></i> Saved!').removeClass('error warning').addClass('valid');
                setTimeout(function() {
                    if (editorModal.editors['override']) validateYaml('override', editorModal.editors['override'].getValue());
                }, 1500);
            } else if (saveErrors.indexOf('__STALE_PATH__') !== -1) {
                return;
            } else {
                $('#editor-validation-override').html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Save failed').removeClass('valid warning').addClass('error');
            }
        }).catch(function() {
            if (saveErrors.indexOf('__STALE_PATH__') !== -1) {
                return;
            }
            $('#editor-validation-override').html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Save failed').removeClass('valid warning').addClass('error');
        });
        return;
    }

    // Only save editor tabs
    if (currentTab !== 'compose' && currentTab !== 'env') return;
    if (!editorModal.modifiedTabs.has(currentTab)) return;

    saveTab(currentTab, saveErrors).then(function(result) {
        if (result === true) {
            // Brief feedback in validation panel
            $('#editor-validation-' + currentTab).html('<i class="fa fa-check editor-validation-icon"></i> Saved!').removeClass('error warning').addClass('valid');
            setTimeout(function() {
                if (editorModal.editors[currentTab]) validateYaml(currentTab, editorModal.editors[currentTab].getValue());
            }, 1500);
        } else if (saveErrors.indexOf('__STALE_PATH__') !== -1) {
            return;
        } else {
            $('#editor-validation-' + currentTab).html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Save failed').removeClass('valid warning').addClass('error');
        }
    }).catch(function() {
        if (saveErrors.indexOf('__STALE_PATH__') !== -1) {
            return;
        }
        $('#editor-validation-' + currentTab).html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Save failed').removeClass('valid warning').addClass('error');
    });
}

function parseSaveResponse(data, saveErrors, saveTarget) {
    if (!data) {
        if (saveErrors) saveErrors.push('Failed to save ' + saveTarget + '. Empty server response.');
        return false;
    }

    var response = data;
    if (typeof response === 'string') {
        var trimmed = response.trim();
        if (trimmed.charAt(0) === '{') {
            try {
                response = JSON.parse(trimmed);
            } catch (e) {
                if (saveErrors) saveErrors.push('Failed to save ' + saveTarget + '. Invalid server response.');
                return false;
            }
        } else {
            return true;
        }
    }

    if (typeof response === 'object' && response !== null && response.result) {
        if (response.result === 'success') {
            return true;
        }
        if (handleStaleEditorResponse(response, 'The save target changed while this dialog was open. Reloading the editor.')) {
            if (saveErrors) saveErrors.push('__STALE_PATH__');
            return false;
        }
        if (saveErrors) saveErrors.push(response.message || ('Failed to save ' + saveTarget + '.'));
        return false;
    }

    return true;
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
        case 'override':
            actionStr = 'saveOverride';
            break;
        default:
            return Promise.reject('Unknown tab');
    }

    var savePayload = {
        action: actionStr,
        script: project,
        scriptContents: content,
        expectedPath: getExpectedSavePathForTab(tabName)
    };
    if (tabName === 'override') {
        savePayload.managed = editorModal.labelsViewMode === 'basic' ? 1 : 0;
    }
    if (tabName === 'compose' && editorModal.filePaths.compose) {
        // Save to the currently selected compose file (main or additional)
        savePayload.file = editorModal.filePaths.compose;
    }

    return $.post(caURL, savePayload).then(function(data) {
        var saveTarget = tabName === 'override' ? 'override file' : (tabName + ' file');
        if (!parseSaveResponse(data, saveErrors, saveTarget)) {
            return false;
        }

        editorModal.originalContent[tabName] = content;
        editorModal.modifiedTabs.delete(tabName);
        // For override, clear 'modified' on the labels tab indicator
        if (tabName === 'override') {
            updateTabModifiedState();
        } else {
            $('#editor-tab-' + tabName).removeClass('modified');
        }
        updateSaveButtonState();
        updateTabModifiedState();

        // Regenerate profiles if compose file was saved
        if (tabName === 'compose') {
            generateProfiles(null, project);
        }

        return true;
    }).fail(function() {
        if (saveErrors) saveErrors.push('Failed to save ' + tabName + ' file.');
        return false;
    });
}

function saveSettings(saveErrors) {
    var project = editorModal.currentProject;
    var savePromises = [];

    // Save name if modified
    if (editorModal.modifiedSettings.has('name')) {
        var newName = $('#settings-name').val();
        savePromises.push(
            $.post(caURL, {
                action: 'changeName',
                script: project,
                newName: newName
            }).then(function() {
                editorModal.currentProjectName = newName || project;
                editorModal.originalSettings['name'] = newName;
                editorModal.modifiedSettings.delete('name');
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

    // Save icon URL, webui URL, env path, default profile, and external compose settings if any are modified
    if (editorModal.modifiedSettings.has('icon-url') || editorModal.modifiedSettings.has('webui-url') || editorModal.modifiedSettings.has('env-path') || editorModal.modifiedSettings.has('extra-compose-files') || editorModal.modifiedSettings.has('default-profile') || editorModal.modifiedSettings.has('external-compose-path') || editorModal.modifiedSettings.has('external-compose-file') || editorModal.modifiedSettings.has('use-default-compose-files')) {
        var iconUrl = $('#settings-icon-url').val();
        var webuiUrl = $('#settings-webui-url').val();
        if (webuiUrl && !isValidWebUIUrl(webuiUrl)) {
            swal({
                type: 'error',
                title: 'Save Failed',
                text: 'Invalid WebUI URL. Must be http:// or https:// (supports [IP] and [PORT:xxxx] placeholders).'
            });
            return $.Deferred().resolve(false).promise();
        }
        if (webuiUrl && /\[PORT\]/i.test(webuiUrl)) {
            swal({
                type: 'error',
                title: 'Save Failed',
                text: 'Bare [PORT] placeholder is not supported at stack level. Use [PORT:xxxx] with a default port instead (e.g. [PORT:8080]).'
            });
            return $.Deferred().resolve(false).promise();
        }
        var envPath = $('#settings-env-path').val();
        var extraComposeFiles = getExtraComposeFilesValue();
        var defaultProfile = $('#settings-default-profile').val();
        var externalComposePath = $('#settings-external-compose-path').val();
        var externalComposeFilePath = $('#settings-external-compose-file').val();
        if (externalComposePath && externalComposeFilePath) {
            swal({
                type: 'error',
                title: 'Save Failed',
                text: 'Set either External Compose Path or External Compose File, not both.'
            });
            return $.Deferred().resolve(false).promise();
        }
        var stackPath = (editorModal.filePaths.stackMeta || '').replace(/\/$/, '');
        if (externalComposeFilePath && stackPath && externalComposeFilePath.startsWith(stackPath + '/')) {
            swal({
                type: 'error',
                title: 'Save Failed',
                text: 'External Compose File cannot be inside the stack project folder. Use a path that is external to this stack.'
            });
            return $.Deferred().resolve(false).promise();
        }
        var useDefaultComposeFiles = $('#settings-use-default-compose-files').is(':checked') ? 'true' : 'false';
        savePromises.push(
            $.post(caURL, {
                action: 'setStackSettings',
                script: project,
                iconUrl: iconUrl,
                webuiUrl: webuiUrl,
                envPath: envPath,
                extraComposeFiles: extraComposeFiles,
                defaultProfile: defaultProfile,
                externalComposePath: externalComposePath,
                externalComposeFilePath: externalComposeFilePath,
                useDefaultComposeFiles: useDefaultComposeFiles
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
                        editorModal.originalSettings['extra-compose-files'] = extraComposeFiles;
                        editorModal.originalSettings['default-profile'] = defaultProfile;
                        editorModal.originalSettings['external-compose-path'] = externalComposePath;
                        editorModal.originalSettings['external-compose-file'] = externalComposeFilePath;
                        editorModal.originalSettings['use-default-compose-files'] = useDefaultComposeFiles;
                        editorModal.modifiedSettings.delete('icon-url');
                        editorModal.modifiedSettings.delete('webui-url');
                        editorModal.modifiedSettings.delete('env-path');
                        editorModal.modifiedSettings.delete('extra-compose-files');
                        editorModal.modifiedSettings.delete('default-profile');
                        editorModal.modifiedSettings.delete('external-compose-path');
                        editorModal.modifiedSettings.delete('external-compose-file');
                        editorModal.modifiedSettings.delete('use-default-compose-files');
                        return true;
                    }
                    if (saveErrors) saveErrors.push(response.message || 'Failed to save stack settings.');
                }
                return false;
            }).fail(function() {
                if (saveErrors) saveErrors.push('Failed to save stack settings (network error).');
                return false;
            })
        );
    }

    // Save labels view mode if modified
    if (editorModal.modifiedSettings.has('labels-view-mode')) {
        var labelsViewMode = editorModal.pendingLabelsViewMode === 'advanced' ? 'advanced' : 'basic';
        savePromises.push(
            $.post(caURL, {
                action: 'setLabelsViewMode',
                script: project,
                labelsViewMode: labelsViewMode
            }).then(function(data) {
                if (data) {
                    try {
                        var response = JSON.parse(data);
                    } catch (e) {
                        if (saveErrors) saveErrors.push('Invalid server response when saving override management mode.');
                        return false;
                    }
                    if (response.result === 'success') {
                        editorModal.originalSettings['labels-view-mode'] = labelsViewMode;
                        editorModal.pendingLabelsViewMode = labelsViewMode;
                        editorModal.modifiedSettings.delete('labels-view-mode');
                        toggleLabelsViewMode(labelsViewMode === 'advanced', true);
                        return true;
                    }
                    if (saveErrors) saveErrors.push(response.message || 'Failed to save override management mode.');
                    return false;
                }
                if (saveErrors) saveErrors.push('Failed to save override management mode. Empty server response.');
                return false;
            }).fail(function() {
                if (saveErrors) saveErrors.push('Failed to save override management mode (network error).');
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

// Save all modified changes (files, settings, and labels)
function saveAllChanges(closeAfterSave) {
    if (typeof closeAfterSave === 'undefined') {
        closeAfterSave = false;
    }

    var savePromises = [];
    var saveErrors = [];
    var skippedManualLabels = false;
    var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
    var pathSensitiveSettingsChanged = hasPathSensitiveSettingsChanges();
    var pathDependentEdits = hasPathDependentEdits();
    var project = editorModal.currentProject;
    var removedServices = [];

    if (totalChanges === 0) {
        return;
    }

    if (project && editorModal.modifiedTabs.has('compose') && isStackRunning(project)) {
        removedServices = getRemovedComposeServices();
    }

    function continueSave() {
        // Track if labels are being modified in Automatic mode (need to offer recreate)
        var labelsWereModified = editorModal.labelsViewMode === 'basic' && editorModal.modifiedLabels.size > 0;

        // Save modified file tabs (compose, env, and override editor)
        editorModal.modifiedTabs.forEach(function(tabName) {
            savePromises.push(saveTab(tabName, saveErrors));
        });

        // Save settings if modified
        if (editorModal.modifiedSettings.size > 0) {
            savePromises.push(saveSettings(saveErrors));
        }

        // Save labels only in Automatic mode.
        if (editorModal.modifiedLabels.size > 0) {
            if (editorModal.labelsViewMode === 'basic') {
                savePromises.push(saveLabels(saveErrors));
            } else {
                skippedManualLabels = true;
            }
        }

        $.when.apply($, savePromises).then(function() {
            var results = Array.prototype.slice.call(arguments);
            var allSucceeded = results.every(function(result) {
                return result === true;
            });

            if (allSucceeded) {
                if (skippedManualLabels) {
                    swal({
                        title: "Partially Saved",
                        text: "Non-label changes were saved. WebUI label form edits were not saved because Override File Management is set to Manual. Switch to Automatic to save Labels form changes.",
                        type: "warning"
                    });
                    updateTabModifiedState();
                    updateSaveButtonState();
                    return;
                }

                // Check if we should offer to recreate containers
                if (labelsWereModified) {
                    promptRecreateContainers(closeAfterSave);
                } else {
                    var saveProject = editorModal.currentProject;
                    if (closeAfterSave) {
                        doCloseEditorModal();
                    }
                    swal({
                        title: "Saved!",
                        text: "All changes have been saved.",
                        type: "success",
                        showConfirmButton: false
                    });
                    setTimeout(function() {
                        swal.close();
                        if (saveProject) {
                            refreshStackByProject(saveProject);
                        }
                    }, 1500);
                }
            } else {
                var filteredErrors = saveErrors.filter(function(message) {
                    return message !== '__STALE_PATH__';
                });
                if (filteredErrors.length === 0 && saveErrors.indexOf('__STALE_PATH__') !== -1) {
                    return;
                }
                swal({
                    title: 'Save Failed',
                    text: filteredErrors.join('\n') || 'An unknown error occurred while saving.',
                    type: 'error'
                });
            }
        });
    }

    if (pathSensitiveSettingsChanged && pathDependentEdits) {
        swal({
            title: 'Reload Required',
            text: 'Compose path or discovery settings changed. Save settings and reload the editor before saving compose, .env, override, or label edits.',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Save Settings Only',
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                saveSettingsWithOptionalReload(closeAfterSave);
            }
        });
        return;
    }

    if (pathSensitiveSettingsChanged) {
        saveSettingsWithOptionalReload(closeAfterSave);
        return;
    }

    if (removedServices.length > 0) {
        swal({
            title: 'Running Stack Changed',
            text: 'This stack is running and the compose file no longer defines: ' + removedServices.join(', ') + '. Saving now can leave orphaned containers behind. Stop the stack first, or use Remove orphans from the Compose Up/Down dialog to clean them up.',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                continueSave();
            }
        });
        return;
    }

    continueSave();
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
            overrideDoc.services[serviceKey].labels[managed_label] = managed_label_name;
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
        scriptContents: rawOverride,
        managed: editorModal.labelsViewMode === 'basic' ? 1 : 0,
        expectedPath: getExpectedSavePathForTab('override')
    }).then(function(data) {
        if (!parseSaveResponse(data, saveErrors, 'WebUI labels')) {
            return false;
        }

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
    saveAllChanges(true);
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
    editorModal.currentProjectName = null;
    editorModal.currentTab = 'compose';
    editorModal.modifiedTabs = new Set();
    editorModal.modifiedSettings = new Set();
    editorModal.modifiedLabels = new Set();
    editorModal.originalContent = {};
    editorModal.originalSettings = {};
    editorModal.originalLabels = {};
    editorModal.labelsData = null;
    editorModal.labelsViewMode = 'basic';
    editorModal.pendingLabelsViewMode = 'basic';

    // Clear editor content to avoid showing stale content on next open
    ['compose', 'env', 'override'].forEach(function(type) {
        if (editorModal.editors[type]) {
            editorModal.editors[type].setValue('', -1);
        }
    });

    // Reset labels view to basic; stack-specific mode reloads on next open.
    toggleLabelsViewMode(false, true);
    $('#editor-validation-override').html('<i class="fa fa-check editor-validation-icon"></i> Ready').removeClass('valid error warning');

    // Reset compose file selector
    $('#compose-file-selector-wrap').hide();
    $('#compose-file-selector').empty();
    editorModal.composeFiles = [];

    // Reset settings fields
    $('#settings-name').val('');
    $('#settings-description').val('');
    $('#settings-icon-url').val('');
    $('#settings-webui-url').val('');
    $('#settings-env-path').val('');
    resetExtraComposeFilesUI();
    $('#settings-default-profile').val('');
    $('#settings-external-compose-path').val('');
    $('#settings-external-compose-file').val('');
    $('#settings-use-default-compose-files').prop('checked', false);
    $('#settings-override-management').prop('checked', true);
    $('#settings-override-management-label').text('Automatic');
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
                    if (!data) {
                        setStackActionInProgress(project, false);
                        swal({
                            title: 'Delete Failed',
                            text: 'Empty response from server while deleting stack.',
                            type: 'error'
                        });
                        return;
                    }

                    var response = JSON.parse(data);
                    if (!response || !response.result) {
                        setStackActionInProgress(project, false);
                        swal({
                            title: 'Delete Failed',
                            text: 'Unexpected delete response from server.',
                            type: 'error'
                        });
                        return;
                    }

                    if (response.result === 'error') {
                        setStackActionInProgress(project, false);
                        swal({
                            title: 'Delete Failed',
                            text: response.message || 'Unable to delete stack.',
                            type: 'error'
                        });
                        return;
                    }

                    removeStackFromUiByProject(project);

                    if (response.result === 'warning') {
                        setTimeout(function() {
                            swal({
                                title: 'Stack deleted. Files remain on disk.',
                                text: response.message || 'Some external files were not removed.',
                                type: 'warning'
                            });
                        }, 100);
                        return;
                    }

                    setTimeout(function() {
                        swal({
                            title: 'Stack deleted',
                            text: composeEscapeHtml(projectName) + ' was removed successfully.',
                            type: 'success',
                            showConfirmButton: false
                        });
                        setTimeout(function() {
                            swal.close();
                        }, 1200);
                    }, 100);
                } catch (e) {
                    setStackActionInProgress(project, false);
                    composeLogger('Delete response parse error', {
                        project: project,
                        error: e
                    }, 'user', 'error', 'stack-action');
                    swal({
                        title: 'Delete Failed',
                        text: 'Could not parse delete response.',
                        type: 'error'
                    });
                }
            }).fail(function() {
                setStackActionInProgress(project, false);
                composeLogger('Delete request failed for project', {
                    project: project
                }, 'user', 'error', 'stack-action');
                swal({
                    title: 'Delete Failed',
                    text: 'Network error while deleting stack.',
                    type: 'error'
                });
            });
        }
    });
}

function purgeDeletedStackCaches(project, stackId) {
    if (!project) {
        return;
    }

    if (stackId !== '') {
        delete expandedStacks[stackId];
        delete stackDetailsDesiredExpanded[stackId];
        delete stackDetailsLoading[stackId];
        delete stackContainersCache[stackId];
    }

    delete composeStackActionInProgress[project];
    delete stackDetailsPrefetchCache[project];
    delete stackDetailsPrefetchPromises[project];
    delete persistentContainerCache[project];
    delete stackUpdateStatus[project];

    pendingUpdateCheckStacks = (pendingUpdateCheckStacks || []).filter(function(stackName) {
        return stackName !== project;
    });
    pendingComposeReloadStacks = (pendingComposeReloadStacks || []).filter(function(stackName) {
        return stackName !== project;
    });
}

function removeStackFromUiByProject(project) {
    var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
    var stackId = '';
    if ($stackRow.length > 0) {
        stackId = ($stackRow.attr('id') || '').replace('stack-row-', '');
    }

    if (stackId !== '') {
        $('#details-row-' + stackId).remove();
    }

    purgeDeletedStackCaches(project, stackId);

    if ($stackRow.length === 0) {
        return;
    }

    $stackRow.remove();
    syncComposeStackRowStriping();
    updateStackToggleAllButtonState();

    var remainingRows = $('#compose_stacks tr.compose-sortable').length;
    if (remainingRows === 0) {
        $('#compose_list').html('<tr><td colspan="14" style="text-align:center;padding:20px;color:var(--alt-text-color);">No Docker Compose stacks found. Click "Add New Stack" to create one.</td></tr>');
    }
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
        stackDetailsDesiredExpanded[stackId] = false;
        $detailsRow.slideUp(200);
        $expandIcon.removeClass('expanded');
        expandedStacks[stackId] = false;
    } else {
        stackDetailsDesiredExpanded[stackId] = true;
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

    if (!stackToggleAllBatching) {
        updateStackToggleAllButtonState();
    }
}

function getComposeStackIds() {
    return $('#compose_stacks tr.compose-sortable[id^="stack-row-"]').map(function() {
        return this.id.replace('stack-row-', '');
    }).get();
}

function isStackExpanded(stackId) {
    if (stackDetailsDesiredExpanded[stackId] === false) {
        expandedStacks[stackId] = false;
        return false;
    }

    var $detailsRow = $('#details-row-' + stackId);
    var isVisible = $detailsRow.is(':visible');

    // Keep JS expansion state aligned with the live DOM. During async detail loads,
    // preserve the expanded intent until the row is rendered and shown.
    if (isVisible) {
        stackDetailsDesiredExpanded[stackId] = true;
        expandedStacks[stackId] = true;
        return true;
    }

    if (!stackDetailsLoading[stackId]) {
        expandedStacks[stackId] = false;
    }

    return !!expandedStacks[stackId];
}

function updateStackToggleAllButtonState() {
    var $button = $('#compose-stack-toggle-all');
    if (!$button.length) return;

    var stackIds = getComposeStackIds();
    var expandedCount = 0;

    stackIds.forEach(function(stackId) {
        if (isStackExpanded(stackId)) {
            expandedCount++;
        }
    });

    var hasStacks = stackIds.length > 0;
    var allExpanded = hasStacks && expandedCount === stackIds.length;

    $button.prop('disabled', !hasStacks);
    $button.attr('title', allExpanded ? 'Collapse all stacks' : 'Expand all stacks');
    $button.attr('aria-label', allExpanded ? 'Collapse all stacks' : 'Expand all stacks');
    $button.toggleClass('is-expanded', allExpanded);
}

function toggleAllStackDetails(forceExpand) {
    if (stackToggleAllBusy) {
        if (typeof forceExpand === 'boolean') {
            stackToggleAllQueuedForceExpand = forceExpand;
            stackToggleAllQueuedToggle = false;
        } else {
            stackToggleAllQueuedToggle = true;
        }
        return;
    }

    var stackIds = getComposeStackIds();
    if (!stackIds.length) return;

    var anyCollapsed = stackIds.some(function(stackId) {
        return !isStackExpanded(stackId);
    });
    var shouldExpand = typeof forceExpand === 'boolean' ? forceExpand : anyCollapsed;

    stackToggleAllBusy = true;
    stackToggleAllBatching = true;

    stackIds.forEach(function(stackId) {
        var expanded = isStackExpanded(stackId);
        if (shouldExpand && !expanded) {
            toggleStackDetails(stackId);
        } else if (!shouldExpand && expanded) {
            toggleStackDetails(stackId);
        }
    });

    stackToggleAllBatching = false;

    updateStackToggleAllButtonState();

    // Allow animations/state to settle, then replay only the latest queued intent.
    setTimeout(function() {
        stackToggleAllBusy = false;

        var queuedForceExpand = stackToggleAllQueuedForceExpand;
        var queuedToggle = stackToggleAllQueuedToggle;
        stackToggleAllQueuedForceExpand = null;
        stackToggleAllQueuedToggle = false;

        if (typeof queuedForceExpand === 'boolean') {
            toggleAllStackDetails(queuedForceExpand);
        } else if (queuedToggle) {
            toggleAllStackDetails();
        }
    }, 240);
}

function loadStackContainerDetails(stackId, project) {
    var $container = $('#details-container-' + stackId);

    if (typeof stackDetailsDesiredExpanded[stackId] === 'undefined') {
        stackDetailsDesiredExpanded[stackId] = !!expandedStacks[stackId] || $('#details-row-' + stackId).is(':visible');
    }

    function renderFromResponse(response, finishLoad) {
        if (response && response.result === 'success') {
            var containers = response.containers || [];

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
            }, 'user', 'debug', 'container-details');
            renderContainerDetails(stackId, containers, project);

            if (stackDetailsDesiredExpanded[stackId] === false) {
                expandedStacks[stackId] = false;
                $('#details-row-' + stackId).stop(true, true).hide();
                finishLoad();
            } else {
                expandedStacks[stackId] = true;
                $('#details-row-' + stackId).stop(true, true).slideDown(200, finishLoad);
            }
            return true;
        }
        return false;
    }

    function renderError(message, finishLoad, level) {
        $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> ' + composeEscapeHtml(message) + '</div>');
        $('#details-row-' + stackId).stop(true, true).slideDown(200, finishLoad);
        composeLogger(level || 'error', {
            stackId: stackId,
            project: project,
            message: message
        }, 'user', level || 'error', 'container-details');
    }

    // Prevent parallel loads for same stack
    if (stackDetailsLoading[stackId]) {
        composeLogger('already-loading', {
            stackId: stackId,
            project: project
        }, 'user', 'warning', 'container-details');
        return stackDetailsLoadPromises[stackId] || Promise.resolve();
    }
    stackDetailsLoading[stackId] = true;
    stackDetailsLoadPromises[stackId] = new Promise(function(resolve) {
        function finishLoad() {
            stackDetailsLoading[stackId] = false;
            delete stackDetailsLoadPromises[stackId];
            updateStackToggleAllButtonState();
            resolve();
        }

    composeLogger('start', {
        stackId: stackId,
        project: project
    }, 'user', 'debug', 'container-details');

    // Show loading state
    $container.html('<div class="stack-details-loading"><i class="fa fa-spinner fa-spin"></i> Loading container details...</div>');

    function fetchAndRender(finishLoad) {
        $.post(caURL, {
            action: 'getStackContainers',
            script: project
        }, function(data) {
            var parsed = tryParseJson(data);
            if (parsed && parsed.result === 'success') {
                // Keep prefetch cache warm with latest direct fetch too.
                stackDetailsPrefetchCache[project] = parsed;
            }

            if (renderFromResponse(parsed, finishLoad)) {
                return;
            }

            if (parsed && parsed.message) {
                renderError(parsed.message, finishLoad, 'error');
            } else {
                renderError('Failed to load container details', finishLoad, 'warning');
            }
        }).fail(function() {
            renderError('Failed to load container details', finishLoad, 'error');
        });
    }

    function consumePrefetchOrFetch(finishLoad) {
        // Fast path: prefetch already finished.
        if (stackDetailsPrefetchCache[project]) {
            var cached = stackDetailsPrefetchCache[project];
            delete stackDetailsPrefetchCache[project];
            if (!renderFromResponse(cached, finishLoad)) {
                fetchAndRender(finishLoad);
            }
            return;
        }

        // If a prefetch is currently in-flight for this project, wait for it once.
        if (stackDetailsPrefetchPromises[project]) {
            stackDetailsPrefetchPromises[project].then(function() {
                if (stackDetailsPrefetchCache[project]) {
                    var prefetched = stackDetailsPrefetchCache[project];
                    delete stackDetailsPrefetchCache[project];
                    if (renderFromResponse(prefetched, finishLoad)) {
                        return;
                    }
                }
                fetchAndRender(finishLoad);
            });
            return;
        }

        // No prefetch available: normal path.
        fetchAndRender(finishLoad);
    }

    consumePrefetchOrFetch(finishLoad);
    });

    return stackDetailsLoadPromises[stackId];
}

$(document).on('compose-list-loaded', updateStackToggleAllButtonState);

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
    html += '<th class="ct-col-health">Health</th>';
    html += '<th class="ct-col-source">Source</th>';
    html += '<th class="ct-col-tag">Tag</th>';
    html += '<th class="ct-col-net">Network</th>';
    html += '<th class="ct-col-ip">Container IP</th>';
    html += '<th class="ct-col-cpu">CPU</th>';
    html += '<th class="ct-col-memory">Memory</th>';
    html += '<th class="ct-col-net_io">Net I/O</th>';
    html += '<th class="ct-col-block_io">Disk I/O</th>';
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
            html += '<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + composeEscapeAttr(project) + '\', \'' + composeEscapeAttr(stackId) + '\', \'update\');">';
            html += '<span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> update ready</span>';
            html += '</a>';
            if (ctLocalSha && ctRemoteSha) {
                // Always show SHA diff when available.
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
                // Show SHA snippet in toggleable detail area for up-to-date containers (15 chars).
                html += '<div style="font-family:var(--font-bitstream);font-size:0.85em;" title="' + composeEscapeAttr(ctLocalSha) + '"><span class="compose-text-muted">' + composeEscapeHtml(ctLocalSha.substring(0, 15)) + '</span></div>';
            }
        } else {
            // Unknown/not checked
            html += '<span class="compose-text-muted" style="white-space:nowrap;"><i class="fa fa-question-circle fa-fw"></i> not checked</span>';
        }
        html += '</td>';

        // Health column
        html += '<td class="ct-col-health">' + composeRenderHealthBadge(container.health || '', state) + '</td>';

        // Source (image name without tag)
        html += '<td class="ct-col-source"><span class="docker_readmore compose-text-muted">' + composeEscapeHtml(imageSource) + '</span></td>';

        // Tag (image tag) — truncated with ellipsis via CSS if too long
        html += '<td class="ct-col-tag ct-col-tag-cell"><span class="ct-tag" title="' + composeEscapeAttr(imageTag) + '">' + composeEscapeHtml(imageTag) + '</span></td>';

        // Network
        html += '<td class="ct-col-net" style="white-space:nowrap;"><span class="docker_readmore">' + networkNames.map(composeEscapeHtml).join('<br>') + '</span></td>';

        // Container IP
        html += '<td class="ct-col-ip" style="white-space:nowrap;"><span class="docker_readmore">' + ipAddresses.map(composeEscapeHtml).join('<br>') + '</span></td>';

        html += '<td class="ct-col-cpu compose-load-cell">';
        var normalizedContainerId = composeNormalizeContainerKey(containerId);
        html += '<span class="compose-cpu-' + normalizedContainerId + ' compose-text-muted">-</span>';
        html += '<div class="usage-disk mm"><span id="compose-cpu-bar-' + normalizedContainerId + '" style="width:0"></span><span></span></div>';
        html += '</td>';
        html += '<td class="ct-col-memory compose-load-cell">';
        html += '<span class="compose-mem-' + normalizedContainerId + ' compose-text-muted">-</span>';
        html += '<div class="usage-disk mm"><span id="compose-mem-bar-' + normalizedContainerId + '" style="width:0"></span><span></span></div>';
        html += '</td>';
        html += '<td class="ct-col-net_io"><span class="compose-netio-' + normalizedContainerId + ' compose-text-muted">-</span></td>';
        html += '<td class="ct-col-block_io"><span class="compose-blockio-' + normalizedContainerId + ' compose-text-muted">-</span></td>';

        // Container Port
        html += '<td class="ct-col-cport-cell" style="white-space:nowrap;"><span class="docker_readmore">' + containerPorts.map(composeEscapeHtml).join('<br>') + '</span></td>';

        // LAN IP:Port
        html += '<td class="ct-col-lport-cell"><span>' + lanPorts.map(composeEscapeHtml).join('<br>') + '</span></td>';

        html += '</tr>';
    });

    html += '</tbody></table>';

    $container.html(html);

    if (window.composeColCustomizer && typeof window.composeColCustomizer.reapply === 'function') {
        window.composeColCustomizer.reapply();
    }
    if (window.composeDockerLoadRenderCached && typeof window.composeDockerLoadRenderCached === 'function') {
        window.composeDockerLoadRenderCached();
    }

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
            }, 'user', 'debug', 'container-details');
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

        // Update stack health from cached container health states.
        try {
            var $healthCell = $stackRow.find('td.col-health');
            var healthStatus = composeAggregateStackHealth(stackInfo.containers);
            $healthCell.html(composeRenderHealthBadge(healthStatus, anyRunning ? 'running' : 'exited'));
        } catch (e) {}

        // Re-apply list helpers after row updates.
        applyListEnhancements();
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
// Keep a context dropdown above the footer and extend the scrollable area so it stays reachable
// (same fix as unraid/webgui#2639)
function fixContextDropdownOverflow(elementId) {
    $('#dropdown-' + elementId).css('z-index', 10001)
        .append('<li class="compose-dropdown-spacer" aria-hidden="true" style="position:absolute;top:100%;left:0;width:1px;height:60px;pointer-events:none;list-style:none"></li>');
}

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
    fixContextDropdownOverflow(elementId);
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
    var runningProfile = $row.data('running-profile') || '';
    var defaultProfile = $row.data('default-profile') || '';
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
                    showProfileSelector('up', path, profiles, runningProfile, defaultProfile);
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
                    showProfileSelector('down', path, profiles, runningProfile, defaultProfile);
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
                    showProfileSelector('stop', path, profiles, runningProfile, defaultProfile);
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
                    showProfileSelector('restart', path, profiles, runningProfile, defaultProfile);
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
                        showProfileSelector('update', path, profiles, runningProfile, defaultProfile);
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
                        showProfileSelector('forceUpdate', path, profiles, runningProfile, defaultProfile);
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
                    showProfileSelector('up', path, profiles, runningProfile, defaultProfile);
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
                        showProfileSelector('down', path, profiles, runningProfile, defaultProfile);
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
                    showProfileSelector('pull', path, profiles, runningProfile, defaultProfile);
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
                    showProfileSelector('update', path, profiles, runningProfile, defaultProfile);
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
    fixContextDropdownOverflow(elementId);
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