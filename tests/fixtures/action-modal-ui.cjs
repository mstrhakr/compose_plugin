const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const main = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeManagerMain.js`, 'utf8');
const dashboard = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeDashboard.js`, 'utf8');
const operations = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeOperations.js`, 'utf8');

function load(context, source, names, indent = '') {
    vm.createContext(context);
    vm.runInContext(operations, context);
    for (const name of names) {
        const match = source.match(new RegExp(`^${indent}function ${name}\\([^]*?\\n${indent}\\}`, 'm'));
        assert.ok(match, `Missing ${name}`);
        vm.runInContext(match[0], context);
    }
}

function harness(response) {
    const events = [];
    const timers = [];
    const context = {
        caURL: 'exec', compURL: 'compose', pendingComposeReloadStacks: [], composeStackActionInProgress: {},
        composeActionStateText: () => 'working',
        basename: path => path.split('/').pop(),
        composeIdentityBlocked: () => false,
        tryParseJson: data => { try { return JSON.parse(data); } catch { return null; } },
        setStackActionInProgress: (project, busy, text, owner) => {
            events.push(['busy', project, busy]);
            if (busy) return context.composeStackActionInProgress[project] = {};
            if (!owner || context.composeStackActionInProgress[project] === owner) {
                delete context.composeStackActionInProgress[project];
            }
        },
        pollBackgroundCompletion: project => events.push(['poll', project]),
        refreshStackByProject: project => events.push(['refresh', project]),
        checkPendingRechecks: () => events.push(['rechecks']),
        openBox: (...args) => events.push(['modal', ...args]),
        notifyBackgroundStarted: () => events.push(['notification']),
        composeLogger: () => {},
        swal: () => events.push(['error']),
        setTimeout: fn => timers.push(fn),
        $: () => ({ text: () => {} }),
    };
    context.$.ajax = options => {
        events.push(['request', options.data]);
        return {
            done(callback) { callback(response); return this; },
            fail() { return this; },
        };
    };
    load(context, main, ['composeActionBoxClosed', 'openComposeActionBox', 'performComposeAction',
        'performMultipleComposeAction', 'executeStartAllStacks', 'executeStopAllStacks', 'ComposeRecreateConfirmed']);
    return { context, events, timers };
}

{
    const url = '/plugins/compose.manager/include/ShowTtyd.php?done=1';
    const project = 'stack "quoted", \u00fc';
    const response = JSON.stringify({ url, operations: { [project]: 'receipt-one' } });
    const { context, events } = harness(response);
    context.performComposeAction({ stackName: project, actionName: 'up' });
    const modal = events.find(event => event[0] === 'modal');
    assert.equal(modal[1], url);
    assert.equal(modal[6], 'composeActionBoxClosed');
    assert.deepEqual(events.filter(event => event[0] === 'poll'), [['poll', project]]);
    assert.ok(!events.some(event => event[0] === 'busy' && event[2] === false));
    const before = events.length;
    context[modal[6]](modal[7]);
    assert.deepEqual(events.slice(before), [['refresh', project]]);
    // Closing before completion does not clear busy state or cancel the independent poll.
    assert.ok(!events.some(event => event[0] === 'busy' && event[2] === false));
}

for (const name of ['executeStartAllStacks', 'executeStopAllStacks']) {
    const { context, events } = harness(JSON.stringify({
        url: '/plugins/compose.manager/include/ShowTtyd.php',
        operations: { one: 'receipt-one', two: 'receipt-two' },
    }));
    context[name]({ stacks: [{ project: 'one', path: '/one' }, { project: 'two', path: '/two' }] });
    assert.deepEqual(events.filter(event => event[0] === 'poll'), [['poll', 'one'], ['poll', 'two']]);
    const modal = events.find(event => event[0] === 'modal');
    context[modal[6]](modal[7]);
    assert.deepEqual(events.filter(event => event[0] === 'refresh'), [['refresh', 'one'], ['refresh', 'two']]);
}

{
    const { context, events } = harness(JSON.stringify({ background: true }));
    context.performComposeAction({ stackName: 'one', background: true });
    assert.ok(events.some(event => event[0] === 'poll'));
    assert.ok(!events.some(event => event[0] === 'modal'));
}
{
    const { context, events, timers } = harness(JSON.stringify({
        url: '/plugins/compose.manager/include/ShowTtyd.php', operations: { one: 'receipt-one' },
    }));
    context.performComposeAction({ stackName: 'one' });
    const operation = context.composeStackActionInProgress.one;
    let receipt = { state: 'running' };
    context.$.ajax = () => ({
        done(callback) {
            callback({ result: 'success', operation: receipt });
            return this;
        },
        fail() { return this; },
        always(callback) { callback(); return this; },
    });
    context.Date = Date;
    load(context, main, ['pollBackgroundCompletion']);
    context.pollBackgroundCompletion('one', 0, operation);
    timers.shift()();
    assert.ok(!events.some(event => event[0] === 'busy' && event[2] === false));
    context.composeActionBoxClosed('one');
    assert.equal(timers.length, 1, 'Closing a running action preserves its next poll');
    receipt = { state: 'completed', exitCode: 0 };
    timers.shift()();
    assert.ok(events.some(event => event[0] === 'busy' && event[2] === false));
    const refreshes = events.filter(event => event[0] === 'refresh').length;
    timers.shift()();
    assert.equal(events.filter(event => event[0] === 'refresh').length, refreshes + 1,
        'Completion refresh still happens after early close');
}
{
    const { context, events } = harness(JSON.stringify({ error: 'refused', message: 'Not safe' }));
    context.performComposeAction({ stackName: 'one' });
    assert.ok(events.some(event => event[0] === 'error'));
    assert.ok(!events.some(event => event[0] === 'modal' || event[0] === 'poll'));
}
{
    const { context } = harness('/terminal.php');
    let options;
    context.confirmedComposeAction = (path, opts) => { options = { path, ...opts }; };
    context.ComposeRecreateConfirmed('/one', 'profile');
    assert.equal(options.payload.action, 'composeUpRecreate');
    assert.equal(options.payload.profile, 'profile');
    assert.equal(options.path, '/one');
}
{
    const { context, events } = harness('/terminal.php');
    context.setStackActionInProgress = () => {};
    context.performMultipleComposeAction = (stacks, opts) => {
        assert.deepEqual(Array.from(opts.stackNames), ['one', 'two']);
        stacks.forEach(stack => context.pollBackgroundCompletion(stack.project));
    };
    context.$.ajax = () => ({
        done(callback) { callback({ result: 'success' }); return this; },
        fail() { return this; },
    });
    load(context, main, ['executeUpdateAllStacks', 'prepareStackRechecks']);
    context.executeUpdateAllStacks([{ project: 'one', path: '/one', isRunning: true },
        { project: 'two', path: '/two', isRunning: true }], false);
    assert.deepEqual(events.filter(event => event[0] === 'poll'), [['poll', 'one'], ['poll', 'two']]);
}

{
    const checked = [], timers = [];
    const context = {
        pendingUpdateCheckStacks: new Set(['queued', 'running']),
        composeStackActionInProgress: { queued: {}, running: {} },
        checkStackUpdates: project => checked.push(project),
        setTimeout: fn => timers.push(fn),
    };
    load(context, main, ['processPendingUpdateCheckForStack', 'processPendingUpdateChecks']);
    context.processPendingUpdateChecks();
    assert.equal(timers.length, 0, 'Queued and running operations retain pending rechecks');
    assert.deepEqual(Array.from(context.pendingUpdateCheckStacks), ['queued', 'running']);
    delete context.composeStackActionInProgress.running;
    context.processPendingUpdateChecks();
    context.processPendingUpdateChecks();
    assert.equal(timers.length, 1, 'Multiple drains schedule one check for the finished stack');
    context.composeStackActionInProgress.running = {};
    timers.shift()();
    assert.deepEqual(checked, [], 'An operation starting before the deferred drain blocks its check');
    delete context.composeStackActionInProgress.running;
    context.processPendingUpdateChecks();
    timers.shift()();
    assert.deepEqual(checked, ['running']);
    assert.deepEqual(Array.from(context.pendingUpdateCheckStacks), ['queued']);
}

for (const size of [100, 200]) {
    let lookups = 0;
    const pending = new Set(Array.from({ length: size }, (_, i) => 'busy-' + i));
    const originalHas = pending.has.bind(pending);
    pending.has = project => { lookups++; return originalHas(project); };
    const context = {
        pendingUpdateCheckStacks: pending,
        composeStackActionInProgress: Object.fromEntries(Array.from(pending, project => [project, {}])),
        setTimeout() { throw new Error('Busy operations must not schedule image checks'); },
    };
    load(context, main, ['processPendingUpdateCheckForStack', 'processPendingUpdateChecks']);
    context.processPendingUpdateChecks();
    assert.equal(lookups, size, 'A complete busy-queue drain performs one constant-time membership lookup per stack');
    assert.equal(pending.size, size, 'Busy stacks retain all pending checks');
}

for (const native of [true, false]) {
    const events = [];
    let receipt = { state: 'running' };
    let actionResponse = {
        url: '/plugins/compose.manager/include/ShowTtyd.php', operations: { one: 'receipt-one' },
    };
    const context = {
        window: {}, stackContainerCache: { stale: true }, caURL: 'exec',
        stackOperations: {}, stackActionErrors: {}, stackPaths: { one: '/configured/projects/one' },
        console: { error: (...args) => events.push(['error', ...args]) },
        loadComposeStacks: () => events.push(['reload']),
        setTimeout: fn => events.push(['timer', fn]),
        Shadowbox: { open: opts => events.push(['fallback', opts]) },
        $: () => ({ text: () => {} }),
    };
    if (native) context.openBox = (...args) => events.push(['modal', ...args]);
    const ajax = options => ({
        done(callback) {
            if (options.data.action !== 'getUiOperation') {
                assert.equal(options.data.trackOperation, '1');
                assert.equal(options.data.path, '/configured/projects/one');
            }
            callback(options.data.action === 'getUiOperation' ? { result: 'success', operation: receipt } : actionResponse);
            return this;
        },
        fail() { return this; },
        always(callback) { callback(); return this; },
    });
    context.$.ajax = ajax;
    load(context, dashboard, ['composeDashboardActionBoxClosed', 'dashboardActionError', 'dashboardStackPath', 'stackAction', 'openStackLogs'], '    ');
    const exportLine = dashboard.match(/^    window\.composeDashboardActionBoxClosed = .*;$/m);
    assert.ok(exportLine);
    vm.runInContext(exportLine[0], context);
    context.stackAction('one', 'start');
    if (native) {
        const modal = events.find(event => event[0] === 'modal');
        assert.equal(modal[6], 'composeDashboardActionBoxClosed');
        context.window[modal[6]](modal[7]);
    } else {
        events.find(event => event[0] === 'fallback')[1].options.onClose();
    }
    assert.ok(events.some(event => event[0] === 'reload'));
    assert.equal(Object.keys(context.stackContainerCache).length, 0);
    assert.ok(context.stackOperations.one, 'Early close does not complete the operation');
    const owner = context.stackOperations.one;
    context.stackAction('one', 'stop');
    assert.equal(context.stackOperations.one, owner, 'Repeated actions preserve the accepted operation owner');
    const runTimer = () => {
        const index = events.findIndex(event => event[0] === 'timer');
        assert.ok(index >= 0);
        events.splice(index, 1)[0][1]();
    };
    runTimer();
    assert.ok(context.stackOperations.one, 'Running receipt schedules another completion check');
    const reloads = events.filter(event => event[0] === 'reload').length;
    receipt = { state: 'completed', exitCode: 0 };
    runTimer();
    assert.equal(context.stackOperations.one, undefined);
    assert.equal(events.filter(event => event[0] === 'reload').length, reloads + 1,
        'Completion refresh occurs even when the terminal was closed early');
    const opened = events.filter(event => ['modal', 'fallback'].includes(event[0])).length;
    for (const response of ['{', { error: 'identity', message: 'Identity blocked' }, {
        url: '/plugins/compose.manager/include/ShowTtyd.php',
    }]) {
        context.$.ajax = () => ({
            done(callback) { callback(response); return this; },
            fail() { return this; },
        });
        context.stackAction('one', 'start');
        assert.ok(context.stackActionErrors.one, 'Refusals and missing tracking evidence are reported');
        assert.equal(context.stackOperations.one, undefined);
    }
    assert.equal(events.filter(event => ['modal', 'fallback'].includes(event[0])).length, opened,
        'Invalid dispatches never open a terminal');
    context.$.ajax = () => ({
        done() { return this; },
        fail(callback) { callback({}, 'error', 'network'); return this; },
    });
    context.stackAction('one', 'start');
    assert.match(context.stackActionErrors.one, /request failed/);
    assert.equal(context.stackOperations.one, undefined);
    context.$.ajax = ajax;
    receipt = { state: 'completed', exitCode: 7 };
    context.stackAction('one', 'restart');
    runTimer();
    assert.match(context.stackActionErrors.one, /code 7/);
    assert.equal(context.stackOperations.one, undefined);
    actionResponse = { background: true, warning: true, message: 'Viewer unavailable', operations: { one: 'receipt-one' } };
    receipt = { state: 'running' };
    context.stackAction('one', 'start');
    assert.ok(context.stackOperations.one, 'Viewer failure preserves Dashboard receipt ownership');
    assert.match(context.stackActionErrors.one, /Viewer unavailable/);
    runTimer();
    assert.ok(context.stackOperations.one, 'Viewer failure keeps polling until actual completion');
    receipt = { state: 'completed', exitCode: 0 };
    runTimer();
    assert.equal(context.stackOperations.one, undefined);
    const logRequests = [];
    context.$.post = (url, data) => logRequests.push(data);
    context.openStackLogs('one');
    assert.equal(logRequests[0].path, '/configured/projects/one', 'Logs use the same configured metadata path as actions');
    delete context.stackPaths.one;
    context.$.ajax = () => { throw new Error('Missing paths must never dispatch'); };
    context.stackAction('one', 'start');
    context.openStackLogs('one');
    assert.match(context.stackActionErrors.one, /Stack path is unavailable/);
    assert.equal(logRequests.length, 1, 'Missing paths cannot fall back to the default root');
}

{
    const requests = [], writes = [];
    const element = {
        length: 1, text(value) { writes.push(value); return this; },
        html(value) { writes.push(value); return this; },
        off() { return this; }, on() { return this; },
    };
    const context = {
        window: {}, caURL: 'exec', stackListGeneration: 0, stackDetailGeneration: {},
        stackContainerCache: {}, stackActionErrors: {}, expandedStacks: {}, stackPaths: {},
        composeIconSrc: () => '', composeStackUpdatePresentation: () => ({}),
        composeUpdatePresentationBadge: () => '', formatUptime: () => '',
        composeEscapeAttr: value => value,
        console, debugLog() {}, noStacks() {}, $: () => element,
    };
    context.$.ajax = options => {
        const request = { options };
        requests.push(request);
        return {
            done(callback) { request.done = callback; return this; },
            fail(callback) { request.fail = callback; return this; },
        };
    };
    load(context, dashboard, ['loadComposeStacks', 'loadStackContainers'], '    ');
    context.loadComposeStacks();
    context.loadStackContainers('one', 'app');
    context.loadComposeStacks();
    requests[2].done({ started: 1, stopped: 0, stacks: [
        { name: 'one', folder: 'one', path: '/configured/projects/one', state: 'started', running: 1, total: 1 },
    ] });
    assert.equal(context.stackPaths.one, '/configured/projects/one', 'Accepted snapshots own the configured action path');
    const acceptedWrites = writes.length;
    requests[0].done({ started: 0, stopped: 1, stacks: [] });
    requests[0].fail();
    requests[1].done('{"result":"success","containers":[]}');
    requests[1].fail();
    assert.equal(context.stackPaths.one, '/configured/projects/one', 'Stale snapshots cannot replace the configured action path');
    assert.equal(writes.length, acceptedWrites, 'Old list/detail successes and failures cannot mutate the latest view');
    context.loadStackContainers('one', 'app');
    context.loadStackContainers('one', 'app');
    requests[4].done('{"result":"success","containers":[]}');
    const currentWrites = writes.length;
    requests[3].done('{"result":"success","containers":[]}');
    assert.equal(writes.length, currentWrites, 'Only the latest detail request may render');
    assert.ok(requests.every(request => request.options.timeout === 30000));
    context.loadComposeStacks();
    requests.at(-1).done({ started: 0, stopped: 0, stacks: [] });
    assert.deepEqual(Object.keys(context.stackPaths), [], 'Fresh snapshots discard paths of removed stacks');
}

{
    const timers = new Map(), snapshots = [];
    let id = 0, passes = 0;
    const context = {
        window: { hideDockerComposeContainers: true }, composeHidingTimer: null,
        setTimeout(callback) { timers.set(++id, callback); return id; },
        clearTimeout(key) { timers.delete(key); },
        setupComposeContainerHiding(names) { snapshots.push(names); },
        doHideContainers() { passes++; },
    };
    load(context, dashboard, ['scheduleComposeContainerHiding'], '    ');
    for (let i = 0; i < 30; i++) context.scheduleComposeContainerHiding(['app-' + i]);
    assert.equal(timers.size, 1, 'Refreshes share one bounded hiding scheduler');
    context.scheduleComposeContainerHiding([]);
    assert.deepEqual(snapshots.at(-1), [], 'Empty membership also reconciles filtering');
    for (let i = 0; i < 120; i++) {
        const [key, callback] = timers.entries().next().value;
        timers.delete(key); callback();
    }
    assert.equal(passes, 120);
    assert.equal(timers.size, 0, 'Fallback polling terminates after its bounded window');
}

{
    const tiles = [{ name: 'current', hidden: false }, { name: 'removed', hidden: true }];
    let counts = 0;
    const context = {
        composeContainerNameSet: { current: true }, debugLog() {},
        extractContainerName: tile => tile.name,
        updateDockerTileCounts() { counts++; }, setDockerViewVisibility() {},
        $: selector => typeof selector === 'string'
            ? { length: 1, find: () => ({ each(callback) { tiles.forEach(tile => callback.call(tile)); } }) }
            : { name: selector.name, hasClass: () => selector.hidden,
                toggleClass(name, hidden) { selector.hidden = hidden; } },
    };
    load(context, dashboard, ['doHideContainers'], '    ');
    context.doHideContainers();
    assert.deepEqual(tiles.map(tile => tile.hidden), [true, false], 'Latest membership hides current containers and unhides removed ones');
    context.composeContainerNameSet = {};
    context.doHideContainers();
    assert.deepEqual(tiles.map(tile => tile.hidden), [false, false], 'Empty membership unhides all previous Compose containers');
    assert.equal(counts, 2, 'Counts update once per changed pass, not once per tile');
}

assert.ok(!/openBox\(/.test(main.replace(/function openComposeActionBox\([^]*?\n\}/, '')),
    'All main-page action terminals must use the no-navigation wrapper');

{
    const attributes = { 'data-ctids': 'oldcontainer1,oldcontainer2' };
    const data = {};
    const timers = new Map();
    const requests = [];
    const renderedHealth = [];
    const aggregateMembership = [];
    let response;
    const row = {
        length: 1,
        attr(key, value) {
            if (value === undefined) return key === 'id' ? 'stack-row-1' : attributes[key];
            attributes[key] = value;
            return this;
        },
        data(key, value) { data[key] = value; return this; },
        is: () => true,
        slideDown() {},
    };
    const context = {
        caURL: 'exec', composeHealthRefreshTimers: {}, composeUpdateCheckRevision: {},
        stackRuntimeGeneration: {}, stackDetailsPrefetchCache: {}, stackDetailsDesiredExpanded: {},
        pendingComposeRefreshCount: 0, stackContainersCache: {}, stackStartedAtCache: {},
        expandedStacks: { 1: true },
        composeLogger: () => {},
        processPendingUpdateChecks: () => {},
        composeCheckedContainers: value => value.containers,
        triggerComposeListRefreshedDebounced: () => aggregateMembership.push(attributes['data-ctids']),
        setTimeout: fn => { const key = {}; timers.set(key, fn); return key; },
        clearTimeout: key => timers.delete(key),
        $: () => row,
        renderContainerDetails: (id, containers) => renderedHealth.push(containers.map(ct => ct.health)),
    };
    context.$.ajax = options => {
        requests.push(options.data.script);
        return {
            done(callback) { callback(JSON.stringify(response)); return this; },
            fail() { return this; },
            always(callback) { callback(); return this; },
        };
    };
    load(context, main, ['composeNormalizeHealthStatus', 'reconcileStackRuntimeMetadata',
        'beginStackRuntimeRequest', 'reconcileStackRuntime', 'refreshStackRow', 'refreshStackByProject']);
    context.updateParentStackFromContainers = (id, project) => {
        context.reconcileStackRuntimeMetadata(row, project, context.stackContainersCache[id]);
    };
    const containers = [
        { id: 'a'.repeat(64), name: 'database', state: 'running', isRunning: true, health: 'healthy' },
        { id: 'b'.repeat(64), name: 'app', state: 'running', isRunning: true, health: 'starting' },
    ];
    response = { result: 'success', containers };
    context.refreshStackRow('1', 'stack');
    assert.deepEqual(aggregateMembership, ['aaaaaaaaaaaa,bbbbbbbbbbbb'],
        'Metric membership switches from old IDs to replacement IDs');
    assert.equal(data.ctids, attributes['data-ctids'], 'jQuery and DOM memberships agree');
    assert.deepEqual(Array.from(data.containers), ['database', 'app']);
    assert.equal(timers.size, 1, 'Starting health schedules another inspect refresh');
    context.refreshStackRow('1', 'stack');
    assert.equal(timers.size, 1, 'Repeated close/completion refreshes do not multiply health polls');
    response.containers[1].health = 'healthy';
    const [key, callback] = [...timers][0];
    timers.delete(key);
    callback();
    assert.deepEqual(requests, ['stack', 'stack', 'stack']);
    assert.deepEqual(Array.from(renderedHealth.at(-1)), ['healthy', 'healthy'],
        'Expanded health follows starting to healthy without navigation');
    assert.equal(timers.size, 0, 'Settled health stops polling');
    assert.equal(aggregateMembership.length, 1, 'Health-only updates retain metric membership');
    response.containers[1].health = 'starting';
    context.refreshStackRow('1', 'stack');
    response.containers[1].health = 'unhealthy';
    context.refreshStackRow('1', 'stack');
    assert.equal(timers.size, 0, 'Unhealthy is a terminal result, not an endless starting spinner');
    response.containers = [];
    context.refreshStackRow('1', 'stack');
    assert.equal(attributes['data-ctids'], '');
    assert.deepEqual(Array.from(data.containers), []);
}

console.log('Action modal close regressions passed.');
