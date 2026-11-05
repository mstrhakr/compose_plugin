import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(__dirname, '../../../source/compose.manager/javascript');
const main = readFileSync(`${root}/composeManagerMain.js`, 'utf8');
const checks = readFileSync(`${root}/composeUpdateChecks.js`, 'utf8');
const exact = (name: string) => main.match(new RegExp(`^function ${name}\\([^]*?\\n\\}`, 'm'))![0];
const factories = main.slice(main.indexOf('function createContainerInfo('), main.indexOf('// Timers for async operations'));
const hooks = [
  'checkStackUpdates', 'checkAllUpdates', 'loadSavedUpdateStatus', 'checkPendingRechecks',
  'processPendingUpdateCheckForStack', 'processPendingUpdateChecks', 'setStackActionInProgress',
  'purgeDeletedStackCaches',
  'loadPersistentContainerCache',
].map(exact).join('\n');

// Deterministic transport/timer adapter: all decisions run in the production coordinator.
const harness = `
  var requests = [], timers = [], paints = [], notices = [], warnings = [], buttons = {};
  var rows = new Set(['a', 'b']), caURL = '/exec', autoCheckUpdates = false;
  var dockerVersionsInstalled = false, savedUpdateStatusLoaded = false;
  var stackUpdateStatus = Object.create(null), composeStackActionInProgress = Object.create(null);
  var stackDetailsPrefetchCache = Object.create(null), stackDetailsPrefetchPromises = {};
  var expandedStacks = {}, stackDetailsDesiredExpanded = {}, stackDetailsLoading = {}, stackDetailsReloadRequested = {};
  var stackContainersCache = {}, composeHealthRefreshTimers = {}, persistentContainerCache = {};
  var pendingComposeReloadStacks = [];
  function composeLogger(message) { notices.push(message); }
  function swal(message) { warnings.push(message); }
  function updateUpdateAllButton() {}
  function checkAutoUpdateIfNeeded() { checkAllUpdates(); }
  function renderComposeUpdateCheckState() {}
  function renderStackActionProgress() {}
  function beginStackRuntimeRequest() {}
  function updateStackUpdateUI(project, info) { paints.push([project, info.lastChecked]); }
  function setTimeout(callback) { timers.push(callback); }
  function clearTimeout() {}
  function flush() { while (timers.length) timers.shift()(); }
  function $(selector) {
    if (typeof selector !== 'string') return { data: () => selector.project };
    var match = selector.match(/data-project="([^"]+)"/);
    return {
      length: match ? Number(rows.has(match[1])) : 1,
      each(callback) { if (!match && selector.includes('tr.compose-sortable')) rows.forEach(project => callback.call({project})); },
      prop(name, value) { buttons[selector + ':' + name] = value; return this; },
      val(value) { buttons[selector + ':label'] = value; return this; }
    };
  }
  function request(payload, success) {
    var entry = { payload, success };
    requests.push(entry);
    return {
      done(callback) { entry.success = callback; return this; },
      fail(callback) { entry.failure = callback; return this; }
    };
  }
  $.post = (url, payload, callback) => request(payload, callback);
  $.ajax = options => request(options.data);
  function respond(index, body) { requests[index].success(JSON.stringify(body)); }
  function fail(index) { requests[index].failure({}, 'timeout', 'late failure'); }
  function singleResult(stamp) {
    return { result: 'success', updates: [{name:'app', image:'app:latest', hasUpdate:true, updateStatus:'update-available'}],
      projectName: 'a', recheckToken: null };
  }
  function info(stamp) { return { lastChecked: stamp, containers: [], hasUpdate: false }; }
  function bulkResult(stacks, superseded = []) { return { result:'success', stacks, superseded }; }
  function ackCount() { return requests.filter(r => r.payload.action === 'clearStackRecheck').length; }
  function snapshot() {
    return structuredClone({ evidence: stackUpdateStatus, state: composeUpdateCheckState, revision: composeUpdateCheckRevision,
      paints, notices, warnings, buttons, actions: requests.map(r => [r.payload.action, r.payload.script]), acks: ackCount() });
  }
`;

async function run(page: Page, scenario: string) {
  return page.evaluate(({ harness, checks, factories, hooks, scenario }) =>
    new Function(harness + factories + checks + hooks + scenario)(),
  { harness, checks, factories, hooks, scenario });
}

test.use({ storageState: { cookies: [], origins: [] } });

for (const outcome of ['error', 'network', 'success', 'empty']) {
  test(`presentation metadata ${outcome} preserves the response contract`, async ({ page }) => {
    const result = await run(page, `
      return (async function() {
        persistentContainerCache = { existing: { app: {icon:'kept.png'} } };
        var pending = loadPersistentContainerCache();
        if (${JSON.stringify(outcome)} === 'network') fail(0);
        else requests[0].success(${outcome === 'error'
          ? "{result:'error', message:'Invalid container metadata.'}"
          : outcome === 'empty'
            ? "{result:'success', cache:[]}"
            : "{result:'success', cache:{fresh:{app:{icon:'new.png'}}}}"});
        await pending;
        return {cache:persistentContainerCache, warnings};
      })();
    `);
    if (outcome === 'error' || outcome === 'network') {
      expect(result.cache.existing.app.icon).toBe('kept.png');
      expect(result.warnings).toHaveLength(1);
      expect(result.warnings[0].title).toBe('Container metadata unavailable');
    } else {
      expect(result.warnings).toEqual([]);
      expect(result.cache).toEqual(outcome === 'empty' ? {} : { fresh: { app: { icon: 'new.png' } } });
    }
  });
}

test('single after bulk owns only its project; bulk merges the other project', async ({ page }) => {
  const result = await run(page, `
    stackUpdateStatus.retained = info(9);
    checkAllUpdates();
    checkStackUpdates('a');
    respond(1, singleResult());
    respond(0, bulkResult({a:info(1), b:info(2)}));
    return snapshot();
  `);
  expect(result.evidence.a.containers[0].hasUpdate).toBe(true);
  expect(result.evidence.b.lastChecked).toBe(2);
  expect(result.evidence.retained.lastChecked).toBe(9);
  expect(result.revision).toMatchObject({ a: 1, b: 1 });
  expect(result.acks).toBe(1);
});

test('bulk after single rejects the late single success without acknowledging', async ({ page }) => {
  const result = await run(page, `
    checkStackUpdates('a');
    checkAllUpdates();
    respond(1, bulkResult({a:info(2), b:info(2)}));
    respond(0, singleResult());
    return snapshot();
  `);
  expect(result.evidence.a.lastChecked).toBe(2);
  expect(result.acks).toBe(0);
  expect(result.revision.a).toBe(1);
});

for (const late of ['transport', 'malformed', 'server']) {
  test(`late ${late} bulk failure cannot fail a newer single`, async ({ page }) => {
    const result = await run(page, `
      checkAllUpdates();
      checkStackUpdates('a');
      respond(1, singleResult());
      ${late === 'transport' ? 'fail(0);' : late === 'malformed'
        ? "requests[0].success('{broken');" : "respond(0, {result:'error'});"}
      return snapshot();
    `);
    expect(result.state.a).toBeUndefined();
    expect(result.state.b).toBe('failed');
    expect(result.evidence.a.hasUpdate).toBe(true);
    expect(result.acks).toBe(1);
  });
}

test('late single failure cannot fail a newer bulk and overlapping bulk buttons stay owned', async ({ page }) => {
  const result = await run(page, `
    checkStackUpdates('a');
    checkAllUpdates();
    checkAllUpdates();
    respond(1, bulkResult({a:info(1), b:info(1)}));
    var stillDisabled = buttons['#checkUpdatesBtn:disabled'];
    respond(2, bulkResult({a:info(2), b:info(2)}));
    fail(0);
    return { ...snapshot(), stillDisabled };
  `);
  expect(result.stillDisabled).toBe(true);
  expect(result.buttons['#checkUpdatesBtn:disabled']).toBe(false);
  expect(result.evidence.a.lastChecked).toBe(2);
  expect(result.state).toEqual({});
  expect(result.notices).toEqual([]);
});

for (const oldFirst of [true, false]) {
  test(`older overlapping bulk cannot lazily claim a newly discovered project (${oldFirst ? 'old' : 'new'} responds first)`, async ({ page }) => {
    const result = await run(page, `
      checkAllUpdates();
      checkAllUpdates();
      rows.add('discovered');
      ${oldFirst
        ? "respond(0, bulkResult({a:info(1), b:info(1), discovered:info(1)}));"
        : "respond(1, bulkResult({a:info(2), b:info(2)}));"}
      var interim = snapshot();
      ${oldFirst
        ? "respond(1, bulkResult({a:info(2), b:info(2), discovered:info(2)}));"
        : "respond(0, bulkResult({a:info(1), b:info(1), discovered:info(1)}));"}
      return { ...snapshot(), interim };
    `);
    expect(result.interim.evidence.discovered).toBeUndefined();
    expect(result.evidence.discovered?.lastChecked).toBe(oldFirst ? 2 : undefined);
    expect(result.revision.discovered).toBe(oldFirst ? 1 : undefined);
    expect(result.evidence.a.lastChecked).toBe(2);
  });
}

for (const cacheFirst of [true, false]) {
  test(`saved reads never supersede fresh checks (cache starts ${cacheFirst ? 'before' : 'after'})`, async ({ page }) => {
    const result = await run(page, `
      ${cacheFirst ? "loadSavedUpdateStatus(); checkStackUpdates('a');" : "checkStackUpdates('a'); loadSavedUpdateStatus();"}
      respond(${cacheFirst ? 1 : 0}, singleResult());
      respond(${cacheFirst ? 0 : 1}, bulkResult({a:info(1), b:info(1)}));
      loadSavedUpdateStatus();
      respond(requests.length - 1, bulkResult({a:info(0), b:info(3)}));
      return snapshot();
    `);
    expect(result.evidence.a.hasUpdate).toBe(true);
    expect(result.evidence.b.lastChecked).toBe(3);
    expect(result.revision).toEqual({ a: 1 });
  });
}

test('older saved success and failure do not supersede a newer saved response', async ({ page }) => {
  const result = await run(page, `
    loadSavedUpdateStatus();
    loadSavedUpdateStatus();
    respond(1, bulkResult({a:info(2)}));
    respond(0, bulkResult({a:info(1)}));
    fail(0);
    return { ...snapshot(), loaded: savedUpdateStatusLoaded };
  `);
  expect(result.evidence.a.lastChecked).toBe(2);
  expect(result.loaded).toBe(true);
  expect(result.notices).toEqual([]);
});

test('action invalidation rejects single, bulk, saved and ack callbacks and defers retry', async ({ page }) => {
  const result = await run(page, `
    loadSavedUpdateStatus();
    checkAllUpdates();
    checkStackUpdates('a');
    respond(2, singleResult());
    var operation = setStackActionInProgress('a', true, 'updating');
    respond(3, {result:'success', remaining:['a']});
    respond(0, bulkResult({a:info(0)}));
    respond(1, bulkResult({a:info(0), b:info(1)}));
    checkStackUpdates('a');
    processPendingUpdateChecks(); flush();
    var before = requests.length;
    setStackActionInProgress('a', false, null, operation);
    processPendingUpdateChecks(); flush();
    return { ...snapshot(), before };
  `);
  expect(result.before).toBe(5);
  expect(result.actions).toHaveLength(6);
  expect(result.actions[5]).toEqual(['checkStackUpdates', 'a']);
  expect(result.evidence.a.hasUpdate).toBe(true);
  expect(result.revision.a).toBe(1);
  expect(result.warnings).toEqual([]);
});

test('deletion invalidates every callback and a previously scheduled pending drain', async ({ page }) => {
  const result = await run(page, `
    loadSavedUpdateStatus();
    checkAllUpdates();
    checkStackUpdates('a');
    checkPendingRechecks();
    respond(2, singleResult());
    respond(3, {result:'success', pending:{a:1}});
    purgeDeletedStackCaches('a', 'a');
    rows.delete('a');
    respond(0, bulkResult({a:info(0)}));
    respond(1, bulkResult({a:info(0), b:info(1)}));
    respond(4, {result:'success', remaining:['a']});
    fail(2);
    processPendingUpdateChecks(); flush();
    return snapshot();
  `);
  expect(result.evidence.a).toBeUndefined();
  expect(result.state.a).toBeUndefined();
  expect(result.actions).toHaveLength(6);
  expect(result.warnings).toEqual([]);
});

for (const via of ['bulk', 'pending']) {
  test(`committed same-name recreation accepts ${via} checks without reviving pre-delete callbacks`, async ({ page }) => {
    const result = await run(page, `
      loadSavedUpdateStatus();
      checkStackUpdates('a');
      checkAllUpdates();
      checkPendingRechecks();
      purgeDeletedStackCaches('a', 'a');
      rows.delete('a');
      // Requests, including an explicit single, cannot reactivate a tombstone.
      checkStackUpdates('a');
      composeUpdateChecks.rowCommitted('a');
      rows.add('a');
      composeUpdateChecks.rowCommitted('a');
      respond(0, bulkResult({a:info(0)}));
      respond(1, singleResult());
      respond(2, bulkResult({a:info(0), b:info(1)}));
      respond(3, {result:'success', pending:{a:1}});
      var beforeFresh = snapshot();
      ${via === 'bulk' ? `
        checkAllUpdates();
        respond(requests.length - 1, bulkResult({a:info(2), b:info(2)}));
      ` : `
        checkPendingRechecks();
        respond(requests.length - 1, {result:'success', pending:{a:1}});
        processPendingUpdateCheckForStack('a'); flush();
        respond(requests.length - 1, singleResult());
      `}
      fail(1);
      return { ...snapshot(), beforeFresh };
    `);
    expect(result.beforeFresh.evidence.a).toBeUndefined();
    expect(result.beforeFresh.state.a).toBeUndefined();
    expect(result.beforeFresh.acks).toBe(0);
    expect(result.evidence.a).toBeDefined();
    expect(result.revision.a).toBe(2); // Deletion invalidation plus one accepted new check.
    expect(result.acks).toBe(via === 'pending' ? 1 : 0);
    expect(result.notices.filter((message: string) => message === 'Update check failed')).toEqual([]);
  });
}

test('missing and busy rows queue once, drain when ready, and deletion cancels queued timers', async ({ page }) => {
  const result = await run(page, `
    rows.delete('a');
    var operation = setStackActionInProgress('b', true, 'updating');
    checkAllUpdates();
    respond(0, bulkResult({a:info(0), b:info(0)}));
    checkPendingRechecks();
    respond(1, {result:'success', pending:{a:1, b:1}});
    processPendingUpdateChecks(); flush();
    var before = requests.length;
    rows.add('a');
    processPendingUpdateCheckForStack('a');
    processPendingUpdateCheckForStack('a');
    purgeDeletedStackCaches('a', 'a');
    setStackActionInProgress('b', false, null, operation);
    processPendingUpdateChecks(); flush();
    return { ...snapshot(), before };
  `);
  expect(result.before).toBe(2);
  expect(result.actions).toEqual([
    ['checkAllStacksUpdates', undefined], ['getPendingRecheckStacks', undefined], ['checkStackUpdates', 'b'],
  ]);
  expect(result.evidence.a).toBeUndefined();
  expect(result.evidence.b).toBeUndefined();
});

test('explicit superseded results retain retry state without failure or acknowledgement', async ({ page }) => {
  const result = await run(page, `
    stackUpdateStatus.a = info(9);
    checkStackUpdates('a');
    respond(0, {result:'superseded'});
    var single = snapshot();
    checkAllUpdates();
    respond(1, bulkResult({a:info(0)}, ['a', 'b']));
    var beforeRetry = snapshot();
    processPendingUpdateChecks(); flush();
    return { single, beforeRetry, ...snapshot() };
  `);
  expect(result.single.state).toEqual({});
  expect(result.beforeRetry.evidence.a.lastChecked).toBe(9);
  expect(result.beforeRetry.evidence.b).toBeUndefined();
  expect(result.notices).toEqual([]);
  expect(result.acks).toBe(0);
  expect(result.actions.slice(2)).toEqual([['checkStackUpdates', 'a'], ['checkStackUpdates', 'b']]);
});

test('failed or retained acknowledgements retry, but stale ack failure does not warn', async ({ page }) => {
  const result = await run(page, `
    checkStackUpdates('a');
    respond(0, singleResult());
    fail(1);
    processPendingUpdateChecks(); flush();
    respond(2, singleResult());
    respond(3, {result:'success', remaining:['a']});
    processPendingUpdateChecks(); flush();
    fail(1);
    return snapshot();
  `);
  expect(result.actions.filter((action: string[]) => action[0] === 'checkStackUpdates')).toHaveLength(3);
  expect(result.warnings).toHaveLength(1);
});

test('prototype project names use own entries for ownership, cache merge and ack tokens', async ({ page }) => {
  const result = await run(page, `
    rows = new Set(['__proto__', 'constructor', 'toString']);
    checkAllUpdates();
    var stacks = Object.create(null);
    rows.forEach(project => stacks[project] = info(1));
    respond(0, bulkResult(stacks));
    rows.forEach(project => checkStackUpdates(project));
    [1,2,3].forEach(index => respond(index, singleResult()));
    return { ...snapshot(), evidenceKeys: Object.keys(stackUpdateStatus),
      revisions: Object.values(composeUpdateCheckRevision),
      tokenKeys: requests.filter(r => r.payload.action === 'clearStackRecheck')
        .map(r => Object.keys(JSON.parse(r.payload.tokens))[0]) };
  `);
  expect(result.evidenceKeys.sort()).toEqual(['__proto__', 'constructor', 'toString'].sort());
  expect(result.revisions).toEqual([2, 2, 2]);
  expect(result.tokenKeys).toEqual(['__proto__', 'constructor', 'toString']);
  expect(result.acks).toBe(3);
});

test('a late saved failure cannot reset loaded state after a fresh check', async ({ page }) => {
  const result = await run(page, `
    savedUpdateStatusLoaded = true;
    loadSavedUpdateStatus();
    checkStackUpdates('a');
    respond(1, singleResult());
    fail(0);
    return { ...snapshot(), loaded: savedUpdateStatusLoaded };
  `);
  expect(result.loaded).toBe(true);
  expect(result.notices).toEqual([]);
  expect(result.state).toEqual({});
});

test('a saved read concurrent with a check still discovers unrelated pending projects', async ({ page }) => {
  const result = await run(page, `
    loadSavedUpdateStatus();
    checkStackUpdates('a');
    respond(1, singleResult());
    respond(0, bulkResult({a:info(0), b:info(0)}));
    respond(3, {result:'success', pending:{a:1, b:1}});
    processPendingUpdateChecks(); flush();
    return snapshot();
  `);
  expect(result.actions.slice(4)).toEqual([['checkStackUpdates', 'b']]);
  expect(result.evidence.a.hasUpdate).toBe(true);
});

test('pending queue reads cannot resurrect a successfully acknowledged token', async ({ page }) => {
  const result = await run(page, `
    checkStackUpdates('a');
    respond(0, singleResult());
    checkPendingRechecks();
    respond(1, {result:'success', remaining:[]});
    respond(2, {result:'success', pending:{a:1}});
    processPendingUpdateChecks(); flush();
    return snapshot();
  `);
  expect(result.actions).toHaveLength(3);
  expect(result.revision.a).toBe(1);
});

for (const response of ['success', 'failure']) {
  test(`action invalidation rejects late single ${response} and retains the retry`, async ({ page }) => {
    const result = await run(page, `
      checkStackUpdates('a');
      var operation = setStackActionInProgress('a', true, 'updating');
      ${response === 'success' ? 'respond(0, singleResult());' : 'fail(0);'}
      setStackActionInProgress('a', false, null, operation);
      processPendingUpdateChecks(); flush();
      return snapshot();
    `);
    expect(result.evidence.a).toBeUndefined();
    expect(result.acks).toBe(0);
    expect(result.actions).toHaveLength(2);
    expect(result.notices.filter((message: string) => message === 'Update check failed')).toEqual([]);
  });
}
