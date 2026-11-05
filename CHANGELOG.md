# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- A passkey sign-in in an app running `cakephp/authentication` now persists the full user row as the identity through the authentication service. Before, only the user id was written to `session.userIdKey`; under the Session authenticator's key, that became an identity without the user's other fields. The id is still written to `session.userIdKey` unless the persisted identity sits under the same root key. New option `session.persistIdentity` (default `true`) turns this off.
- The plugin's own translations (German ships with it) now load in host apps. The `passkeys` text domain did not map to the `CakePasskeys` plugin, so every string fell back to the app's `default` domain. Clear the `_cake_translations_` cache after upgrading.
- With `cakephp/authorization`, every plugin action failed the `requireAuthorizationCheck` guard. The plugin now marks them as checked.
- A leftover `session.userIdKey` no longer counts as signed in when `cakephp/authentication` is loaded, so a logged-out browser cannot manage passkeys through it.
- A sign-in without user verification removes an `mfa_satisfied` flag left by an earlier one.

### Added

- `CakePasskeys.beforeRegister` event: stop it to refuse a passkey registration (403), for example when the sign-in is not recent.
- `CakePasskeys.beforeDelete` event, the same for deleting a passkey.
- `users.userHandle` option, so an app that stored passkeys before using the plugin can keep the user handle those passkeys were registered with.
- An `afterLogin` listener can add keys to the `login/finish` JSON answer (for example an API token).
- Sign-in accepts EdDSA passkeys (with the sodium extension), so passkeys an app registered before using the plugin keep working. New passkeys are still offered ES256 and RS256.

### Changed

- Events now carry the plugin's `PasskeysController` as subject instead of `null`, so listeners can reach the request and session.
- Requires `cakephp/cakephp` `^5.3` (was `^5.2`; `cakephp/migrations` 5 already required 5.3, so 5.2 never installed) and `web-auth/webauthn-lib` `^5.3.5` (was `^5.0`; earlier releases carry security advisories, one about `allowed_origins`).
- Rate limiting is on by default (`CakePasskeys\Service\CacheRateLimiter`, counted in a copy of the `cache` engine that lives an hour; refuses when the count cannot be stored); `rateLimiter => false` turns it off. A full bucket now answers 429 instead of 400, and `login/finish` and `register/finish` are throttled too.

### Removed

- Binding the rate limiter through the DI container. It never took effect, because the controller looked for a `container` request attribute that CakePHP does not set; use the `rateLimiter` key.

## [0.1.0] - 2026-10-07

Initial release.

### Added

- Passkey registration, sign-in and re-confirmation for CakePHP 5, as eight
  JSON endpoints on top of `web-auth/webauthn-lib`.
- `Manager`, `LoginButton` and `RegisterNudge` cells and the `Passkeys` helper.
- JavaScript bundle shipped as a plugin asset, binding `data-passkey-*`
  attributes. The same code is on npm with React, Vue, Alpine and Stimulus
  adapters.
- Events `CakePasskeys.afterRegister`, `afterLogin`, `afterRename` and
  `afterDelete`.
- `Reauth::isFresh()` to check a recent passkey confirmation on the server.
- Works with integer, big integer, UUID and string user ids
  (`CakePasskeys.users.idType`).
- The current user comes from `CakePasskeys.identityResolver`, the `identity`
  request attribute or a session key.
- Tested on SQLite, MySQL and PostgreSQL.
- de_DE and en_US translations.
