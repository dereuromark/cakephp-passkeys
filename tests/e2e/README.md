# Playwright E2E harness

Integration smoke for the cakephp-passkeys plugin against a real browser.

## What it proves

- The plugin boots cleanly from a fresh, single-file CakePHP app (`harness/public/index.php`).
- The PasskeysHelper emits a valid `<script>` + endpoints `<meta>` pair.
- The ManagerCell renders its empty-state with the data-attribute hooks the browser bundle binds against.
- The LoginButton cell renders, and the auto-init binder in the IIFE bundle wires up + unhides it on DOMContentLoaded.
- The bundle in `webroot/js` (`/cake_passkeys/js/passkeys.min.js`) is reachable as a static asset.
- Chrome DevTools Protocol's virtual authenticator can be attached against the harness (sanity check for a future full-ceremony spec).

## What it does NOT prove

Full register/login ceremony round-trip. The harness intentionally does NOT wire CSRF, sessions, or the host's auth pipeline, so the JSON `/passkeys/*/start|finish` endpoints are out of scope here — they have unit coverage in `tests/TestCase/Controller/`. The ceremonies themselves are covered by `tests/TestCase/Service/WebAuthnServiceTest.php` with a software authenticator.

## Running

```bash
cd tests/e2e
npm install
npx playwright install chromium
npx playwright test
```

The Playwright config boots `php -S localhost:8765 -t harness/public/` on demand and reuses it across specs locally.
