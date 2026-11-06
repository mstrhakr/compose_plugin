const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeManagerMain.js`, 'utf8');
const updateChecks = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeUpdateChecks.js`, 'utf8');

function harness() {
    const checks = [], acknowledgements = [], writes = [], notices = [], logs = [], timers = [];
    const cell = { html(value) { writes.push(value); } };
    const context = {
        caURL: 'exec', composeStackActionInProgress: Object.create(null),
        stackUpdateStatus: Object.create(null), stackDetailsPrefetchCache: Object.create(null),
        setTimeout: callback => timers.push(callback),
        createStackInfo: (name, updates) => ({ containers: updates }),
        mergeStackUpdateStatus() {}, updateUpdateAllButton() {}, beginStackRuntimeRequest() {},
        renderStackActionProgress() {}, composeEscapeAttr: value => value,
        renderComposeUpdateCheckState() {},
        updateStackUpdateUI(name, value) { writes.push(value); },
        tryParseJson(value) { try { return JSON.parse(value); } catch { return null; } },
        composeLogger: (...args) => logs.push(args), swal: value => notices.push(value),
        $: () => ({ length: 1, find: () => cell }),
    };
    context.$.post = (url, data, callback) => {
        const request = { data, done: callback };
        checks.push(request);
        return { fail(callback) { request.fail = callback; return this; } };
    };
    context.$.ajax = options => {
        const request = { options };
        acknowledgements.push(request);
        return {
            done(callback) { request.done = callback; return this; },
            fail(callback) { request.fail = callback; return this; },
        };
    };
    vm.createContext(context);
    vm.runInContext(updateChecks, context);
    for (const name of ['checkStackUpdates', 'setStackActionInProgress', 'checkPendingRechecks', 'processPendingUpdateChecks']) {
        vm.runInContext(source.match(new RegExp(`^function ${name}\\([^]*?\\n\\}`, 'm'))[0], context);
    }
    return { context, checks, acknowledgements, writes, notices, logs, timers };
}
const result = token => JSON.stringify({ result: 'success', updates: [{ image: 'current' }], recheckToken: token });
for (const token of [null, 'a'.repeat(64)]) {
    for (const mode of ['success', 'renewed', 'network', 'malformed', 'refused']) {
        const h = harness();
        h.context.checkStackUpdates('constructor');
        h.checks[0].done(result(token));
        assert.equal(h.acknowledgements.length, 1);
        const ack = h.acknowledgements[0];
        assert.equal(ack.options.timeout, 30000);
        assert.equal(JSON.parse(ack.options.data.tokens).constructor, token);
        assert.equal(h.writes.length, 1, 'Image result is applied independently of acknowledgement persistence');
        if (mode === 'network') ack.fail({}, 'timeout', 'offline');
        else ack.done(mode === 'malformed' ? '{broken' : {
            result: mode === 'refused' ? 'error' : 'success',
            remaining: mode === 'renewed' ? ['constructor'] : [],
        });
        h.context.processPendingUpdateChecks();
        assert.equal(h.timers.length, mode !== 'success' ? 1 : 0, 'Unacknowledged results remain retryable');
        assert.equal(h.notices.length, ['network', 'malformed', 'refused'].includes(mode) ? 1 : 0);
    }
}
{
    const h = harness();
    h.context.composeStackActionInProgress.app = {};
    h.context.checkStackUpdates('app');
    assert.equal(h.checks.length, 0, 'Checks requested during a local action wait instead of leaving an obsolete spinner');
    delete h.context.composeStackActionInProgress.app;
    h.context.processPendingUpdateChecks();
    assert.equal(h.timers.length, 1, 'Deferred checks remain queued until the action finishes');
}
{
    const h = harness();
    h.context.checkStackUpdates('app');
    const owner = h.context.setStackActionInProgress('app', true);
    h.context.setStackActionInProgress('app', false, null, owner);
    h.checks[0].done(result('a'.repeat(64)));
    assert.equal(h.acknowledgements.length, 0, 'Pre-action check cannot acknowledge after the newer action finishes');
    assert.equal(h.writes.length, 1, 'Only action invalidation repaints existing evidence, never the stale result');
    assert.equal(h.writes[0].containers.length, 0);
    assert.equal(h.context.composeUpdateCheckState.app, undefined, 'Invalidation removes the obsolete checking state');
    h.context.processPendingUpdateChecks();
    assert.equal(h.timers.length, 1, 'Invalidation retains a deferred retry');
}
{
    const h = harness();
    h.context.checkStackUpdates('app');
    h.context.checkStackUpdates('app');
    h.checks[0].done(result(null));
    h.checks[0].fail({}, 'error', 'old');
    assert.equal(h.writes.length, 0);
    h.checks[1].done(result(null));
    assert.equal(h.acknowledgements.length, 1, 'Only the latest check owns acknowledgement');
}
for (const mode of ['network', 'error', 'empty', 'malformed']) {
    const h = harness();
    h.context.checkPendingRechecks();
    if (mode === 'network') h.checks[0].fail({}, 'timeout', 'offline');
    else h.checks[0].done(mode === 'empty' ? '' : mode === 'malformed' ? '{broken' : '{"result":"error"}');
    assert.equal(h.notices.length, 1, 'Pending-store failures cannot disappear as an empty successful queue');
    assert.equal(h.logs.length, 1);
}
console.log('Recheck tokens, stale-check ownership, explicit acknowledgement/read failures and retryable evidence passed.');
