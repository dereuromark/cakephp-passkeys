# Contributing

Thanks for considering a contribution. This plugin ships a PHP package
and an npm package together — most changes touch both sides.

## Dev setup

```bash
composer install
npm install
```

## Test commands

```bash
composer test          # PHPUnit
composer stan          # PHPStan (level 8)
composer cs-check      # PHPCS (autofix: composer cs-fix)
npm test               # Vitest
npm run typecheck      # tsc --noEmit
npm run build          # Rollup: ESM + IIFE bundles
```

End-to-end smoke (Playwright with a virtual authenticator):

```bash
cd tests/e2e && npx playwright test
```

## Library version pin policy

`web-auth/webauthn-lib` is hard-pinned to `^5.0`. Major version bumps
are deliberate, security-reviewed releases of this plugin — any move to
`web-auth/webauthn-lib ^6.0` lands as a new plugin minor or major,
never as a transitive upgrade.

## Pull-request checklist

Before opening a PR, confirm all of these pass locally:

- [ ] `composer test` green
- [ ] `composer stan` clean (level 8)
- [ ] `composer cs-check` clean
- [ ] `npm test` green
- [ ] `npm run typecheck` clean
- [ ] `npm run build` succeeds for both ESM and IIFE outputs

## AAGUID map refresh

The plugin bundles a snapshot of authenticator AAGUID → device name
mappings at [`resources/aaguid-map.json`](resources/aaguid-map.json).
Refresh source:
[`passkeydeveloper/passkey-authenticator-aaguids`](https://github.com/passkeydeveloper/passkey-authenticator-aaguids).

For plugin releases, open a PR with the regenerated JSON so device
labels stay current.

## The committed bundle

`webroot/js/passkeys.min.js` is committed, so a composer install has it.
After a change under `js/src/`:

```bash
npm run build && npm run vendor:webroot
git add webroot/js
```

CI fails when the committed bundle does not match the sources.

## Release checklist

1. Move the `Unreleased` section of CHANGELOG.md to `[X.Y.Z] - YYYY-MM-DD`.
2. Tag `X.Y.Z` (no `v` prefix) and publish the GitHub release.
3. The release workflow publishes the npm package. Packagist picks up the tag.
