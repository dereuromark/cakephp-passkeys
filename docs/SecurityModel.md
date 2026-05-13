# Security model

What the plugin verifies, what it leaves to the host, what to watch
when reviewing.

## What the plugin checks

Per the WebAuthn / FIDO2 specs, delegated to
[`web-auth/webauthn-lib ^5.0`](https://github.com/web-auth/webauthn-framework):

- **Origin binding.** A passkey is bound to your origin via the
  Relying Party ID. The library rejects any ceremony where the
  authenticator-reported origin does not match the configured `rpId`.
  This is the structural defense against phishing.
- **RP-ID hash check.** Authenticator data carries the SHA-256 of the
  RP-ID; the library validates it against the configured value.
- **Signature verification.** The authenticator signs over
  `(authData || clientDataJSON-hash)`; the library validates the
  signature against the stored public key.
- **Counter rollback.** `signCount` must be strictly greater than the
  stored count when non-zero. Synced passkeys (iCloud Keychain, Google
  Password Manager) always return `signCount = 0` and are accepted
  unconditionally — by spec, those don't track a counter.
- **User verification.** Configurable via `CakePasskeys.ceremony.userVerification`
  (default `required` on login). When `required`, the library rejects
  ceremonies whose `UV` flag is unset.

## What the plugin manages itself

- **Challenge nonce store.** Each `start*` call issues a random 16-byte
  challenge, stores it in a cache engine with `CakePasskeys.challengeTtl`
  duration (default 300 s), and consumes it on the matching `finish*`
  call. One-shot semantics — replay is rejected because the second
  consume returns null.
- **User handle privacy.** The WebAuthn `user.id` field exposed to the
  authenticator is **never** the raw DB row id. It is
  `hash_hmac('sha256', $userId, Security::salt())`. Compromising a
  passkey row does not reveal the host's primary-key sequence.
- **Per-user cap.** `CakePasskeys.maxPerUser` (default 5) caps the number
  of passkeys a single user can register. Prevents the abuse case where
  an attacker who briefly compromises a session piles dozens of their
  own passkeys onto the account.
- **Session ID renewal on login.** `loginFinish()` calls
  `$session->renew()` before any identity write. Closes the
  session-fixation attack where an attacker fixes a pre-auth session
  cookie on the victim's browser.

## What the host owns

These are deliberate seams. The plugin documents them; the host wires.

- **Rate limiting.** The plugin ships a `NullRateLimiter` (no-op) by
  default. Bind your own `CakePasskeys\Contract\RateLimiterInterface`
  implementation via `CakePasskeys.rateLimiter` config. Recommended
  protection: per-IP throttle on `loginStart` (unauthenticated, open
  to enumeration); per-user-handle throttle on `registerStart`.
- **CSRF skip on the plugin's 8 endpoints.** The plugin's URLs handle
  raw JSON request/response bodies and use the WebAuthn challenge nonce
  as the anti-replay guard — same pattern as Stripe webhooks. The host
  must add a `skipCheckCallback` for `/passkeys/*` in the CSRF
  middleware setup (README ships the snippet). Forgetting this breaks
  the plugin entirely with a clear error; failure mode is loud.
- **Audit logging.** All four lifecycle events fire on the Cake event
  manager. Host subscribes and writes whatever audit shape it uses.
- **Identity hand-off after login.** The plugin writes the configured
  session key (`CakePasskeys.session.userIdKey`, default `Auth.id`) on
  successful login. Host's auth middleware reads it; the plugin does
  not call `setIdentity()` directly because every auth stack has a
  different identity object shape.
- **Recovery flow.** See [Modes — Recovery](Modes.md#recovery---host-owned).
- **`CakePasskeys.enabled = false`.** When the master switch is off, the
  plugin's `beforeFilter()` returns 404 on all 8 endpoints and the
  Cells / Helper render empty. Host should hide its own related UI too
  if any survived.

## Posture decisions worth knowing

- **No attestation pinning.** Any FIDO2-conformant authenticator is
  accepted. Enterprise environments that need to restrict to specific
  hardware can extend `WebAuthnService::buildOptions()` to enable the
  metadata service. Out of scope for v0.1.
- **Library version pin.** `web-auth/webauthn-lib: ^5.0` is hard-pinned
  in `composer.json`. Major bumps (any `^6`) require an explicit,
  security-reviewed plugin release — never a transparent dependency
  upgrade. The pin policy is documented in CONTRIBUTING.md.
- **AAGUID label snapshot.** Bundled `resources/aaguid-map.json`
  (~50 entries) is used purely for display purposes ("iCloud Keychain"
  vs "YubiKey 5"). A label mismatch is a UX issue, never a security
  bypass — the AAGUID itself is still recorded in raw form and used
  only after the ceremony validates.

## Reporting a vulnerability

Do not file a public issue. See [SECURITY.md](../SECURITY.md) for the
private-disclosure channels.
