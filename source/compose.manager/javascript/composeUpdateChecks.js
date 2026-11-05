// Request and retry ownership for the Compose Manager page (not the dashboard).
var composeUpdateCheckState = Object.create(null);
var composeUpdateCheckRevision = Object.create(null);

var composeUpdateChecks = (function() {
    var owners = Object.create(null);
    var generations = Object.create(null);
    var deleted = Object.create(null);
    var pending = new Set();
    var scheduled = new Set();
    var clock = 0;
    var savedRequest = 0;
    var bulkRequest = 0;

    function parse(data) {
        return typeof data === 'string' ? JSON.parse(data) : data;
    }

    function row(project) {
        return $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
    }

    function busy(project) {
        return !!composeStackActionInProgress[project];
    }

    function current(project, owner) {
        return owners[project] === owner && !busy(project) && !deleted[project];
    }

    function setState(project, state) {
        if (state) composeUpdateCheckState[project] = state;
        else delete composeUpdateCheckState[project];
        renderComposeUpdateCheckState(project);
    }

    function claim(project) {
        var owner = {};
        generations[project] = ++clock;
        owners[project] = owner;
        pending.delete(project);
        setState(project, 'checking');
        return owner;
    }

    function retry(project, owner) {
        if (!current(project, owner)) return;
        pending.add(project);
        setState(project, null);
        updateStackUpdateUI(project, stackUpdateStatus[project] || { containers: [] });
    }

    function failure(project, owner, message) {
        if (!current(project, owner)) return;
        pending.add(project);
        composeLogger('Update check failed', { stackName: project, message: message }, 'user', 'error', 'update-check');
        setState(project, 'failed');
    }

    function apply(project, info, fresh) {
        mergeStackUpdateStatus(info, stackUpdateStatus[project], true);
        stackUpdateStatus[project] = info;
        if (fresh) {
            delete composeUpdateCheckState[project];
            composeUpdateCheckRevision[project] = (composeUpdateCheckRevision[project] || 0) + 1;
            delete stackDetailsPrefetchCache[project];
        }
        updateStackUpdateUI(project, info);
    }

    function acknowledge(project, owner, token) {
        owner.acknowledging = true;
        function failed() {
            if (!current(project, owner)) return;
            owner.acknowledging = false;
            pending.add(project);
            composeLogger('Unable to acknowledge image recheck', { stack: project }, 'user', 'error', 'update-check');
            swal({ title: 'Image check completed', text: 'The result was received, but its pending recheck could not be acknowledged. Refresh or check again to retry.', type: 'warning' });
        }
        var tokens = Object.create(null);
        tokens[project] = token;
        $.ajax({
            url: caURL, method: 'POST', timeout: 30000,
            data: { action: 'clearStackRecheck', stacks: JSON.stringify([project]), tokens: JSON.stringify(tokens) }
        }).done(function(data) {
            if (!current(project, owner)) return;
            try {
                var response = parse(data);
                if (!response || response.result !== 'success' || !Array.isArray(response.remaining)) {
                    failed();
                } else {
                    owner.acknowledging = false;
                    // Pending-queue reads started before this ack must not requeue its old token.
                    generations[project] = ++clock;
                    if (response.remaining.some(function(name) { return String(name) === project; })) pending.add(project);
                    else pending.delete(project);
                }
            } catch (e) { failed(); }
        }).fail(failed);
    }

    function single(project) {
        if (deleted[project]) return;
        if (!row(project).length || busy(project)) {
            pending.add(project);
            return;
        }
        var owner = claim(project);
        $.post(caURL, { action: 'checkStackUpdates', script: project }, function(data) {
            if (!current(project, owner)) return;
            try {
                var response = parse(data);
                if (response && response.result === 'superseded') {
                    retry(project, owner);
                    return;
                }
                if (!response || response.result !== 'success' || !Array.isArray(response.updates)
                    || !(response.recheckToken === null || typeof response.recheckToken === 'string'
                        && /^[a-f0-9]{64}$/.test(response.recheckToken))) {
                    failure(project, owner, response && response.message || 'The server did not return an update result.');
                    return;
                }
                apply(project, createStackInfo(project, response.updates, { projectName: response.projectName }), true);
                updateUpdateAllButton();
                acknowledge(project, owner, response.recheckToken);
            } catch (e) { failure(project, owner, String(e)); }
        }).fail(function(xhr, status, error) { failure(project, owner, error || status); });
    }

    function bulk() {
        var request = ++bulkRequest;
        var start = clock;
        var requests = Object.create(null);
        var deferred = new Set();
        $('#checkUpdatesBtn').prop('disabled', true).val('Checking...');
        $('#updateAllBtn').prop('disabled', true);
        function reserve(project) {
            if (deleted[project] || Object.prototype.hasOwnProperty.call(requests, project) || deferred.has(project)) return;
            if (busy(project) || !row(project).length) {
                deferred.add(project);
                pending.add(project);
            } else {
                requests[project] = claim(project);
            }
        }
        $('#compose_stacks tr.compose-sortable').each(function() { reserve(String($(this).data('project'))); });
        Object.keys(stackUpdateStatus).forEach(reserve);
        Array.from(pending).forEach(reserve);
        // Include newly discovered rows only if no intervening request/action owns them.
        function ownerFor(project) {
            if (!Object.prototype.hasOwnProperty.call(requests, project)
                && request === bulkRequest && !deferred.has(project) && !deleted[project]
                && (generations[project] || 0) <= start) reserve(project);
            return requests[project];
        }
        function finish(hasFailure) {
            if (request === bulkRequest) $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
            updateUpdateAllButton();
            if (hasFailure && request === bulkRequest) $('#updateAllBtn').prop('disabled', true);
        }
        function failed(message) {
            var hasFailure = false;
            Object.keys(requests).forEach(function(project) {
                if (current(project, requests[project])) hasFailure = true;
                failure(project, requests[project], message);
            });
            finish(hasFailure);
        }
        $.post(caURL, { action: 'checkAllStacksUpdates' }, function(data) {
            try {
                var response = parse(data);
                if (!response || response.result !== 'success' || !response.stacks || typeof response.stacks !== 'object') {
                    failed(response && response.message || 'The server did not return update results.');
                    return;
                }
                var superseded = new Set(Array.isArray(response.superseded) ? response.superseded.map(String) : []);
                superseded.forEach(function(project) {
                    var owner = ownerFor(project);
                    if (owner) retry(project, owner);
                });
                Object.keys(response.stacks).forEach(function(project) {
                    var owner = ownerFor(project);
                    if (owner && !superseded.has(project) && current(project, owner)) apply(project, response.stacks[project], true);
                });
                Object.keys(requests).forEach(function(project) {
                    if (!Object.prototype.hasOwnProperty.call(response.stacks, project) && !superseded.has(project)) {
                        retry(project, requests[project]);
                    }
                });
                finish();
            } catch (e) { failed(String(e)); }
        }).fail(function() { failed('The update check request failed.'); });
    }

    function pendingChecks(callback, start) {
        if (start === undefined) start = clock;
        function failed(message) {
            composeLogger('Unable to read pending image rechecks', { message: message }, 'user', 'error', 'update-check');
            swal({ title: 'Pending image checks unavailable', text: 'Could not load pending rechecks. Refresh to retry; saved evidence has not been cleared.', type: 'warning' });
            // An unreadable queue is not an empty queue: do not launch an automatic bulk check.
            if (callback) callback(true);
        }
        $.post(caURL, { action: 'getPendingRecheckStacks' }, function(data) {
            try {
                var response = parse(data);
                if (!response || response.result !== 'success' || !response.pending || typeof response.pending !== 'object') {
                    failed(response && response.message);
                    return;
                }
                var projects = Object.keys(response.pending);
                projects.forEach(function(project) {
                    if (!deleted[project] && (generations[project] || 0) <= start
                        && composeUpdateCheckState[project] !== 'checking'
                        && !(owners[project] && owners[project].acknowledging)) {
                        pending.add(project);
                        drain(project);
                    }
                });
                if (callback) callback(projects.length > 0);
            } catch (e) { failed(String(e)); }
        }).fail(function(xhr, status, error) { failed(error || status); });
    }

    function auto(stacks) {
        if (!autoCheckUpdates) return;
        var now = Math.floor(Date.now() / 1000);
        var intervalSeconds = autoCheckDays * 24 * 60 * 60;
        var latestCheck = 0;
        Object.keys(stacks).forEach(function(project) {
            if (stacks[project].lastChecked > latestCheck) latestCheck = stacks[project].lastChecked;
        });
        if (latestCheck > 0 && now - latestCheck < intervalSeconds) {
            composeLogger('Last check was ' + Math.round((now - latestCheck) / 60) + ' minutes ago, interval is ' +
                Math.round(intervalSeconds / 60) + ' minutes. Skipping.', null, 'user', 'debug', 'update-check');
            return;
        }
        composeLogger('Running automatic update check...', null, 'user', 'debug', 'update-check');
        bulk();
    }

    function saved() {
        var request = ++savedRequest;
        var start = clock;
        function followUp(stacks) {
            if (request !== savedRequest) return;
            pendingChecks(function(hadPending) {
                if (request !== savedRequest || start !== clock || hadPending || !autoCheckUpdates) return;
                if (stacks) auto(stacks);
                else bulk();
            }, start);
        }
        $.post(caURL, { action: 'getSavedUpdateStatus' }, function(data) {
            if (request !== savedRequest) return;
            try {
                var response = parse(data);
                if (!response || response.result !== 'success' || !response.stacks || typeof response.stacks !== 'object') {
                    if (start === clock) composeLogger('Unable to load saved update status', {}, 'user', 'error', 'update-check');
                    followUp();
                    return;
                }
                Object.keys(response.stacks).forEach(function(project) {
                    // Cache hydration never takes ownership from a fresh check, even a completed one.
                    if (!generations[project] && !busy(project) && !deleted[project]) apply(project, response.stacks[project], false);
                });
                if (response.dockerVersionsInstalled) dockerVersionsInstalled = true;
                savedUpdateStatusLoaded = true;
                updateUpdateAllButton();
                followUp(response.stacks);
            } catch (e) {
                if (start === clock) composeLogger('Failed to load saved update status', { error: String(e) }, 'user', 'error', 'update-check');
                followUp();
            }
        }).fail(function(xhr, status, error) {
            if (request !== savedRequest || start !== clock) return;
            savedUpdateStatusLoaded = false;
            composeLogger('Failed to load saved update status', { status: status, error: error }, 'user', 'error', 'update-check');
        });
    }

    function drain(project) {
        if (!project || !pending.has(project) || scheduled.has(project) || busy(project)
            || deleted[project] || !row(project).length || composeUpdateCheckState[project] === 'checking') return;
        var generation = generations[project] || 0;
        scheduled.add(project);
        setTimeout(function() {
            scheduled.delete(project);
            if (generation !== (generations[project] || 0) || !pending.has(project)) return;
            single(project);
        }, 0);
    }

    function invalidate(project, removed) {
        var hadState = !!composeUpdateCheckState[project];
        if (!removed && composeUpdateCheckState[project] === 'checking') pending.add(project);
        generations[project] = ++clock;
        delete owners[project];
        delete composeUpdateCheckState[project];
        if (removed) {
            deleted[project] = true;
            pending.delete(project);
            composeUpdateCheckRevision[project] = (composeUpdateCheckRevision[project] || 0) + 1;
        } else if (hadState) {
            updateStackUpdateUI(project, stackUpdateStatus[project] || { containers: [] });
        }
    }

    function rowCommitted(project) {
        if (!deleted[project] || !row(project).length) return;
        // Only a committed row may reactivate a deleted name; old callbacks keep their old owner.
        delete deleted[project];
        generations[project] = ++clock;
        delete owners[project];
    }

    return {
        single: single, bulk: bulk, saved: saved, pending: pendingChecks, auto: auto,
        drain: drain,
        drainAll: function() { Array.from(pending).forEach(drain); },
        invalidate: invalidate, rowCommitted: rowCommitted
    };
})();
