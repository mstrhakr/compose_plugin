const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const main = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeManagerMain.js`, 'utf8');
const dashboard = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeDashboard.js`, 'utf8');
const updateChecks = fs.readFileSync(`${__dirname}/../../source/compose.manager/javascript/composeUpdateChecks.js`, 'utf8');

function load(source, names, context, indent = '') {
    vm.createContext(context);
    for (const name of names) {
        const match = source.match(new RegExp(`^${indent}function ${name}\\([^]*?\\n${indent}\\}`, 'm'));
        assert.ok(match, name);
        vm.runInContext(match[0], context);
    }
}

for (const project of ['constructor', '__proto__', 'ordinary']) {
    const requests = [], timers = [], applied = [];
    const context = {
        caURL: 'exec', composeListGeneration: 1,
        composeLogger() {}, renderStackActionProgress() {}, composeDashboardActionBoxClosed() {},
        composePollOperation: options => timers.push(options),
        applyStackSummaryFromResponse: (name, value) => applied.push([name, value]),
        tryParseJson: JSON.parse,
        $: () => ({ text() {} }),
        openBox() {},
    };
    context.$.ajax = options => {
        const request = { options };
        requests.push(request);
        return {
            done(callback) { request.done = callback; return this; },
            fail() { return this; },
            always(callback) { request.always = callback; return this; },
        };
    };
    vm.createContext(context);
    vm.runInContext(updateChecks, context);
    for (const source of [main, dashboard]) {
        const declarations = source.match(/^\s*(?:var )?(?:composeStackActionInProgress|composeUpdateCheckRequests|stackRuntimeGeneration|stackDetailsPrefetchCache|stackDetailsPrefetchPromises|composeUpdateCheckRevision|stackOperations|stackPaths|stackActionErrors) = .*;$/gm);
        assert.ok(declarations);
        vm.runInContext(declarations.join('\n'), context);
    }
    load(main, ['beginStackRuntimeRequest', 'prefetchStackDetailsInBackground', 'setStackActionInProgress',
        'getPersistentContainerInfo'], context);
    load(dashboard, ['dashboardStackPath', 'dashboardActionError', 'stackAction'], context, '    ');
    assert.equal(context.beginStackRuntimeRequest(project), 1);
    assert.equal(context.beginStackRuntimeRequest(project), 2);
    const owner = context.setStackActionInProgress(project, true, 'working');
    assert.equal(context.composeStackActionInProgress[project], owner);
    context.setStackActionInProgress(project, false, null, owner);
    assert.equal(context.composeStackActionInProgress[project], undefined);
    context.prefetchStackDetailsInBackground([project], 1);
    assert.equal(requests.length, 1, 'Inherited names cannot skip real prefetch');
    requests[0].done({ result: 'success', containers: [] });
    requests[0].always();
    assert.equal(applied.length, 1);
    assert.ok(Array.isArray(context.stackDetailsPrefetchCache[project].containers));
    context.stackPaths[project] = '/configured/' + project;
    context.stackAction(project, 'start');
    assert.equal(requests.length, 2, 'Valid inherited names must dispatch Dashboard actions');
    assert.equal(requests[1].options.data.path, '/configured/' + project);
    requests[1].done({ background: true, operations: JSON.parse(`{"${project}":"receipt"}`) });
    assert.equal(timers.length, 1);
    assert.ok(context.stackOperations[project]);
    context.stackAction(project, 'stop');
    assert.equal(requests.length, 2, 'Actual owners still prevent duplicate actions');
    timers[0].onComplete(0);
    assert.equal(context.stackOperations[project], undefined);
    context.persistentContainerCache = JSON.parse(`{"${project}":{"app":{"name":"cached"}}}`);
    assert.equal(context.getPersistentContainerInfo(project, 'app').name, 'cached');
    assert.equal(context.getPersistentContainerInfo(project, 'constructor'), null);
}
console.log('Prototype-named projects preserve generation, prefetch, action ownership and service-cache contracts.');
