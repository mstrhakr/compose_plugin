import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeManagerMain.js'), 'utf8');
function fn(name: string, next: string): string {
  return source.slice(source.indexOf(`function ${name}(`), source.indexOf(`function ${next}(`));
}
const state = fn('setStackActionInProgress', 'UpdateStackConfirmed');
const dispatch = `function openComposeActionBox(url) { openBox(url); }\n` + fn('performComposeAction', 'confirmedComposeAction');
const poll = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeOperations.js'), 'utf8') +
  fn('pollBackgroundCompletion', 'composeActionStateText');

test.describe('Operation state ownership', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('queued and malformed receipts cannot finish a tracked operation even when the stack is unlocked', async ({ page }) => {
    const result = await page.evaluate(({ state, poll }) => new Function(`
      var composeStackActionInProgress = {}, timers = [], request, refreshed = 0, caURL = '/exec', notices = [], payloads = [];
      var $ = () => ({ length: 0 });
      $.ajax = options => {
        payloads.push(options.data);
        request = {};
        return {
          done(callback) { request.done = callback; return this; },
          fail(callback) { request.fail = callback; return this; },
          always(callback) { request.always = callback; return this; }
        };
      };
      function setTimeout(callback) { timers.push(callback); }
      function composeLogger() {}
      function swal(value) { notices.push(value.type); }
      function beginStackRuntimeRequest() {}
      function refreshStackByProject() { refreshed++; }
      function checkPendingRechecks() {}
      function tryParseJson(value) { try { return JSON.parse(value); } catch { return null; } }
      ${state}
      ${poll}
      var operation = setStackActionInProgress('stack', true, 'starting');
      operation.receipt = 'dispatch-receipt';
      pollBackgroundCompletion('stack', 0, operation);
      var active = [];
      for (var response of [
        { result: 'success', locked: false, operation: { state: 'queued' } },
        { result: 'success', operation: { state: 'running' } },
        { result: 'success', operation: { state: 'completed' } },
        { result: 'error', message: 'missing receipt' },
        { result: 'success', operation: { state: 'completed', exitCode: 7 } }
      ]) {
        timers.shift()();
        request.done(response);
        request.always();
        active.push(!!composeStackActionInProgress.stack);
      }
      timers.shift()();
      return { active, refreshed, notices, payloads, timers: timers.length };
    `)(), { state, poll });
    expect(result.active).toEqual([true, true, true, true, false]);
    expect(result.refreshed).toBe(1);
    expect(result.notices).toEqual(['error']);
    expect(result.timers).toBe(0);
    expect(result.payloads).toHaveLength(5);
    for (const payload of result.payloads) {
      expect(payload).toEqual({ action: 'getUiOperation', script: 'stack', operationId: 'dispatch-receipt' });
    }
  });

  test('foreground single and bulk dispatches keep progress until their own receipts finish', async ({ page }) => {
    const result = await page.evaluate(({ state, dispatch }) => new Function(`
      var composeStackActionInProgress = {}, requests = [], opened = [], polls = [], refreshed = [], compURL = '/compose';
      var $ = () => ({ length: 0 });
      $.ajax = options => {
        var request = { options };
        requests.push(request);
        return { done(callback) { request.done = callback; return this; }, fail() { return this; } };
      };
      function composeLogger() {}
      function beginStackRuntimeRequest() {}
      function composeActionStateText() { return 'working'; }
      function pollBackgroundCompletion(project, delay, operation) { polls.push([project, operation.receipt]); }
      function queuePendingComposeReloads(projects) { refreshed.push(...projects); }
      function openBox(url) { opened.push(url); }
      ${state}
      ${dispatch}
      var url = '/plugins/compose.manager/include/ShowTtyd.php?done=1';
      performComposeAction({ stackName: 'single', actionName: 'up' });
      requests[0].done({ url, operations: { single: 'single-receipt' } });
      performMultipleComposeAction([{ project: 'first' }, { project: 'second' }, { project: 'skipped' }], { actionName: 'up' });
      requests[1].done({ url, operations: { first: 'first-receipt', second: 'second-receipt' } });
      return { active: Object.keys(composeStackActionInProgress), opened, polls, refreshed,
        tracking: requests.map(request => request.options.data.trackOperation) };
    `)(), { state, dispatch });
    expect(result.active).toEqual(['single', 'first', 'second']);
    expect(result.polls).toEqual([['single', 'single-receipt'], ['first', 'first-receipt'], ['second', 'second-receipt']]);
    expect(result.opened).toHaveLength(2);
    expect(result.refreshed).toEqual(['skipped']);
    expect(result.tracking).toEqual(['1', '1']);
  });

  test('viewer startup warnings preserve single and bulk receipt tracking', async ({ page }) => {
    const result = await page.evaluate(({ state, dispatch }) => new Function(`
      var composeStackActionInProgress = {}, requests = [], polls = [], notices = [], compURL = '/compose';
      var $ = () => ({ length: 0 });
      $.ajax = options => {
        var request = { options }; requests.push(request);
        return { done(callback) { request.done = callback; return this; }, fail() { return this; } };
      };
      function composeLogger() {}
      function beginStackRuntimeRequest() {}
      function composeActionStateText() { return 'working'; }
      function pollBackgroundCompletion(project, delay, operation) { polls.push([project, operation.receipt]); }
      function swal(value) { notices.push(value); }
      function openBox() { throw new Error('Unavailable viewer must not open'); }
      ${state}
      ${dispatch}
      performComposeAction({ stackName: 'single', actionName: 'up' });
      requests[0].done({ background: true, warning: true, message: 'Viewer unavailable', operations: { single: 'receipt' } });
      performMultipleComposeAction([{ project: 'bulk' }], { actionName: 'up' });
      requests[1].done({ background: true, warning: true, message: 'Startup uncertain', operations: { bulk: 'bulk-receipt' } });
      return { active: Object.keys(composeStackActionInProgress), polls, notices };
    `)(), { state, dispatch });
    expect(result.active).toEqual(['single', 'bulk']);
    expect(result.polls).toEqual([['single', 'receipt'], ['bulk', 'bulk-receipt']]);
    expect(result.notices.map((notice: { type: string }) => notice.type)).toEqual(['warning', 'warning']);
  });

  test('receipt completion reconciles parent and expanded containers from the same fresh snapshot', async ({ page }) => {
    const runtime = `var composeUpdateCheckRevision = {};
${fn('composeCheckedContainers', 'composeContainerUpdateBadge')}
${fn('beginStackRuntimeRequest', 'prefetchStackDetailsInBackground')}`;
    const refresh = fn('refreshStackByProject', 'setStackActionInProgress');
    const result = await page.evaluate(({ poll, runtime, refresh }) => [true, false].map(expanded => new Function('expanded', `
      var operation = { receipt: 'current-dispatch' }, composeStackActionInProgress = { stack: operation };
      var caURL = '/exec', requests = [], timers = [], parent = [], details = [], notices = [];
      var stackRuntimeGeneration = {}, stackDetailsPrefetchCache = {}, stackContainersCache = {}, stackStartedAtCache = {};
      var expandedStacks = { '1': expanded }, stackDetailsDesiredExpanded = { '1': expanded }, pendingComposeRefreshCount = 0;
      var attributes = { id: 'stack-row-1' };
      var row = {
        length: 1, data() { return this; },
        attr(key, value) { if (value !== undefined) { attributes[key] = value; return this; } return attributes[key]; },
        is() { return expanded; }, slideDown() { return this; }
      };
      var $ = () => row;
      $.ajax = options => {
        var request = { options };
        requests.push(request);
        return {
          done(callback) { request.done = callback; return this; },
          fail() { return this; }, always(callback) { request.always = callback; return this; }
        };
      };
      function setTimeout(callback) { timers.push(callback); }
      function setStackActionInProgress() { delete composeStackActionInProgress.stack; }
      function tryParseJson(value) { return JSON.parse(value); }
      function composeLogger() {}
      function swal(value) { notices.push(value.type); }
      function checkPendingRechecks() {}
      function createContainerInfo(value) { return value; }
      function mergeUpdateStatus() {}
      function updateParentStackFromContainers(id) { parent.push(stackContainersCache[id][0].state); }
      function triggerComposeListRefreshedDebounced() {}
      function reconcileStackRuntimeMetadata() {}
      function processPendingUpdateChecks() {}
      function renderContainerDetails(id, containers) { details.push(containers[0].state); }
      ${runtime}
      ${refresh}
      ${poll}
      pollBackgroundCompletion('stack', 0, operation);
      timers.shift()();
      requests[0].done({ result: 'success', operation: { state: 'completed', exitCode: 0 } });
      requests[0].always();
      timers.shift()();
      requests[1].done({ result: 'success', containers: [{ id: 'new-id', name: 'app', state: 'running' }] });
      requests[1].always();
      return { expanded, parent, details, notices, active: !!composeStackActionInProgress.stack, pendingComposeRefreshCount };
    `)(expanded)), { poll, runtime, refresh });
    for (const item of result) {
      expect(item.parent).toEqual(['running']);
      expect(item.details).toEqual(item.expanded ? ['running'] : []);
      expect(item.notices).toEqual([]);
      expect(item.active).toBe(false);
      expect(item.pendingComposeRefreshCount).toBe(0);
    }
  });

  test('follow-logs retains its existing attached terminal path instead of polling an endless command', async ({ page }) => {
    const result = await page.evaluate(({ state, dispatch }) => new Function(`
      var composeStackActionInProgress = {}, request, opened = [], refresh = [], compURL = '/compose';
      var $ = () => ({ length: 0 });
      $.ajax = options => {
        request = { options };
        return { done(callback) { request.done = callback; return this; }, fail() { return this; } };
      };
      function composeLogger() {}
      function beginStackRuntimeRequest() {}
      function composeActionStateText() { return 'starting'; }
      function tryParseJson() { return null; }
      function queuePendingComposeReloads(projects) { refresh.push(...projects); }
      function openBox(url) { opened.push(url); }
      ${state}
      ${dispatch}
      performComposeAction({ stackName: 'stack', actionName: 'up', pendingReload: true, payload: { followLogs: true } });
      var url = '/plugins/compose.manager/include/ShowTtyd.php?done=1&path=stack';
      request.done(url);
      return { tracking: request.options.data.trackOperation, active: !!composeStackActionInProgress.stack, opened, refresh };
    `)(), { state, dispatch });
    expect(result.tracking).toBe('0');
    expect(result.active).toBe(false);
    expect(result.opened).toEqual(['/plugins/compose.manager/include/ShowTtyd.php?done=1&path=stack']);
    expect(result.refresh).toEqual(['stack']);
  });

  test('an old dispatch response or transport failure cannot finish a newer action', async ({ page }) => {
    const result = await page.evaluate(({ state, dispatch }) => new Function(`
      var composeStackActionInProgress = {}, requests = [], opened = [], notices = [];
      var compURL = '/compose';
      var $ = () => ({ length: 0 });
      $.ajax = () => {
        var entry = {};
        requests.push(entry);
        return { done(cb) { entry.callback = cb; return this; }, fail(cb) { entry.fail = cb; return this; } };
      };
      function composeLogger() {}
      function beginStackRuntimeRequest() {}
      function composeActionStateText() { return 'starting'; }
      function tryParseJson(value) { try { return JSON.parse(value); } catch { return null; } }
      function swal(value) { notices.push(value.type); }
      function composeHandleIdentityError() { swal({ type: 'error' }); }
      function openBox(value) { opened.push(value); }
      ${state}
      ${dispatch}
      performComposeAction({ stackName: 'stack', actionName: 'up' });
      var old = composeStackActionInProgress.stack;
      performComposeAction({ stackName: 'stack', actionName: 'stop' });
      var current = composeStackActionInProgress.stack;
      requests[0].callback('/old-terminal');
      requests[0].fail();
      var stillCurrent = composeStackActionInProgress.stack === current;
      requests[1].callback('');
      return { distinct: old !== current, stillCurrent, opened, notices,
        active: !!composeStackActionInProgress.stack };
    `)(), { state, dispatch });
    expect(result).toEqual({ distinct: true, stillCurrent: true, opened: [], notices: ['error'], active: false });
  });

  test('an old in-flight completion poll cannot clear or refresh a newer action', async ({ page }) => {
    const result = await page.evaluate(({ state, poll }) => new Function(`
      var composeStackActionInProgress = {}, timers = [], request, refreshed = 0, caURL = '/exec';
      var $ = () => ({ length: 0 });
      $.ajax = () => {
        request = {};
        return {
          done(cb) { request.done = cb; return this; },
          fail() { return this; },
          always(cb) { request.always = cb; return this; }
        };
      };
      function setTimeout(callback) { timers.push(callback); }
      function composeLogger() {}
      function beginStackRuntimeRequest() {}
      function refreshStackByProject() { refreshed++; }
      function checkPendingRechecks() {}
      ${state}
      ${poll}
      var old = setStackActionInProgress('stack', true, 'starting');
      old.receipt = 'old-dispatch';
      pollBackgroundCompletion('stack', 0, old);
      timers.shift()();
      var current = setStackActionInProgress('stack', true, 'stopping');
      request.done({ result: 'success', operation: { state: 'completed', exitCode: 0 } });
      request.always();
      return { current: composeStackActionInProgress.stack === current, refreshed, timers: timers.length };
    `)(), { state, poll });
    expect(result).toEqual({ current: true, refreshed: 0, timers: 0 });
  });

  test('replacement rows regain progress without creating another operation or saving spinner text', async ({ page }) => {
    const result = await page.evaluate(state => new Function(`
      var composeStackActionInProgress = {}, invalidations = 0;
      function element(text, classes) {
        var data = {}, attrs = { class: classes };
        return {
          length: 1, value: text, attrs,
          data(key, value) { if (arguments.length === 2) { data[key] = value; return this; } return data[key]; },
          removeData(key) { delete data[key]; return this; },
          attr(key) { return attrs[key]; },
          removeClass() { attrs.class = ''; return this; },
          addClass(value) { attrs.class += value; return this; },
          text(value) { if (value !== undefined) { this.value = value; return this; } return this.value; }
        };
      }
      function makeRow() {
        var row = element('', ''), icon = element('', 'fa fa-play green-text'), label = element('started', '');
        row.find = selector => selector === 'span[data-stackid]' ? { each() {} } : selector === '.state' ? label : icon;
        return { row, icon, label };
      }
      var current = makeRow();
      var $ = () => current.row;
      function composeLogger() {}
      function beginStackRuntimeRequest() { invalidations++; }
      ${state}
      var token = setStackActionInProgress('stack', true, 'updating...');
      current = makeRow();
      renderStackActionProgress('stack');
      renderStackActionProgress('stack');
      var during = current.label.value;
      var same = token === composeStackActionInProgress.stack;
      setStackActionInProgress('stack', false, null, token);
      return { during, same, after: current.label.value, classes: current.icon.attrs.class, invalidations };
    `)(), state);
    expect(result.during).toBe('updating...');
    expect(result.same).toBe(true);
    expect(result.after).toBe('started');
    expect(result.classes).toContain('fa-play');
    expect(result.invalidations).toBe(1);
  });

  test('bulk errors and foreground success settle every owned stack', async ({ page }) => {
    const result = await page.evaluate(({ state, dispatch }) => {
      return ['network', 'empty', 'invalid', 'error', 'foreground', 'background'].map(mode => new Function('mode', `
        var composeStackActionInProgress = {}, request, refresh = [], polls = [], notices = [];
        var compURL = '/compose';
        var $ = () => ({ length: 0 });
        $.ajax = () => {
          request = {};
          return { done(cb) { request.callback = cb; return this; }, fail(cb) { request.fail = cb; return this; } };
        };
        function composeLogger() {}
        function beginStackRuntimeRequest() {}
        function composeActionStateText() { return 'working'; }
        function tryParseJson(value) { try { return JSON.parse(value); } catch { return null; } }
        function swal(value) { notices.push(value.type); }
        function composeHandleIdentityError() { swal({ type: 'error' }); }
        function openBox() {}
        function notifyBackgroundStarted() {}
        function pollBackgroundCompletion(project) { polls.push(project); }
        function queuePendingComposeReloads(projects) { refresh.push(...projects); }
        ${state}
        ${dispatch}
        performMultipleComposeAction([{ project: 'a' }, { project: 'b' }], {
          actionName: 'up', background: mode === 'background'
        });
        if (mode === 'network') request.fail();
        else request.callback(mode === 'empty' ? '' : mode === 'invalid' ? '{"result":"unexpected"}' : mode === 'error'
          ? '{"error":"identity","message":"blocked"}' : mode === 'background'
            ? '{"background":true}' : '/plugins/compose.manager/include/ShowTtyd.php?done=1');
        return { mode, active: Object.keys(composeStackActionInProgress), refresh, polls, notices };
      `)(mode));
    }, { state, dispatch });
    for (const item of result) {
      expect(item.active).toEqual(item.mode === 'background' ? ['a', 'b'] : []);
      expect(item.refresh).toEqual(item.mode === 'foreground' ? ['a', 'b'] : []);
      expect(item.polls).toEqual(item.mode === 'background' ? ['a', 'b'] : []);
      expect(item.notices).toEqual(['background', 'foreground'].includes(item.mode) ? [] : ['error']);
    }
  });

  test('a bulk failure does not clear a stack that has started a newer action', async ({ page }) => {
    const result = await page.evaluate(({ state, dispatch }) => new Function(`
      var composeStackActionInProgress = {}, fail, compURL = '/compose';
      var $ = () => ({ length: 0 });
      $.ajax = () => ({ done() { return this; }, fail(callback) { fail = callback; return this; } });
      function composeLogger() {}
      function beginStackRuntimeRequest() {}
      function composeActionStateText() { return 'working'; }
      function swal() {}
      ${state}
      ${dispatch}
      performMultipleComposeAction([{ project: 'a' }, { project: 'b' }], { actionName: 'up' });
      var current = setStackActionInProgress('b', true, 'stopping');
      fail();
      return { active: Object.keys(composeStackActionInProgress), same: current === composeStackActionInProgress.b };
    `)(), { state, dispatch });
    expect(result).toEqual({ active: ['b'], same: true });
  });

  test('Update All refuses empty, malformed and failed preparation instead of dispatching', async ({ page }) => {
    const prepare = fn('executeUpdateAllStacks', 'updateStackUpdateUI');
    const result = await page.evaluate(({ state, prepare }) =>
      ['network', 'empty', 'invalid', 'error', 'success'].map(mode => new Function('mode', `
        var composeStackActionInProgress = {}, compURL = '/compose', caURL = '/exec', dispatches = 0, notices = [];
        var $ = () => ({ length: 0 });
        $.ajax = () => ({
          done(callback) {
            if (mode !== 'network') callback(mode === 'empty' ? '' : mode === 'invalid' ? '{broken' : { result: mode });
            return this;
          },
          fail(callback) { if (mode === 'network') callback(); return this; }
        });
        function composeLogger() {}
        function beginStackRuntimeRequest() {}
        function composeActionStateText() { return 'updating'; }
        function tryParseJson(value) { try { return JSON.parse(value); } catch { return null; } }
        function swal(value) { notices.push(value.type); }
        function performMultipleComposeAction() { dispatches++; }
        ${state}
        ${prepare}
        executeUpdateAllStacks([{ project: 'a', path: '/stacks/a', isRunning: true }], true, true);
        return { mode, dispatches, active: Object.keys(composeStackActionInProgress), notices };
      `)(mode)), { state, prepare });
    for (const item of result) {
      expect(item.dispatches).toBe(item.mode === 'success' ? 1 : 0);
      expect(item.active).toEqual(item.mode === 'success' ? ['a'] : []);
      expect(item.notices).toEqual(item.mode === 'success' ? [] : ['error']);
    }
  });

  test('image-preparing Up and single updates require successful persisted recheck preparation', async ({ page }) => {
    const preparation = fn('prepareStackRechecks', 'updateStackUpdateUI');
    const confirmed = fn('confirmedComposeAction', 'ComposeRecreateConfirmed');
    const force = fn('ForceUpdateStackConfirmed', 'ForceUpdateStack');
    const update = fn('UpdateStackConfirmed', 'UpdateStack');
    const results = await page.evaluate(({ preparation, confirmed, force, update }) =>
      ['up', 'prepareUp', 'update', 'force'].flatMap(action =>
        ['network', 'empty', 'invalid', 'error', 'success'].map(mode => new Function('action', 'mode', `
          var requests = [], dispatched = [], notices = [], caURL = '/exec', compURL = '/compose';
          var $ = { extend: (...args) => Object.assign({}, ...args.filter(value => typeof value === 'object')) };
          $.ajax = options => {
            requests.push(options.data);
            return {
              done(callback) {
                if (mode !== 'network') callback(mode === 'empty' ? '' : mode === 'invalid' ? '{broken' : { result: mode });
                return this;
              },
              fail(callback) { if (mode === 'network') callback(); return this; }
            };
          };
          function basename(path) { return path.split('/').pop(); }
          function composeIdentityBlocked() { return false; }
          function composeLogger() {}
          function tryParseJson(value) { try { return JSON.parse(value); } catch { return null; } }
          function swal(value) { notices.push(value.type); }
          function performComposeAction(options) { dispatched.push(options); }
          ${preparation}
          ${confirmed}
          ${force}
          ${update}
          if (action === 'update') UpdateStackConfirmed('/stacks/app', { includeStopped: true, profile: 'worker' });
          else if (action === 'force') ForceUpdateStackConfirmed('/stacks/app', { includeStopped: true });
          else ComposeUpConfirmed('/stacks/app', { prepareImages: action === 'prepareUp', profile: 'worker', background: true });
          return { action, mode, requests, dispatched, notices };
        `)(action, mode))), { preparation, confirmed, force, update });
    for (const result of results) {
      const shouldDispatch = result.action === 'up' || result.mode === 'success';
      expect(result.dispatched.length).toBe(shouldDispatch ? 1 : 0);
      expect(result.requests.length).toBe(result.action === 'up' ? 0 : 1);
      expect(result.notices).toEqual(shouldDispatch ? [] : ['error']);
      if (result.requests.length) {
        expect(result.requests[0]).toEqual({ action: 'markStackForRecheck', stacks: '["app"]' });
      }
      if (shouldDispatch && ['up', 'prepareUp'].includes(result.action)) {
        expect(result.dispatched[0].payload.prepareImages).toBe(result.action === 'prepareUp' ? '1' : '0');
        expect(result.dispatched[0].payload.profile).toBe('worker');
        expect(result.dispatched[0].background).toBe(true);
      }
      if (shouldDispatch && result.action === 'update') {
        expect(result.dispatched[0].payload.includeStopped).toBe('1');
        expect(result.dispatched[0].payload.profile).toBe('worker');
      }
    }
  });

  test('malformed, empty and failed container responses clean progress and request reconciliation', async ({ page }) => {
    const container = fn('containerAction', 'addComposeStackContext');
    const result = await page.evaluate(container => ['malformed', 'empty', 'network', 'server'].map(mode => new Function('mode', `
      var composeStackActionInProgress = {}, refreshed = [], notices = [], caURL = '/exec';
      var element = {
        length: 0, first() { return this; }, closest() { return this; }, find() { return this; },
        data() { return 'stack'; }, attr() { return ''; }, is() { return false; }, remove() { return this; }
      };
      var $ = () => element;
      $.ajax = () => ({
        done(callback) {
          if (mode !== 'network') callback(mode === 'empty' ? '' : mode === 'server' ? '{"result":"error","message":"Docker unavailable"}' : '{broken');
          return this;
        },
        fail(callback) { if (mode === 'network') callback(); return this; }
      });
      function setStackActionInProgress(project, active) {
        if (active) return composeStackActionInProgress[project] = {};
        delete composeStackActionInProgress[project];
      }
      function composeActionStateText() { return 'working'; }
      function composeLogger() {}
      function swal(value) { notices.push(value.type); }
      function refreshStackByProject(project) { refreshed.push(project); }
      ${container}
      containerAction('container', 'stop', '1');
      return { active: Object.keys(composeStackActionInProgress), refreshed, notices };
    `)(mode)), container);
    for (const item of result) {
      expect(item).toEqual({ active: [], refreshed: ['stack'], notices: ['error'] });
    }
  });
});
