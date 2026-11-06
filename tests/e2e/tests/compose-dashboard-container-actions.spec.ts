import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeDashboard.js'), 'utf8');
const action = source.match(/^    function dockerAction\([^]*?\n    \}/m)![0];
interface Harness {
  dockerAction(name: string, action: string): void;
  respond(body: unknown): void;
  fail(status: string): void;
  notices: { type: string; text: string }[];
  refreshes: number;
  requests: { options: { timeout: number } }[];
  containerActionsPending: Record<string, boolean>;
}
test.use({ storageState: { cookies: [], origins: [] } });

test.beforeEach(async ({ page }) => {
  await page.addScriptTag({ content: `
    var containerActionsPending = Object.create(null), requests = [], notices = [], refreshes = 0;
    var caURL = '/exec';
    function debugLog() {}
    function swal(message) { notices.push(message); }
    function loadComposeStacks() { refreshes++; }
    var $ = { ajax(options) {
      var entry = { options }; requests.push(entry);
      return {
        done(fn) { entry.done = fn; return this; },
        fail(fn) { entry.fail = fn; return this; },
        always(fn) { entry.always = fn; return this; }
      };
    }};
    ${action}
    function respond(body) { var req = requests[requests.length - 1]; req.done(body); req.always(); }
    function fail(status) { var req = requests[requests.length - 1]; req.fail({}, status); req.always(); }
  ` });
});

for (const response of [
  { label: 'server error', body: JSON.stringify({ result: 'error', message: 'Stack is busy.' }) },
  { label: 'malformed JSON', body: '{broken' },
  { label: 'empty response', body: '' },
  { label: 'wrong response shape', body: JSON.stringify({ message: 'not a success' }) },
]) {
  test(`Dashboard rejects ${response.label} without pretending completion`, async ({ page }) => {
    const state = await page.evaluate(body => {
      const w = window as typeof window & Harness;
      w.dockerAction('app', 'restart');
      w.respond(body);
      return { notices: w.notices, refreshes: w.refreshes, pending: Object.keys(w.containerActionsPending) };
    }, response.body);
    expect(state.refreshes).toBe(0);
    expect(state.notices).toHaveLength(1);
    expect(state.notices[0].type).toBe('error');
    expect(state.pending).toEqual([]);
  });
}

test('Dashboard prevents duplicate requests while allowing independent containers and retry', async ({ page }) => {
  const state = await page.evaluate(() => {
    const w = window as typeof window & Harness;
    w.dockerAction('app', 'restart');
    w.dockerAction('app', 'stop');
    w.dockerAction('other', 'start');
    const count = w.requests.length;
    w.respond({ result: 'success', message: 'other' });
    w.dockerAction('other', 'stop');
    return { count, after: w.requests.length, refreshes: w.refreshes, timeout: w.requests[0].options.timeout };
  });
  expect(state).toEqual({ count: 2, after: 3, refreshes: 1, timeout: 30000 });
});

for (const status of ['error', 'timeout']) {
  test(`Dashboard reports transport ${status} and permits retry`, async ({ page }) => {
    const state = await page.evaluate(status => {
      const w = window as typeof window & Harness;
      w.dockerAction('app', 'stop');
      w.fail(status);
      w.dockerAction('app', 'stop');
      return { requests: w.requests.length, notices: w.notices, refreshes: w.refreshes };
    }, status);
    expect(state.requests).toBe(2);
    expect(state.refreshes).toBe(0);
    expect(state.notices[0].text).toContain(status === 'timeout' ? 'timed out' : 'Unable to complete');
  });
}
