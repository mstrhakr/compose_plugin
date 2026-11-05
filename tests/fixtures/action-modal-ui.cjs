const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const main = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeManagerMain.js`, 'utf8');
const dashboard = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeDashboard.js`, 'utf8');

function load(context, source, names, indent = '') {
    vm.createContext(context);
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
    load(context, main, ['executeUpdateAllStacks']);
    context.executeUpdateAllStacks([{ project: 'one', path: '/one', isRunning: true },
        { project: 'two', path: '/two', isRunning: true }], false);
    assert.deepEqual(events.filter(event => event[0] === 'poll'), [['poll', 'one'], ['poll', 'two']]);
}

{
    const checked = [], timers = [];
    const context = {
        pendingUpdateCheckStacks: ['queued', 'running'],
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

for (const native of [true, false]) {
    const events = [];
    const context = {
        window: {}, stackContainerCache: { stale: true },
        loadComposeStacks: () => events.push(['reload']),
        setTimeout: fn => events.push(['timer', fn]),
        Shadowbox: { open: opts => events.push(['fallback', opts]) },
        $: () => ({ text: () => {} }),
    };
    if (native) context.openBox = (...args) => events.push(['modal', ...args]);
    context.$.post = (url, payload, callback) => callback('/terminal.php');
    load(context, dashboard, ['composeDashboardActionBoxClosed', 'stackAction'], '    ');
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
    // The existing delayed refresh still runs independently of modal close.
    assert.ok(events.some(event => event[0] === 'timer'));
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
