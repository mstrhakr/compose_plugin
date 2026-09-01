#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const baseURL = process.env.E2E_BASE_URL || '';
const storageStatePath = process.env.E2E_STORAGE_STATE || '.auth/storage-state.json';
const username = process.env.AUTH_USERNAME || '';
const password = process.env.AUTH_PASSWORD || '';
const ignoreHTTPSErrors = ['1', 'true', 'yes'].includes(
  (process.env.E2E_IGNORE_HTTPS_ERRORS || '').toLowerCase()
);

function fail(message) {
  console.error(message);
  process.exit(1);
}

if (!baseURL) {
  fail('E2E_BASE_URL is required.');
}

if (!username || !password) {
  fail('AUTH_USERNAME and AUTH_PASSWORD are required.');
}

const loginURL = new URL('/login', baseURL).toString();

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ ignoreHTTPSErrors });
const page = await context.newPage();

try {
  await page.goto(loginURL, { waitUntil: 'domcontentloaded' });

  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);

  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.locator('button[type="submit"]').click(),
  ]);

  const onLoginPage = page.url().toLowerCase().includes('/login');
  if (onLoginPage) {
    const loginError = (await page.locator('#error').first().textContent())?.trim() || 'Login failed.';
    throw new Error(loginError);
  }

  const outputDir = path.dirname(storageStatePath);
  fs.mkdirSync(outputDir, { recursive: true });

  await context.storageState({ path: storageStatePath });
  console.log(`Storage state saved: ${storageStatePath}`);
} finally {
  await browser.close();
}