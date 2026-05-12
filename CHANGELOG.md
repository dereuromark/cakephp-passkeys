# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Security: session ID is now renewed on passkey login** before any
  identity-bearing data is written to the session. Closes the
  session-fixation hole where a pre-seeded cookie could be promoted to
  an authenticated session.
- **Security: `Passkeys.challengeTtl` is now actually honored.** The
  plugin registers a dedicated `passkeys_challenges` cache engine on
  bootstrap with `duration` pinned to the configured TTL; previously
  Cake's default cache duration applied and challenges could outlive
  their intended window.
- **API: `loginStart` now accepts `emailHint` from the JSON body** (the
  shape the shipped JS client sends), in addition to the legacy
  `?email=` query string. Body wins when both are present.
- **Master switch: `Passkeys.enabled = false` now 404s every controller
  endpoint.** Previously the flag only hid UI cells while the JSON API
  stayed live and probable.
- **JS: `PasskeysHelper::reauthGuard()` forms now actually trigger the
  reauth ceremony on submit.** The binder previously rendered the
  attribute but no client wired it up.

### Changed

- `PasskeyUserInterface::getUserId()` narrowed from `int|string` to
  `int`. Several controller call sites already cast to `int`, and the
  migration stores `user_id` as INTEGER — the previous contract lied.
  UUID / string IDs are not supported in v0.1; see `README.md` under
  *Limitations*.

### Added

- Initial public release.
- PHP plugin with `WebAuthnService`, `PasskeysController` (8 JSON
  actions), `Manager` / `LoginButton` / `RegisterNudge` cells,
  `PasskeysHelper`, `NudgePolicy`, AAGUID label resolver with bundled
  snapshot, `UserResolver` (convention) plus `PasskeyUserInterface`
  (override).
- npm package `dereuromark/cakephp-passkeys` with vanilla core plus
  React, Vue, Alpine, and Stimulus adapters and a zero-build IIFE
  bundle (6 kB / 2.18 kB gzipped).
- 4 events: `afterRegister`, `afterLogin`, `afterRename`, `afterDelete`.
- Conditional UI helper for the autofill-chip path.
- Emoji column and AAGUID device-type labels in the manager cell.
- de_DE and en_US translations for the `passkeys` domain.
- Playwright smoke E2E (manager cell renders, login binder unhides the
  button, bundle URL resolves, virtual authenticator attaches).
- Tested against PHP 8.2 / 8.3 / 8.4 with CakePHP 5.x (PHPUnit 11).
  Browser support: Chrome/Edge 109+, Firefox 122+, Safari 16+.

### Known limitations

- 3 unit tests are marked `markTestIncomplete` pending captured
  authenticator-response fixtures for end-to-end ceremony validation.
  Coverage will land in a follow-up PR; current unit tests already
  exercise challenge replay rejection, RP-ID mismatch, and counter
  rollback via direct method calls.
- iOS 16 may reject WebAuthn calls after an intermediate `fetch()` (no
  user-activation propagation). iOS 17+ confirmed working.
