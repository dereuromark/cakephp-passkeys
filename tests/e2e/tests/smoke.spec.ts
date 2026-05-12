import { test, expect } from '@playwright/test';

/**
 * Smoke verification that the plugin loads cleanly into a fresh CakePHP
 * application and that the helper + cell render the DOM hooks the browser
 * bundle binds against.
 *
 * Why a smoke spec rather than a full register/login round-trip: the harness
 * is intentionally tiny — it does not wire CSRF, sessions, or the host's
 * auth pipeline, so the JSON ceremony endpoints (which are unit-tested
 * already) are out of scope here. The full round-trip with a virtual
 * authenticator lands once the plugin is consumed by RentCraft (Task 26).
 */

test('plugin boots and renders the manager cell empty state', async ({ page }) => {
    await page.goto('/');

    // The page renders without server-side fatals.
    await expect(page).toHaveTitle('Settings');

    // Helper output: bundle script + endpoints meta are in <head>.
    await expect(page.locator('script[src*="/passkeys/dist/passkeys.min.js"]')).toBeAttached();
    await expect(page.locator('meta[name="passkeys-endpoints"]')).toBeAttached();

    // Manager cell renders the empty-state explainer + the "Add a passkey"
    // button, with the data-attribute hooks the bundle's binder attaches to.
    await expect(page.locator('.passkeys-empty')).toBeVisible();
    const addBtn = page.locator('[data-passkey-register]');
    await expect(addBtn).toBeVisible();
    await expect(addBtn).toHaveAttribute('data-passkey-name-prompt', '');
});

test('login page renders the passkey button and the bundle binds + unhides it', async ({ page }) => {
    await page.goto('/login');

    await expect(page).toHaveTitle('Login');

    // The cell template ships the button with `hidden` set; the bundle's
    // auto-init binder unhides it on DOMContentLoaded when WebAuthn is
    // available in the browser (it is, under Chromium). We verify both:
    //   - the button is in the DOM with the expected hooks
    //   - the binder ran (data-passkey-bound="1") and unhid it
    const btn = page.locator('[data-passkey-authenticate]');
    await expect(btn).toBeAttached();
    await expect(btn).toBeVisible();
    await expect(btn).toHaveAttribute('data-passkey-bound', '1');

    // Its inline endpoints payload carries the start/finish URLs.
    const endpoints = await btn.getAttribute('data-passkey-endpoints');
    expect(endpoints).toBeTruthy();
    const parsed = JSON.parse(endpoints as string);
    expect(parsed.start).toBe('/passkeys/login/start');
    expect(parsed.finish).toBe('/passkeys/login/finish');
});

test('bundle is reachable as a real asset and exposes the IIFE namespace', async ({ page }) => {
    // Direct fetch — proves the route-script fallback in index.php correctly
    // streams the plugin's webroot/dist bundle.
    const resp = await page.request.get('/passkeys/dist/passkeys.min.js');
    expect(resp.status()).toBe(200);
    const body = await resp.text();
    expect(body).toContain('Passkeys'); // IIFE global

    // Loading the page should expose the global to the runtime.
    await page.goto('/');
    const hasGlobal = await page.evaluate(() => typeof (globalThis as any).Passkeys !== 'undefined');
    expect(hasGlobal).toBe(true);
});

test('virtual authenticator can be attached via CDP (best-effort)', async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium', 'Virtual authenticator requires Chromium CDP');

    await page.goto('/');
    const client = await page.context().newCDPSession(page);
    await client.send('WebAuthn.enable');
    const { authenticatorId } = await client.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
        },
    });
    expect(authenticatorId).toBeTruthy();

    // Tear down — the harness does not persist anything cross-test, but a
    // leftover authenticator on the session is sticky and would leak.
    await client.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
});
