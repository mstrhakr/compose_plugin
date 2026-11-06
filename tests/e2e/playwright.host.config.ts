import { defineConfig } from '@playwright/test';
import config from './playwright.config';

const baseURL = process.env.E2E_BASE_URL;
if (!baseURL || !['1', 'true', 'yes'].includes((process.env.E2E_ENABLE_MUTATION_TESTS || '').toLowerCase())) {
  throw new Error('Host validation requires E2E_BASE_URL and explicit E2E_ENABLE_MUTATION_TESTS=1.');
}
if (process.env.E2E_HOST_TEST_ACK !== new URL(baseURL).origin) {
  throw new Error('Set E2E_HOST_TEST_ACK to the exact server origin to acknowledge isolated host mutations.');
}

export default defineConfig(config, {
  testMatch: '**/compose-host-stabilization.spec.ts',
  workers: 1,
  fullyParallel: false,
  retries: 0,
  repeatEach: 1,
  forbidOnly: true,
});
