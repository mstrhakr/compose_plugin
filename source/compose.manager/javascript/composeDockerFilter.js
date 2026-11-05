$(function() {
    var timer;
    var applyTimer;
    var inFlight = false;
    var refreshAgain = false;
    var names = new Set();
    var rowNames = new Set();
    var table = document.getElementById('docker_list');

    function namesChanged(previous, current) {
        return current.size !== previous.size ||
            Array.from(current).some(function(name) { return !previous.has(name); });
    }

    function applyFilter() {
        var currentNames = new Set();
        $('#docker_list tr.sortable').each(function() {
            var $row = $(this);
            var name = $row.find('td:first span.appname').first().text().trim();
            if (!name) name = $row.find('td:first').text().trim();
            if (name) currentNames.add(name);
            $row.toggleClass('compose-manager-hidden', names.has(name));
        });
        var changed = namesChanged(rowNames, currentNames);
        rowNames = currentNames;
        return changed;
    }

    function refresh() {
        var requestTable = document.getElementById('docker_list');
        if (!requestTable) return;
        if (inFlight) {
            refreshAgain = true;
            return;
        }
        applyFilter();
        var requestRowNames = rowNames;
        inFlight = true;
        $.post('/plugins/compose.manager/include/Exec.php', {
            action: 'getComposeContainerNames'
        }).done(function(data) {
            var response;
            try {
                response = typeof data === 'string' ? JSON.parse(data) : data;
            } catch (error) {
                console.error('[Compose] Invalid Docker filter response', error);
                return;
            }
            if (!response || response.result !== 'success' || !Array.isArray(response.names)) {
                console.error('[Compose] Unable to refresh Docker filter', response);
                return;
            }
            names = new Set(response.names);
            applyFilter();
            if (requestTable !== document.getElementById('docker_list') || namesChanged(requestRowNames, rowNames)) {
                refreshAgain = true;
            }
        }).fail(function(xhr, status, error) {
            console.error('[Compose] Docker filter request failed', status, error);
        }).always(function() {
            inFlight = false;
            if (refreshAgain) {
                refreshAgain = false;
                scheduleRefresh();
            }
        });
    }

    function scheduleRefresh() {
        clearTimeout(timer);
        timer = setTimeout(refresh, 300);
    }

    $(document).on('compose-list-loaded compose-stack-removed', scheduleRefresh);
    new MutationObserver(function(records) {
        var currentTable = document.getElementById('docker_list');
        if (currentTable !== table) {
            table = currentTable;
            scheduleRefresh();
        } else if (table && records.some(function(record) {
            return $(record.target).closest('#docker_list').length;
        })) {
            clearTimeout(applyTimer);
            applyTimer = setTimeout(function() {
                if (applyFilter()) scheduleRefresh();
            }, 300);
        }
    }).observe(document.body, { childList: true, subtree: true });
    refresh();
});
