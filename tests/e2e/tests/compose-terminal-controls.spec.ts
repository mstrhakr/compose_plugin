import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../../source/compose.manager/javascript/composeTerminal.js'), 'utf8');
const markup = `
  <p id="terminal-status" role="status"></p>
  <button id="terminal-background">Continue in background</button>
  <button id="terminal-stop" disabled>Stop command</button>
  <button id="done-btn" hidden>Done</button>`;
const terminalPage = readFileSync(resolve(__dirname, '../../../source/compose.manager/include/ShowTtyd.php'), 'utf8');
const terminalStyles = terminalPage.match(/<style>([\s\S]*?)<\/style>/)![1];
const recoveryMarkup = `<style>.ui-dialog-buttonset button { font-family: "Times New Roman"; }
${terminalStyles}</style>
  <p id="terminal-status" role="status"></p>
  <span class="ui-dialog-buttonset">
    <button id="terminal-background">Continue in background</button>
    <button id="terminal-stop" disabled>Stop command</button>
    <button id="done-btn" hidden>Done</button>
    <button id="terminal-retry" hidden>Retry live connection</button>
  </span>
  <p id="terminal-connection"></p>
  <iframe id="ttyd-frame" hidden></iframe>
  <pre id="terminal-output" hidden></pre>`;

test.use({ storageState: { cookies: [], origins: [] } });
test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    Object.assign(window, { csrf_token: 'synthetic-terminal-csrf' });
  });
});

for (const [command, effect] of [
  ['up', 'This Up runs detached (-d)'],
  ['down', 'Containers and networks already removed will not be restored'],
  ['stop', 'Containers already stopped stay stopped'],
  ['pull', 'this action does not recreate or stop containers'],
  ['update', 'The stack may be partially updated'],
  ['gitdeploy', 'The stack may be partially deployed'],
  ['unknown', 'Depending on the command, containers may be stopped'],
]) {
  test(`cancellation explains ${command} effects and batch scope without claiming phase knowledge`, async ({ page }) => {
    let stopRequests = 0;
    await page.route('http://terminal.test/**', async route => {
      if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: markup });
      expect(new URLSearchParams(route.request().postData() || '').get('csrf_token')).toBe('synthetic-terminal-csrf');
      if (new URLSearchParams(route.request().postData() || '').get('action') === 'stopTerminalSession') stopRequests++;
      await route.fulfill({ json: { result: 'success', session: { state: 'running' } } });
    });
    await page.goto('http://terminal.test/');
    await page.evaluate(command => Object.assign(window, {
      composeTerminalSession: 'd'.repeat(32), composeTerminalCommand: command,
      composeTerminalBulk: true, Shadowbox: { close() {} },
    }), command);
    await page.addScriptTag({ content: source });
    const stop = page.getByRole('button', { name: 'Stop command' });
    await expect(stop).toBeEnabled();
    const dialogPromise = page.waitForEvent('dialog');
    const clickPromise = stop.click();
    const dialog = await dialogPromise;
    const message = dialog.message();
    await dialog.dismiss();
    await clickPromise;
    expect(message).toContain(effect);
    expect(message).toContain('remaining queued actions are cancelled');
    expect(message).toContain('exact execution phase is not tracked');
    expect(message).toContain('Changes already made will not be undone');
    expect(stopRequests).toBe(0);
  });
}

test('continuing in background closes only the viewer and sends no stop request', async ({ page }) => {
  const actions: string[] = [];
  await page.route('http://terminal.test/**', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: markup });
    expect(new URLSearchParams(route.request().postData() || '').get('csrf_token')).toBe('synthetic-terminal-csrf');
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
    expect(new URLSearchParams(route.request().postData() || '').get('csrf_token')).toBe('synthetic-terminal-csrf');
    const params = new URLSearchParams(route.request().postData() || '');
    const action = params.get('action');
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
    expect(new URLSearchParams(route.request().postData() || '').get('csrf_token')).toBe('synthetic-terminal-csrf');
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

  test('connection retries are bounded, preserve controls, and downgrade to safe readable output', async ({ page }) => {
    const actions: string[] = [];
    const retries: string[] = [];
    let stopped = false;
    await page.route('http://terminal.test/**', async route => {
      if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: recoveryMarkup });
      const params = new URLSearchParams(route.request().postData() || '');
      const action = params.get('action') || '';
      actions.push(action);
      expect(params.get('csrf_token')).toBe('synthetic-terminal-csrf');
      if (action === 'connectTerminalViewer') {
        retries.push(params.get('retry') || '');
        return route.fulfill({ json: { result: 'success', viewer: { ready: false, connected: false } } });
      }
      if (action === 'stopTerminalSession') stopped = true;
      const session = stopped ? { state: 'completed', exitCode: 143 } : { state: 'running', viewerConnected: false };
      const text = '\x1b[32mold\x1b[0m\rnew\n<script>unsafe()</script>\n';
      await route.fulfill({ json: {
        result: 'success', session,
        ...(action === 'getTerminalOutput' ? { output: {
          data: params.get('offset') === '0' ? Buffer.from(text).toString('base64') : '',
          offset: Buffer.byteLength(text), generation: 0, reset: false, remaining: false,
        } } : {}),
      } });
    });
    await page.goto('http://terminal.test/');
    await page.evaluate(() => Object.assign(window, {
      composeTerminalSession: 'e'.repeat(32), composeTerminalUrl: '/logterminal/fixture/',
      Shadowbox: { close() {} },
    }));
    await page.addScriptTag({ content: source });
    await expect(page.locator('#terminal-output')).toHaveText('new\n<script>unsafe()</script>\n');
    await expect.poll(() => retries.length).toBe(3);
    expect(retries).toEqual(['0', '1', '1']);
    await expect(page.locator('#terminal-connection')).toContainText('Showing saved output instead');
    await expect(page.getByRole('button', { name: 'Stop command' })).toBeEnabled();
    expect(await page.locator('#terminal-output script').count()).toBe(0);
    await page.waitForTimeout(1200);
    expect(retries).toHaveLength(3);
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Stop command' }).click();
    await expect(page.locator('#terminal-status')).toContainText('exit code 143');
    expect(actions.filter(action => action === 'stopTerminalSession')).toHaveLength(1);
    expect(actions.every(action => ['getTerminalSession', 'getTerminalOutput', 'connectTerminalViewer', 'stopTerminalSession'].includes(action))).toBe(true);
  });

  test('manual retry reconnects only the viewer and status confirms an actual attachment', async ({ page }) => {
    let attempts = 0;
    let connected = false;
    const retries: string[] = [];
    await page.route('http://terminal.test/**', async route => {
      if (route.request().method() === 'GET') {
        if (route.request().url().includes('/logterminal/')) connected = true;
        return route.fulfill({ contentType: 'text/html', body: recoveryMarkup });
      }
      const params = new URLSearchParams(route.request().postData() || '');
      const action = params.get('action');
      const session = { state: 'running', viewerConnected: connected };
      if (action === 'connectTerminalViewer') {
        retries.push(params.get('retry') || '');
        return route.fulfill({ json: { result: 'success', viewer: { ready: ++attempts > 3, connected: false } } });
      }
      await route.fulfill({ json: { result: 'success', session,
        ...(action === 'getTerminalOutput' ? { output: { data: '', offset: 0, generation: 0, reset: false, remaining: false } } : {}),
      } });
    });
    await page.goto('http://terminal.test/');
    await page.evaluate(() => Object.assign(window, {
      composeTerminalSession: 'f'.repeat(32), composeTerminalUrl: '/logterminal/fixture/', Shadowbox: { close() {} },
    }));
    await page.addScriptTag({ content: source });
    await expect.poll(() => attempts).toBe(3);
    await page.getByRole('button', { name: 'Retry live connection' }).click();
    await expect(page.locator('#terminal-connection')).toHaveText('Live terminal connected.');
    await expect(page.locator('#ttyd-frame')).toBeVisible();
    await expect(page.locator('#terminal-output')).toBeHidden();
    expect(retries).toEqual(['0', '1', '1', '1']);
  });

  test('closing during connection setup ignores a late viewer response', async ({ page }) => {
    let release!: () => void;
    const wait = new Promise<void>(resolve => { release = resolve; });
    let launched = false;
    await page.route('http://terminal.test/**', async route => {
      if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: recoveryMarkup });
      const action = new URLSearchParams(route.request().postData() || '').get('action');
      if (action === 'connectTerminalViewer') {
        launched = true;
        await wait;
        return route.fulfill({ json: { result: 'success', viewer: { ready: true } } });
      }
      await route.fulfill({ json: { result: 'success', session: { state: 'running' } } });
    });
    await page.goto('http://terminal.test/');
    await page.evaluate(() => Object.assign(window, {
      composeTerminalSession: 'a'.repeat(32), composeTerminalUrl: '/logterminal/fixture/',
      Shadowbox: { close() {} },
    }));
    await page.addScriptTag({ content: source });
    await expect.poll(() => launched).toBe(true);
    await page.getByRole('button', { name: 'Continue in background' }).click();
    release();
    await expect(page.locator('#ttyd-frame')).toBeHidden();
    expect(await page.locator('#ttyd-frame').getAttribute('src')).toBeNull();
  });

  test('a ready socket without browser attachment retries then shows completed saved output', async ({ page }) => {
    await page.clock.install();
    let attempts = 0;
    await page.route('http://terminal.test/**', async route => {
      if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: recoveryMarkup });
      const action = new URLSearchParams(route.request().postData() || '').get('action');
      if (action === 'connectTerminalViewer') {
        attempts++;
        return route.fulfill({ json: { result: 'success', viewer: { ready: true, connected: false } } });
      }
      await route.fulfill({ json: { result: 'success', session: { state: 'completed', exitCode: 0, viewerConnected: false },
        ...(action === 'getTerminalOutput' ? { output: {
          data: new URLSearchParams(route.request().postData() || '').get('offset') === '0'
            ? Buffer.from('finished\n').toString('base64') : '', offset: 9, generation: 0, reset: false, remaining: false,
        } } : {}),
      } });
    });
    await page.goto('http://terminal.test/');
    await page.evaluate(() => Object.assign(window, {
      composeTerminalSession: 'b'.repeat(32), composeTerminalUrl: '/logterminal/fixture/', Shadowbox: { close() {} },
    }));
    await page.addScriptTag({ content: source });
    for (let attempt = 1; attempt <= 3; attempt++) {
      await expect.poll(() => attempts).toBe(attempt);
      await page.clock.runFor(11000);
    }
    await expect(page.locator('#terminal-output')).toHaveText('finished\n');
    await expect(page.locator('#terminal-output')).toBeVisible();
    await expect(page.locator('#terminal-status')).toHaveText('Command completed.');
    await expect(page.getByRole('button', { name: 'Done', exact: true })).toBeVisible();
  });

  test('fast completed viewers retain connection acknowledgement and controls use a readable font', async ({ page }) => {
    let viewed = false;
    let attempts = 0;
    await page.route('http://terminal.test/**', async route => {
      if (route.request().method() === 'GET') {
        if (route.request().url().includes('/logterminal/')) viewed = true;
        return route.fulfill({ contentType: 'text/html', body: recoveryMarkup });
      }
      const action = new URLSearchParams(route.request().postData() || '').get('action');
      if (action === 'connectTerminalViewer') {
        attempts++;
        return route.fulfill({ json: { result: 'success', viewer: { ready: true } } });
      }
      await route.fulfill({ json: { result: 'success',
        session: { state: 'completed', exitCode: 0, viewerConnected: false, viewerViewed: viewed },
      } });
    });
    await page.goto('http://terminal.test/');
    await page.evaluate(() => Object.assign(window, {
      composeTerminalSession: 'c'.repeat(32), composeTerminalUrl: '/logterminal/fixture/', Shadowbox: { close() {} },
    }));
    await page.addScriptTag({ content: source });
    await expect(page.locator('#terminal-connection')).toHaveText('Live terminal connected.');
    expect(attempts).toBe(1);
    await expect(page.locator('#terminal-output')).toBeHidden();
    expect(await page.getByRole('button', { name: 'Done', exact: true }).evaluate(element => getComputedStyle(element).fontFamily)).toContain('clear-sans');
  });

  test('saved output decodes split UTF-8 and ANSI bytes and resets its cursor on rotation', async ({ page }) => {
    let chunkIndex = 0;
    let release!: () => void;
    const rotation = new Promise<void>(resolve => { release = resolve; });
    const chunks = [Buffer.from('\x1b[3'), Buffer.from('2m'), Buffer.from([0xe2]),
      Buffer.concat([Buffer.from([0x9c, 0x93]), Buffer.from(' done\x1b[0m\n')])];
    let offset = 0;
    let complete = false;
    await page.route('http://terminal.test/**', async route => {
      if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: recoveryMarkup });
      const params = new URLSearchParams(route.request().postData() || '');
      const action = params.get('action');
      if (action === 'connectTerminalViewer') return route.fulfill({ json: { result: 'success', viewer: { ready: false } } });
      let output;
      if (action === 'getTerminalOutput') {
        expect(Number(params.get('offset'))).toBe(offset);
        if (chunkIndex < chunks.length) {
          const chunk = chunks[chunkIndex++];
          offset += chunk.length;
          output = { data: chunk.toString('base64'), offset, generation: 0, reset: false, remaining: true };
        } else {
          await rotation;
          complete = true;
          offset = 8;
          output = { data: Buffer.from('rotated\n').toString('base64'), offset, generation: 1, reset: true, remaining: false };
        }
      }
      await route.fulfill({ json: { result: 'success', output,
        session: complete ? { state: 'completed', exitCode: 0 } : { state: 'running', viewerConnected: false },
      } });
    });
    await page.goto('http://terminal.test/');
    await page.evaluate(() => Object.assign(window, {
      composeTerminalSession: 'd'.repeat(32), composeTerminalUrl: '/logterminal/fixture/', Shadowbox: { close() {} },
    }));
    await page.addScriptTag({ content: source });
    await expect(page.locator('#terminal-output')).toHaveText('\u2713 done\n');
    release();
    await expect(page.locator('#terminal-output')).toHaveText('rotated\n');
    await expect(page.locator('#terminal-status')).toHaveText('Command completed.');
  });
