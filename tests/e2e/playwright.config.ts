import { defineConfig } from '@playwright/test';

/**
 * Playwright E2E for the cakephp-passkeys plugin.
 *
 * Boots a tiny standalone Cake harness (`harness/public/index.php`) under
 * php-S on port 8765, then runs the specs in `tests/` against it.
 *
 * Port 8765 (not the more obvious 8000) so it doesn't collide with whatever
 * else the developer happens to have running on port 8000 (most projects'
 * default), and stays close to but disjoint from DDEV's 8080/8443.
 */
export default defineConfig({
    testDir: './tests',
    timeout: 30_000,
    fullyParallel: false,
    workers: 1,
    reporter: process.env.CI ? 'github' : 'list',
    webServer: {
        command: 'php -S localhost:8765 -t harness/public/',
        url: 'http://localhost:8765/passkeys/dist/passkeys.min.js',
        timeout: 30_000,
        reuseExistingServer: !process.env.CI,
        stdout: 'pipe',
        stderr: 'pipe',
    },
    use: {
        baseURL: 'http://localhost:8765/',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
