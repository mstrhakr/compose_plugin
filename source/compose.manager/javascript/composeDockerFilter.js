$(function() {
    var timer;
    var applyTimer;
    var inFlight = false;
    var refreshAgain = false;
    var names = new Set();
    var rowNames = new Set();
    var table = document.getElementById('docker_list');

    function applyFilter() {
        var currentNames = new Set();
        $('#docker_list tr.sortable').each(function() {
            var $row = $(this);
            var name = $row.find('td:first span.appname').first().text().trim();
            if (!name) name = $row.find('td:first').text().trim();
            if (name) currentNames.add(name);
            $row.toggleClass('compose-manager-hidden', names.has(name));
        });
        var changed = currentNames.size !== rowNames.size ||
            Array.from(currentNames).some(function(name) { return !rowNames.has(name); });
        rowNames = currentNames;
        return changed;
    }

    function refresh() {
        if (!document.getElementById('docker_list')) return;
        if (inFlight) {
            refreshAgain = true;
            return;
        }
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
