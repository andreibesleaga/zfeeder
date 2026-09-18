// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * The suite runs against a server that is already listening on ZF_BASE_URL.
 * tools/run-e2e.sh starts either the built container or `php -S` and exports it,
 * so the same tests cover local development, CI and a deployed instance.
 */
const baseURL = process.env.ZF_BASE_URL || 'http://127.0.0.1:8181';

module.exports = defineConfig({
  testDir: './specs',
  outputDir: '../../test-results',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI
    ? [['list'], ['html', { outputFolder: '../../playwright-report', open: 'never' }]]
    : [['list']],
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 10_000,
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
    {
      name: 'dark',
      use: { ...devices['Desktop Chrome'], colorScheme: 'dark', viewport: { width: 1280, height: 900 } },
    },
  ],
});
