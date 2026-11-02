import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeManagerMain.js'), 'utf8');
function fn(name: string, next: string): string {
  return source.slice(source.indexOf(`function ${name}(`), source.indexOf(`function ${next}(`));
}
const runtime = fn('beginStackRuntimeRequest', 'prefetchStackDetailsInBackground');
const refresh = fn('refreshStackRow', 'setStackActionInProgress');
const details = fn('loadStackContainerDetails', 'renderContainerDetails')
  .split("$(document).on('compose-list-loaded'")[0];

test.describe('Runtime reconciliation', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

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
        var stackDetailsLoading = {}, stackDetailsLoadPromises = {}, stackDetailsPrefetchPromises = {};
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
        function updateParentStackFromContainers(id) { paints.push(stackContainersCache[id][0].state); }
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
    expect(result.notifications).toBe(2);
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

  test('empty action responses clear progress and show an explicit error', async ({ page }) => {
    const action = fn('performComposeAction', 'confirmedComposeAction');
    const result = await page.evaluate(action => new Function(`
      var compURL = '/compose', progress = [], notices = [];
      function composeActionStateText() { return 'starting'; }
      function setStackActionInProgress(stack, active) { progress.push(active); }
      function tryParseJson() { return null; }
      function composeLogger() {}
      function swal(notice) { notices.push(notice.type); }
      var $ = { post(url, payload, callback) { callback(''); return { fail() {} }; } };
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
