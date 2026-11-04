import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(
  resolve(__dirname, '../../../source/compose.manager/javascript/composeManagerMain.js'), 'utf8'
);
const factories = source.slice(source.indexOf('function createContainerInfo('), source.indexOf('// Timers for async operations'));
const statusSource = readFileSync(
  resolve(__dirname, '../../../source/compose.manager/javascript/composeUpdateStatus.js'), 'utf8'
);

function exactFunction(name: string): string {
  return source.match(new RegExp(`^function ${name}\\([^]*?\\n\\}`, 'm'))![0];
}

function functionSource(name: string, next: string): string {
  return source.slice(source.indexOf(`function ${name}(`), source.indexOf(`function ${next}(`));
}

test.describe('Compose update state regressions', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('known false replaces old true, unknown preserves it, and changed tags reject it', async ({ page }) => {
    const results = await page.evaluate((factories) => {
      const run = new Function(factories + `
        const previous = { containers: [{
          name: 'app', image: 'postgres:18', hasUpdate: true,
          updateStatus: 'update-available', localSha: 'old', remoteSha: 'new'
        }] };
        const fresh = createStackInfo('stack', [{
          name: 'app', image: 'docker.io/library/postgres:18',
          hasUpdate: false, updateStatus: 'up-to-date', localSha: 'current', remoteSha: 'current'
        }]);
        const unknown = createStackInfo('stack', [{ name: 'app', image: 'postgres:18', updateStatus: 'unknown' }]);
        const changed = createStackInfo('stack', [{ name: 'app', image: 'postgres:19', updateStatus: 'unknown' }]);
        const cleared = { name: 'app', image: 'postgres:18', hasUpdate: true, updateStatus: 'update-available', localSha: 'old', remoteSha: 'new' };
        mergeContainerUpdateStatus(cleared, { image: 'postgres:18', hasUpdate: false, status: 'up-to-date' }, false);
        const freshTimestamp = mergeStackUpdateStatus(
          { lastChecked: 200, containers: [] },
          { lastChecked: 100, containers: [] }
        );
        const fallbackTimestamp = mergeStackUpdateStatus(
          { lastChecked: null, containers: [] },
          { lastChecked: 100, containers: [] }
        );
        return {
          fresh: mergeStackUpdateStatus(fresh, previous),
          unknown: mergeStackUpdateStatus(unknown, previous),
          changed: mergeStackUpdateStatus(changed, previous),
          cleared,
          freshTimestamp,
          fallbackTimestamp
        };
      `);
      return run();
    }, factories);

    expect(results.fresh.hasUpdate).toBe(false);
    expect(results.fresh.containers[0].localSha).toBe('current');
    expect(results.unknown.hasUpdate).toBe(true);
    expect(results.unknown.containers[0].remoteSha).toBe('new');
    expect(results.changed.hasUpdate).toBe(false);
    expect(results.changed.containers[0].localSha).toBe('');
    expect(results.cleared).toMatchObject({ hasUpdate: false, updateStatus: 'up-to-date', localSha: '', remoteSha: '' });
    expect(results.freshTimestamp.lastChecked).toBe(200);
    expect(results.fallbackTimestamp.lastChecked).toBe(100);
  });

  test('both merge paths reject stale tags without overwriting runtime or pin state', async ({ page }) => {
    const merge = functionSource('mergeUpdateStatus', 'showComposeChangelog');
    const results = await page.evaluate(({ factories, merge }) => {
      return new Function(factories + `
        var stackUpdateStatus = { stack: { containers: [{
          name: 'app', service: 'web', image: 'repo/app:v1',
          hasUpdate: true, updateStatus: 'update-available', isPinned: true
        }] } };
        ${merge}
        const changed = { name: 'app', service: 'web', image: 'repo/app:v2', state: 'running', updateStatus: 'unknown' };
        const replica = { name: 'app-2', service: 'web', image: 'repo/app:v1', state: 'running', updateStatus: 'unknown' };
        const fresh = { name: 'app', service: 'web', image: 'repo/app:v1', state: 'running', hasUpdate: false, updateStatus: 'up-to-date', isPinned: false };
        mergeUpdateStatus([changed, fresh, replica], 'stack');
        return { changed, fresh, replica };
      `)();
    }, { factories, merge });
    expect(results.changed).toMatchObject({ state: 'running', updateStatus: 'unknown' });
    expect(results.changed.hasUpdate).toBeUndefined();
    expect(results.replica.updateStatus).toBe('unknown');
    expect(results.fresh).toMatchObject({ state: 'running', hasUpdate: false, updateStatus: 'up-to-date', isPinned: false });
  });

  test('fresh update checks replace known status from the persistent-cache fallback', async ({ page }) => {
    const merge = functionSource('mergeUpdateStatus', 'showComposeChangelog');
    const result = await page.evaluate(({ factories, merge }) => {
      return new Function(factories + `
        var stackUpdateStatus = { stack: { containers: [{
          name: 'app', service: 'web', image: 'repo/app:v1',
          hasUpdate: true, updateStatus: 'update-available', localSha: 'old', remoteSha: 'new'
        }] } };
        ${merge}
        const cached = {
          name: 'app', service: 'web', image: 'repo/app:v1',
          hasUpdate: false, updateStatus: 'up-to-date', localSha: 'old', remoteSha: 'old'
        };
        mergeUpdateStatus([cached], 'stack', false);
        return cached;
      `)();
    }, { factories, merge });
    expect(result).toMatchObject({
      hasUpdate: true, updateStatus: 'update-available', localSha: 'old', remoteSha: 'new'
    });
  });

  test('unknown checks are not displayed as up-to-date', async ({ page }) => {
    const updateUi = functionSource('updateStackUpdateUI', 'checkStackUpdates');
    const results = await page.evaluate(({ updateUi, statusSource }) => {
      let html = '';
      const cell = { html(value: string) { html = value; } };
      const row = {
        length: 1,
        attr: () => 'stack-row-1',
        find: (selector: string) => selector === '.state' ? { text: () => 'started' } : cell,
      };
      const $ = (selector: string) => selector.startsWith('#compose_stacks') ? row : { each() {} };
      const run = new Function('$', 'composeEscapeAttr', `
        var stackContainersCache = {}, expandedStacks = {};
        function composeUpdateCheckBadge() { return ''; }
        ${statusSource}
        ${updateUi}
        updateStackUpdateUI('stack', { containers: [{ hasUpdate: false, updateStatus: 'unknown' }] });
      `);
      run($, (value: string) => value);
      const unknown = html;
      const mixed = new Function('$', 'composeEscapeAttr', `
        var stackContainersCache = {}, expandedStacks = {};
        function composeUpdateCheckBadge() { return ''; }
        ${statusSource}
        ${updateUi}
        updateStackUpdateUI('stack', { containers: [
          { hasUpdate: false, updateStatus: 'unknown' },
          { hasUpdate: true, updateStatus: 'update-available', updateReadiness: 'download-required' }
        ] });
      `);
      mixed($, (value: string) => value);
      return { unknown, mixed: html };
    }, { updateUi, statusSource });
    expect(results.unknown).toContain('1 unchecked');
    expect(results.unknown).not.toContain('up-to-date');
    expect(results.mixed).toContain('Available');
    expect(results.mixed).toContain('1 unchecked');
  });

  test('background completion polling retries transport and malformed responses', async ({ page }) => {
    const poll = functionSource('pollBackgroundCompletion', 'composeActionStateText');
    const parse = functionSource('tryParseJson', 'syncComposeStackRowStriping');
    const result = await page.evaluate(({ poll, parse }) => {
      const timers: Array<() => void> = [];
      const outcomes = ['failure', 'locked', 'malformed', 'missing-lock', 'complete'];
      const progress: boolean[] = [];
      const refreshed: string[] = [];
      const errors: string[] = [];
      const timeouts: number[] = [];
      let rechecks = 0;
      const $ = {
        ajax(options: { timeout: number }) {
          timeouts.push(options.timeout);
          const outcome = outcomes.shift();
          return {
            done(callback: (value: string | object) => void) {
              if (outcome !== 'failure') {
                callback(outcome === 'malformed' ? '{broken'
                  : outcome === 'missing-lock' ? { result: 'success' }
                    : { result: 'success', locked: outcome === 'locked' });
              }
              return this;
            },
            fail(callback: (xhr: object, status: string, error: string) => void) {
              if (outcome === 'failure') callback({}, 'timeout', 'request timed out');
              return this;
            },
            always(callback: () => void) { callback(); return this; },
          };
        },
      };
      const run = new Function('$', 'setTimeout', 'setStackActionInProgress',
        'refreshStackByProject', 'checkPendingRechecks', 'composeLogger', `
        var caURL = '/exec';
        ${parse}
        ${poll}
        pollBackgroundCompletion('stack');
      `);
      run($, (callback: () => void) => timers.push(callback),
        (_stack: string, active: boolean) => progress.push(active),
        (stack: string) => refreshed.push(stack), () => rechecks++,
        (message: string) => errors.push(message));
      for (let count = 0; timers.length && count < 10; count++) timers.shift()!();
      return { progress, refreshed, rechecks, errors, timeouts, pendingTimers: timers.length };
    }, { poll, parse });
    expect(result.timeouts).toEqual([30000, 30000, 30000, 30000, 30000]);
    expect(result.progress).toEqual([false]);
    expect(result.refreshed).toEqual(['stack']);
    expect(result.rechecks).toBe(1);
    expect(result.errors).toContain('Lock check request failed');
    expect(result.errors).toContain('Lock check failed');
    expect(result.pendingTimers).toBe(0);
  });

  test('failed update-check requests replace the checking spinner with a retry link', async ({ page }) => {
    const check = functionSource('checkStackUpdates', 'isValidWebUIUrl');
    const checkState = exactFunction('setComposeUpdateCheckState') + exactFunction('composeUpdateCheckBadge');
    const results = await page.evaluate(({ check, factories, checkState }) => {
      return ['network', 'malformed', 'server', 'empty', 'incomplete'].map((mode) => {
        let html = '';
        const messages: string[] = [];
        const cell = { html(value: string) { html = value; } };
        const row = { length: 1, attr: () => 'stack-row-1', find: () => cell, html: cell.html };
        const $ = Object.assign(() => row, {
          post(_url: string, payload: { action: string }, callback?: (data: string) => void) {
            if (payload.action === 'checkStackUpdates') {
              if (mode === 'malformed') callback!('{broken');
              if (mode === 'server') callback!('{"result":"error","message":"Docker unavailable"}');
              if (mode === 'empty') callback!('');
              if (mode === 'incomplete') callback!('{"result":"success"}');
            }
            return { fail(callback: (xhr: object, status: string, error: string) => void) {
              if (payload.action === 'checkStackUpdates' && mode === 'network') {
                callback({}, 'timeout', 'request timed out');
              }
            } };
          },
        });
        const run = new Function('$', 'composeEscapeAttr', 'composeLogger', `
          var caURL = '/exec';
          var stackUpdateStatus = {};
          var pendingUpdateCheckStacks = [];
          var composeUpdateCheckState = {};
          function updateStackUpdateUI() {}
          function updateUpdateAllButton() {}
          ${factories}
          ${checkState}
          ${check}
          checkStackUpdates('stack');
        `);
        run($, (value: string) => value, (message: string) => messages.push(message));
        return { html, messages };
      });
    }, { check, factories, checkState });
    for (const result of results) {
      expect(result.html).toContain('check failed');
      expect(result.html).toContain("checkStackUpdates('stack')");
      expect(result.html).not.toContain('fa-spin');
      expect(result.messages).toContain('Update check failed');
    }
  });

  test('a failed foreground dispatch clears the starting state even with a pending reload', async ({ page }) => {
    const action = functionSource('performComposeAction', 'confirmedComposeAction');
    const result = await page.evaluate((action) => {
      const progress: boolean[] = [];
      const notices: Array<{ type: string }> = [];
      const $ = { post: () => ({ fail(callback: () => void) { callback(); } }) };
      const run = new Function('$', 'setStackActionInProgress', 'composeLogger', 'swal', `
        var compURL = '/compose';
        function composeActionStateText() { return 'starting...'; }
        ${action}
        performComposeAction({ stackName: 'stack', actionName: 'up', pendingReload: true });
      `);
      run($, (_stack: string, active: boolean) => progress.push(active), () => {},
        (notice: { type: string }) => notices.push(notice));
      return { progress, notices };
    }, action);
    expect(result.progress).toEqual([true, false]);
    expect(result.notices).toEqual([expect.objectContaining({ type: 'error' })]);
  });

  test('a started stack updates its runtime label before repainting the update column', async ({ page }) => {
    const refresh = functionSource('updateParentStackFromContainers', 'fixContextDropdownOverflow');
    const result = await page.evaluate(({ factories, refresh }) => {
      let label = 'stopped';
      const observedLabels: string[] = [];
      let fallbackReloads = 0;
      const empty = {
        length: 0,
        data() { return this; },
        attr() { return this; },
        find() { return this; },
        html() { return this; },
      };
      const state = {
        text(value?: string) { if (value !== undefined) label = value; return label; },
        data: () => undefined,
        removeData() {},
      };
      const row = {
        ...empty, length: 1,
        find: (selector: string) => selector === '.state' ? state : empty,
      };
      const $ = (selector: string) => selector.startsWith('#compose_stacks') ? row : empty;
      const run = new Function('$', 'updateStackUpdateUI', 'composeLoadlist', 'composeLogger', `
        ${factories}
        var stackUpdateStatus = {}, composeStackActionInProgress = {}, stackStartedAtCache = {};
        function buildStackInfoFromCache() { return createStackInfo('stack', [{ name: 'app', state: 'running' }]); }
        function deriveStackState() { return { label: 'started', state: 'started', runningCount: 1, totalCount: 1, colorClass: 'green-text' }; }
        function applyListEnhancements() {}
        function reconcileStackRuntimeMetadata() {}
        ${refresh}
        updateParentStackFromContainers('1', 'stack');
      `);
      run($, () => observedLabels.push(label), () => fallbackReloads++, () => {});
      return { label, observedLabels, fallbackReloads };
    }, { factories, refresh });
    expect(result.label).toBe('started');
    expect(result.observedLabels).toEqual(['started']);
    expect(result.fallbackReloads).toBe(0);
  });
});
