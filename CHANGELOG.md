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
- `users.userHandle` option, so an app that stored passkeys before using the plugin can keep the user handle those passkeys were registered with.
- An `afterLogin` listener can add keys to the `login/finish` JSON answer (for example an API token).

### Changed

- Events now carry the plugin's `PasskeysController` as subject instead of `null`, so listeners can reach the request and session.

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
