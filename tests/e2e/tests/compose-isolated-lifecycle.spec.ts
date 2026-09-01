import { expect, test, type Page } from '@playwright/test';
import {
  buildStackName,
  composeDownAndWait,
  deleteStackWithRetries,
  postForm,
  readCsrfToken,
} from './helpers/composeE2eHelpers';

const composePath = process.env.E2E_COMPOSE_PATH || '/Docker/Compose';
const mutationEnabled = ['1', 'true', 'yes'].includes(
  (process.env.E2E_ENABLE_MUTATION_TESTS || '').toLowerCase()
);
const stackPrefix = (process.env.E2E_TEST_STACK_PREFIX || 'pw-e2e').trim() || 'pw-e2e';
const externalTestDir = (process.env.E2E_EXTERNAL_TEST_DIR || '').trim();

function parsePostAction(postData: string | null): string {
  if (!postData) {
    return '';
  }

  const params = new URLSearchParams(postData);
  return (params.get('action') || '').trim();
}

test.describe('Compose Manager isolated lifecycle (GUID stack)', () => {
  test('create/edit/start/stop/delete stays scoped to generated stack', async ({ page }) => {
    test.setTimeout(120_000);
    test.skip(!process.env.E2E_BASE_URL, 'Set E2E_BASE_URL for live-server E2E tests.');
    test.skip(!mutationEnabled, 'Set E2E_ENABLE_MUTATION_TESTS=1 to allow mutation lifecycle tests.');

    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    if (page.url().toLowerCase().includes('/login')) {
      throw new Error('Not authenticated. Refresh storage state and retry.');
    }

    const csrfToken = await readCsrfToken(page);
    expect(csrfToken, 'Missing csrf_token in page context.').not.toBe('');

    const stackName = buildStackName(stackPrefix);
    let createdProject = '';
    let projectPath = '';

    const composeYaml = [
      'services:',
      '  app:',
      '    image: alpine:3.20',
      '    command: ["sh", "-lc", "sleep 90"]',
      '',
    ].join('\n');

    try {
      const addStack = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'addStack',
        stackName,
        stackDesc: 'Playwright isolated lifecycle test stack',
      });

      expect(addStack.response.ok(), addStack.body).toBeTruthy();
      expect(addStack.json?.result, addStack.body).toBe('success');

      createdProject = String(addStack.json?.project || '').trim();
      expect(createdProject).not.toBe('');

      const settings = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });

      expect(settings.response.ok(), settings.body).toBeTruthy();
      expect(settings.json?.result, settings.body).toBe('success');

      projectPath = String(settings.json?.projectPath || '').trim();
      expect(projectPath).not.toBe('');

      const saveYml = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'saveYml',
        script: createdProject,
        scriptContents: composeYaml,
      });

      expect(saveYml.response.ok(), saveYml.body).toBeTruthy();
      expect(saveYml.body.toLowerCase()).toContain('saved');

      const setDesc = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'changeDesc',
        script: createdProject,
        newDesc: `Updated ${stackName}`,
      });

      expect(setDesc.response.ok(), setDesc.body).toBeTruthy();
      expect(setDesc.json?.result, setDesc.body).toBe('success');

      const start = await postForm(page, '/plugins/compose.manager/include/ComposeUtil.php', {
        action: 'composeUp',
        path: projectPath,
        background: '1',
      });

      expect(start.response.ok(), start.body).toBeTruthy();
      expect(start.json?.background, start.body).toBe(true);

      const stop = await postForm(page, '/plugins/compose.manager/include/ComposeUtil.php', {
        action: 'composeStop',
        path: projectPath,
        background: '1',
      });

      expect(stop.response.ok(), stop.body).toBeTruthy();
      expect(stop.json?.background, stop.body).toBe(true);
    } finally {
      if (createdProject) {
        const downError = await composeDownAndWait(page, createdProject, projectPath);
        if (downError) {
          test.info().attach('cleanup-composeDown', {
            body: downError,
            contentType: 'text/plain',
          });
        } else {
          const deleteError = await deleteStackWithRetries(page, createdProject, 6);
          if (!deleteError) {
            createdProject = '';
          } else {
            test.info().attach('cleanup-deleteStack', {
              body: deleteError,
              contentType: 'text/plain',
            });
          }
        }
      }
    }

    expect(createdProject, 'Stack cleanup failed; check attachments for deleteStack response.').toBe('');
  });

  test('compose down confirmation modal appears and dismisses on confirm', async ({ page }) => {
    test.setTimeout(120_000);
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
      'DISABLE_ACTION_WARNINGS=true bypasses the confirmation modal.'
    );

    const stackName = buildStackName(stackPrefix);
    let createdProject = '';
    let projectPath = '';
    const composeActions: string[] = [];

    const onRequest = (request: { url(): string; method(): string; postData(): string | null }) => {
      if (!request.url().includes('/plugins/compose.manager/include/ComposeUtil.php')) {
        return;
      }
      if (request.method().toUpperCase() !== 'POST') {
        return;
      }
      const action = parsePostAction(request.postData());
      if (action) {
        composeActions.push(action);
      }
    };
    page.on('request', onRequest);

    try {
      const addStack = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'addStack',
        stackName,
        stackDesc: 'Playwright modal verification stack',
      });
      expect(addStack.response.ok(), addStack.body).toBeTruthy();
      expect(addStack.json?.result, addStack.body).toBe('success');

      createdProject = String(addStack.json?.project || '').trim();
      expect(createdProject).not.toBe('');

      const settings = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });
      expect(settings.response.ok(), settings.body).toBeTruthy();
      expect(settings.json?.result, settings.body).toBe('success');

      projectPath = String(settings.json?.projectPath || '').trim();
      expect(projectPath).not.toBe('');

      await page.evaluate(
        ({ path }) => {
          const fn = (window as { showStackActionDialog?: (action: string, p: string, profile: string) => void })
            .showStackActionDialog;
          if (typeof fn !== 'function') {
            throw new Error('showStackActionDialog is unavailable in page context.');
          }
          fn('down', path, '');
        },
        { path: projectPath }
      );

      const downTitle = page.locator('.sweet-alert h2').filter({ hasText: 'Compose Down:' });
      await expect(downTitle).toBeVisible();
      await expect(page.locator('#swal-run-bg-checkbox')).toBeVisible();

      await page.locator('.sweet-alert button.confirm').click();
      await expect(downTitle).toBeHidden({ timeout: 15_000 });

      await expect
        .poll(() => composeActions.includes('composeDown'), { timeout: 15_000 })
        .toBe(true);
    } finally {
      page.off('request', onRequest);

      if (createdProject) {
        const downError = await composeDownAndWait(page, createdProject, projectPath);
        if (downError) {
          test.info().attach('modal-cleanup-composeDown', {
            body: downError,
            contentType: 'text/plain',
          });
        } else {
          const deleteError = await deleteStackWithRetries(page, createdProject, 6);
          if (!deleteError) {
            createdProject = '';
          } else {
            test.info().attach('modal-cleanup-deleteStack', {
              body: deleteError,
              contentType: 'text/plain',
            });
          }
        }
      }
    }

    expect(createdProject, 'Stack cleanup failed after modal verification test.').toBe('');
  });

  test('stack settings validates icon types and preserves state on invalid input', async ({ page }) => {
    test.setTimeout(120_000);
    test.skip(!process.env.E2E_BASE_URL, 'Set E2E_BASE_URL for live-server E2E tests.');
    test.skip(!mutationEnabled, 'Set E2E_ENABLE_MUTATION_TESTS=1 to allow mutation lifecycle tests.');

    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    if (page.url().toLowerCase().includes('/login')) {
      throw new Error('Not authenticated. Refresh storage state and retry.');
    }

    const stackName = buildStackName(stackPrefix);
    let createdProject = '';
    let projectPath = '';

    const httpIcon = 'https://example.com/icon.png';
    const dataIcon = 'data:image/svg+xml;base64,PHN2Zy8+';
    const localIcon = '/mnt/user/appdata/compose.manager/icon-local.png';
    const validWebui = 'https://[IP]:[PORT:8080]';

    try {
      const addStack = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'addStack',
        stackName,
        stackDesc: 'Playwright stack settings icon validation',
      });
      expect(addStack.response.ok(), addStack.body).toBeTruthy();
      expect(addStack.json?.result, addStack.body).toBe('success');

      createdProject = String(addStack.json?.project || '').trim();
      expect(createdProject).not.toBe('');

      const settings = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });
      expect(settings.response.ok(), settings.body).toBeTruthy();
      expect(settings.json?.result, settings.body).toBe('success');

      projectPath = String(settings.json?.projectPath || '').trim();
      expect(projectPath).not.toBe('');

      const setHttpIcon = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        iconUrl: httpIcon,
        webuiUrl: validWebui,
      });
      expect(setHttpIcon.response.ok(), setHttpIcon.body).toBeTruthy();
      expect(setHttpIcon.json?.result, setHttpIcon.body).toBe('success');

      const afterHttp = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });
      expect(afterHttp.json?.iconUrl, afterHttp.body).toBe(httpIcon);
      expect(afterHttp.json?.webuiUrl, afterHttp.body).toBe(validWebui);

      const setDataIcon = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        iconUrl: dataIcon,
        webuiUrl: validWebui,
      });
      expect(setDataIcon.response.ok(), setDataIcon.body).toBeTruthy();
      expect(setDataIcon.json?.result, setDataIcon.body).toBe('success');

      const afterData = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });
      expect(afterData.json?.iconUrl, afterData.body).toBe(dataIcon);

      const setLocalIcon = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        iconUrl: localIcon,
        webuiUrl: validWebui,
      });
      expect(setLocalIcon.response.ok(), setLocalIcon.body).toBeTruthy();
      expect(setLocalIcon.json?.result, setLocalIcon.body).toBe('success');

      const invalidIcon = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        iconUrl: 'javascript:alert(1)',
        webuiUrl: validWebui,
      });
      expect(invalidIcon.response.ok(), invalidIcon.body).toBeTruthy();
      expect(invalidIcon.json?.result, invalidIcon.body).toBe('error');
      expect(String(invalidIcon.json?.message || '')).toContain('Invalid icon');

      const invalidWebui = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        iconUrl: localIcon,
        webuiUrl: 'https://[IP]:[PORT]',
      });
      expect(invalidWebui.response.ok(), invalidWebui.body).toBeTruthy();
      expect(invalidWebui.json?.result, invalidWebui.body).toBe('error');
      expect(String(invalidWebui.json?.message || '')).toContain('Bare [PORT] is not supported');

      const afterInvalid = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });
      expect(afterInvalid.json?.iconUrl, afterInvalid.body).toBe(localIcon);
      expect(afterInvalid.json?.webuiUrl, afterInvalid.body).toBe(validWebui);
    } finally {
      if (createdProject) {
        const downError = await composeDownAndWait(page, createdProject, projectPath);
        if (downError) {
          test.info().attach('settings-cleanup-composeDown', {
            body: downError,
            contentType: 'text/plain',
          });
        } else {
          const deleteError = await deleteStackWithRetries(page, createdProject, 6);
          if (!deleteError) {
            createdProject = '';
          } else {
            test.info().attach('settings-cleanup-deleteStack', {
              body: deleteError,
              contentType: 'text/plain',
            });
          }
        }
      }
    }

    expect(createdProject, 'Stack cleanup failed after icon/settings validation test.').toBe('');
  });

  test('external stack options and settings round-trip with restore', async ({ page }) => {
    test.setTimeout(120_000);
    test.skip(!process.env.E2E_BASE_URL, 'Set E2E_BASE_URL for live-server E2E tests.');
    test.skip(!mutationEnabled, 'Set E2E_ENABLE_MUTATION_TESTS=1 to allow mutation lifecycle tests.');
    test.skip(
      !externalTestDir,
      'Set E2E_EXTERNAL_TEST_DIR to enable external-path mutation tests explicitly.'
    );

    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    if (page.url().toLowerCase().includes('/login')) {
      throw new Error('Not authenticated. Refresh storage state and retry.');
    }

    const stackName = buildStackName(stackPrefix);
    let createdProject = '';
    let projectPath = '';

    const validExternalPath = externalTestDir;
    const validIcon = '/mnt/user/appdata/compose.manager/icon-roundtrip.png';
    const validWebui = 'https://[IP]:[PORT:8080]';

    try {
      const addStack = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'addStack',
        stackName,
        stackDesc: 'Playwright external settings round-trip',
      });
      expect(addStack.response.ok(), addStack.body).toBeTruthy();
      expect(addStack.json?.result, addStack.body).toBe('success');

      createdProject = String(addStack.json?.project || '').trim();
      expect(createdProject).not.toBe('');

      const initial = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });
      expect(initial.response.ok(), initial.body).toBeTruthy();
      expect(initial.json?.result, initial.body).toBe('success');

      projectPath = String(initial.json?.projectPath || '').trim();
      expect(projectPath).not.toBe('');

      const baseline = {
        iconUrl: String(initial.json?.iconUrl || ''),
        webuiUrl: String(initial.json?.webuiUrl || ''),
        envPath: String(initial.json?.envPath || ''),
        defaultProfile: String(initial.json?.defaultProfile || ''),
        extraComposeFiles: String(initial.json?.extraComposeFiles || ''),
        useDefaultComposeFiles: Boolean(initial.json?.useDefaultComposeFiles),
        labelsViewMode: String(initial.json?.labelsViewMode || 'basic'),
      };

      const setLabelsAdvanced = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setLabelsViewMode',
        script: createdProject,
        labelsViewMode: 'advanced',
      });
      expect(setLabelsAdvanced.response.ok(), setLabelsAdvanced.body).toBeTruthy();
      expect(setLabelsAdvanced.json?.result, setLabelsAdvanced.body).toBe('success');
      expect(setLabelsAdvanced.json?.labelsViewMode, setLabelsAdvanced.body).toBe('advanced');

      const invalidLabelsMode = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setLabelsViewMode',
        script: createdProject,
        labelsViewMode: 'weird',
      });
      expect(invalidLabelsMode.response.ok(), invalidLabelsMode.body).toBeTruthy();
      expect(invalidLabelsMode.json?.result, invalidLabelsMode.body).toBe('error');
      expect(String(invalidLabelsMode.json?.message || '')).toContain('Invalid labels view mode');

      const invalidExtraCompose = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        iconUrl: validIcon,
        webuiUrl: validWebui,
        extraComposeFiles: '../escape.yml',
      });
      expect(invalidExtraCompose.response.ok(), invalidExtraCompose.body).toBeTruthy();
      expect(invalidExtraCompose.json?.result, invalidExtraCompose.body).toBe('error');
      expect(String(invalidExtraCompose.json?.message || '')).toContain('must not contain ".."');

      const applySettings = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        iconUrl: validIcon,
        webuiUrl: validWebui,
        envPath: '/mnt/user/appdata/compose.manager/test.env',
        defaultProfile: 'default',
        useDefaultComposeFiles: 'true',
        extraComposeFiles: '',
      });
      expect(applySettings.response.ok(), applySettings.body).toBeTruthy();
      expect(applySettings.json?.result, applySettings.body).toBe('success');

      const afterApply = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });
      expect(afterApply.json?.iconUrl, afterApply.body).toBe(validIcon);
      expect(afterApply.json?.webuiUrl, afterApply.body).toBe(validWebui);
      expect(afterApply.json?.envPath, afterApply.body).toBe('/mnt/user/appdata/compose.manager/test.env');
      expect(afterApply.json?.defaultProfile, afterApply.body).toBe('default');
      expect(typeof afterApply.json?.useDefaultComposeFiles, afterApply.body).toBe('boolean');
      expect(String(afterApply.json?.externalComposePath || ''), afterApply.body).toBe('');

      const bothExternalModes = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        externalComposePath: validExternalPath,
        externalComposeFilePath: '/boot/config/does-not-matter.yml',
      });
      expect(bothExternalModes.response.ok(), bothExternalModes.body).toBeTruthy();
      expect(bothExternalModes.json?.result, bothExternalModes.body).toBe('error');
      expect(String(bothExternalModes.json?.message || '')).toContain('Set either External Compose Path or External Compose File');

      const disallowedExternalPath = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        externalComposePath: '/tmp',
      });
      expect(disallowedExternalPath.response.ok(), disallowedExternalPath.body).toBeTruthy();
      expect(disallowedExternalPath.json?.result, disallowedExternalPath.body).toBe('error');
      expect(String(disallowedExternalPath.json?.message || '')).toContain('must be under /mnt/ or /boot/config/');

      const sameAsProjectPath = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        externalComposePath: projectPath,
      });
      expect(sameAsProjectPath.response.ok(), sameAsProjectPath.body).toBeTruthy();
      expect(sameAsProjectPath.json?.result, sameAsProjectPath.body).toBe('error');
      expect(String(sameAsProjectPath.json?.message || '')).toContain('cannot be the stack project folder');

      const missingExternalFile = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        externalComposeFilePath: '/boot/config/this-file-should-not-exist-1234567.yml',
      });
      expect(missingExternalFile.response.ok(), missingExternalFile.body).toBeTruthy();
      expect(missingExternalFile.json?.result, missingExternalFile.body).toBe('error');
      expect(String(missingExternalFile.json?.message || '')).toContain('External compose file does not exist');

      const restoreSettings = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setStackSettings',
        script: createdProject,
        iconUrl: baseline.iconUrl,
        webuiUrl: baseline.webuiUrl,
        envPath: baseline.envPath,
        defaultProfile: baseline.defaultProfile,
        useDefaultComposeFiles: baseline.useDefaultComposeFiles ? 'true' : 'false',
        externalComposePath: '',
        externalComposeFilePath: '',
        extraComposeFiles: baseline.extraComposeFiles,
      });
      expect(restoreSettings.response.ok(), restoreSettings.body).toBeTruthy();
      expect(restoreSettings.json?.result, restoreSettings.body).toBe('success');

      const restoreLabels = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'setLabelsViewMode',
        script: createdProject,
        labelsViewMode: baseline.labelsViewMode === 'advanced' ? 'advanced' : 'basic',
      });
      expect(restoreLabels.response.ok(), restoreLabels.body).toBeTruthy();
      expect(restoreLabels.json?.result, restoreLabels.body).toBe('success');

      const afterRestore = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
        action: 'getStackSettings',
        script: createdProject,
      });
      expect(afterRestore.json?.iconUrl, afterRestore.body).toBe(baseline.iconUrl);
      expect(afterRestore.json?.webuiUrl, afterRestore.body).toBe(baseline.webuiUrl);
      expect(afterRestore.json?.envPath, afterRestore.body).toBe(baseline.envPath);
      expect(afterRestore.json?.defaultProfile, afterRestore.body).toBe(baseline.defaultProfile);
      expect(Boolean(afterRestore.json?.useDefaultComposeFiles), afterRestore.body).toBe(baseline.useDefaultComposeFiles);
      expect(afterRestore.json?.labelsViewMode, afterRestore.body).toBe(
        baseline.labelsViewMode === 'advanced' ? 'advanced' : 'basic'
      );
      expect(String(afterRestore.json?.externalComposePath || ''), afterRestore.body).toBe('');
      expect(String(afterRestore.json?.externalComposeFilePath || ''), afterRestore.body).toBe('');

      // External mode checks are last: switching to indirect mode can make
      // getStackSettings unavailable if target folders have no compose file.
    } finally {
      if (createdProject) {
        const downError = await composeDownAndWait(page, createdProject, projectPath);
        if (downError) {
          test.info().attach('roundtrip-cleanup-composeDown', {
            body: downError,
            contentType: 'text/plain',
          });
        } else {
          const deleteError = await deleteStackWithRetries(page, createdProject, 6);
          if (!deleteError) {
            createdProject = '';
          } else {
            test.info().attach('roundtrip-cleanup-deleteStack', {
              body: deleteError,
              contentType: 'text/plain',
            });
          }
        }
      }
    }

    expect(createdProject, 'Stack cleanup failed after external settings round-trip test.').toBe('');
  });
});