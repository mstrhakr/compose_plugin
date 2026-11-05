import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeManagerMain.js'), 'utf8');
function fn(name: string, next: string): string {
  return source.slice(source.indexOf(`function ${name}(`), source.indexOf(`function ${next}(`));
}
const runtime = `var composeUpdateCheckRevision = {}, composeHealthRefreshTimers = {};
${fn('composeCheckedContainers', 'composeContainerUpdateBadge')}
${fn('composeNormalizeHealthStatus', 'composeHealthMeta')}
${fn('reconcileStackRuntimeMetadata', 'updateParentStackFromContainers')}
${fn('beginStackRuntimeRequest', 'prefetchStackDetailsInBackground')}`;
const refresh = fn('refreshStackRow', 'setStackActionInProgress');
const details = 'var composeUpdateCheckRevision = {};\n' + fn('loadStackContainerDetails', 'renderContainerDetails')
  .split("$(document).on('compose-list-loaded'")[0];

test.describe('Runtime reconciliation', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('superseded list metadata and row callbacks cannot replace the latest list', async ({ page }) => {
    const list = fn('composeLoadlist', 'initStackListUI');
    const result = await page.evaluate(async list => {
      return await new Function(`
        var composeListGeneration = 0, cancelComposeListLoad = null;
        var composeTimers = {}, requests = [], writes = [], events = [], initialized = 0, refreshed = [];
        var window = {}, caURL = '/exec', compose_root = '/stacks';
        var savedUpdateStatusLoaded = true, stackRuntimeGeneration = {};
        var composeDefaultExpandQueue = Promise.resolve();
        var element = {
          length: 0, off() { return this; }, on() { return this; },
          html(value) { writes.push(value); return this; },
          before(value) { writes.push(value); return this; },
          each() { return this; }, text() { return this; },
          remove() { return this; }, show() { return this; },
          find() { return this; }, data() { return undefined; }
        };
        var $ = selector => typeof selector === 'string' && selector.startsWith('<') ? selector : element;
        $.ajax = options => {
          var entry = { options };
          requests.push(entry);
          return {
            done(cb) { entry.done = cb; return this; },
            fail(cb) { entry.fail = cb; return this; },
            always(cb) { entry.always = cb; return this; }
          };
        };
        element.trigger = event => events.push(event);
        function tryParseJson(value) { return JSON.parse(value); }
        function composeLogger() {}
        function showComposeSpinner() {}
        function hideComposeSpinner() {}
        function flushComposeListRefreshedDebounce() {}
        function initStackListUI() { initialized++; }
        function ensureComposeDefaultExpandSettings() {}
        function prefetchStackDetailsInBackground() {}
        function initializeProgressiveLoadedRows() {}
        function processPendingUpdateCheckForStack() {}
        function refreshStackByProject(project) { refreshed.push(project); }
        ${list}
        var first = composeLoadlist();
        requests[0].done('{"result":"success","projects":["stack"]}');
        var second = composeLoadlist();
        var third = composeLoadlist();
        requests[2].done('{"result":"success","projects":["obsolete-meta"]}');
        requests[3].done('{"result":"success","projects":["stack"]}');
        stackRuntimeGeneration.stack = 1;
        requests[4].done('{"result":"success","html":"<tr>latest</tr>"}');
        requests[4].always();
        requests[1].done('{"result":"success","html":"<tr>obsolete-row</tr>"}');
        requests[1].always();
        requests[1].fail({}, 'timeout', 'old request failed');
        return Promise.all([first, second, third]).then(modes => ({
          modes, writes, initialized, events, refreshed,
          timeouts: requests.map(request => request.options.timeout),
          projects: requests.map(request => request.options.data.project).filter(Boolean)
        }));
      `)();
    }, list);
    expect(result.modes).toEqual(['superseded', 'superseded', 'progressive']);
    expect(result.writes).toContain('<tr>latest</tr>');
    expect(result.writes.join('')).not.toContain('obsolete');
    expect(result.initialized).toBe(1);
    expect(result.events).toEqual(['compose-list-loaded']);
    expect(result.timeouts).toEqual([30000, 30000, 30000, 30000, 30000]);
    expect(result.projects).toEqual(['stack', 'stack']);
    expect(result.refreshed).toEqual(['stack']);
  });

  test('detail refreshes requested during a load are replayed and failures respect collapse intent', async ({ page }) => {
    const result = await page.evaluate(details => new Function(`
      var stackDetailsDesiredExpanded = { '1': true }, expandedStacks = {};
      var stackDetailsLoading = {}, stackDetailsLoadPromises = {}, stackDetailsReloadRequested = {};
      var stackDetailsPrefetchCache = {}, stackDetailsPrefetchPromises = {};
      var stackRuntimeGeneration = {}, stackContainersCache = {};
      var requests = [], slides = 0, hidden = 0, html = '', finished = 0;
      var caURL = '/exec';
      var element = {
        html(value) { html = value; return this; }, is() { return false; },
        stop() { return this; }, hide() { hidden++; return this; },
        slideDown(ms, cb) { slides++; if (cb) cb(); return this; }
      };
      var $ = () => element;
      $.ajax = () => {
        var entry = {};
        requests.push(entry);
        return {
          done(cb) { entry.done = cb; return this; },
          fail(cb) { entry.fail = cb; return this; }
        };
      };
      function beginStackRuntimeRequest(project) {
        return stackRuntimeGeneration[project] = (stackRuntimeGeneration[project] || 0) + 1;
      }
      function composeLogger() {}
      function composeEscapeHtml(value) { return value; }
      function updateStackToggleAllButtonState() { finished++; }
      function tryParseJson(value) { return JSON.parse(value); }
      function renderContainerDetails() {}
      ${details}
      var first = loadStackContainerDetails('1', 'stack');
      var second = loadStackContainerDetails('1', 'stack');
      stackDetailsDesiredExpanded['1'] = false;
      beginStackRuntimeRequest('stack');
      requests[0].done('{"result":"success","containers":[]}');
      requests[1].fail();
      return Promise.all([first, second]).then(() => ({
        requests: requests.length, slides, hidden, html, finished,
        loading: stackDetailsLoading['1'], queued: !!stackDetailsReloadRequested['1']
      }));
    `)(), details);
    expect(result.requests).toBe(2);
    expect(result.slides).toBe(0);
    expect(result.hidden).toBe(2);
    expect(result.html).toContain('Failed to load container details');
    expect(result.finished).toBe(1);
    expect(result.loading).toBe(false);
    expect(result.queued).toBe(false);
  });

  test('deletion invalidates an active progressive load before scheduling a fresh list', async ({ page }) => {
    const remove = fn('removeStackFromUiByProject', 'toggleStackDetails');
    const result = await page.evaluate(remove => new Function(`
      var cancelled = 0, composeListGeneration = 3, scheduled = [], purged = [], events = [];
      var cancelComposeListLoad = () => cancelled++;
      function hideComposeSpinner() {}
      function composeLoadlist() {}
      function setTimeout(callback) { scheduled.push(callback === composeLoadlist); }
      function purgeDeletedStackCaches(project) { purged.push(project); }
      var $ = () => ({ length: 0, trigger(name, projects) { events.push([name, projects]); } });
      ${remove}
      removeStackFromUiByProject('stack');
      return { cancelled, generation: composeListGeneration, scheduled, purged, events,
        pending: !!cancelComposeListLoad };
    `)(), remove);
    expect(result).toEqual({
      cancelled: 1, generation: 4, scheduled: [true], purged: ['stack'], pending: false,
      events: [['compose-stack-removed', ['stack']]],
    });
  });

  test('a delayed prefetch waiter cannot start a request after action invalidation', async ({ page }) => {
    const result = await page.evaluate(details => new Function(`
      var stackDetailsDesiredExpanded = { '1': true }, expandedStacks = {};
      var stackDetailsLoading = {}, stackDetailsLoadPromises = {}, stackDetailsReloadRequested = {};
      var stackDetailsPrefetchCache = {}, stackRuntimeGeneration = { stack: 1 }, stackContainersCache = {};
      var resolvePrefetch, requests = 0, html = '', slides = 0;
      var stackDetailsPrefetchPromises = { stack: new Promise(resolve => resolvePrefetch = resolve) };
      var element = {
        html(value) { html = value; return this; }, is() { return false; },
        stop() { return this; }, hide() { return this; }, slideDown() { slides++; return this; }
      };
      var $ = () => element;
      $.ajax = () => { requests++; throw new Error('Obsolete waiter issued a new request'); };
      function composeLogger() {}
      function updateStackToggleAllButtonState() {}
      ${details}
      var pending = loadStackContainerDetails('1', 'stack');
      stackRuntimeGeneration.stack++;
      resolvePrefetch();
      return pending.then(() => ({ requests, html, slides, loading: stackDetailsLoading['1'] }));
    `)(), details);
    expect(result.requests).toBe(0);
    expect(result.html).toContain('state changed while loading');
    expect(result.html).not.toContain('fa-spinner');
    expect(result.slides).toBe(1);
    expect(result.loading).toBe(false);
  });

  test('late refreshes cannot roll back state; details fetch after prefetch invalidation', async ({ page }) => {
    const result = await page.evaluate(({ runtime, refresh, details }) => {
      return new Function(`
        var attributes = { id: 'stack-row-1', 'data-ctids': 'oldid' };
        var requests = [], paints = [], notifications = 0, pendingComposeRefreshCount = 0;
        var stackRuntimeGeneration = {}, stackDetailsPrefetchCache = {
          stack: { result: 'success', containers: [{ id: 'oldid', name: 'old', state: 'exited' }] }
        };
        var stackContainersCache = {}, stackStartedAtCache = { '1': 'old-start' };
        var expandedStacks = {}, stackDetailsDesiredExpanded = { '1': false };
        var stackDetailsLoading = {}, stackDetailsLoadPromises = {}, stackDetailsPrefetchPromises = {}, stackDetailsReloadRequested = {};
        var row = {
          length: 1, attr(key, value) { if (value !== undefined) { attributes[key] = value; return this; } return attributes[key]; },
          data() { return this; }, is() { return false; }, html() { return this; },
          stop() { return this; }, hide() { return this; }, slideDown(ms, cb) { if (cb) cb(); return this; }
        };
        var $ = () => row, caURL = '/exec';
        function request(callback) {
          var entry = { callback, done: callback, always: () => {} };
          requests.push(entry);
          return {
            done(cb) { entry.done = cb; return this; },
            fail() { return this; },
            always(cb) { entry.always = cb; return this; }
          };
        }
        $.ajax = () => request();
        $.post = (url, payload, callback) => request(callback);
        function createContainerInfo(ct) { return ct; }
        function mergeUpdateStatus() {}
        function composeLogger() {}
        function updateParentStackFromContainers(id, project) {
          reconcileStackRuntimeMetadata(row, project, stackContainersCache[id]);
          paints.push(stackContainersCache[id][0].state);
        }
        function triggerComposeListRefreshedDebounced() { notifications++; }
        function processPendingUpdateChecks() {}
        function updateStackToggleAllButtonState() {}
        function renderContainerDetails() {}
        function tryParseJson(data) { return JSON.parse(data); }
        ${runtime}
        ${refresh}
        ${details}
        function respond(index, state) {
          requests[index].done(JSON.stringify({ result: 'success', containers: [{
            id: '1234567890123456', name: 'stack-app-1', state
          }] }));
          requests[index].always();
        }
        refreshStackRow('1', 'stack');
        refreshStackRow('1', 'stack');
        respond(1, 'running');
        respond(0, 'exited');
        var afterRefresh = stackContainersCache['1'][0].state;
        var pending = pendingComposeRefreshCount;
        loadStackContainerDetails('1', 'stack');
        var requestCount = requests.length;
        requests[2].done(JSON.stringify({ result: 'success', containers: [{
          id: '1234567890123456', name: 'stack-app-1', state: 'running'
        }] }));
        return { afterRefresh, paints, attributes, notifications, pending, requestCount,
          startedAt: stackStartedAtCache['1'] };
      `)();
    }, { runtime, refresh, details });
    expect(result.afterRefresh).toBe('running');
    expect(result.paints).toEqual(['running', 'running']);
    expect(result.attributes['data-ctids']).toBe('123456789012');
    expect(JSON.parse(result.attributes['data-containers'])).toEqual(['stack-app-1']);
    expect(result.pending).toBe(0);
    expect(result.requestCount).toBe(3);
    expect(result.notifications).toBe(1);
    expect(result.startedAt).toBe('');
  });

  test('action invalidation rejects old responses and missing rows', async ({ page }) => {
    const actionState = fn('setStackActionInProgress', 'UpdateStackConfirmed');
    const result = await page.evaluate(({ runtime, actionState }) => new Function(`
      var stackRuntimeGeneration = {}, stackDetailsPrefetchCache = {}, composeStackActionInProgress = {};
      var $ = () => ({ length: 0 });
      function composeLogger() {}
      ${runtime}
      ${actionState}
      var old = beginStackRuntimeRequest('stack');
      stackDetailsPrefetchCache.stack = { old: true };
      setStackActionInProgress('stack', true);
      return {
        cached: !!stackDetailsPrefetchCache.stack,
        stale: reconcileStackRuntime('stack', { result: 'success', containers: [] }, old),
        missing: reconcileStackRuntime('stack', { result: 'success', containers: [] }, stackRuntimeGeneration.stack)
      };
    `)(), { runtime, actionState });
    expect(result).toEqual({ cached: false, stale: null, missing: null });
  });

  test('failed and timed-out row requests release accounting without repainting stale state', async ({ page }) => {
    const result = await page.evaluate(({ runtime, refresh }) => new Function(`
      var stackRuntimeGeneration = {}, stackDetailsPrefetchCache = {};
      var pendingComposeRefreshCount = 0, notices = [], timeout, completed = 0;
      var caURL = '/exec';
      function composeLogger(message) { notices.push(message); }
      function processPendingUpdateChecks() { completed++; }
      function updateParentStackFromContainers() { throw new Error('Stale cache repaint'); }
      var $ = { ajax(options) {
        timeout = options.timeout;
        return {
          done() { return this; },
          fail(callback) { callback({}, 'timeout', 'request timed out'); return this; },
          always(callback) { callback(); return this; }
        };
      } };
      ${runtime}
      ${refresh}
      refreshStackRow('1', 'stack');
      return { pending: pendingComposeRefreshCount, notices, timeout, completed };
    `)(), { runtime, refresh });
    expect(result).toEqual({
      pending: 0, notices: ['Runtime refresh request failed'], timeout: 30000, completed: 1,
    });
  });

  test('an in-flight prefetch cannot repopulate the cache after an action invalidates it', async ({ page }) => {
    const prefetch = fn('prefetchStackDetailsInBackground', 'openStackActionsMenu');
    const result = await page.evaluate(({ runtime, prefetch }) => new Function(`
      var stackRuntimeGeneration = {}, stackDetailsPrefetchCache = {}, stackDetailsPrefetchPromises = {};
      var caURL = '/exec', respond, finalized, paints = 0;
      var $ = { ajax() {
        return {
          done(callback) { respond = callback; return this; },
          fail() { return this; },
          always(callback) { finalized = callback; return this; }
        };
      } };
      function tryParseJson(data) { return JSON.parse(data); }
      function composeLogger() {}
      function applyStackSummaryFromResponse() { paints++; }
      ${runtime}
      ${prefetch}
      prefetchStackDetailsInBackground(['stack']);
      beginStackRuntimeRequest('stack');
      respond(JSON.stringify({ result: 'success', containers: [] }));
      finalized();
      return { cached: !!stackDetailsPrefetchCache.stack, paints,
        pending: !!stackDetailsPrefetchPromises.stack };
    `)(), { runtime, prefetch });
    expect(result).toEqual({ cached: false, paints: 0, pending: false });
  });

  test('superseding a list stops its prefetch queue and ignores its pending snapshots', async ({ page }) => {
    const prefetch = fn('prefetchStackDetailsInBackground', 'openStackActionsMenu');
    const result = await page.evaluate(({ runtime, prefetch }) => new Function(`
      var stackRuntimeGeneration = {}, stackDetailsPrefetchCache = {}, stackDetailsPrefetchPromises = {};
      var composeListGeneration = 1, caURL = '/exec', requests = [], paints = 0;
      var $ = { ajax() {
        var entry = {};
        requests.push(entry);
        return {
          done(cb) { entry.done = cb; return this; },
          fail() { return this; },
          always(cb) { entry.always = cb; return this; }
        };
      } };
      function tryParseJson(value) { return JSON.parse(value); }
      function composeLogger() {}
      function applyStackSummaryFromResponse() { paints++; }
      ${runtime}
      ${prefetch}
      prefetchStackDetailsInBackground(['a', 'b', 'c', 'd', 'e'], composeListGeneration);
      composeListGeneration++;
      requests.forEach(request => {
        request.done('{"result":"success","containers":[]}');
        request.always();
      });
      return { requests: requests.length, cached: Object.keys(stackDetailsPrefetchCache), paints };
    `)(), { runtime, prefetch });
    expect(result).toEqual({ requests: 4, cached: [], paints: 0 });
  });

  test('empty action responses clear progress and show an explicit error', async ({ page }) => {
    const action = fn('performComposeAction', 'confirmedComposeAction');
    const result = await page.evaluate(action => new Function(`
      var compURL = '/compose', progress = [], notices = [];
      function composeActionStateText() { return 'starting'; }
      function setStackActionInProgress(stack, active) { progress.push(active); }
      function tryParseJson() { return null; }
      function composeLogger() {}
      function swal(notice) { notices.push(notice.type); }
      var $ = { ajax() { return { done(callback) { callback(''); return this; }, fail() {} }; } };
      ${action}
      performComposeAction({ stackName: 'stack', actionName: 'start' });
      return { progress, notices };
    `)(), action);
    expect(result).toEqual({ progress: [true, false], notices: ['error'] });
  });

  test('stats connection and reconnection recover the publisher, but stopped sockets do not', async ({ page }) => {
    const start = source.indexOf('function initComposeDockerLoadSubscriber()');
    const subscriber = source.slice(start, source.indexOf('// Standalone compose mode can race', start));
    const result = await page.evaluate(subscriber => new Function(`
      var handlers = {}, checks = 0, starts = 0, composeListReady = true;
      var window = {}, document = { addEventListener() {} };
      var empty = { length: 0, off() { return this; }, on() { return this; }, closest() { return this; } };
      var $ = () => empty;
      function composeLogger() {}
      function composeHasVisibleLoadColumns() { return false; }
      function composeShouldEnableDockerLoad() { return false; }
      function ensureComposeInfoPublisherRunning() { checks++; }
      function setInterval() {}
      function NchanSubscriber() {
        this.on = (event, callback) => handlers[event] = callback;
        this.start = () => starts++;
        this.stop = () => {};
      }
      ${subscriber}
      initComposeDockerLoadSubscriber();
      window.composeDockerLoadToggle(true);
      handlers.connect();
      handlers.error('disconnect');
      handlers.connect();
      window.composeDockerLoadToggle(false);
      handlers.connect();
      handlers.error('late-error-after-stop');
      return { checks, starts };
    `)(), subscriber);
    expect(result).toEqual({ checks: 2, starts: 1 });
  });
});
