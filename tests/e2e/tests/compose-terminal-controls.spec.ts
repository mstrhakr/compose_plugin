import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeTerminal.js'), 'utf8');
const markup = `
  <p id="terminal-status" role="status"></p>
  <button id="terminal-background">Continue in background</button>
  <button id="terminal-stop" disabled>Stop command</button>
  <button id="done-btn" hidden>Done</button>`;

test.use({ storageState: { cookies: [], origins: [] } });

test('continuing in background closes only the viewer and sends no stop request', async ({ page }) => {
  const actions: string[] = [];
  await page.route('http://terminal.test/**', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: markup });
    actions.push(new URLSearchParams(route.request().postData() || '').get('action') || '');
    await route.fulfill({ json: { result: 'success', session: { state: 'running' } } });
  });
  await page.goto('http://terminal.test/');
  await page.evaluate(() => {
    Object.assign(window, {
      composeTerminalSession: 'a'.repeat(32),
      closeCount: 0,
      Shadowbox: { close() { (window as unknown as { closeCount: number }).closeCount++; } },
    });
  });
  await page.addScriptTag({ content: source });
  await expect(page.getByRole('button', { name: 'Stop command' })).toBeEnabled();
  await page.getByRole('button', { name: 'Continue in background' }).click();
  expect(await page.evaluate(() => (window as unknown as { closeCount: number }).closeCount)).toBe(1);
  expect(actions).toContain('getTerminalSession');
  expect(actions).not.toContain('stopTerminalSession');
  await expect(page.getByRole('button', { name: 'Done', exact: true })).toBeHidden();
});

test('stop requires confirmation, reports cancellation, then exposes only Done', async ({ page }) => {
  let stopped = false;
  await page.route('http://terminal.test/**', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: markup });
    const action = new URLSearchParams(route.request().postData() || '').get('action');
    if (action === 'stopTerminalSession') stopped = true;
    await route.fulfill({ json: { result: 'success', session: stopped
      ? { state: 'completed', exitCode: 143 } : { state: 'running' } } });
  });
  await page.goto('http://terminal.test/');
  await page.evaluate(() => Object.assign(window, {
    composeTerminalSession: 'b'.repeat(32), Shadowbox: { close() {} },
  }));
  await page.addScriptTag({ content: source });
  const stop = page.getByRole('button', { name: 'Stop command' });
  await expect(stop).toBeEnabled();
  page.once('dialog', dialog => dialog.dismiss());
  await stop.click();
  expect(stopped).toBe(false);
  page.once('dialog', dialog => dialog.accept());
  await stop.click();
  await expect(page.getByRole('status')).toContainText('exit code 143');
  await expect(page.getByRole('button', { name: 'Done', exact: true })).toBeVisible();
  await expect(stop).toBeHidden();
  await expect(page.getByRole('button', { name: 'Continue in background' })).toBeHidden();
});

test('status failures remain visible and retry without presenting false completion', async ({ page }) => {
  let stage = 0;
  await page.route('http://terminal.test/**', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: markup });
    const responses = [
      { result: 'error', message: 'Receipt unavailable' },
      { result: 'success', session: { state: 'completed' } },
      { result: 'success', session: { state: 'completed', exitCode: 0 } },
    ];
    await route.fulfill({ json: responses[stage] });
  });
  await page.goto('http://terminal.test/');
  await page.evaluate(() => Object.assign(window, {
    composeTerminalSession: 'c'.repeat(32), Shadowbox: { close() {} },
  }));
  await page.addScriptTag({ content: source });
  await expect(page.getByRole('status')).toContainText('Receipt unavailable');
  await expect(page.getByRole('button', { name: 'Done', exact: true })).toBeHidden();
  await expect(page.getByRole('button', { name: 'Stop command' })).toBeDisabled();
  stage = 1;
  await expect(page.getByRole('status')).toContainText('Invalid terminal session receipt');
  await expect(page.getByRole('button', { name: 'Done', exact: true })).toBeHidden();
  stage = 2;
  await expect(page.getByRole('status')).toHaveText('Command completed.');
  await expect(page.getByRole('button', { name: 'Done', exact: true })).toBeVisible();
});
