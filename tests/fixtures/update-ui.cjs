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
    vm.runInContext(fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeUpdateStatus.js`, 'utf8'), context);
    for (const name of ['composeUpdateScopeOptionHtml', 'ForceUpdateStackConfirmed', 'UpdateStackConfirmed',
        'renderStackActionDialog', 'composeUpdateDigestDetails', 'executeUpdateAllStacks', 'updateAllStacks', 'showProfileSelector',
        'updateStackUpdateUI', 'showUpdateWarning', 'createContainerInfo', 'composeImageUpdateKey', 'mergeContainerUpdateStatus',
        'mergeStackUpdateStatus', 'checkAllUpdates']) {
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

{
    const { context } = harness();
    for (const [state, label] of Object.entries({
        'current': 'Current', 'download-required': 'Available', 'ready-to-apply': 'Downloaded',
        'rebuild-required': 'Rebuild', 'unknown': 'Unchecked'
    })) {
        const container = { updateReadiness: state };
        assert.equal(context.composeUpdatePresentation(container).label, label);
        const badge = context.composeUpdateBadge(container);
        assert.match(badge, /white-space:nowrap/);
        assert.match(badge, /aria-label=/);
        assert.doesNotMatch(badge, /<div|<br|arrow-right/);
    }
    assert.equal(context.composeUpdatePresentation({ hasUpdate: true }).label, 'Pending');
    assert.equal(context.composeUpdatePresentation({ updateStatus: 'up-to-date', updateReadiness: 'unknown' }).label, 'Unchecked');
    assert.equal(context.composeUpdatePresentation({ updateStatus: 'up-to-date' }).label, 'Current');
    assert.equal(context.composeUpdatePresentation({ hasUpdate: true, updateReadiness: 'unknown' }).label, 'Pending');
    assert.equal(context.composeUpdatePresentation({ isPinned: true }).label, 'Pinned');
    assert.match(context.composeUpdateDigestDetails({ updateKind: 'build-base', remoteSha: 'base' }), /Remote base image/);
    assert.doesNotMatch(context.composeUpdateDigestDetails({ updateKind: 'build-base', remoteSha: 'base' }), /Remote application image/);
}
{
    const { context } = harness();
    let markup = '';
    context.stackContainersCache = {};
    context.expandedStacks = {};
    context.composeEscapeAttr = text => String(text).replace(/"/g, '&quot;');
    context.$ = selector => ({
        length: 1, attr: () => 'stack-row-test', each: () => {},
        find: () => ({ html: value => { markup = value; }, text: () => 'stopped' }),
    });
    for (const containers of [
        [{ hasUpdate: true, updateReadiness: 'download-required' }],
        [{ hasUpdate: true, updateReadiness: 'ready-to-apply' }, { hasUpdate: true, updateReadiness: 'rebuild-required' }],
        [{ updateStatus: 'up-to-date' }, { isPinned: true }],
        [{ updateStatus: 'unknown' }],
        [],
    ]) {
        context.updateStackUpdateUI('test', { containers });
        assert.match(markup, /white-space:nowrap/);
        assert.doesNotMatch(markup, /<div|<br|arrow-right|force update|> stopped</);
    }
    context.updateStackUpdateUI('test', { containers: [
        { hasUpdate: true, updateReadiness: 'ready-to-apply' },
        { hasUpdate: true, updateReadiness: 'rebuild-required' },
        { updateStatus: 'unknown' },
    ] });
    assert.match(markup, /2 updates/);
    assert.match(markup, /1 downloaded/);
    assert.match(markup, /1 rebuild/);
    assert.match(markup, /1 unchecked/);
    context.updateStackUpdateUI('test', { containers: [{ updateStatus: 'up-to-date', updateReadiness: 'unknown' }] });
    assert.match(markup, /1 unchecked/);
}
console.log('Compact readiness badges, mixed summaries, stopped-stack visibility, and digest labels passed.');

{
    const { context } = harness();
    const prior = { containers: [{
        name: 'app', image: 'app:latest', hasUpdate: true, updateStatus: 'update-available',
        updateReadiness: 'ready-to-apply', localImageSha: 'old-evidence'
    }] };
    const fresh = { containers: [{ name: 'app', image: 'app:latest', updateStatus: 'unknown', updateReadiness: 'unknown' }] };
    context.mergeStackUpdateStatus(fresh, prior, true);
    assert.equal(fresh.hasUpdate, true, 'An unknown check retains pending history');
    assert.equal(fresh.containers[0].updateReadiness, 'unknown');
    assert.equal(fresh.containers[0].localImageSha, '');
    assert.equal(context.composeStackUpdatePresentation(fresh.containers).label, 'Pending');
    const success = { containers: [{
        name: 'app', image: 'app:latest', updateStatus: 'up-to-date', updateReadiness: 'current', localImageSha: 'new-evidence'
    }] };
    context.mergeStackUpdateStatus(success, { containers: [
        { name: 'app', image: 'app:latest', updateStatus: 'unknown', updateReadiness: 'unknown' }
    ] }, true);
    assert.equal(success.containers[0].updateReadiness, 'current', 'Cached unknown must not invalidate a successful fresh check');
    assert.equal(success.containers[0].localImageSha, 'new-evidence');
    assert.equal(context.composeStackUpdatePresentation([]).label, 'Unchecked');
    assert.equal(context.composeStackUpdatePresentation([{ isPinned: true }]).label, 'All pinned');
    assert.equal(context.composeStackUpdatePresentation([{ updateReadiness: 'current' }, { updateReadiness: 'unknown' }]).label, '1 unchecked');
    assert.equal(context.composeStackUpdatePresentation([{ updateReadiness: 'current' }]).label, 'Current');
}
for (const response of ['', 'not json', JSON.stringify({ result: 'error', message: 'Cannot save results' })]) {
    const { context } = harness();
    let markup = '';
    let disabled;
    const row = { find: () => ({ html: value => { markup = value; } }) };
    context.composeLogger = () => {};
    context.$ = selector => typeof selector === 'object' ? selector : ({
        prop: (key, value) => { if (selector === '#checkUpdatesBtn') disabled = value; return context.$(selector); },
        val: () => context.$(selector),
        each: fn => { fn.call(selector.includes('tr.compose-sortable') ? row : { html: value => { markup = value; } }); }
    });
    context.$.post = (url, payload, callback) => { callback(response); return { fail: () => {} }; };
    context.checkAllUpdates();
    assert.match(markup, /check failed/);
    assert.equal(disabled, false);
}

for (const action of ['update', 'forceUpdate']) {
    const h = harness();
    h.context.composeIconSrc = () => 'icon.png';
    h.context.composeEscapeAttr = text => String(text).replace(/"/g, '&quot;');
    h.context.dockerVersionsInstalled = true;
    h.context.renderStackActionDialog(action, 'Test', 'test', '', [{
        name: 'test-app-1', image: 'test-app', state: 'exited', hasUpdate: true, updateKind: 'build-base',
        updateReadiness: 'rebuild-required', localSha: 'app-old', localImageSha: 'app-local', remoteSha: 'base-new',
    }], true, false);
    const dialog = h.events[0].dialog.text;
    assert.match(dialog, /Rebuild/);
    assert.match(dialog, /Remote base image/);
    assert.match(dialog, /Local application image/);
    assert.match(dialog, /last check/);
    assert.match(dialog, /state: exited/);
    assert.doesNotMatch(dialog, /arrow-right|data-changelog-container/);
}
{
    const { context, events } = harness({ DISABLE_ACTION_WARNINGS: 'true' });
    context.renderStackActionDialog('update', 'Test', 'test', '', [], false, false, true);
    assert.ok(events[0].dialog, 'Viewing details must still open a dialog when action warnings are disabled');
    assert.equal(events.some(event => event.payload), false);
    events[0].callback(false);
    assert.equal(events.some(event => event.payload), false, 'Closing details must not update');
    context.compose_root = '/stacks';
    context.showStackActionDialog = (...args) => events.push(args);
    context.showUpdateWarning('test', 'test', 'update');
    assert.equal(events.at(-1)[3], true, 'Status links must request details explicitly');
}
{
    const { context, events } = harness({ DISABLE_ACTION_WARNINGS: 'true' });
    vm.runInContext(source.match(/^function showComposeChangelog\([^]*?\n\}/m)[0], context);
    let tick;
    let open = true;
    context.showChangeLog = () => {};
    context.$ = () => ({ hide: () => {}, hasClass: () => open });
    context.setInterval = fn => { tick = fn; return 1; };
    context.clearInterval = () => {};
    context.showStackActionDialog = (...args) => events.push(args);
    context.showComposeChangelog('test-app', '/stacks/test', '');
    tick();
    open = false;
    tick();
    assert.equal(events[0][3], true, 'Changelog close must reopen details, not start an update');
}
