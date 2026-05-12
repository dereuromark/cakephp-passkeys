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

## Release checklist

1. `npm run build && npm run vendor:webroot` — produces `webroot/dist/passkeys.min.js`.
2. `git add webroot/dist/ && git commit -m "build: vendor dist for vX.Y.Z"`.
3. Update CHANGELOG.md's `Unreleased` heading to `[X.Y.Z] - YYYY-MM-DD`.
4. `git tag vX.Y.Z && git push --tags`.
5. CI publishes to npm. Packagist webhook auto-picks up the tag.
