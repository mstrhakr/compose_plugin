import { expect, test, type Page } from '@playwright/test';
import {
  buildStackName,
  composeDownAndWait,
  deleteStackWithRetries,
  postForm,
  waitForStackUnlock,
} from './helpers/composeE2eHelpers';

const composePath = process.env.E2E_COMPOSE_PATH || '/Docker/Compose';
const mutationEnabled = ['1', 'true', 'yes'].includes(
  (process.env.E2E_ENABLE_MUTATION_TESTS || '').toLowerCase()
);
const stackPrefix = (process.env.E2E_TEST_STACK_PREFIX || 'pw-e2e').trim() || 'pw-e2e';

type OperationRequest = {
  action: string;
  background: string;
};

function parsePostField(postData: string | null, field: string): string {
  if (!postData) {
    return '';
  }

  const params = new URLSearchParams(postData);
  return (params.get(field) || '').trim();
}


async function createUiFlowStack(page: Page, stackName: string): Promise<{ createdProject: string; projectPath: string }> {
  const composeYaml = [
    'services:',
    '  app:',
    '    image: alpine:3.20',
    '    command: ["sh", "-lc", "sleep 90"]',
    '',
  ].join('\n');

  const addStack = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
    action: 'addStack',
    stackName,
    stackDesc: 'Playwright UI operation flow stack',
  });

  expect(addStack.response.ok(), addStack.body).toBeTruthy();
  expect(addStack.json?.result, addStack.body).toBe('success');

  const createdProject = String(addStack.json?.project || '').trim();
  expect(createdProject).not.toBe('');

  const settings = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
    action: 'getStackSettings',
    script: createdProject,
  });

  expect(settings.response.ok(), settings.body).toBeTruthy();
  expect(settings.json?.result, settings.body).toBe('success');

  const projectPath = String(settings.json?.projectPath || '').trim();
  expect(projectPath).not.toBe('');

  const saveYml = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
    action: 'saveYml',
    script: createdProject,
    scriptContents: composeYaml,
  });

  expect(saveYml.response.ok(), saveYml.body).toBeTruthy();
  expect(saveYml.body.toLowerCase()).toContain('saved');

  return { createdProject, projectPath };
}

async function triggerUiActionAndCaptureRequest(
  page: Page,
  projectPath: string,
  actionName: 'up' | 'down',
  runInBackground: boolean
): Promise<OperationRequest[]> {
  const observed: OperationRequest[] = [];

  const onRequest = (request: { url(): string; method(): string; postData(): string | null }) => {
    if (!request.url().includes('/plugins/compose.manager/include/ComposeUtil.php')) {
      return;
    }
    if (request.method().toUpperCase() !== 'POST') {
      return;
    }

    const action = parsePostField(request.postData(), 'action');
    const background = parsePostField(request.postData(), 'background');
    observed.push({ action, background });
  };

  page.on('request', onRequest);
  try {
    await page.evaluate(
      ({ action, path }) => {
        const fn = (window as { showStackActionDialog?: (a: string, p: string, profile: string) => void })
          .showStackActionDialog;
        if (typeof fn !== 'function') {
          throw new Error('showStackActionDialog is unavailable in page context.');
        }
        fn(action, path, '');
      },
      { action: actionName, path: projectPath }
    );

    const titleText = actionName === 'up' ? 'Compose Up:' : 'Compose Down:';
    const dialogTitle = page.locator('.sweet-alert h2').filter({ hasText: titleText });
    await expect(dialogTitle).toBeVisible();

    // Modal should render the container list block and at least one icon image.
    await expect(page.locator('.sweet-alert .fa.fa-cubes')).toBeVisible();
    const modalIcons = page.locator('.sweet-alert img');
    await expect(modalIcons.first()).toBeVisible();
    await expect
      .poll(
        async () => {
          const firstIcon = modalIcons.first();
          return firstIcon.evaluate((img) => {
            const image = img as HTMLImageElement;
            return image.complete && image.naturalWidth > 0;
          });
        },
        { timeout: 10_000 }
      )
      .toBe(true);

    const runBgCheckbox = page.locator('#swal-run-bg-checkbox');
    await expect(runBgCheckbox).toBeVisible();

    if (runInBackground) {
      await runBgCheckbox.check({ force: true });
    } else {
      await runBgCheckbox.uncheck({ force: true });
    }

    await page.locator('.sweet-alert button.confirm').click();
    await expect(dialogTitle).toBeHidden({ timeout: 20_000 });

    const expectedComposeAction = actionName === 'up' ? 'composeUp' : 'composeDown';
    await expect
      .poll(
        () =>
          observed.some(
            (entry) =>
              entry.action === expectedComposeAction &&
              entry.background === (runInBackground ? '1' : '0')
          ),
        { timeout: 20_000 }
      )
      .toBe(true);

    return observed;
  } finally {
    page.off('request', onRequest);
  }
}

test.describe('Compose Manager UI flow: background operations', () => {
  test('compose up posts with background=1 from modal', async ({ page }) => {
    test.setTimeout(180_000);
    test.skip(!process.env.E2E_BASE_URL, 'Set E2E_BASE_URL for live-server E2E tests.');
    test.skip(!mutationEnabled, 'Set E2E_ENABLE_MUTATION_TESTS=1 to allow mutation lifecycle tests.');

    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    if (page.url().toLowerCase().includes('/login')) {
      throw new Error('Not authenticated. Refresh storage state and retry.');
    }

    const modalWarningsDisabled = await page.evaluate(async () => {
      const fn = (window as { getConfig?: () => Promise<Record<string, string>> }).getConfig;
      if (typeof fn !== 'function') {
        return false;
      }
      const cfg = await fn();
      return String(cfg?.DISABLE_ACTION_WARNINGS || '').toLowerCase() === 'true';
    });
    test.skip(
      modalWarningsDisabled,
      'DISABLE_ACTION_WARNINGS=true bypasses modal options and prevents explicit bg/fg UI flow testing.'
    );

    const stackName = buildStackName(stackPrefix);
    let createdProject = '';
    let projectPath = '';

    try {
      const created = await createUiFlowStack(page, stackName);
      createdProject = created.createdProject;
      projectPath = created.projectPath;

      await triggerUiActionAndCaptureRequest(page, projectPath, 'up', true);
    } finally {
      if (createdProject) {
        const downError = await composeDownAndWait(page, createdProject, projectPath);
        if (downError) {
          test.info().attach('bg-cleanup-composeDown', {
            body: downError,
            contentType: 'text/plain',
          });
        } else {
          const deleteError = await deleteStackWithRetries(page, createdProject, 6);
          if (!deleteError) {
            createdProject = '';
          } else {
            test.info().attach('bg-cleanup-deleteStack', {
              body: deleteError,
              contentType: 'text/plain',
            });
          }
        }
      }
    }

    expect(createdProject, 'Background UI flow cleanup failed.').toBe('');
  });
});

test.describe('Compose Manager UI flow: foreground operations', () => {
  test('compose up foreground opens ttyd/openBox and closes after completion', async ({ page }) => {
    test.setTimeout(180_000);
    test.skip(!process.env.E2E_BASE_URL, 'Set E2E_BASE_URL for live-server E2E tests.');
    test.skip(!mutationEnabled, 'Set E2E_ENABLE_MUTATION_TESTS=1 to allow mutation lifecycle tests.');

    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    if (page.url().toLowerCase().includes('/login')) {
      throw new Error('Not authenticated. Refresh storage state and retry.');
    }

    const modalWarningsDisabled = await page.evaluate(async () => {
      const fn = (window as { getConfig?: () => Promise<Record<string, string>> }).getConfig;
      if (typeof fn !== 'function') {
        return false;
      }
      const cfg = await fn();
      return String(cfg?.DISABLE_ACTION_WARNINGS || '').toLowerCase() === 'true';
    });
    test.skip(
      modalWarningsDisabled,
      'DISABLE_ACTION_WARNINGS=true bypasses modal options and prevents explicit bg/fg UI flow testing.'
    );

    const stackName = buildStackName(stackPrefix);
    let createdProject = '';
    let projectPath = '';

    try {
      const created = await createUiFlowStack(page, stackName);
      createdProject = created.createdProject;
      projectPath = created.projectPath;

      await triggerUiActionAndCaptureRequest(page, projectPath, 'up', false);

      const shadowboxWrapper = page.locator('#sb-wrapper');
      await expect(shadowboxWrapper).toBeVisible({ timeout: 30_000 });

      const openBoxFrame = page.locator('#sb-wrapper iframe').first();
      await expect(openBoxFrame).toBeVisible({ timeout: 30_000 });

      const openBoxDoc = page.frameLocator('#sb-wrapper iframe').first();
      const ttydRuntime = openBoxDoc.locator('#ttyd-frame');
      const ttydDoneButton = openBoxDoc.locator('#done-btn');
      const loggingDoneButton = openBoxDoc.locator('button.logLine').filter({ hasText: /^Done$/i });

      await expect
        .poll(
          async () => {
            if (await ttydRuntime.isVisible()) {
              return 'ttyd';
            }
            if (await loggingDoneButton.first().isVisible()) {
              return 'logging';
            }
            return 'none';
          },
          { timeout: 30_000 }
        )
        .not.toBe('none');

      // Foreground flow should keep modal open until user closes it after completion.
      await waitForStackUnlock(page, createdProject);

      if (await ttydDoneButton.isVisible()) {
        await ttydDoneButton.click();
      } else if (await loggingDoneButton.first().isVisible()) {
        await loggingDoneButton.first().click();
      } else {
        await page.locator('#sb-nav-close').click();
      }

      await expect(shadowboxWrapper).toBeHidden({ timeout: 20_000 });
    } finally {
      if (createdProject) {
        const downError = await composeDownAndWait(page, createdProject, projectPath);
        if (downError) {
          test.info().attach('fg-cleanup-composeDown', {
            body: downError,
            contentType: 'text/plain',
          });
        } else {
          const deleteError = await deleteStackWithRetries(page, createdProject, 6);
          if (!deleteError) {
            createdProject = '';
          } else {
            test.info().attach('fg-cleanup-deleteStack', {
              body: deleteError,
              contentType: 'text/plain',
            });
          }
        }
      }
    }

    expect(createdProject, 'Foreground UI flow cleanup failed.').toBe('');
  });
});
