const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeManagerMain.js`, 'utf8');

function harness(config = {}) {
    const checked = {};
    const disabled = {};
    const handlers = {};
    const events = [];
    const context = {
        console,
        document: { documentElement: {} },
        window: { getComputedStyle: () => ({ getPropertyValue: () => '' }) },
        setTimeout: fn => fn(),
        getConfig: () => ({ then: fn => fn(config) }),
        composeEscapeHtml: value => value,
        composeEscapeAttr: value => value,
        buildRemoveOrphansCheckboxHtml: () => '',
        basename: path => path.split('/').pop(),
        caURL: 'exec', compURL: 'compose',
        stackUpdateStatus: Object.create(null), stackDetailsPrefetchCache: {},
        composeStackActionInProgress: Object.create(null),
        confirmedComposeAction: (path, options) => events.push({ path, ...options }),
        performComposeAction: options => events.push(options),
        performMultipleComposeAction: (stacks, options) => events.push(options),
        setStackActionInProgress: () => {},
        composeActionStateText: () => 'updating',
        updateUpdateAllButton() {},
        swal: (options, callback) => {
            checked['#swal-include-stopped'] = /id="swal-include-stopped" checked/.test(options.text || '');
            events.push({ dialog: options, callback });
        },
        $: selector => ({
            length: 1,
            is: () => !!checked[selector],
            prop: (key, value) => { (key === 'disabled' ? disabled : checked)[selector] = value; },
            on: (event, handler) => { handlers[`${selector}:${event}`] = handler; },
            toggle: () => {},
        }),
    };
    context.$.post = (url, payload, callback) => {
        events.push({ url, payload });
        if (callback) callback();
    };
    context.$.ajax = () => ({
        done(callback) { callback({ result: 'success' }); return this; },
        fail() { return this; },
    });
    for (const name of ['ComposeUpConfirmed', 'ComposeDownConfirmed', 'ComposeStopConfirmed',
        'ComposeRestartConfirmed', 'ComposePullConfirmed']) {
        context[name] = () => {};
    }
    vm.createContext(context);
    vm.runInContext(fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeUpdateChecks.js`, 'utf8'), context);
    vm.runInContext(fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeUpdateStatus.js`, 'utf8'), context);
    for (const name of ['ComposeUpConfirmed', 'composeUpdateScopeOptionHtml', 'ForceUpdateStackConfirmed', 'UpdateStackConfirmed',
        'renderStackActionDialog', 'showStackActionDialog', 'composeUpdateDigestDetails', 'executeUpdateAllStacks', 'updateAllStacks', 'showProfileSelector',
        'composeUpdateDetailsTable', 'prepareStackRechecks',
        'updateStackUpdateUI', 'showUpdateWarning', 'createContainerInfo', 'composeImageUpdateKey', 'mergeContainerUpdateStatus',
        'mergeStackUpdateStatus', 'mergeUpdateStatus', 'checkAllUpdates', 'composeUpdateCheckBadge',
        'renderComposeUpdateCheckState', 'composeCheckedContainers', 'composeContainerUpdateBadge',
        'checkStackUpdates', 'createStackInfo']) {
        const match = source.match(new RegExp(`^function ${name}\\([^]*?\\n\\}`, 'm'));
        assert.ok(match, `Missing function ${name}`);
        vm.runInContext(match[0], context);
    }
    return { context, checked, disabled, handlers, events };
}

{
    const { context, checked, disabled, handlers, events } = harness({ RUN_IN_BACKGROUND_DEFAULT: 'true' });
    context.renderStackActionDialog('up', 'Stack', '/stacks/test', '', [], true, false);
    assert.match(events[0].dialog.text, /Saved settings are unchanged/);
    assert.equal(checked['#swal-run-bg-checkbox'], true);
    const change = handlers['#swal-follow-logs-checkbox:change'];
    checked['#swal-follow-logs-checkbox'] = true;
    change.call({ checked: true });
    assert.equal(disabled['#swal-run-bg-checkbox'], true);
    assert.equal(checked['#swal-run-bg-checkbox'], false);
    events[0].callback(true);
    assert.equal(events.at(-1).payload.followLogs, true);
    assert.equal(events.at(-1).background, false);
    change.call({ checked: false });
    assert.equal(disabled['#swal-run-bg-checkbox'], false);
    assert.equal(checked['#swal-run-bg-checkbox'], true);
}

for (const disableWarnings of [false, true]) {
    const { context, events } = harness({ DISABLE_ACTION_WARNINGS: String(disableWarnings) });
    context.renderStackActionDialog('prepareUp', 'New stack', '/stacks/new', 'web,monitoring', [], true, false);
    if (!disableWarnings) {
        assert.match(events[0].dialog.title, /Pull\/Build & Up/);
        assert.ok(!events[0].dialog.text.includes('swal-include-stopped'));
        events[0].callback(true);
    }
    const request = events.at(-1);
    assert.equal(request.payload.action, 'composeUp');
    assert.equal(request.payload.prepareImages, '1');
    assert.equal(request.payload.profile, 'web,monitoring');
    assert.equal(request.payload.includeStopped, undefined);
}
for (const all of [false, true]) {
    const { context, events, checked } = harness();
    const dollar = context.$;
    context.$ = selector => selector === '.profile_checkbox:checked'
        ? { each(callback) { callback.call({ val: () => 'web' }); } }
        : typeof selector === 'object' ? selector : dollar(selector);
    checked['#profile_all_profiles'] = all;
    let profile;
    context.ComposePrepareUp = (path, selected) => { profile = selected; };
    context.showProfileSelector('prepareUp', '/stacks/new', ['web', 'jobs'], '', '');
    assert.equal(events[0].dialog.title, 'Select Profiles');
    events[0].callback(true);
    assert.equal(profile, all ? '*' : 'web');
}
for (const response of ['bad JSON', '{"result":"error"}', '{"result":"success","services":[]}', '{"result":"success","services":"web"}']) {
    const { context, events } = harness();
    const dollar = context.$;
    context.$ = selector => selector.startsWith('#compose_stacks') ? { length: 0 } : dollar(selector);
    context.$.post = (url, payload, callback) => {
        assert.equal(payload.action, 'getProfileServices');
        assert.equal(payload.profiles, 'web');
        callback(response);
        return { fail() {} };
    };
    context.composeLogger = () => {};
    context.showStackActionDialog('prepareUp', '/stacks/new', 'web');
    assert.equal(events.at(-1).dialog.title, 'Deployment scope unavailable');
    assert.equal(events.length, 1, 'Invalid scope never dispatches Up');
}
{
    const { context, events } = harness();
    context.ComposeUpConfirmed('/stacks/new', { profile: 'web' });
    assert.equal(events[0].payload.prepareImages, '0', 'Ordinary Up does not prepare images');
}

for (const kind of ['image', 'build-base']) {
    for (const order of ['details-first', 'check-first']) {
        const { context } = harness();
        const pending = {
            name: 'app', service: 'web', image: 'app:latest', state: 'running',
            localSha: 'original-image', remoteSha: 'target-image', hasUpdate: true,
            updateStatus: 'update-available', updateKind: kind,
            updateReadiness: kind === 'image' ? 'download-required' : 'rebuild-required',
        };
        const unknown = {
            ...pending, hasUpdate: false, updateStatus: 'unknown',
            updateReadiness: 'unknown', remoteSha: '',
        };
        context.stackUpdateStatus = {};
        const revisionAtRequest = context.composeUpdateCheckRevision.test || 0;
        const completeDetails = () => context.composeCheckedContainers({ containers: [{ ...unknown }] }, 'test', revisionAtRequest);
        const completeCheck = () => {
            context.stackUpdateStatus.test = { containers: [{ ...pending }] };
            context.composeUpdateCheckRevision.test = 1;
        };
        if (order === 'details-first') {
            const details = completeDetails();
            assert.equal(details[0].updateReadiness, 'unknown');
            completeCheck();
            context.mergeUpdateStatus(details, 'test', false);
            assert.equal(details[0].updateReadiness, pending.updateReadiness);
        } else {
            completeCheck();
            assert.equal(completeDetails()[0].updateReadiness, pending.updateReadiness);
        }
        const replacement = context.composeCheckedContainers({
            containers: [{ ...unknown, localSha: 'replacement-image' }],
        }, 'test', revisionAtRequest)[0];
        assert.equal(replacement.hasUpdate, false, 'An in-flight check for the old image must not attach to its replacement');
        assert.equal(replacement.localSha, 'replacement-image');
        assert.equal(replacement.remoteSha, '');
        assert.equal(replacement.updateReadiness, 'unknown');
        assert.equal(context.composeCheckedContainers({ containers: [{ ...unknown }] }, 'test', 1)[0].hasUpdate,
            false, 'A response requested after the check owns authoritative invalidation');
    }
}

{
    const { context } = harness();
    const job = {
        name: 'job', service: 'app', image: 'app:latest', state: 'exited', oneOff: true,
        hasUpdate: true, updateStatus: 'update-available', updateReadiness: 'ready-to-apply',
    };
    const normalized = context.createContainerInfo(job);
    assert.equal(normalized.oneOff, true);
    assert.equal(normalized.hasUpdate, false);
    assert.equal(normalized.updateStatus, 'unknown');
    assert.equal(normalized.updateReadiness, 'unknown');
    assert.equal(context.composeUpdatePresentation(job).label, 'Job');
    const stack = context.createStackInfo('jobs', [job]);
    assert.equal(stack.containers.length, 1, 'Jobs must stay visible in runtime details');
    assert.equal(stack.hasUpdate, false, 'Expansion must not make job-only stacks pending');
    context.mergeContainerUpdateStatus(normalized, { ...job, oneOff: false }, false);
    assert.equal(normalized.hasUpdate, false, 'Stale browser history cannot restore job evidence');
    const regular = { ...job, name: 'app', oneOff: false };
    assert.equal(context.createStackInfo('mixed', [job, regular]).hasUpdate, true,
        'Excluding jobs must not hide a regular service update');
    const current = { ...regular, hasUpdate: false, updateStatus: 'up-to-date', updateReadiness: 'current' };
    const summary = context.composeStackUpdatePresentation([job, current]);
    assert.equal(summary.label, 'Current');
    assert.equal(summary.needsCheck, false, 'Jobs must not make checked services appear unchecked');
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
    context.$.ajax = baseDollar.ajax;
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
    assert.equal(events[0].rendered[4].map(container => container.name).join(','), 'running-app,stopped-backup');
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
    context.isValidIconSrc = () => true;
    const normalize = context.createContainerInfo;
    for (const count of [10, 100, 200]) {
        let normalizations = 0;
        context.createContainerInfo = raw => { normalizations++; return normalize(raw); };
        const containers = Array.from({ length: count }, (_, i) => ({
            name: `app-${i}`, service: 'app', image: 'app:latest', updateStatus: 'unknown',
        }));
        context.stackUpdateStatus = { test: { containers: containers.map((container, i) => ({
            ...container, updateStatus: 'update-available', hasUpdate: true,
            remoteSha: `target-${i}`, updateReadiness: 'rebuild-required',
        })) } };
        context.mergeUpdateStatus(containers, 'test');
        assert.ok(normalizations <= 4 * count, `Expected linear work, got ${normalizations} for ${count}`);
        containers.forEach((container, i) => assert.equal(container.remoteSha, `target-${i}`));
        const newReplica = { name: 'new-replica', service: 'app', image: 'app:latest' };
        context.mergeUpdateStatus([newReplica], 'test');
        assert.equal(newReplica.remoteSha, undefined, 'Service fallback must not copy a different replica\'s evidence');
    }
}

{
    const { context } = harness();
    const prior = { containers: [{
        name: 'app', image: 'app:latest', hasUpdate: true, updateStatus: 'update-available',
        updateReadiness: 'ready-to-apply', localImageSha: 'old-evidence'
    }] };
    const fresh = { hasUpdate: true, containers: [{
        name: 'app', image: 'app:latest', hasUpdate: true, updateStatus: 'update-available',
        updateReadiness: 'unknown', localImageSha: '',
    }] };
    context.mergeStackUpdateStatus(fresh, prior, true);
    assert.equal(fresh.hasUpdate, true, 'Server-owned pending history is preserved without browser enrichment');
    assert.equal(fresh.containers[0].updateReadiness, 'unknown');
    assert.equal(fresh.containers[0].localImageSha, '');
    assert.equal(context.composeStackUpdatePresentation(fresh.containers).label, 'Pending');
    const noHistory = { hasUpdate: false, containers: [{
        name: 'app', image: 'app:latest', hasUpdate: false, updateStatus: 'unknown', updateReadiness: 'unknown',
    }] };
    context.mergeStackUpdateStatus(noHistory, prior, true);
    assert.equal(noHistory.hasUpdate, false, 'A fresh server result cannot regain discarded browser history');
    assert.equal(context.composeStackUpdatePresentation(noHistory.containers).label, '1 unchecked');
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
    const row = { length: 1, data: () => 'test', attr: () => 'stack-row-test',
        find: () => ({ html: value => { markup = value; } }) };
    context.composeLogger = () => {};
    context.$ = selector => typeof selector === 'object' ? selector : ({
        prop: (key, value) => { if (selector === '#checkUpdatesBtn') disabled = value; return context.$(selector); },
        length: 1, attr: () => 'stack-row-test', find: () => ({ html: value => { markup = value; } }),
        html: value => { markup = value; },
        val: () => context.$(selector),
        each: fn => { fn.call(selector.includes('tr.compose-sortable') ? row : { html: value => { markup = value; } }); }
    });
    context.$.post = (url, payload, callback) => { callback(response); return { fail: () => {} }; };
    context.checkAllUpdates();
    assert.match(markup, /check failed/i);
    assert.equal(disabled, false);
}

{
    const { context } = harness();
    const previous = {
        name: 'app', image: 'app:latest', updateStatus: 'update-available', hasUpdate: true,
        updateKind: 'build-base', updateReadiness: 'rebuild-required', remoteSha: 'new-base',
    };
    context.stackUpdateStatus = { test: { containers: [previous] } };
    context.stackContainersCache = { test: [{ ...previous }] };
    context.expandedStacks = { test: true };
    context.composeEscapeAttr = text => String(text);
    context.composeLogger = () => {};
    context.updateUpdateAllButton = () => {};
    let stackHtml = '', containerHtml = '', complete;
    const containerRow = {
        attr: () => 'app', find: () => ({ html: value => { containerHtml = value; } }),
    };
    const stackRow = {
        length: 1, attr: () => 'stack-row-test', data: () => 'test',
        each: fn => fn.call(stackRow),
        find: () => ({ html: value => { stackHtml = value; } }),
    };
    context.$ = selector => {
        if (typeof selector === 'object') return selector;
        if (selector.includes('tr.compose-sortable')) return stackRow;
        return {
            prop() { return this; }, val() { return this; },
            html: value => { containerHtml = value; },
            each: fn => { if (selector.includes('tr[data-container]')) fn.call(containerRow); },
        };
    };
    context.$.post = (url, payload, callback) => {
        if (payload.action === 'checkStackUpdates' || payload.action === 'checkAllStacksUpdates') complete = callback;
        return { fail: () => {} };
    };
    context.$.ajax = () => ({
        done(callback) { callback({ result: 'success', remaining: [] }); return this; },
        fail() { return this; },
    });
    context.checkStackUpdates('test');
    assert.match(stackHtml, /Checking/);
    assert.match(containerHtml, /Checking/);
    assert.match(context.composeContainerUpdateBadge(previous, 'test', 'test'), /Checking/);
    complete(JSON.stringify({ result: 'success', updates: [previous], recheckToken: null }));
    assert.match(stackHtml, /Rebuild/);
    assert.match(containerHtml, /Rebuild/);
    assert.doesNotMatch(containerHtml, /Checking/);

    const staleResponse = { containers: [{ ...previous, hasUpdate: false, updateStatus: 'unknown', updateReadiness: 'unknown' }] };
    assert.equal(context.composeCheckedContainers(staleResponse, 'test', 0)[0].updateReadiness, 'rebuild-required',
        'An expansion started before a completed check must use the newer check');
    const freshResponse = { containers: [{ ...previous, hasUpdate: false, updateStatus: 'unknown', updateReadiness: 'unknown' }] };
    assert.equal(context.composeCheckedContainers(freshResponse, 'test', 1)[0].updateReadiness, 'unknown',
        'A fresh server invalidation must not be overwritten by older browser evidence');
    const invalidated = {
        ...previous, hasUpdate: false, updateStatus: 'unknown', updateReadiness: 'unknown',
        remoteSha: '', localImageSha: '',
    };
    context.stackUpdateStatus.test = { containers: [invalidated] };
    context.updateStackUpdateUI('test', context.stackUpdateStatus.test, true);
    assert.equal(context.stackContainersCache.test[0].hasUpdate, false, 'An authoritative unknown must clear cached pending evidence');
    assert.equal(context.stackContainersCache.test[0].updateStatus, 'unknown');
    assert.equal(context.stackContainersCache.test[0].remoteSha, '');
    assert.match(containerHtml, /Unchecked/);

    context.checkStackUpdates('test');
    complete(JSON.stringify({ result: 'error', message: 'Registry unavailable' }));
    assert.match(containerHtml, /check failed/i);
    assert.doesNotMatch(containerHtml, /Rebuild/);
    context.checkAllUpdates();
    assert.match(stackHtml, /Checking/);
    assert.match(containerHtml, /Checking/);
    complete(JSON.stringify({ result: 'success', stacks: { test: { containers: [previous], hasUpdate: true } } }));
    assert.match(stackHtml, /Rebuild/);
    assert.match(containerHtml, /Rebuild/);
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
    assert.match(dialog, /<table class="compose-update-table">/);
    assert.match(dialog, /Service \/ image/);
    assert.equal(h.events[0].dialog.customClass, 'compose-update-dialog');
    assert.equal(h.events[0].dialog.type, undefined, 'Compact update dialog must not reserve space for a large warning icon');
    assert.doesNotMatch(dialog, /arrow-right|data-changelog-container/);
}
{
    const { context } = harness();
    context.composeIconSrc = () => 'icon.png';
    context.composeEscapeAttr = text => String(text).replace(/"/g, '&quot;');
    context.composeEscapeHtml = text => String(text).replace(/</g, '&lt;').replace(/>/g, '&gt;');
    context.dockerVersionsInstalled = true;
    const html = context.composeUpdateDetailsTable([
        { name: 'db', service: '<db>', image: 'registry:5000/db:latest', state: 'running',
            hasUpdate: true, updateReadiness: 'download-required', localSha: 'old', remoteSha: 'new' },
        { name: 'app', image: 'built-app', state: 'running', hasUpdate: true,
            updateKind: 'build-base', updateReadiness: 'rebuild-required', remoteSha: 'base' },
    ], '/stacks/test', '');
    assert.equal((html.match(/<tr>/g) || []).length, 3, 'Two services must use two table rows plus the header');
    assert.match(html, /&lt;db&gt;/);
    assert.match(html, /registry:5000\/db:latest/);
    assert.equal((html.match(/data-changelog-container=/g) || []).length, 1, 'Only published-image changes offer a changelog');
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
