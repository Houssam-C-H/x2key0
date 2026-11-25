import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests (tests/e2e) against a running X2KEY (Sail/Docker by default).
 *   npm run e2e                     # BASE_URL=http://localhost, fixtures via `docker compose exec`
 *   E2E_ARTISAN="php artisan" BASE_URL=http://127.0.0.1:8000 npm run e2e
 * Uses the installed Microsoft Edge (no browser download). Fixtures: `php artisan e2e:prepare` (never production).
 */
export default defineConfig({
    testDir: 'tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    timeout: 60_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'storage/e2e-report' }]],
    outputDir: 'storage/e2e-results',
    use: {
        baseURL: process.env.BASE_URL ?? 'http://localhost',
        channel: 'msedge',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        locale: 'en-US',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Edge'], channel: 'msedge', viewport: { width: 1366, height: 900 } } },
        { name: 'phone', use: { ...devices['Pixel 7'], channel: 'msedge' } },
    ],
});
