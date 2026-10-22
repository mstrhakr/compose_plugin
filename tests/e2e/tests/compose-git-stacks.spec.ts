import { expect, test } from '@playwright/test';
import {
  buildStackName,
  composeDownAndWait,
  postForm,
  readCsrfToken,
} from './helpers/composeE2eHelpers';

// A git stack made in the Add Stack dialog, deployed, and deleted again.
// Needs a repository the server can clone, with a compose file that starts on
// its own (no .env needed) and uses no port another stack holds:
//   E2E_GIT_TEST_REPO          the repository address, e.g. https://github.com/me/stacks.git
//   E2E_GIT_TEST_COMPOSE_PATH  the compose file's path in it, e.g. whoami/compose.yaml
//   E2E_GIT_TEST_BRANCH        optional, default main
// Deleting the stack leaves its clone under the git clones folder, as Delete
// always does for a git stack; remove those by hand.

const composePath = process.env.E2E_COMPOSE_PATH || '/Docker/Compose';
const mutationEnabled = ['1', 'true', 'yes'].includes(
  (process.env.E2E_ENABLE_MUTATION_TESTS || '').toLowerCase()
);
const stackPrefix = (process.env.E2E_TEST_STACK_PREFIX || 'pw-e2e').trim() || 'pw-e2e';
const gitRepo = (process.env.E2E_GIT_TEST_REPO || '').trim();
const gitComposePath = (process.env.E2E_GIT_TEST_COMPOSE_PATH || '').trim();
const gitBranch = (process.env.E2E_GIT_TEST_BRANCH || 'main').trim();
const execUrl = '/plugins/compose.manager/include/Exec.php';

test.describe('Compose Manager git stacks (GUID stack)', () => {
  test('add from the dialog, deploy from the stack menu, then delete', async ({ page }) => {
    test.setTimeout(240_000);
    test.skip(!process.env.E2E_BASE_URL, 'Set E2E_BASE_URL for live-server E2E tests.');
    test.skip(!mutationEnabled, 'Set E2E_ENABLE_MUTATION_TESTS=1 to allow mutation lifecycle tests.');
    test.skip(!gitRepo || !gitComposePath, 'Set E2E_GIT_TEST_REPO and E2E_GIT_TEST_COMPOSE_PATH for git stack tests.');

    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    if (page.url().toLowerCase().includes('/login')) {
      throw new Error('Not authenticated. Refresh storage state and retry.');
    }
    expect(await readCsrfToken(page), 'Missing csrf_token in page context.').not.toBe('');

    const stackName = buildStackName(stackPrefix);
    // The folder the server will make, as the Add Stack dialog's preview works it out, so the
    // stack can be cleaned up even if the "was added" dialog never shows.
    const expectedProject = await page.evaluate(
      (name) => (window as unknown as { composeSanitizeProjectSlug: (raw: string) => string }).composeSanitizeProjectSlug(name),
      stackName
    );
    let createdProject = '';
    let projectPath = '';

    try {
      // 1. Add Stack, compose source "Git repository".
      await page.getByRole('button', { name: 'Add New Stack' }).or(page.locator('input[value="Add New Stack"]')).first().click();
      await page.fill('#compose-stack-name', stackName);
      await page.check('input[name="compose-stack-compose-source"][value="git"]');
      await expect(page.locator('#compose-stack-env-path')).toBeHidden();
      await page.fill('#compose-stack-git-url', gitRepo);
      await page.fill('#compose-stack-git-branch', gitBranch);
      await page.fill('#compose-stack-git-compose-path', gitComposePath);
      await page.click('#compose-stack-create-btn');

      // Creating goes back to the stack list and says the stack was added.
      await expect(page.locator('.sweet-alert:visible h2')).toHaveText(`${stackName} was added`, { timeout: 60_000 });
      createdProject = expectedProject;
      await page.waitForTimeout(800); // SweetAlert drops a click made during its fade-in.
      await page.locator('.sweet-alert:visible button.confirm').click();

      // The editor shows the git banner over the compose file.
      await page.evaluate(
        ([project, name]) => (window as unknown as { openEditorModalByProject: (p: string, n: string) => void }).openEditorModalByProject(project, name),
        [createdProject, stackName]
      );
      await expect(page.locator('#editor-compose-git-banner')).toBeVisible({ timeout: 30_000 });

      // The Sources tab shows the repository in place of the Compose Source choice.
      await page.click('#editor-tab-sources');
      await expect(page.locator('#settings-git-url')).toHaveText(gitRepo);
      await expect(page.locator('#settings-git-deployed')).toHaveText('Not deployed yet');
      await expect(page.locator('#settings-compose-source-field')).toBeHidden();

      const settings = await postForm(page, execUrl, { action: 'getStackSettings', script: createdProject });
      expect(settings.json?.isGitStack, settings.body).toBe(true);
      projectPath = String(settings.json?.projectPath || '').trim();
      expect(projectPath).not.toBe('');

      // 2. The stack menu has the git items.
      await page.goto(composePath, { waitUntil: 'domcontentloaded' });
      const row = page.locator(`tr.compose-sortable[data-project="${createdProject}"]`);
      await expect(row).toHaveAttribute('data-gitstack', '1', { timeout: 30_000 });
      await expect(row.locator('.compose-git-line')).toContainText(`${gitBranch} @ not deployed`);
      await row.locator('[id^="stack-"][data-stackid]').first().click();
      const menu = page.locator('.dropdown-context:visible');
      await expect(menu.getByText('Check for Changes')).toBeVisible();
      await expect(menu.getByText('Pull and Redeploy')).toBeVisible();
      await expect(menu.getByText('Deploy Commit...')).toBeVisible();
      await page.keyboard.press('Escape');

      // 3. Deploy in the background and wait for it. The menu opens a terminal window
      //    instead, which a test cannot read; the background deploy runs the same command.
      const deploy = await postForm(page, '/plugins/compose.manager/include/ComposeUtil.php', {
        action: 'composeGitDeploy',
        path: projectPath,
        background: '1',
      });
      expect(deploy.json?.background, deploy.body).toBe(true);

      await expect
        .poll(
          async () => {
            const status = await postForm(page, execUrl, { action: 'getGitStackStatus', script: createdProject });
            const git = (status.json?.git || {}) as { deployedCommit?: string | null; failedCommit?: string | null };
            return git.failedCommit ? 'failed' : git.deployedCommit ? 'deployed' : 'waiting';
          },
          { timeout: 180_000, intervals: [2_000] }
        )
        .toBe('deployed');

      const check = await postForm(page, execUrl, { action: 'checkGitStack', script: createdProject });
      expect((check.json?.check as { upToDate?: boolean } | undefined)?.upToDate, check.body).toBe(true);
    } finally {
      if (!createdProject) {
        // The "was added" dialog never showed (a slow clone, say), but the server may still have made the stack.
        await page.goto(composePath, { waitUntil: 'domcontentloaded' }).catch(() => undefined);
        const leftover = page.locator(`tr.compose-sortable[data-project="${expectedProject}"]`);
        await leftover.first().waitFor({ timeout: 30_000 }).catch(() => undefined);
        if ((await leftover.count().catch(() => 0)) > 0) {
          createdProject = expectedProject;
        }
      }
      if (createdProject) {
        const downError = await composeDownAndWait(page, createdProject, projectPath);
        if (downError) {
          test.info().attach('cleanup-composeDown', { body: downError, contentType: 'text/plain' });
        }
        // Deleting a git stack answers "warning": its clone stays on disk, and is named.
        const remove = await postForm(page, execUrl, { action: 'deleteStack', stackName: createdProject });
        if (remove.json?.result === 'warning' || remove.json?.result === 'success') {
          test.info().attach('clone-left-behind', { body: String(remove.json?.message || ''), contentType: 'text/plain' });
          createdProject = '';
        } else {
          test.info().attach('cleanup-deleteStack', { body: remove.body, contentType: 'text/plain' });
        }
      }
    }

    expect(createdProject, 'Stack cleanup failed; check attachments for deleteStack response.').toBe('');
  });
});
