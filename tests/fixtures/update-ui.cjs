const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeManagerMain.js`, 'utf8');

function harness(config = {}) {
    const checked = {};
    const events = [];
    const context = {
        console,
        document: { documentElement: {} },
        window: { getComputedStyle: () => ({ getPropertyValue: () => '' }) },
        setTimeout: fn => fn(),
        getConfig: () => ({ then: fn => fn(config) }),
        composeEscapeHtml: value => value,
        basename: path => path.split('/').pop(),
        caURL: 'exec', compURL: 'compose',
        confirmedComposeAction: (path, options) => events.push({ path, ...options }),
        performComposeAction: options => events.push(options),
        setStackActionInProgress: () => {},
        composeActionStateText: () => 'updating',
        swal: (options, callback) => {
            checked['#swal-include-stopped'] = /id="swal-include-stopped" checked/.test(options.text || '');
            events.push({ dialog: options, callback });
        },
        $: selector => ({
            length: 1,
            is: () => !!checked[selector],
            prop: (key, value) => { checked[selector] = value; },
            toggle: () => {},
        }),
    };
    context.$.post = (url, payload, callback) => {
        events.push({ url, payload });
        if (callback) callback();
    };
    for (const name of ['ComposeUpConfirmed', 'ComposeDownConfirmed', 'ComposeStopConfirmed',
        'ComposeRestartConfirmed', 'ComposePullConfirmed']) {
        context[name] = () => {};
    }
    vm.createContext(context);
    for (const name of ['composeUpdateScopeOptionHtml', 'ForceUpdateStackConfirmed', 'UpdateStackConfirmed',
        'renderStackActionDialog', 'executeUpdateAllStacks', 'updateAllStacks', 'showProfileSelector']) {
        const match = source.match(new RegExp(`^function ${name}\\([^]*?\\n\\}`, 'm'));
        assert.ok(match, `Missing function ${name}`);
        vm.runInContext(match[0], context);
    }
    return { context, checked, events };
}

for (const action of ['update', 'forceUpdate']) {
    for (const initial of [false, true]) {
        const config = { UPDATE_INCLUDE_STOPPED_DEFAULT: initial ? 'true' : 'false' };
        const { context, checked, events } = harness(config);
        context.renderStackActionDialog(action, 'Stack', '/stacks/test', '', [], false, false);
        assert.equal(checked['#swal-include-stopped'], initial);
        assert.match(events[0].dialog.text, /they will be started/);
        checked['#swal-include-stopped'] = !initial;
        events[0].callback(true);
        const request = events.at(-1);
        assert.equal(request.payload.includeStopped, initial ? '0' : '1');
        assert.equal(request.payload.recreate, action === 'forceUpdate' ? '1' : undefined);
        assert.equal(config.UPDATE_INCLUDE_STOPPED_DEFAULT, initial ? 'true' : 'false');
    }
    const { context, events } = harness({
        DISABLE_ACTION_WARNINGS: 'true', UPDATE_INCLUDE_STOPPED_DEFAULT: 'true',
    });
    context.renderStackActionDialog(action, 'Stack', '/stacks/test', '', [], false, false);
    assert.equal(events.at(-1).payload.includeStopped, '1');
    assert.equal(events.some(event => event.dialog), false);
}

for (const include of [false, true]) {
    const { context, events } = harness();
    const stacks = [
        { project: 'active', path: '/stacks/active', isRunning: true },
        { project: 'stopped', path: '/stacks/stopped', isRunning: false },
    ];
    context.executeUpdateAllStacks(stacks, true, false, include);
    const request = events.at(-1);
    assert.equal(request.payload.includeStopped, include ? '1' : '0');
    assert.deepEqual(JSON.parse(request.payload.paths), include
        ? ['/stacks/active', '/stacks/stopped'] : ['/stacks/active']);
}
for (const initial of [false, true]) {
    const config = { UPDATE_INCLUDE_STOPPED_DEFAULT: initial ? 'true' : 'false' };
    const { context, checked, events } = harness(config);
    context.stackUpdateStatus = { active: { hasUpdate: true }, stopped: { hasUpdate: true } };
    const baseDollar = context.$;
    context.$ = selector => {
        const row = selector.includes('data-project=');
        const stopped = selector.includes('"stopped"');
        if (!row) return baseDollar(selector);
        return {
            length: 1,
            find: () => ({ text: () => stopped ? 'stopped' : 'started', is: () => false }),
            data: key => key === 'path' ? `/stacks/${stopped ? 'stopped' : 'active'}` : 'Stack',
        };
    };
    context.$.post = (url, payload, callback) => { if (callback) callback(); };
    context.updateAllStacks();
    assert.equal(checked['#swal-include-stopped'], initial);
    checked['#swal-include-stopped'] = !initial;
    events[0].callback(true);
    const request = events.at(-1);
    assert.equal(request.payload.includeStopped, initial ? '0' : '1');
    assert.equal(JSON.parse(request.payload.paths).length, initial ? 1 : 2);
    assert.equal(config.UPDATE_INCLUDE_STOPPED_DEFAULT, initial ? 'true' : 'false');
}
{
    const { context, events } = harness();
    context.executeUpdateAllStacks([{ project: 'stopped', path: '/stacks/stopped', isRunning: false }], false);
    assert.equal(events[0].dialog.title, 'No Eligible Stacks');
    assert.equal(events.some(event => event.payload), false);
}
{
    const { context, events } = harness();
    context.showStackActionDialog = (action, path, profile) => events.push({ action, path, profile });
    context.showProfileSelector('update', '/stacks/test', ['backup'], '', 'backup');
    assert.equal(events[0].action, 'update');
    assert.equal(events[0].profile, '');
}
{
    const { context, events } = harness();
    const match = source.match(/^function showStackActionDialog\([^]*?\n\}/m);
    vm.runInContext(match[0], context);
    const containers = [{ name: 'running-app', state: 'running' }, { name: 'stopped-backup', state: 'exited' }];
    context.$ = () => ({
        length: 1, attr: () => 'stack-row-test',
        data: key => key === 'projectname' ? 'Test stack' : '0',
    });
    context.tryParseJson = data => data;
    context.mergeUpdateStatus = value => value;
    context.waitForStackActionDialogClose = callback => callback();
    context.renderStackActionDialog = (...args) => events.push({ rendered: args });
    context.$.post = (url, payload, callback) => {
        assert.equal(payload.action, 'getStackContainers');
        callback({ result: 'success', containers });
        return { fail: () => {} };
    };
    context.showStackActionDialog('update', '/stacks/test', 'wrong-default');
    assert.equal(events[0].rendered[3], '');
    assert.equal(events[0].rendered[4], containers);
    context.$.post = (url, payload, callback) => {
        callback({ result: 'error' });
        return { fail: () => {} };
    };
    context.showStackActionDialog('update', '/stacks/test', '');
    assert.equal(events.at(-1).dialog.type, 'error');
    assert.equal(events.filter(event => event.rendered).length, 1);
}
console.log('Update modal defaults, overrides, warning bypass, runtime details, and bulk scope tests passed.');
