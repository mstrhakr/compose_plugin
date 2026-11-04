$(function() {
    var timer;
    var inFlight = false;
    var refreshAgain = false;

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
            var names = new Set(response.names);
            $('#docker_list tr.sortable').each(function() {
                var $row = $(this);
                var name = $row.find('td:first span.appname').first().text().trim();
                if (!name) name = $row.find('td:first').text().trim();
                $row.toggleClass('compose-manager-hidden', names.has(name));
            });
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

    $(document).on('compose-list-loaded', scheduleRefresh);
    new MutationObserver(function(records) {
        if (records.some(function(record) {
            return $(record.target).closest('#docker_list').length || Array.from(record.addedNodes).some(function(node) {
                return node.nodeType === 1 && (node.id === 'docker_list' || node.querySelector('#docker_list'));
            });
        })) {
            scheduleRefresh();
        }
    }).observe(document.body, { childList: true, subtree: true });
    refresh();
});
