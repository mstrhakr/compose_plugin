(function() {
    var caURL = '/plugins/compose.manager/include/Exec.php';
    var expandedStacks = {};
    var stackContainerCache = {};
    var stackOperations = {};
    var stackPaths = Object.create(null);
    var stackActionErrors = {};
    var stackListGeneration = 0;
    var stackDetailGeneration = {};

    // Debug logging function - respects plugin debug setting
    function debugLog() {
        if (window.composeDashDebug) {
            console.log.apply(console, ['[ComposeDash]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    // HTML escape function to prevent XSS
    function composeEscapeHtml(text) {
        if (text === null || text === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    // Escape for HTML attributes (more strict)
    function composeEscapeAttr(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Resolve WebUI URL placeholders for a container
    // As of this version, WebUI URLs are resolved server-side in exec.php
    // (matching Unraid's DockerClient logic for IP/PORT resolution).
    // This function is kept as a fallback for any unresolved placeholders.
    function resolveContainerWebUI(url) {
        if (!url) return '';
        // Fallback: replace any remaining [IP] with hostname
        url = url.replace(/\[IP\]/gi, window.location.hostname);
        // Fallback: replace any remaining [PORT:xxxx] with the port number
        url = url.replace(/\[PORT:(\d+)\]/gi, '$1');
        return url;
    }

    // Smart uptime formatting - single unit, less granularity over time
    function formatUptime(startedAt, isRunning) {
        if (!isRunning) return 'stopped';
        if (!startedAt) return '';

        var started = new Date(startedAt);
        var now = new Date();
        var diffMs = now - started;
        var mins = Math.floor(diffMs / (1000 * 60));
        var hours = Math.floor(diffMs / (1000 * 60 * 60));
        var days = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        var weeks = Math.floor(days / 7);
        var months = Math.floor(days / 30);
        var years = Math.floor(days / 365);

        if (mins < 120) {
            return mins + ' min' + (mins !== 1 ? 's' : '');
        } else if (hours < 48) {
            return hours + ' hour' + (hours !== 1 ? 's' : '');
        } else if (days < 14) {
            return days + ' day' + (days !== 1 ? 's' : '');
        } else if (weeks < 8) {
            return weeks + ' week' + (weeks !== 1 ? 's' : '');
        } else if (months < 24) {
            return months + ' month' + (months !== 1 ? 's' : '');
        } else {
            return years + ' year' + (years !== 1 ? 's' : '');
        }
    }

    // Stack context menu - called onclick like Docker pattern
    function addStackContext(elementId, stackName, isRunning, webui) {
        debugLog('addStackContext called:', elementId, stackName, isRunning, 'webui:', webui);

        var dropdownId = 'dropdown-' + elementId;
        var $dropdown = $('#' + dropdownId);
        if ($dropdown.length && $dropdown.is(':visible')) {
            debugLog('Menu already open, closing');
            $dropdown.hide();
            return;
        }

        var opts = [];
        context.settings({right:false, above:false});

        if (isRunning) {
            if (webui && webui !== '') {
                opts.push({text:'WebUI', icon:'fa-globe', action:function(e){
                    e.preventDefault();
                    window.open(webui, '_blank');
                }});
            }
            opts.push({text:'Logs', icon:'fa-file-text-o', action:function(e){
                e.preventDefault();
                openStackLogs(stackName);
            }});
            opts.push({divider:true});
            opts.push({text:'Stop', icon:'fa-stop', action:function(e){
                e.preventDefault();
                stackAction(stackName, 'stop');
            }});
            opts.push({text:'Restart', icon:'fa-refresh', action:function(e){
                e.preventDefault();
                stackAction(stackName, 'restart');
            }});
        } else {
            opts.push({text:'Start', icon:'fa-play', action:function(e){
                e.preventDefault();
                stackAction(stackName, 'start');
            }});
        }
        opts.push({divider:true});
        opts.push({text:'Open Compose', icon:'fa-cubes', action:function(e){
            e.preventDefault();
            location.href = window.composeDashPage || '/Compose';
        }});

        context.destroy('#' + elementId);
        context.attach('#' + elementId, opts);
        debugLog('Context menu attached to #' + elementId);
    }

    function composeDashboardActionBoxClosed() {
        stackContainerCache = {};
        loadComposeStacks();
    }

    window.composeDashboardActionBoxClosed = composeDashboardActionBoxClosed;

    function dashboardActionError(stackName, message) {
        stackActionErrors[stackName] = message;
        console.error('[ComposeDash] ' + stackName + ': ' + message);
        $('#compose_stacks_status').text(stackName + ': ' + message);
    }

    function dashboardStackPath(stackName) {
        var path = stackPaths[stackName];
        if (typeof path !== 'string' || path.charAt(0) !== '/') {
            dashboardActionError(stackName, 'Stack path is unavailable. Refresh the Dashboard before retrying.');
            return null;
        }
        return path;
    }

    function stackAction(stackName, action) {
        if (stackOperations[stackName]) return;
        var projectPath = dashboardStackPath(stackName);
        if (!projectPath) return;
        var actionMap = {
            'start': 'composeUp',
            'stop': 'composeStop',
            'restart': 'composeUpRecreate'
        };
        var apiAction = actionMap[action] || ('compose' + action.charAt(0).toUpperCase() + action.slice(1));
        var actionTitle = action.charAt(0).toUpperCase() + action.slice(1);
        var $status = $('#compose_stacks_status');
        $status.text(actionTitle + 'ing ' + stackName + '...');
        var operation = {};
        stackOperations[stackName] = operation;
        delete stackActionErrors[stackName];

        $.ajax({
            url: '/plugins/compose.manager/include/ComposeUtil.php',
            method: 'POST',
            timeout: 30000,
            data: {
                action: apiAction,
                path: projectPath,
                trackOperation: '1'
            }
        }).done(function(data) {
            if (stackOperations[stackName] !== operation) return;
            var response;
            try {
                response = typeof data === 'string' ? JSON.parse(data) : data;
            } catch (error) {
                delete stackOperations[stackName];
                dashboardActionError(stackName, 'Invalid action response. Refresh to check whether the operation started.');
                return;
            }
            if (!response || response.error || !response.operations || !response.operations[stackName] ||
                !(response.background === true || typeof response.url === 'string' &&
                /^\/plugins\/compose\.manager\/include\/ShowTtyd\.php(?:\?|$)/.test(response.url))) {
                delete stackOperations[stackName];
                dashboardActionError(stackName, response && response.message || 'The server did not confirm operation tracking. Refresh to check its status.');
                return;
            }
            composePollOperation({
                endpoint: caURL,
                project: stackName,
                operationId: response.operations[stackName],
                isCurrent: function() { return stackOperations[stackName] === operation; },
                onComplete: function(exitCode) {
                    delete stackOperations[stackName];
                    if (exitCode !== 0) {
                        dashboardActionError(stackName, 'Action exited with code ' + exitCode + '. Check its command log.');
                    }
                    composeDashboardActionBoxClosed();
                },
                onError: function(message, details) {
                    console.error('[ComposeDash] ' + message, details);
                },
                onTimeout: function() {
                    delete stackOperations[stackName];
                    dashboardActionError(stackName, 'Unable to confirm completion. The operation may still be running.');
                    composeDashboardActionBoxClosed();
                }
            });
            if (response.warning) {
                dashboardActionError(stackName, response.message || 'The command is being tracked without a terminal viewer.');
            }
            if (response.background !== true) {
                if (typeof openBox === 'function') {
                    openBox(response.url, actionTitle + ' Stack ' + stackName, 800, 1200, true,
                        'composeDashboardActionBoxClosed', encodeURIComponent(stackName));
                } else {
                    Shadowbox.open({
                        content: response.url, player: 'iframe', title: actionTitle + ' Stack ' + stackName,
                        height: 800, width: 1200,
                        options: { onClose: function() {
                            composeDashboardActionBoxClosed(encodeURIComponent(stackName));
                        } }
                    });
                }
            }
        }).fail(function(xhr, status, error) {
            if (stackOperations[stackName] !== operation) return;
            delete stackOperations[stackName];
            dashboardActionError(stackName, 'Action request failed. Refresh to check whether it started.');
        });
    }

    function openStackLogs(stackName) {
        var projectPath = dashboardStackPath(stackName);
        if (!projectPath) return;
        $.post('/plugins/compose.manager/include/ComposeUtil.php', {
            action: 'composeLogs',
            path: projectPath
        }, function(data) {
            if (data) {
                var h = Math.min(screen.availHeight, 800);
                var w = Math.min(screen.availWidth, 1200);
                window.open(data, 'Logs_' + stackName.replace(/[^a-zA-Z0-9]/g, '_'),
                    'height=' + h + ',width=' + w + ',resizable=yes,scrollbars=yes');
            }
        });
    }

    function addContainerContext(elementId, ctName, ctId, isRunning, webui, shell) {
        debugLog('addContainerContext called:', elementId, ctName, isRunning);

        var dropdownId = 'dropdown-' + elementId;
        var $dropdown = $('#' + dropdownId);
        if ($dropdown.length && $dropdown.is(':visible')) {
            debugLog('Container menu already open, closing');
            $dropdown.hide();
            return;
        }

        var opts = [];
        context.settings({right:false, above:false});

        if (isRunning) {
            if (webui && webui !== '' && webui !== '#') {
                opts.push({text:'WebUI', icon:'fa-globe', action:function(e){
                    e.preventDefault();
                    window.open(webui, '_blank');
                }});
            }
            opts.push({text:'Console', icon:'fa-terminal', action:function(e){
                e.preventDefault();
                openDockerTerminal(ctName, false, shell || '/bin/bash');
            }});
            opts.push({text:'Logs', icon:'fa-file-text-o', action:function(e){
                e.preventDefault();
                openDockerTerminal(ctName, true);
            }});
            opts.push({divider:true});
            opts.push({text:'Stop', icon:'fa-stop', action:function(e){
                e.preventDefault();
                dockerAction(ctName, 'stop');
            }});
            opts.push({text:'Restart', icon:'fa-refresh', action:function(e){
                e.preventDefault();
                dockerAction(ctName, 'restart');
            }});
        } else {
            opts.push({text:'Start', icon:'fa-play', action:function(e){
                e.preventDefault();
                dockerAction(ctName, 'start');
            }});
        }

        context.destroy('#' + elementId);
        context.attach('#' + elementId, opts);
        debugLog('Container context menu attached to #' + elementId);
    }
    window.addContainerContext = addContainerContext;

    function dockerAction(ctName, action) {
        debugLog('dockerAction:', ctName, action);
        $.post(caURL, {
            action: 'containerAction',
            container: ctName,
            containerAction: action
        }, function() {
            if (!window.hideDockerComposeContainers && typeof window.loadlist === 'function') {
                window.loadlist();
            } else {
                loadComposeStacks();
            }
        });
    }

    function openDockerTerminal(name, isLogs, shell) {
        if (isLogs) {
            $.post('/plugins/compose.manager/include/ComposeUtil.php', {
                action: 'containerLogs',
                container: name
            }, function(data) {
                if (data) {
                    var h = Math.min(screen.availHeight, 800);
                    var w = Math.min(screen.availWidth, 1200);
                    window.open(data, 'Logs_' + name.replace(/[^a-zA-Z0-9]/g, '_'),
                        'height=' + h + ',width=' + w + ',resizable=yes,scrollbars=yes');
                }
            });
        } else {
            $.post('/plugins/compose.manager/include/ComposeUtil.php', {
                action: 'containerConsole',
                container: name,
                shell: shell || '/bin/bash'
            }, function(data) {
                if (data) {
                    var h = Math.min(screen.availHeight, 800);
                    var w = Math.min(screen.availWidth, 1200);
                    window.open(data, 'Console_' + name.replace(/[^a-zA-Z0-9]/g, '_'),
                        'height=' + h + ',width=' + w + ',resizable=yes,scrollbars=yes');
                }
            });
        }
    }

    function toggleStack(stackId, stackName) {
        var $containers = $('#compose-dash-ct-' + stackId);
        var $icon = $('#compose-dash-exp-' + stackId);

        if (expandedStacks[stackId]) {
            $containers.removeClass('expanded');
            $icon.removeClass('expanded');
            expandedStacks[stackId] = false;
        } else {
            $containers.addClass('expanded');
            $icon.addClass('expanded');
            expandedStacks[stackId] = true;

            if (!stackContainerCache[stackId]) {
                loadStackContainers(stackId, stackName);
            }
        }
    }

    function loadStackContainers(stackId, stackName) {
        var listGeneration = stackListGeneration;
        var generation = (stackDetailGeneration[stackId] || 0) + 1;
        stackDetailGeneration[stackId] = generation;
        var $container = $('#compose-dash-ct-' + stackId);
        $container.html('<div class="compose-dash-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</div>');

        function isCurrent() {
            return listGeneration === stackListGeneration && generation === stackDetailGeneration[stackId];
        }
        $.ajax({ url: caURL, method: 'POST', timeout: 30000,
            data: {action: 'getStackContainers', script: stackName}
        }).done(function(data) {
            if (!isCurrent()) return;
            try {
                var response = typeof data === 'string' ? JSON.parse(data) : data;
                if (!response || response.result !== 'success' || !Array.isArray(response.containers)) {
                    throw new Error('Invalid container snapshot');
                }
                if (response.containers && response.containers.length > 0) {
                    var html = '';
                    response.containers.forEach(function(ct) {
                        var ctName = ct.Name || ct.name || ct.Service || ct.service || 'unknown';
                        var ctState = ct.State || ct.state || '';
                        var ctIdRaw = ct.ID || ct.Id || ct.id || ctName;
                        var ctId = String(ctIdRaw || ctName);
                        var ctIdShort = ctId.substring(0, 12);
                        var ctIcon = ct.Icon || ct.icon || '/plugins/compose.manager/images/question.png';
                        var ctShell = ct.Shell || ct.shell || '/bin/bash';
                        var ctWebUI = ct.WebUI || ct.webUI || ct.webui || '';
                        var ctImage = ct.Image || ct.image || '';
                        var ctUpdateStatus = ct.UpdateStatus || ct.updateStatus || 'unknown';
                        var ctStartedAt = ct.StartedAt || ct.startedAt || '';

                        var isRunning = ctState === 'running';
                        var stateIcon = isRunning ? 'fa-play' : 'fa-square';
                        var stateColor = isRunning ? 'green-text' : 'red-text';
                        var stateText = isRunning ? 'started' : 'stopped';
                        var ctElId = 'dash-ct-' + ctIdShort;
                        var imgSrc = composeIconSrc(ctIcon, ctName);
                        var shell = ctShell;
                        var shortId = ctIdShort;
                        var webui = resolveContainerWebUI(ctWebUI);
                        var image = ctImage;
                        var imageDisplay = image.replace(/^.*\//, '');

                        var updateHtml = composeUpdateBadge(Object.assign({}, ct, {
                            updateStatus: ctUpdateStatus,
                            hasUpdate: ct.hasUpdate || ctUpdateStatus === 'update-available'
                        }));

                        var ctUptime = formatUptime(ctStartedAt, isRunning);

                        html += '<div class="compose-dash-container">';
                        html += '<span class="compose-dash-ct-icon" id="' + ctElId + '" data-ct-name="' + composeEscapeAttr(ctName) + '" data-ct-id="' + composeEscapeAttr(shortId) + '" data-ct-running="' + (isRunning ? '1' : '0') + '" data-ct-webui="' + composeEscapeAttr(webui) + '" data-ct-shell="' + composeEscapeAttr(shell) + '">';
                        html += '<img src="' + composeEscapeAttr(imgSrc) + '" onerror="composeIconFallback(this)">';
                        html += '</span>';
                        html += '<span class="compose-dash-ct-info">';
                        html += '<div class="compose-dash-ct-name">' + composeEscapeHtml(ctName) + '</div>';
                        html += '<div class="compose-dash-ct-state ' + stateColor + '"><i class="fa ' + stateIcon + '"></i> ' + stateText + '</div>';
                        html += '</span>';
                        html += '<span class="compose-dash-ct-cols">';
                        html += '<span class="compose-dash-ct-update">' + updateHtml + '</span>';
                        html += '<span class="compose-dash-ct-image" title="' + image + '">' + imageDisplay + '</span>';
                        html += '<span class="compose-dash-ct-runtime">' + ctUptime + '</span>';
                        html += '</span>';
                        html += '</div>';
                    });
                    $container.html(html);

                    stackContainerCache[stackId] = true;
                } else {
                    $container.html('<div class="compose-dash-loading">No containers</div>');
                }
            } catch(e) {
                console.error('Error parsing containers:', e);
                $container.html('<div class="compose-dash-loading">Error loading containers</div>');
            }
        }).fail(function() {
            if (!isCurrent()) return;
            console.error('[ComposeDash] Failed to load containers for ' + stackName);
            $container.html('<div class="compose-dash-loading red-text">Failed to load containers. Collapse and expand to retry.</div>');
        });
    }

    function loadComposeStacks() {
        var generation = ++stackListGeneration;
        stackDetailGeneration = {};
        $.ajax({ url: '/plugins/compose.manager/include/DashboardStacks.php',
            method: 'POST', dataType: 'json', timeout: 30000
        }).done(function(data) {
            if (generation !== stackListGeneration) return;
            if (!data || !Array.isArray(data.stacks)) {
                console.error('[ComposeDash] Invalid stack snapshot', data);
                $('#compose_stacks_status').text('Stacks -- Invalid response; refresh to retry');
                return;
            }
            var statusText = 'Stacks -- Started: ' + data.started + ', Stopped: ' + data.stopped;
            if (data.partial > 0) statusText += ', Partial: ' + data.partial;
            Object.keys(stackActionErrors).forEach(function(project) {
                statusText += ' | ' + project + ': ' + stackActionErrors[project];
            });
            $('#compose_stacks_status').text(statusText);

            if (window.hideDockerComposeContainers && Array.isArray(data.composeContainerNames)) {
                scheduleComposeContainerHiding(data.composeContainerNames);
            }

            stackContainerCache = {};
            stackPaths = Object.create(null);

            var html = '';
            if (data.stacks.length === 0) {
                html = '<div class="compose-dash-loading">No compose stacks defined</div>';
            } else {
                data.stacks.forEach(function(stack, idx) {
                    stackPaths[stack.folder] = stack.path;
                    var stackId = 'stack-' + stack.folder.replace(/[^a-zA-Z0-9_-]/g, '_');
                    var state = stack.state;
                    var stateIcon = state === 'started' ? 'fa-play' :
                                   (state === 'partial' ? 'fa-exclamation-circle' : 'fa-square');
                    var stateColor = state === 'started' ? 'green-text' :
                                    (state === 'partial' ? 'orange-text' : 'red-text');
                    var stateText = state === 'partial' ? 'partial' : state;
                    var imgSrc = composeIconSrc(stack.icon);
                    var isRunning = state === 'started' || state === 'partial';

                    var updatePresentation = composeStackUpdatePresentation(stack.updateContainers);

                    var containerText = stack.running + '/' + stack.total;
                    var uptimeText = formatUptime(stack.startedAt, isRunning);
                    var webui = (stack.webui || '');
                    var stateClass = state === 'stopped' ? 'stopped' : 'started';
                    html += '<div class="compose-dash-stack outer stacks ' + stateClass + '" data-stackid="' + stackId + '" data-folder="' + composeEscapeAttr(stack.folder) + '">';
                    html += '<i class="fa fa-chevron-right compose-dash-expand" id="compose-dash-exp-' + stackId + '"></i>';
                    html += '<span class="compose-dash-icon" id="compose-dash-icon-' + stackId + '" data-stack-folder="' + composeEscapeAttr(stack.folder) + '" data-stack-running="' + (isRunning ? '1' : '0') + '" data-stack-webui="' + composeEscapeAttr(webui) + '"><img src="' + composeEscapeAttr(imgSrc) + '" onerror="composeIconFallback(this)"></span>';
                    html += '<span class="compose-dash-info">';
                    html += '<div class="compose-dash-name">' + $('<div>').text(stack.name).html() + '</div>';
                    html += '<div class="compose-dash-state ' + stateColor + '"><i class="fa ' + stateIcon + '"></i> ' + stateText + '</div>';
                    html += '</span>';
                    html += '<span class="compose-dash-cols">';
                    html += '<span class="compose-dash-col update">' + composeUpdatePresentationBadge(updatePresentation) + '</span>';
                    html += '<span class="compose-dash-col containers" title="Running/Total containers">' + containerText + '</span>';
                    html += '<span class="compose-dash-col runtime" title="Uptime">' + uptimeText + '</span>';
                    html += '</span>';
                    html += '</div>';
                    html += '<div class="compose-dash-containers" id="compose-dash-ct-' + stackId + '"></div>';
                });
            }

            $('#compose_dash_content').html(html);

            Object.keys(expandedStacks).forEach(function(stackId) {
                if (expandedStacks[stackId]) {
                    var $containers = $('#compose-dash-ct-' + stackId);
                    var $icon = $('#compose-dash-exp-' + stackId);
                    if ($containers.length) {
                        $containers.addClass('expanded');
                        $icon.addClass('expanded');
                        var folder = $containers.prev('.compose-dash-stack').data('folder');
                        if (folder) {
                            loadStackContainers(stackId, folder);
                        }
                    } else {
                        delete expandedStacks[stackId];
                    }
                }
            });

            $('#compose_dash_content').off('click', '.compose-dash-stack').on('click', '.compose-dash-stack', function(e) {
                if ($(e.target).closest('.compose-dash-icon').length) {
                    return;
                }

                var menuOpen = $('.dropdown-context:visible').length > 0;
                if (menuOpen && !$(e.target).closest('.compose-dash-expand').length) {
                    return;
                }

                var stackId = $(this).data('stackid');
                var folder = $(this).data('folder');
                window.composeToggleStack(stackId, folder);
            });

            var cookie = (typeof $.cookie === 'function' && $.cookie('unraid_settings')) ? JSON.parse($.cookie('unraid_settings')) : {};
            if (cookie.my_stacks === 'startedOnly') {
                $('.compose-dash-stack.stopped').hide();
            }
            noStacks();

            debugLog('Loaded', data.stacks.length, 'stacks, context menus attached via onclick');
        }).fail(function() {
            if (generation !== stackListGeneration) return;
            $('#compose_stacks_status').text('Stacks -- Error loading');
            $('#compose_dash_content').html('<div class="compose-dash-loading red-text">Failed to load stacks</div>');
        });
    }

    window.composeToggleStack = toggleStack;
    window.addStackContext = addStackContext;

    $(document).on('click', '.compose-dash-ct-icon[data-ct-name]', function(e) {
        e.stopPropagation();
        var $el = $(this);
        addContainerContext(
            $el.attr('id'),
            $el.data('ct-name'),
            $el.data('ct-id'),
            $el.data('ct-running') === '1' || $el.data('ct-running') === 1,
            $el.data('ct-webui') || '',
            $el.data('ct-shell') || '/bin/bash'
        );
    });

    $(document).on('click', '.compose-dash-icon[data-stack-folder]', function(e) {
        e.stopPropagation();
        var $el = $(this);
        addStackContext(
            $el.attr('id'),
            $el.data('stack-folder'),
            $el.data('stack-running') === '1' || $el.data('stack-running') === 1,
            $el.data('stack-webui') || ''
        );
    });

    $(document).on('contextmenu', '.compose-dash-stack', function(e) {
        var $icon = $(this).find('.compose-dash-icon').first();
        if ($icon.length) {
            e.preventDefault();
            e.stopPropagation();
            $icon.trigger($.Event('click', { pageX: e.pageX, pageY: e.pageY }));
        }
    });

    $(document).on('contextmenu', '.compose-dash-container', function(e) {
        var $icon = $(this).find('.compose-dash-ct-icon').first();
        if ($icon.length) {
            e.preventDefault();
            e.stopPropagation();
            $icon.trigger($.Event('click', { pageX: e.pageX, pageY: e.pageY }));
        }
    });

    function noStacks() {
        if ($('#compose_dash_content .compose-dash-stack:visible').length === 0 && $('#compose_dash_content .compose-dash-stack').length > 0) {
            if (!$('#no_stacks').length) {
                $('#compose_dash_content').append('<div id="no_stacks" class="compose-dash-loading">No stacks to display</div>');
            }
            $('#no_stacks').show();
        } else {
            $('#no_stacks').hide();
        }
    }
    window.noStacks = noStacks;

    var composeContainerNameSet = null;
    var hideContainersSetup = false;
    var composeHidingTimer = null;

    function setDockerViewVisibility(visible) {
        var $dockerView = $('#docker_view');
        if (!$dockerView.length) return;

        if (visible) {
            $dockerView.removeClass('compose-docker-hidden');
        } else {
            $dockerView.addClass('compose-docker-hidden');
        }
    }

    function scheduleComposeContainerHiding(containerNames) {
        clearTimeout(composeHidingTimer);
        setupComposeContainerHiding(containerNames);
        var attempts = 0;
        function tryHide() {
            composeHidingTimer = null;
            if (!window.hideDockerComposeContainers) return;
            doHideContainers();
            attempts++;
            if (attempts < 120) {
                composeHidingTimer = setTimeout(tryHide, 1000);
            }
        }
        composeHidingTimer = setTimeout(tryHide, 1000);
    }

    function setupComposeContainerHiding(containerNames) {
        if (!Array.isArray(containerNames)) return;

        debugLog('Setting up compose container hiding for:', containerNames);

        composeContainerNameSet = {};
        containerNames.forEach(function(n) { composeContainerNameSet[n.toLowerCase()] = true; });

        if (!$('#compose-hide-style').length) {
            $('head').append('<style id="compose-hide-style">.compose-hidden-container { display: none !important; } #docker_view.compose-docker-hidden { display: none !important; }</style>');
        }

        setDockerViewVisibility(false);

        if (!hideContainersSetup) {
            hideContainersSetup = true;
            $(document).ajaxComplete(function(event, xhr, settings) {
                if (settings && settings.url && settings.url.indexOf('DashboardApps') !== -1) {
                    debugLog('DashboardApps.php AJAX complete, scheduling container hiding');
                    setTimeout(doHideContainers, 50);
                    setTimeout(doHideContainers, 300);
                }
            });
        }

        doHideContainers();
        setTimeout(function() {
            setDockerViewVisibility(true);
        }, 250);
    }

    function doHideContainers() {
        if (!composeContainerNameSet) return;

        var $dockerView = $('#docker_view');
        if (!$dockerView.length) return;

        var hiddenCount = 0;
        $dockerView.find('span.outer.apps').each(function() {
            var $el = $(this);
            var name = extractContainerName($el);
            var hide = !!(name && composeContainerNameSet[name.toLowerCase()]);
            if ($el.hasClass('compose-hidden-container') !== hide) {
                debugLog('Hiding Docker tile container:', name);
                $el.toggleClass('compose-hidden-container', hide);
                hiddenCount++;
            }
        });

        if (hiddenCount > 0) {
            debugLog('Hidden', hiddenCount, 'compose containers from Docker tile');
            updateDockerTileCounts();
        }

        setDockerViewVisibility(true);
    }

    function extractContainerName($el) {
        var onclick = '';
        $el.find('[onclick]').each(function() {
            var attr = $(this).attr('onclick') || '';
            if (attr.indexOf('addDockerContainerContext') !== -1) { onclick = attr; return false; }
        });
        if (onclick) {
            var match = onclick.match(/addDockerContainerContext\(\s*'([^']+)'/);
            if (match) return match[1];
        }

        var $inner = $el.find('span.inner > span').first();
        if ($inner.length) {
            var t = $inner.text().trim();
            if (t) return t;
        }

        return null;
    }

    function updateDockerTileCounts() {
        var started = $('#docker_view').find('span.outer.apps.started').not('.compose-hidden-container').length;
        var stopped = $('#docker_view').find('span.outer.apps.stopped').not('.compose-hidden-container').length;
        var paused  = $('#docker_view').find('span.outer.apps.paused').not('.compose-hidden-container').length;
        $('.apps.switch').html('Containers -- Started: ' + started + ', Stopped: ' + stopped + ', Paused: ' + paused);
    }

    $(function() {
        var cookie = (typeof $.cookie === 'function' && $.cookie('unraid_settings')) ? JSON.parse($.cookie('unraid_settings')) : {};
        var $toggle = $('#compose_stacks_toggle');
        if (!$toggle.data('switchbutton-initialized')) {
            $toggle.switchButton({
                labels_placement: 'right',
                off_label: 'All Stacks',
                on_label: 'Started only',
                checked: cookie.my_stacks === 'startedOnly'
            });
            $toggle.data('switchbutton-initialized', true);
        }

        $('#compose_stacks_toggle').off('change.composedash').on('change.composedash', function() {
            $('.compose-dash-stack.stopped').finish().toggle('fast', function() { noStacks(); });
            var cookie = (typeof $.cookie === 'function' && $.cookie('unraid_settings')) ? JSON.parse($.cookie('unraid_settings')) : {};
            if ($('#compose_stacks_toggle').is(':checked')) {
                cookie.my_stacks = 'startedOnly';
            } else {
                delete cookie.my_stacks;
            }
            $.cookie('unraid_settings', JSON.stringify(cookie), {expires: 3650, path: '/'});
        });

        loadComposeStacks();
    });

    (function hookLoadlist() {
        var composeRefreshTimer = null;

        function wrapLoadlist() {
            if (typeof window.loadlist === 'function' && !window.loadlist._composeDashHooked) {
                var originalLoadlist = window.loadlist;
                window.loadlist = function() {
                    originalLoadlist.apply(this, arguments);
                    clearTimeout(composeRefreshTimer);
                    composeRefreshTimer = setTimeout(function() {
                        stackContainerCache = {};
                        loadComposeStacks();
                    }, 2000);
                };
                window.loadlist._composeDashHooked = true;
                debugLog('Hooked loadlist() for dashboard sync');
                return true;
            }
            return false;
        }

        if (!wrapLoadlist()) {
            var hookInterval = setInterval(function() {
                if (wrapLoadlist()) clearInterval(hookInterval);
            }, 1000);
            setTimeout(function() { clearInterval(hookInterval); }, 60000);
        }
    })();
})();
