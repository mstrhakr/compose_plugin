import { expect, test as base, type Page } from '@playwright/test';
import {
  buildStackName, composeDownAndWait, deleteStackWithRetries, postForm,
  type PostFormResult, waitForOperation,
} from './helpers/composeE2eHelpers';

const exec = '/plugins/compose.manager/include/Exec.php';
const util = '/plugins/compose.manager/include/ComposeUtil.php';
const composePath = process.env.E2E_COMPOSE_PATH || '/Docker/Compose';
const enabled = !!process.env.E2E_BASE_URL
  && ['1', 'true', 'yes'].includes((process.env.E2E_ENABLE_MUTATION_TESTS || '').toLowerCase())
  && process.env.E2E_HOST_TEST_ACK === new URL(process.env.E2E_BASE_URL!).origin;

type Snapshot = { service?: string; name?: string; id?: string; state?: string; health?: string };
type HostStack = {
  project: string;
  path: string;
  save(yaml: string): Promise<void>;
  action(action: string, extra?: Record<string, string>): Promise<PostFormResult>;
  containers(): Promise<Snapshot[]>;
  refresh(): Promise<void>;
};

const yaml = [
  'services:',
  '  app:',
  '    image: alpine:3.20',
  '    mem_limit: 32m',
  '    cpus: 0.10',
  '    pids_limit: 32',
  '    command: ["sh", "-c", "sleep 600"]',
  '    healthcheck:',
  '      test: ["CMD", "true"]',
  '      interval: 2s',
  '      timeout: 1s',
  '      retries: 2',
  '      start_period: 30s',
  '  selected:',
  '    image: alpine:3.20',
  '    mem_limit: 32m',
  '    cpus: 0.10',
  '    pids_limit: 32',
  '    command: ["sh", "-c", "sleep 600"]',
  '    profiles: [selected]',
  '  excluded:',
  '    image: alpine:3.20',
  '    mem_limit: 32m',
  '    cpus: 0.10',
  '    pids_limit: 32',
  '    command: ["sh", "-c", "exit 97"]',
  '    profiles: [excluded]',
  '',
].join('\n');

const test = base.extend<{ stack: HostStack }>({
  stack: async ({ page }, use) => {
    test.skip(!enabled, 'Explicit host URL, mutation opt-in and matching E2E_HOST_TEST_ACK are required.');
    test.setTimeout(300_000);
    const name = buildStackName('pw-host');
    let project = '';
    let path = '';
    const containerNames = new Set<string>();
    const terminalSessions = new Set<string>();
    const blocked: string[] = [];
    // Browser-originated mutations are restricted to this test's exact project.
    // APIRequestContext calls below have their own fixed, scoped payloads.
    await page.route(/\/(?:Exec|ComposeUtil)\.php(?:\?|$)/, async route => {
      const request = route.request();
      const params = new URLSearchParams(request.postData() || '');
      const action = params.get('action') || '';
      const readOnly = new Set([
        'getComposeProjects', 'getComposeStackRow', 'getStackContainers', 'getConfig',
        'getUpdateStatus', 'getSavedUpdateStatus', 'getProfileServices', 'getUiOperation',
        'getStackSettings', 'checkStackLock', 'getPendingRechecks',
        'getPendingRecheckStacks', 'getPersistentContainerCache', 'getComposeLoadSnapshot',
        'getDescription', 'getGitStackStatus', 'getYml', 'getEnvPath', 'getEnv', 'getOverride',
      ]);
      if (request.method() === 'GET' || readOnly.has(action)) {
        await route.continue();
        return;
      }
      if (['getTerminalSession', 'stopTerminalSession'].includes(action)
        && terminalSessions.has(params.get('session') || '')) {
        await route.continue();
        return;
      }
      if (['composeUp', 'composeStop', 'composeDown', 'composeUpRecreate'].includes(action)
        && project && params.get('path') === path && params.get('trackOperation') === '1') {
        // Only the terminal cases explicitly wait, bounded to this disposable stack.
        if (params.get('waitForHealthy') !== '1' || params.get('waitTimeout') !== '30') {
          params.set('waitForHealthy', '0');
        }
        const response = await route.fetch({ postData: params.toString() });
        const result = await response.json();
        if (typeof result.url === 'string') {
          const url = new URL(result.url, page.url());
          const session = url.searchParams.get('session') || '';
          if (url.origin === new URL(page.url()).origin
            && url.pathname === '/plugins/compose.manager/include/ShowTtyd.php'
            && /^[a-f0-9]{32}$/.test(session)
            && url.searchParams.get('socket') === 'compose_action_' + session) {
            terminalSessions.add(session);
          }
        }
        await route.fulfill({ response });
        return;
      }
      if (action === 'containerAction' && containerNames.has(params.get('container') || '')
        && params.get('containerAction') === 'restart') {
        await route.continue();
        return;
      }
      await route.abort('blockedbyclient');
      blocked.push(action);
    });
    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    expect(page.url()).not.toContain('/login');
    try {
      const created = await postForm(page, exec, { action: 'addStack', stackName: name });
      expect(created.response.ok(), created.body).toBeTruthy();
      expect(created.json?.result, created.body).toBe('success');
      project = String(created.json?.project || '');
      expect(project, 'Refuse cleanup or mutations unless creation returns this exact generated identity.').toBe(name);
      const settings = await postForm(page, exec, { action: 'getStackSettings', script: project });
      expect(settings.json?.result, settings.body).toBe('success');
      path = String(settings.json?.projectPath || '');
      expect(path.endsWith('/' + project)).toBe(true);
      const stack: HostStack = {
        project, path,
        async save(content) {
          const result = await postForm(page, exec, { action: 'saveYml', script: project, scriptContents: content });
          expect(result.response.ok(), result.body).toBeTruthy();
          expect(result.body.toLowerCase()).toContain('saved');
        },
        action(action, extra = {}) {
          if (!['composeUp', 'composeStop', 'composeDown', 'composeUpRecreate'].includes(action)) {
            throw new Error('Unapproved host-test action');
          }
          return postForm(page, util, { ...extra, action, path, background: '1', trackOperation: '1', waitForHealthy: '0' });
        },
        async containers() {
          const result = await postForm(page, exec, { action: 'getStackContainers', script: project });
          expect(result.response.ok(), result.body).toBeTruthy();
          expect(result.json?.result, result.body).toBe('success');
          expect(Array.isArray(result.json?.containers), result.body).toBe(true);
          const containers = result.json!.containers as Snapshot[];
          containers.forEach(ct => {
            expect(ct.name?.startsWith(project + '-') || ct.name?.startsWith(project + '_'),
              'Refuse container actions outside the generated project namespace.').toBe(true);
            containerNames.add(ct.name!);
          });
          return containers;
        },
        async refresh() {
          await page.reload({ waitUntil: 'domcontentloaded' });
          await expect(page.locator(`[data-project="${project}"].compose-sortable`)).toBeVisible();
        },
      };
      await stack.save(yaml);
      const profiles = await postForm(page, exec, {
        action: 'saveProfiles', script: project, scriptContents: JSON.stringify(['selected', 'excluded']),
      });
      expect(profiles.response.ok(), profiles.body).toBeTruthy();
      await use(stack);
    } finally {
      // Never use a guessed project/path after an incomplete create response.
      if (project === name && path.endsWith('/' + project)) {
        const error = await composeDownAndWait(page, project, path);
        expect(error, 'Cleanup Down failed; leave metadata intact for investigation.').toBeNull();
        const deleteError = await deleteStackWithRetries(page, project);
        expect(deleteError, 'Generated host-test stack was not deleted.').toBeNull();
      } else if (project) {
        throw new Error('Creation was incomplete; refusing cleanup with an unverified path. Inspect the pw-host stack.');
      }
      if (blocked.length) {
        await test.info().attach('blocked-browser-actions', {
          body: JSON.stringify(blocked), contentType: 'application/json',
        });
      }
    }
  },
});

async function start(page: Page, stack: HostStack, extra: Record<string, string> = {}) {
  const response = await stack.action('composeUp', extra);
  await waitForOperation(page, stack.project, response);
}

test('Pull/Build & Up creates only default and selected-profile services, then redeploys stopped services', async ({ page, stack }) => {
  await stack.refresh();
  const icon = page.locator(`[data-project="${stack.project}"].compose-sortable .hand[data-stackid]`);
  const iconId = await icon.getAttribute('id');
  await icon.click();
  await page.locator(`#dropdown-${iconId}`).getByText('Pull/Build & Up', { exact: true }).click();
  await expect(page.locator('.sweet-alert:visible h2')).toHaveText('Select Profiles');
  await page.locator('#profile_all_profiles').uncheck();
  await page.locator('.profile_checkbox[value="selected"]').check();
  await page.locator('.profile_checkbox[value="excluded"]').uncheck();
  let dispatchSeen = false;
  const dispatchPromise = page.waitForResponse(response => {
    const params = new URLSearchParams(response.request().postData() || '');
    return response.url().endsWith('/ComposeUtil.php')
      && params.get('action') === 'composeUp' && params.get('path') === stack.path;
  }, { timeout: 30_000 }).then(response => { dispatchSeen = true; return response; });
  // Keep the rejection owned while the SweetAlert transition is in flight.
  void dispatchPromise.catch(() => {});
  await page.waitForTimeout(800);
  await page.locator('.sweet-alert:visible button.confirm').click();
  const confirmation = page.locator('.sweet-alert:visible h2').filter({ hasText: 'Pull/Build & Up:' });
  await expect.poll(async () => dispatchSeen || await confirmation.isVisible()).toBe(true);
  if (await confirmation.isVisible()) {
    await page.waitForTimeout(800);
    await page.locator('#swal-run-bg-checkbox').check({ force: true });
    await page.locator('.sweet-alert:visible button.confirm').click();
  }
  const response = await dispatchPromise;
  const params = new URLSearchParams(response.request().postData() || '');
  expect(params.get('prepareImages')).toBe('1');
  expect(params.get('profile')).toBe('selected');
  const body = await response.text();
  await waitForOperation(page, stack.project, { response, body, json: JSON.parse(body) });
  expect((await stack.containers()).map(ct => ct.service).sort()).toEqual(['app', 'selected']);
  await waitForOperation(page, stack.project, await stack.action('composeStop', { profile: 'selected' }));
  expect((await stack.containers()).every(ct => ct.state === 'exited')).toBe(true);
  await start(page, stack, { prepareImages: '1', profile: 'selected' });
  expect((await stack.containers()).filter(ct => ct.state === 'running').map(ct => ct.service).sort()).toEqual(['app', 'selected']);
});

test('failed image preparation records failure and never creates containers', async ({ page, stack }) => {
  await stack.save('services:\n  app:\n    image: INVALID IMAGE REFERENCE\n');
  const dispatch = await stack.action('composeUp', { prepareImages: '1' });
  // Configuration failure may refuse dispatch or produce a failed receipt.
  if (dispatch.json?.operations) {
    await waitForOperation(page, stack.project, dispatch, 'nonzero');
  } else {
    expect(dispatch.json?.error, dispatch.body).toBeTruthy();
  }
  expect(await stack.containers()).toEqual([]);
  // Restore valid configuration before cleanup.
  await stack.save(yaml);
});

test('build-only deployment builds successfully and a later build failure prevents startup', async ({ page, stack }) => {
  const buildYaml = (instruction: string) => [
    'services:', '  app:', '    mem_limit: 32m', '    cpus: 0.10', '    pids_limit: 32',
    '    build:', '      context: .', '      dockerfile_inline: |',
    '        FROM alpine:3.20', `        ${instruction}`, '        CMD ["sleep", "600"]', '',
  ].join('\n');
  try {
    await stack.save(buildYaml('LABEL purpose=compose-isolated-host-test'));
    await start(page, stack, { prepareImages: '1' });
    expect((await stack.containers()).map(ct => ct.state)).toEqual(['running']);
    await waitForOperation(page, stack.project, await stack.action('composeDown'));
    await stack.save(buildYaml('RUN exit 23'));
    await waitForOperation(page, stack.project, await stack.action('composeUp', { prepareImages: '1' }), 'nonzero');
    expect(await stack.containers()).toEqual([]);
  } finally {
    await stack.save(yaml);
  }
});

for (const control of ['background', 'stop', 'done'] as const) {
test(`foreground terminal ${control} preserves explicit command lifecycle`, async ({ page, stack }) => {
  await stack.save(yaml
    .replace('sleep 600', 'sleep 10; touch /tmp/ready; sleep 600')
    .replace('["CMD", "true"]', '["CMD", "test", "-f", "/tmp/ready"]'));
  await stack.refresh();
  const responsePromise = page.waitForResponse(response => {
    if (!response.url().endsWith('/ComposeUtil.php')) return false;
    const data = new URLSearchParams(response.request().postData() || '');
    return data.get('path') === stack.path && data.get('action') === 'composeUp';
  });
  const originalURL = page.url();
  await page.evaluate(({ path, project }) => {
    (window as unknown as { performComposeAction(options: Record<string, unknown>): void })
      .performComposeAction({
        stackName: project, actionName: 'up', background: false,
        payload: { action: 'composeUp', path, waitForHealthy: '1', waitTimeout: '30' },
      });
  }, { path: stack.path, project: stack.project });
  const response = await responsePromise;
  const body = await response.text();
  const dispatch = { response, body, json: JSON.parse(body) };
  await expect(page.locator('#sb-container iframe')).toBeVisible();
  const terminal = page.frameLocator('#sb-container iframe');
  if (control === 'done') {
    await waitForOperation(page, stack.project, dispatch);
    await expect(terminal.getByRole('button', { name: 'Stop command' })).toBeHidden();
    await terminal.getByRole('button', { name: 'Done', exact: true }).click();
  } else {
    await expect(terminal.getByRole('button', { name: 'Stop command' })).toBeEnabled();
    await expect(terminal.getByRole('button', { name: 'Done', exact: true })).toBeHidden();
    if (control === 'stop') {
      page.once('dialog', dialog => dialog.accept());
      await terminal.getByRole('button', { name: 'Stop command' }).click();
      await waitForOperation(page, stack.project, dispatch, 'nonzero');
      await expect(terminal.getByRole('status')).toContainText('exit code 143');
      await terminal.getByRole('button', { name: 'Done', exact: true }).click();
    } else {
      await terminal.getByRole('button', { name: 'Continue in background' }).click();
      await waitForOperation(page, stack.project, dispatch);
    }
  }
  await expect(page.locator('#sb-container iframe')).toBeHidden();
  await expect(page).toHaveURL(originalURL);
  if (control !== 'stop') {
    const row = page.locator(`[data-project="${stack.project}"].compose-sortable`);
    await expect(row.locator('.state').first()).toHaveText(/started/);
  }
});
}

test('actual container restart clears unchanged detail indicators and restores menu actions', async ({ page, stack }) => {
  await stack.save(yaml.replace(/    healthcheck:[\s\S]*?  selected:/, '  selected:'));
  await start(page, stack);
  await stack.refresh();
  await page.evaluate(project => {
    const row = document.querySelector(`[data-project="${project}"].compose-sortable`);
    const id = row?.id.replace('stack-row-', '');
    if (!id) throw new Error('Generated row missing');
    (window as unknown as { toggleStackDetails(id: string): void }).toggleStackDetails(id);
  }, stack.project);
  // Resolve the exact generated container name from the authoritative snapshot.
  const container = (await stack.containers()).find(ct => ct.service === 'app');
  expect(container?.name).toBeTruthy();
  const row = page.locator(`tr[data-container="${container!.name}"]`);
  await expect(row).toBeVisible();
  await expect(row.locator('.state')).toHaveText('started');
  const iconId = await row.locator('.hand[data-name]').getAttribute('id');
  expect(iconId).toBeTruthy();
  await page.evaluate(({ project, name }) => {
    const id = document.querySelector(`[data-project="${project}"].compose-sortable`)!.id.replace('stack-row-', '');
    (window as unknown as { containerAction(name: string, action: string, id: string): void })
      .containerAction(name!, 'restart', id);
  }, { project: stack.project, name: container!.name });
  await expect(row.locator('.state')).toHaveText('restarting');
  await page.evaluate(id => {
    (window as unknown as { addComposeContainerContext(id: string): void }).addComposeContainerContext(id!);
  }, iconId);
  await expect(page.locator(`#dropdown-${iconId}`).getByText('Stop', { exact: true }))
    .toHaveAttribute('aria-disabled', 'true');
  await expect(row.locator('.compose-status-spinner')).toHaveCount(0, { timeout: 60_000 });
  await expect(row.locator('.state')).toHaveText('started');
  await expect(page.locator(`#dropdown-${iconId}`).getByText('Stop', { exact: true }))
    .not.toHaveAttribute('aria-disabled', 'true');
});

test('starting-health polling recovers after a real browser snapshot is aborted', async ({ page, stack }) => {
  await stack.save(yaml.replace('sleep 600', 'sleep 14; touch /tmp/ready; sleep 600')
    .replace('test: ["CMD", "true"]', 'test: ["CMD", "test", "-f", "/tmp/ready"]'));
  await start(page, stack);
  await stack.refresh();
  await expect(page.locator(`[data-project="${stack.project}"].compose-sortable td.col-health`))
    .toContainText('starting');
  let aborted = false;
  await page.route('**/Exec.php', async route => {
    const params = new URLSearchParams(route.request().postData() || '');
    if (!aborted && params.get('action') === 'getStackContainers' && params.get('script') === stack.project) {
      aborted = true;
      await route.abort('failed');
    } else {
      await route.fallback();
    }
  });
  // Seed the accepted starting snapshot using the normal targeted refresh.
  await page.evaluate(project => {
    (window as unknown as { refreshStackByProject(project: string): void }).refreshStackByProject(project);
  }, stack.project);
  await expect.poll(() => aborted).toBe(true);
  await expect(page.locator(`[data-project="${stack.project}"].compose-sortable td.col-health`))
    .toContainText('healthy', { timeout: 60_000 });
});

test('recreated container identities reach the live row without a page reload', async ({ page, stack }) => {
  await start(page, stack);
  await stack.refresh();
  const before = (await stack.containers()).find(ct => ct.service === 'app')?.id;
  expect(before).toBeTruthy();
  await waitForOperation(page, stack.project, await stack.action('composeUpRecreate'));
  const after = (await stack.containers()).find(ct => ct.service === 'app')?.id;
  expect(after).toBeTruthy();
  expect(after).not.toBe(before);
  const originalURL = page.url();
  await page.evaluate(project => {
    (window as unknown as { refreshStackByProject(project: string): void }).refreshStackByProject(project);
  }, stack.project);
  const row = page.locator(`[data-project="${stack.project}"].compose-sortable`);
  await expect(row).toHaveAttribute('data-ctids', after!.replace(/^sha256:/, '').slice(0, 12));
  await expect(page).toHaveURL(originalURL);
});

test('Dashboard action completion rejects an older real host snapshot', async ({ page, stack }) => {
  await page.goto(process.env.E2E_DASHBOARD_PATH || '/Dashboard', { waitUntil: 'domcontentloaded' });
  const icon = page.locator(`.compose-dash-icon[data-stack-folder="${stack.project}"]`);
  await expect(icon).toBeVisible();
  await expect(icon).toHaveAttribute('data-stack-running', '0');
  let release!: () => void;
  let fetched!: () => void;
  let delivered!: () => void;
  const held = new Promise<void>(resolve => { release = resolve; });
  const snapshotFetched = new Promise<void>(resolve => { fetched = resolve; });
  const snapshotDelivered = new Promise<void>(resolve => { delivered = resolve; });
  let holdNext = true;
  await page.route('**/DashboardStacks.php', async route => {
    if (!holdNext) {
      await route.continue();
      return;
    }
    holdNext = false;
    const snapshot = await route.fetch();
    expect(snapshot.ok()).toBe(true);
    fetched();
    await held;
    await route.fulfill({ response: snapshot });
    delivered();
  });
  try {
    await page.evaluate(() => {
      (window as unknown as { composeDashboardActionBoxClosed(): void }).composeDashboardActionBoxClosed();
    });
    await snapshotFetched;
    const dispatchPromise = page.waitForResponse(response => {
      const params = new URLSearchParams(response.request().postData() || '');
      return response.url().endsWith('/ComposeUtil.php')
        && params.get('action') === 'composeUp' && params.get('path') === stack.path;
    }, { timeout: 30_000 });
    void dispatchPromise.catch(() => {});
    await icon.click();
    const iconId = await icon.getAttribute('id');
    const menu = page.locator(`#dropdown-${iconId}`);
    // Dashboard attaches its menu on the first click; the host delegated
    // opener can require a second click once that binding exists.
    if (!await menu.isVisible()) await icon.click();
    await menu.getByText('Start', { exact: true }).click({ timeout: 10_000 });
    const dispatchResponse = await dispatchPromise;
    const body = await dispatchResponse.text();
    await waitForOperation(page, stack.project, {
      response: dispatchResponse, body, json: JSON.parse(body),
    });
    await expect(icon).toHaveAttribute('data-stack-running', '1', { timeout: 60_000 });
    release();
    await snapshotDelivered;
    await expect(icon).toHaveAttribute('data-stack-running', '1');
    // Let the browser process the old response before asserting retained state.
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await expect(icon).toHaveAttribute('data-stack-running', '1');
  } finally {
    release();
  }
});
