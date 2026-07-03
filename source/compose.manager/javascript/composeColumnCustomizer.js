/**
 * Column customization for stack + service tables.
 * Preferences are persisted server-side in a JSON file.
 */
(function() {
    'use strict';

    var composeBootstrap = window.composeManagerBootstrap || {};
    var columnModel = composeBootstrap.columnModel || {};

    var STACK_COLS = $.extend({}, columnModel.stackCols || {});
    var SERVICE_COLS = $.extend({}, columnModel.serviceCols || {});

    var defaults = {
        stack: $.extend({}, (columnModel.defaults || {}).stack || {}),
        service: $.extend({}, (columnModel.defaults || {}).service || {})
    };

    if (!Object.keys(defaults.stack).length || !Object.keys(defaults.service).length) {
        if (typeof composeLogger === 'function') {
            composeLogger('Column model bootstrap missing; customizer disabled for this page load', null, 'user', 'warning', 'column-layout');
        }
        return;
    }

    var prefs = {
        stack: $.extend({}, defaults.stack),
        service: $.extend({}, defaults.service),
        stackOrder: Object.keys(defaults.stack).filter(function(key) { return defaults.stack[key]; }),
        serviceOrder: Object.keys(defaults.service).filter(function(key) { return defaults.service[key]; })
    };

    // Seed from the server-provided layout synchronously so the very first
    // reapply()/reorder pass uses the saved order — the table is already
    // server-rendered in this order, so reapply is a no-op (no visible snap).
    // The async fetchPrefs() below simply refreshes the same values.
    try {
        var bootstrapLayout = composeBootstrap.columnLayout;
        if (bootstrapLayout) {
            prefs = normalizePrefs(bootstrapLayout);
            if (typeof composeLogger === 'function') {
                composeLogger('Applied bootstrap column layout', {
                    stackVisible: (prefs.stackOrder || []).length,
                    serviceVisible: (prefs.serviceOrder || []).length
                }, 'user', 'debug', 'column-layout');
            }
        }
    } catch (e) { /* fall back to defaults */ }

    var STACK_WIDTH_WEIGHTS = $.extend({}, columnModel.stackWidthWeights || {});

    var STACK_DEFAULT_VISIBLE = $.extend({}, columnModel.stackAlwaysVisible || {});

    var STACK_CELL_CLASS_MAP = {
        update: 'col-update',
        containers: 'col-containers',
        uptime: 'col-uptime',
        health: 'col-health',
        cpu: 'col-cpu',
        memory: 'col-memory',
        net_io: 'col-net_io',
        block_io: 'col-block_io',
        description: 'col-description',
        path: 'col-path'
    };

    var SERVICE_HEADER_CLASS_MAP = {
        update: 'ct-col-update',
        health: 'ct-col-health',
        cpu: 'ct-col-cpu',
        memory: 'ct-col-memory',
        net_io: 'ct-col-net_io',
        block_io: 'ct-col-block_io',
        source: 'ct-col-source',
        tag: 'ct-col-tag',
        net: 'ct-col-net',
        ip: 'ct-col-ip',
        cport: 'ct-col-cport',
        lport: 'ct-col-lport'
    };

    var SERVICE_BODY_CLASS_MAP = {
        update: 'ct-updatecolumn',
        health: 'ct-col-health',
        cpu: 'ct-col-cpu',
        memory: 'ct-col-memory',
        net_io: 'ct-col-net_io',
        block_io: 'ct-col-block_io',
        source: 'ct-col-source',
        tag: 'ct-col-tag',
        net: 'ct-col-net',
        ip: 'ct-col-ip',
        cport: 'ct-col-cport-cell',
        lport: 'ct-col-lport-cell'
    };

    function toBooleanLike(value, fallback) {
        if (value === true || value === false) return value;
        if (value === 1 || value === 0) return value === 1;
        if (typeof value === 'string') {
            var normalized = value.trim().toLowerCase();
            if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on') return true;
            if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off' || normalized === '') return false;
        }
        return !!fallback;
    }

    function normalizePrefs(incoming) {
        var out = {
            stack: $.extend({}, defaults.stack),
            service: $.extend({}, defaults.service),
            stackOrder: Object.keys(defaults.stack).filter(function(key) { return defaults.stack[key]; }),
            serviceOrder: Object.keys(defaults.service).filter(function(key) { return defaults.service[key]; })
        };
        if (!incoming || typeof incoming !== 'object') return out;

        ['stack', 'service'].forEach(function(scope) {
            if (!incoming[scope] || typeof incoming[scope] !== 'object') return;
            Object.keys(out[scope]).forEach(function(key) {
                if (Object.prototype.hasOwnProperty.call(incoming[scope], key)) {
                    out[scope][key] = toBooleanLike(incoming[scope][key], out[scope][key]);
                }
            });
        });

        // Handle order arrays
        var scopeMap = { stack: 'stackOrder', service: 'serviceOrder' };
        ['stack', 'service'].forEach(function(scope) {
            var orderKey = scopeMap[scope];
            if (Array.isArray(incoming[orderKey])) {
                var visibleCols = [];
                incoming[orderKey].forEach(function(col) {
                    if (out[scope].hasOwnProperty(col) && out[scope][col]) {
                        visibleCols.push(col);
                    }
                });
                // Ensure all visible columns are in the order, add missing ones at end
                Object.keys(out[scope]).forEach(function(col) {
                    if (out[scope][col] && visibleCols.indexOf(col) === -1) {
                        visibleCols.push(col);
                    }
                });
                out[orderKey] = visibleCols;
            }
        });

        return out;
    }

    function applyScope(scope) {
        var selector = scope === 'stack' ? '#compose_stacks' : '.compose-ct-table';
        var $tables = $(selector);

        if (scope === 'stack') {
            reorderStackTable();
        } else {
            reorderServiceTables();
        }

        Object.keys(prefs[scope]).forEach(function(col) {
            $tables.toggleClass('hide-col-' + col, !prefs[scope][col]);
        });
        if (scope === 'stack') {
            applyStackWidthMath();
            syncStackColspans();
        }
        forceTableLayoutReflow($tables);
    }

    // Number of physically rendered stack columns. Arrow, icon, name and
    // autostart are structural and always visible; all other stack columns are
    // user-toggleable.
    function getVisibleStackColCount() {
        var count = 4; // arrow, icon, name, autostart
        Object.keys(STACK_COLS).forEach(function(col) {
            if (prefs.stack[col]) count++;
        });
        return count;
    }

    // Full-width rows (detail rows, progress/empty/error rows) are authored with
    // a static colspan that assumes every column exists. Under table-layout:fixed
    // an oversized colspan keeps the display:none columns' slots alive, so they
    // steal width from the visible columns. Clamp colspan to the live column count.
    function syncStackColspans() {
        var count = getVisibleStackColCount();
        $('#compose_stacks').find('td[colspan]').attr('colspan', count);
    }

    function applyStackWidthMath() {
        var $table = $('#compose_stacks');
        if (!$table.length) return;

        var visible = $.extend({}, STACK_DEFAULT_VISIBLE);
        Object.keys(STACK_COLS).forEach(function(col) {
            visible[col] = !!prefs.stack[col];
        });

        var totalWeight = 0;
        Object.keys(STACK_WIDTH_WEIGHTS).forEach(function(col) {
            if (visible[col]) {
                totalWeight += STACK_WIDTH_WEIGHTS[col];
            }
        });
        if (totalWeight <= 0) return;

        var tableEl = $table[0];
        Object.keys(STACK_WIDTH_WEIGHTS).forEach(function(col) {
            var fraction = visible[col] ? (STACK_WIDTH_WEIGHTS[col] / totalWeight) : 0;
            var cssVar = '--cm-col-' + col.replace(/_/g, '-') + '-frac';
            tableEl.style.setProperty(cssVar, String(fraction));
        });
    }

    function forceTableLayoutReflow($tables) {
        if (!$tables || !$tables.length) return;

        // Toggling display on columns already triggers a fixed-layout recompute;
        // we only need a read to flush it synchronously. No width mutation (that
        // caused a visible "snap"). Column widths come purely from CSS vars.
        $tables.each(function() {
            if (this) void this.offsetWidth;
        });
    }

    function getScopeRenderOrder(scope) {
        var map = getScopeMap(scope);
        var orderKey = scope === 'stack' ? 'stackOrder' : 'serviceOrder';
        var order = Array.isArray(prefs[orderKey]) ? prefs[orderKey].slice() : [];

        Object.keys(map).forEach(function(col) {
            if (order.indexOf(col) === -1) {
                order.push(col);
            }
        });

        return order;
    }

    function reorderStackTable() {
        var order = getScopeRenderOrder('stack');

        $('#compose_stacks tr').each(function() {
            var $row = $(this);
            if ($row.children('[colspan]').length) return;

            var $autostart = $row.children('.col-autostart').first();
            if (!$autostart.length) return;

            order.forEach(function(col) {
                var className = STACK_CELL_CLASS_MAP[col];
                if (!className) return;

                var $cell = $row.children('.' + className).first();
                if ($cell.length) {
                    $autostart.before($cell);
                }
            });
        });
    }

    function reorderServiceTables() {
        var order = getScopeRenderOrder('service');

        $('.compose-ct-table').each(function() {
            var $table = $(this);

            $table.find('tr').each(function() {
                var $row = $(this);
                var $anchor = $row.children('.ct-col-name, .ct-name').first();
                if (!$anchor.length) return;

                order.forEach(function(col) {
                    var className = $row.closest('thead').length ? SERVICE_HEADER_CLASS_MAP[col] : SERVICE_BODY_CLASS_MAP[col];
                    if (!className) return;

                    var $cell = $row.children('.' + className).first();
                    if ($cell.length) {
                        $anchor.after($cell);
                        $anchor = $cell;
                    }
                });
            });
        });
    }

    function setTransferSelection(scope, side, values, shouldFocus) {
        var normalizedValues = Array.isArray(values) ? values.filter(Boolean) : (values ? [values] : []);
        var $select = $(scopeSelectId(scope, side));
        if (!$select.length) return;

        $select.val(normalizedValues);
        $select.find('option').prop('selected', false);
        normalizedValues.forEach(function(value) {
            $select.find('option[value="' + value + '"]').prop('selected', true);
        });

        if (shouldFocus) {
            $select.trigger('focus');
        }
    }

    function applyAll() {
        applyScope('stack');
        applyScope('service');
    }

    function getScopeMap(scope) {
        return scope === 'stack' ? STACK_COLS : SERVICE_COLS;
    }

    function scopeSelectId(scope, side) {
        return '#compose-col-' + scope + '-' + side;
    }

    function renderScopeTransfer(scope) {
        var map = getScopeMap(scope);
        var $hidden = $(scopeSelectId(scope, 'hidden'));
        var $visible = $(scopeSelectId(scope, 'visible'));

        if (!$hidden.length || !$visible.length) return;

        var hiddenHtml = '';
        var visibleHtml = '';
        var hiddenSelection = getSelectedTransferKeys(scope, 'hidden');
        var visibleSelection = getSelectedTransferKeys(scope, 'visible');
        var order = getScopeRenderOrder(scope);

        // Build visible list in order
        order.forEach(function(col) {
            if (prefs[scope] && prefs[scope][col]) {
                var optionHtml = '<option value="' + col + '">' + map[col] + '</option>';
                visibleHtml += optionHtml;
            }
        });

        // Build hidden list
        Object.keys(map).forEach(function(col) {
            if (!prefs[scope] || !prefs[scope][col]) {
                var optionHtml = '<option value="' + col + '">' + map[col] + '</option>';
                hiddenHtml += optionHtml;
            }
        });

        if (!hiddenHtml) {
            hiddenHtml = '<option value="" disabled>(none)</option>';
        }
        if (!visibleHtml) {
            visibleHtml = '<option value="" disabled>(none)</option>';
        }

        $hidden.html(hiddenHtml);
        $visible.html(visibleHtml);
        setTransferSelection(scope, 'hidden', hiddenSelection, false);
        setTransferSelection(scope, 'visible', visibleSelection, false);
    }

    function renderTransferLists() {
        renderScopeTransfer('stack');
        renderScopeTransfer('service');
    }

    function setScopeColumnVisibility(scope, keys, isVisible) {
        if (!keys || !keys.length || !prefs[scope]) return;

        var orderKey = scope === 'stack' ? 'stackOrder' : 'serviceOrder';
        var order = prefs[orderKey] || [];

        keys.forEach(function(col) {
            if (Object.prototype.hasOwnProperty.call(prefs[scope], col)) {
                prefs[scope][col] = isVisible;
                // Update order: add if visible and not in order, remove if hidden
                var idx = order.indexOf(col);
                if (isVisible && idx === -1) {
                    order.push(col);
                } else if (!isVisible && idx !== -1) {
                    order.splice(idx, 1);
                }
            }
        });

        applyScope(scope);
        renderScopeTransfer(scope);
    }

    function moveColumnInOrder(scope, direction) {
        var orderKey = scope === 'stack' ? 'stackOrder' : 'serviceOrder';
        var order = prefs[orderKey] || [];
        var selectedValues = getSelectedTransferKeys(scope, 'visible');
        if (!selectedValues.length) return;

        var col = selectedValues[0];
        var idx = order.indexOf(col);
        if (idx === -1) return;

        var newIdx;
        if (direction === 'up' && idx > 0) {
            newIdx = idx - 1;
        } else if (direction === 'down' && idx < order.length - 1) {
            newIdx = idx + 1;
        } else {
            return;
        }

        // Swap positions
        var temp = order[idx];
        order[idx] = order[newIdx];
        order[newIdx] = temp;

        if (scope === 'stack') {
            reorderStackTable();
        } else {
            reorderServiceTables();
        }
        applyScope(scope);
        renderScopeTransfer(scope);
        setTransferSelection(scope, 'visible', [col], true);
    }

    function getSelectedTransferKeys(scope, side) {
        var values = $(scopeSelectId(scope, side)).val();
        if (!values) return [];
        return Array.isArray(values) ? values : [values];
    }

    function moveSelected(scope, toVisible) {
        var side = toVisible ? 'hidden' : 'visible';
        var keys = getSelectedTransferKeys(scope, side).filter(function(key) {
            return key !== '';
        });
        setScopeColumnVisibility(scope, keys, toVisible);
        setTransferSelection(scope, toVisible ? 'visible' : 'hidden', keys, false);
    }

    function moveAll(scope, toVisible) {
        var map = getScopeMap(scope);
        var keys = [];

        Object.keys(map).forEach(function(col) {
            var currentlyVisible = !!(prefs[scope] && prefs[scope][col]);
            if (toVisible && !currentlyVisible) keys.push(col);
            if (!toVisible && currentlyVisible) keys.push(col);
        });

        setScopeColumnVisibility(scope, keys, toVisible);
    }

    function buildTransferSection(scope, title) {
        var html = '<div class="compose-col-section">';
        html += '<div class="compose-col-section-title">' + title + '</div>';
        html += '<div class="compose-transfer-wrap">';
        html += '<div class="compose-transfer-col">';
        html += '<label for="compose-col-' + scope + '-hidden">Hidden</label>';
        html += '<select id="compose-col-' + scope + '-hidden" class="compose-transfer-select" multiple></select>';
        html += '</div>';
        html += '<div class="compose-transfer-actions">';
        html += '<div class="compose-transfer-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="selected-right" title="Show selected" aria-label="Show selected">&gt;</div>';
        html += '<div class="compose-transfer-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="all-right" title="Show all" aria-label="Show all">&gt;&gt;</div>';
        html += '<div class="compose-transfer-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="selected-left" title="Hide selected" aria-label="Hide selected">&lt;</div>';
        html += '<div class="compose-transfer-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="all-left" title="Hide all" aria-label="Hide all">&lt;&lt;</div>';
        html += '</div>';
        html += '<div class="compose-transfer-col">';
        html += '<label for="compose-col-' + scope + '-visible">Visible</label>';
        html += '<select id="compose-col-' + scope + '-visible" class="compose-transfer-select" multiple></select>';
        html += '</div>';
        html += '<div class="compose-reorder-actions">';
        html += '<div class="compose-reorder-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="move-up" title="Move up" aria-label="Move selected column up">&uarr;</div>';
        html += '<div class="compose-reorder-btn" role="button" tabindex="0" data-scope="' + scope + '" data-action="move-down" title="Move down" aria-label="Move selected column down">&darr;</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
        return html;
    }

    function buildModal() {
        var html = '<div id="compose-col-settings-modal" class="compose-col-modal" style="display:none;">';
        html += '<div class="compose-modal-overlay"></div>';
        html += '<div class="compose-modal-content">';
        html += '<div class="compose-modal-header"><span>Column Visibility</span>';
        html += '<button class="compose-modal-close" type="button" onclick="composeColCustomizer.closeModal();"><i class="fa fa-times"></i></button>';
        html += '</div>';
        html += '<div class="compose-modal-body">';
        html += buildTransferSection('stack', 'Stack Columns');
        html += buildTransferSection('service', 'Service Columns');
        html += '</div>';
        html += '<div class="compose-modal-footer">';
        html += '<button class="compose-modal-btn compose-modal-btn-save" type="button" onclick="composeColCustomizer.saveAndClose();">Save</button>';
        html += '<button class="compose-modal-btn compose-modal-btn-cancel" type="button" onclick="composeColCustomizer.closeModal();">Cancel</button>';
        html += '</div></div></div>';
        return html;
    }

    function syncModalFromPrefs() {
        renderTransferLists();
    }

    function fetchPrefs(cb) {
        $.post(caURL, {
            action: 'getColumnVisibility'
        }, function(resp) {
            var parsed = resp;
            if (typeof parsed === 'string') {
                try {
                    parsed = JSON.parse(parsed);
                } catch (e) {
                    parsed = null;
                }
            }
            if (parsed && parsed.result === 'success' && parsed.visibility) {
                prefs = normalizePrefs(parsed.visibility);
                if (typeof composeLogger === 'function') {
                    composeLogger('Fetched column visibility preferences', {
                        stackVisible: (prefs.stackOrder || []).length,
                        serviceVisible: (prefs.serviceOrder || []).length
                    }, 'user', 'debug', 'column-layout');
                }
            } else {
                prefs = normalizePrefs(null);
                if (typeof composeLogger === 'function') {
                    composeLogger('Column visibility fetch returned fallback payload; using defaults', {
                        result: parsed && parsed.result ? parsed.result : 'invalid'
                    }, 'user', 'debug', 'column-layout');
                }
            }
            if (typeof cb === 'function') cb();
        }).fail(function() {
            prefs = normalizePrefs(null);
            if (typeof composeLogger === 'function') {
                composeLogger('Column visibility fetch failed; using defaults', null, 'user', 'warning', 'column-layout');
            }
            if (typeof cb === 'function') cb();
        });
    }

    function savePrefs(cb) {
        $.post(caURL, {
            action: 'saveColumnVisibility',
            visibility: JSON.stringify(prefs)
        }, function(resp) {
            var parsed = resp;
            if (typeof parsed === 'string') {
                try {
                    parsed = JSON.parse(parsed);
                } catch (e) {
                    parsed = null;
                }
            }
            if (parsed && parsed.result === 'success' && parsed.visibility) {
                prefs = normalizePrefs(parsed.visibility);
                if (typeof composeLogger === 'function') {
                    composeLogger('Saved column visibility preferences', {
                        stackVisible: (prefs.stackOrder || []).length,
                        serviceVisible: (prefs.serviceOrder || []).length
                    }, 'user', 'debug', 'column-layout');
                }
            } else if (typeof composeLogger === 'function') {
                composeLogger('Column visibility save returned non-success payload', {
                    result: parsed && parsed.result ? parsed.result : 'invalid'
                }, 'user', 'warning', 'column-layout');
            }
            if (typeof cb === 'function') cb();
        }).fail(function() {
            if (typeof composeLogger === 'function') {
                composeLogger('Column visibility save request failed', null, 'user', 'warning', 'column-layout');
            }
            if (typeof cb === 'function') cb();
        });
    }

    window.composeColCustomizer = {
        init: function() {
            if (!$('#compose-col-settings-modal').length) {
                $('body').append(buildModal());
            }

            $(document).on('click', '.compose-transfer-btn', function() {
                var scope = $(this).data('scope');
                var action = $(this).data('action');

                if (!scope || !action) return;

                if (action === 'selected-right') moveSelected(scope, true);
                if (action === 'all-right') moveAll(scope, true);
                if (action === 'selected-left') moveSelected(scope, false);
                if (action === 'all-left') moveAll(scope, false);
            });

            $(document).on('click', '.compose-reorder-btn', function() {
                var scope = $(this).data('scope');
                var action = $(this).data('action');

                if (!scope || !action) return;

                if (action === 'move-up') moveColumnInOrder(scope, 'up');
                if (action === 'move-down') moveColumnInOrder(scope, 'down');
            });

            $(document).on('keydown', '.compose-transfer-btn, .compose-reorder-btn', function(e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                $(this).trigger('click');
            });

            this.addToolbarButton();
            fetchPrefs(function() {
                syncModalFromPrefs();
                applyAll();
            });
        },

        reapply: function() {
            applyAll();
        },

        syncColspans: function() {
            syncStackColspans();
        },

        openModal: function() {
            syncModalFromPrefs();
            $('#compose-col-settings-modal').fadeIn(150);
            $('body').css('overflow', 'hidden');
        },

        closeModal: function() {
            $('#compose-col-settings-modal').fadeOut(150);
            $('body').css('overflow', '');
        },

        saveAndClose: function() {
            var self = this;
            savePrefs(function() {
                applyAll();
                self.closeModal();
            });
        },

        addToolbarButton: function() {
            if ($('#compose-col-launcher-wrap').length) return;

            var launcherHtml = '' +
                '<div id="compose-col-launcher-wrap" class="ToggleViewMode compose-col-launcher-wrap">' +
                '<a href="#" class="compose-col-launcher" title="Customize visible columns" onclick="event.preventDefault(); composeColCustomizer.openModal();">' +
                '<i class="fa fa-sliders fa-rotate-90" aria-hidden="true"></i>' +
                '<span>Columns</span>' +
                '</a>' +
                '</div>';

            var $launcher = $(launcherHtml);
            var $tableWrapper = $('#compose_stacks').closest('.TableContainer');
            if ($tableWrapper.length) {
                $tableWrapper.before($launcher);
            } else if ($('#compose_stacks').length) {
                $('#compose_stacks').before($launcher);
            } else if ($('.tabs').length) {
                $('.tabs').append($launcher);
            } else {
                $('body').prepend($launcher);
            }
        }
    };

    $(function() {
        window.composeColCustomizer.init();
        $(document).on('composeListRefreshed.composeColumnCustomizer', function() {
            if (window.composeColCustomizer && typeof window.composeColCustomizer.reapply === 'function') {
                window.composeColCustomizer.reapply();
            }
        });
    });
})();
