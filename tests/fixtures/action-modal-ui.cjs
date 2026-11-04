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
        caURL: 'exec', compURL: 'compose', pendingComposeReloadStacks: [],
        composeActionStateText: () => 'working',
        basename: path => path.split('/').pop(),
        composeIdentityBlocked: () => false,
        tryParseJson: data => { try { return JSON.parse(data); } catch { return null; } },
        setStackActionInProgress: (...args) => events.push(['busy', ...args]),
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
    context.$.post = (url, payload, callback) => {
        events.push(['request', payload]);
        callback(response);
        return { fail() {} };
    };
    load(context, main, ['composeActionBoxClosed', 'openComposeActionBox', 'performComposeAction',
        'executeStartAllStacks', 'executeStopAllStacks', 'ComposeRecreateConfirmed']);
    return { context, events, timers };
}

for (const response of ['/terminal.php?done=1', JSON.stringify('/terminal.php?done=1')]) {
    const { context, events } = harness(response);
    const project = 'stack "quoted", \u00fc';
    context.performComposeAction({ stackName: project, actionName: 'up' });
    const modal = events.find(event => event[0] === 'modal');
    assert.equal(modal[1], '/terminal.php?done=1');
    assert.equal(modal[6], 'composeActionBoxClosed');
    assert.deepEqual(events.filter(event => event[0] === 'poll'), [['poll', project]]);
    assert.ok(!events.some(event => event[0] === 'busy' && event[2] === false));
    const before = events.length;
    context[modal[6]](modal[7]);
    assert.deepEqual(events.slice(before), [['refresh', project], ['rechecks']]);
    // Closing before completion does not clear busy state or cancel the independent poll.
    assert.ok(!events.some(event => event[0] === 'busy' && event[2] === false));
}

for (const name of ['executeStartAllStacks', 'executeStopAllStacks']) {
    const { context, events } = harness('/terminal.php');
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
    const { context, events, timers } = harness('/terminal.php');
    let locked = true;
    context.$.ajax = () => ({
        done(callback) {
            callback({ result: 'success', locked });
            return this;
        },
        fail() { return this; },
        always(callback) { callback(); return this; },
    });
    context.Date = Date;
    load(context, main, ['pollBackgroundCompletion']);
    context.performComposeAction({ stackName: 'one' });
    timers.shift()();
    assert.ok(!events.some(event => event[0] === 'busy' && event[2] === false));
    context.composeActionBoxClosed('one');
    assert.equal(timers.length, 1, 'Closing a running action preserves its next poll');
    locked = false;
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
    context.performComposeAction = opts => {
        assert.deepEqual(Array.from(opts.stackNames), ['one', 'two']);
        opts.onComplete(null, '/terminal.php');
    };
    load(context, main, ['executeUpdateAllStacks']);
    context.executeUpdateAllStacks([{ project: 'one', path: '/one', isRunning: true },
        { project: 'two', path: '/two', isRunning: true }], false);
    assert.deepEqual(events.filter(event => event[0] === 'poll'), [['poll', 'one'], ['poll', 'two']]);
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
console.log('Action modal close regressions passed.');
