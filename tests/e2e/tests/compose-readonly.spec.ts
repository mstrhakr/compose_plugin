import { expect, test, type Page } from '@playwright/test';

const composePath = process.env.E2E_COMPOSE_PATH || '/Docker/Compose';
const targetStack = (process.env.E2E_TEST_STACK || '').trim();

function parsePostAction(postData: string | null): string {
  if (!postData) {
    return '';
  }

  const params = new URLSearchParams(postData);
  return (params.get('action') || '').trim();
}

function stackRows(page: Page) {
  return page.locator('#compose_list tr.compose-sortable');
}

function targetStackRows(page: Page) {
  const rows = stackRows(page);
  if (!targetStack) {
    return rows;
  }
  return rows.filter({ hasText: targetStack });
}

async function waitForStackListReady(page: Page): Promise<void> {
  const loadingRow = page.locator('#compose_list').getByText('Loading stacks...', { exact: false });
  if (await loadingRow.count()) {
    await expect(loadingRow).toBeHidden({ timeout: 30_000 });
  }

  // Wait until either rows are rendered or the explicit empty-state message appears.
  await expect
    .poll(
      async () => {
        const rowCount = await stackRows(page).count();
        if (rowCount > 0) {
          return true;
        }

        const noStacks = await page
          .locator('#compose_list td')
          .filter({ hasText: 'No Docker Compose stacks found.' })
          .count();
        return noStacks > 0;
      },
      { timeout: 30_000 }
    )
    .toBe(true);
}

test.describe('Compose Manager read-only smoke checks', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    if (!process.env.E2E_BASE_URL) {
      test.skip(true, 'Set E2E_BASE_URL to run live-server E2E tests.');
    }

    const blocked: string[] = [];
    testInfo.annotations.push({ type: 'blocked-actions', description: JSON.stringify(blocked) });

    const installGuard = async (url: string) => {
      await page.route(url, async (route) => {
        const request = route.request();
        if (request.method().toUpperCase() !== 'POST') {
          await route.continue();
          return;
        }

        const action = parsePostAction(request.postData());
        blocked.push(`${request.url()}::${action || 'POST'}`);
        await route.abort('blockedbyclient');
      });
    };

    await installGuard('**/plugins/compose.manager/include/Exec.php**');
    await installGuard('**/plugins/compose.manager/include/ComposeUtil.php**');

    page.on('response', async (response) => {
      if (!response.url().includes('/plugins/compose.manager/include/')) {
        return;
      }
      if (response.status() >= 500) {
        testInfo.attach('server-error', {
          body: `${response.status()} ${response.url()}`,
          contentType: 'text/plain',
        });
      }
    });
  });

  test.afterEach(async ({}, testInfo) => {
    const annotation = testInfo.annotations.find((entry) => entry.type === 'blocked-actions');
    const blocked = annotation ? JSON.parse(annotation.description || '[]') : [];
    expect(blocked, `Blocked write actions were attempted: ${blocked.join(', ')}`).toEqual([]);
  });

  test('loads Compose page and key read-only UI elements', async ({ page }) => {
    await page.goto(composePath, { waitUntil: 'domcontentloaded' });

    if (page.url().toLowerCase().includes('login')) {
      throw new Error(
        'Not authenticated. Create storage state in tests/e2e/.auth/storage-state.json and retry.'
      );
    }

    await expect(page.locator('#compose_stacks')).toBeVisible();
    await expect(page.locator('#compose_list')).toBeVisible();
  });

  test('renders stack table headers for read-only inspection', async ({ page }) => {
    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    await waitForStackListReady(page);

    await expect(page.locator('#compose_stacks th').first()).toBeVisible();
    const headerCount = await page.locator('#compose_stacks th').count();
    expect(headerCount).toBeGreaterThan(0);
  });

  test('target stack is visible when configured', async ({ page }) => {
    test.skip(!targetStack, 'Set E2E_TEST_STACK to enforce single test-stack targeting.');

    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    await waitForStackListReady(page);

    const rows = targetStackRows(page);
    await expect(rows.first(), `Target stack not found: ${targetStack}`).toBeVisible();
    await expect(rows).toHaveCount(1);
  });

  test('expand/collapse interaction stays read-only', async ({ page }) => {
    await page.goto(composePath, { waitUntil: 'domcontentloaded' });
    await waitForStackListReady(page);

    const rows = targetStack ? targetStackRows(page) : stackRows(page);
    const rowCount = await rows.count();
    if (rowCount === 0) {
      const reason = targetStack
        ? `Target stack not found for interaction smoke test: ${targetStack}`
        : 'No stacks available for interaction smoke test.';
      test.skip(true, reason);
    }

    const firstRow = rows.first();
    await firstRow.click();

    await expect(firstRow).toBeVisible();
    await expect
      .poll(async () => page.locator('.stack-details-row:visible').count(), { timeout: 10_000 })
      .toBeGreaterThan(0);
  });
});
