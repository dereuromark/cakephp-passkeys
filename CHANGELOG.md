# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
