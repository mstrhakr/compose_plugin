import { expect, type APIResponse, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';

export type JsonValue = Record<string, unknown>;

export type PostFormResult = {
  response: APIResponse;
  body: string;
  json: JsonValue | null;
};

export function sanitizeStackToken(value: string): string {
  return value.toLowerCase().replace(/[^a-z0-9-]/g, '').slice(0, 24) || 'stack';
}

export function buildStackName(stackPrefix: string): string {
  const guid = randomUUID().replace(/-/g, '').slice(0, 12);
  return `${sanitizeStackToken(stackPrefix)}-${guid}`;
}

export async function readCsrfToken(page: Page): Promise<string> {
  const token = await page.evaluate(() => {
    const value = (window as { csrf_token?: unknown }).csrf_token;
    return typeof value === 'string' ? value.trim() : '';
  });
  return token;
}

export async function postForm(page: Page, endpoint: string, data: Record<string, string>): Promise<PostFormResult> {
  const csrfToken = await readCsrfToken(page);
  const response = await page.request.post(endpoint, {
    form: {
      ...data,
      csrf_token: csrfToken,
    },
    headers: {
      'x-requested-with': 'XMLHttpRequest',
    },
  });

  const body = await response.text();
  let json: JsonValue | null = null;
  try {
    json = JSON.parse(body) as JsonValue;
  } catch {
    json = null;
  }

  return {
    response,
    body,
    json,
  };
}

export async function waitForStackUnlock(page: Page, project: string): Promise<void> {
  await page.waitForTimeout(1_500);
  await expect
    .poll(
      async () => {
        const lock = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
          action: 'checkStackLock',
          script: project,
        });
        if (!lock.response.ok()) {
          return 'request-error';
        }
        if (!lock.json || lock.json.result !== 'success') {
          return 'json-error';
        }
        return lock.json.locked ? 'locked' : 'unlocked';
      },
      { timeout: 120_000 }
    )
    .toBe('unlocked');
}

export async function waitForStackContainersRemoved(page: Page, project: string): Promise<void> {
  await expect
    .poll(
      async () => {
        const stack = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
          action: 'getStackContainers',
          script: project,
        });
        if (!stack.response.ok() || !stack.json || stack.json.result !== 'success') {
          return -1;
        }
        const containers = Array.isArray(stack.json.containers) ? stack.json.containers : [];
        return containers.length;
      },
      { timeout: 120_000 }
    )
    .toBe(0);
}

export async function composeDownAndWait(page: Page, project: string, projectPath: string): Promise<string | null> {
  const down = await postForm(page, '/plugins/compose.manager/include/ComposeUtil.php', {
    action: 'composeDown',
    path: projectPath || `/boot/config/plugins/compose.manager/projects/${project}`,
    background: '1',
    removeOrphans: '1',
  });

  if (!down.response.ok() || !down.json || down.json.background !== true) {
    return down.body;
  }

  await waitForStackUnlock(page, project);
  await waitForStackContainersRemoved(page, project);
  return null;
}

export async function deleteStackWithRetries(page: Page, project: string, maxAttempts = 6): Promise<string | null> {
  let lastBody = '';
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const remove = await postForm(page, '/plugins/compose.manager/include/Exec.php', {
      action: 'deleteStack',
      stackName: project,
    });

    lastBody = remove.body;
    if (remove.response.ok() && remove.json && remove.json.result === 'success') {
      return null;
    }

    await page.waitForTimeout(1_500);
  }

  return lastBody || 'deleteStack failed';
}
