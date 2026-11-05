# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `GET /.well-known/passkey-endpoints` tells password managers where users add and manage passkeys. Off until `wellKnown.enroll` or `wellKnown.manage` is set.
- `bin/cake passkeys list <user>` and `bin/cake passkeys revoke <user> [<passkey>]` to show and remove a user's passkeys, for example after a lost device. Each deletion dispatches `CakePasskeys.afterDelete`.
- `allowedAaguids` option: limit new passkeys to a list of authenticator models (AAGUIDs). A policy filter on the self-reported AAGUID, not attestation-backed; existing passkeys are not affected.
- Optional security alert mail when a passkey is added to an account (`notify.onRegister`, `notify.emailProfile`, `notify.manageUrl`), sent with the new `CakePasskeys\Mailer\PasskeyMailer`.
- WebAuthn Signal API support (Chrome 132+): unknown credentials, the remaining passkeys after a delete and the current account names are signaled to the authenticator. Turn off with `CakePasskeys.signals`.
- Passkeys store whether they can sync (`backup_eligible`) and whether they are synced (`backed_up`), updated on each sign-in. The Manager cell labels them "Synced" or "This device only", the JSON adds `backupEligible` and `backedUp`, and the RegisterNudge cell suggests a second passkey when all of a user's passkeys are device-bound (`nudge.deviceBoundOnly`, default `true`). Run the new migration; apps with their own `passkeys` table add the two columns themselves.

### Changed

- `reauth/finish`, `rename` and `delete` are rate-limited too (`passkeys.reauth_finish.<user>` 20 per minute, `passkeys.manage.<user>` 30 per minute for rename and delete together).
- A full bucket throws `TooManyRequestsException` (still HTTP 429) instead of a plain `HttpException`, now that CakePHP 5.3 is the minimum.

## [0.1.1] - 2026-11-05

### Fixed

- A passkey sign-in in an app running `cakephp/authentication` now persists the full user row as the identity through the authentication service. Before, only the user id was written to `session.userIdKey`; under the Session authenticator's key, that became an identity without the user's other fields. The id is still written to `session.userIdKey` unless the persisted identity sits under the same root key. New option `session.persistIdentity` (default `true`) turns this off.
- The plugin's own translations (German ships with it) now load in host apps. The `passkeys` text domain did not map to the `CakePasskeys` plugin, so every string fell back to the app's `default` domain. Clear the `_cake_translations_` cache after upgrading.
- With `cakephp/authorization`, every plugin action failed the `requireAuthorizationCheck` guard. The plugin now marks them as checked.
- A leftover `session.userIdKey` no longer counts as signed in when `cakephp/authentication` is loaded, so a logged-out browser cannot manage passkeys through it.
- A sign-in without user verification removes an `mfa_satisfied` flag left by an earlier one.
- A `CakePasskeys.cache` that names a missing cache config now fails at bootstrap with a clear message instead of on the first request.
- An identity that `cakephp/authentication` cannot store (not `ArrayAccess`) no longer breaks the sign-in; the plugin falls back to `session.userIdKey`.

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

- `webroot/aaguid-map.json`, an unused public copy of `resources/aaguid-map.json`.
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
