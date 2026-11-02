import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeManagerMain.js'), 'utf8');
function fn(name: string, next: string): string {
  return source.slice(source.indexOf(`function ${name}(`), source.indexOf(`function ${next}(`));
}
const state = fn('setStackActionInProgress', 'UpdateStackConfirmed');
const dispatch = fn('performComposeAction', 'confirmedComposeAction');
const poll = fn('pollBackgroundCompletion', 'composeActionStateText');

test.describe('Operation state ownership', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

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
      pollBackgroundCompletion('stack', 0, old);
      timers.shift()();
      var current = setStackActionInProgress('stack', true, 'stopping');
      request.done({ result: 'success', locked: false });
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
        row.find = selector => selector === '.state' ? label : icon;
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
        executeUpdateAllStacks([{ project: 'a', path: '/stacks/a' }], true, true);
        return { mode, dispatches, active: Object.keys(composeStackActionInProgress), notices };
      `)(mode)), { state, prepare });
    for (const item of result) {
      expect(item.dispatches).toBe(item.mode === 'success' ? 1 : 0);
      expect(item.active).toEqual(item.mode === 'success' ? ['a'] : []);
      expect(item.notices).toEqual(item.mode === 'success' ? [] : ['error']);
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
