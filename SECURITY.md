# Security Policy

`dereuromark/cakephp-passkeys` implements WebAuthn / passkey authentication
for CakePHP 5 apps. Security-impacting issues are taken seriously.

## Reporting a vulnerability

**Do not** open a public GitHub issue, pull request, or discussion thread
for security findings. Public disclosure before a fix exists puts every
host app at risk.

Instead, use one of the private channels:

- **GitHub Private Vulnerability Reporting:** open a report at
  <https://github.com/dereuromark/cakephp-passkeys/security/advisories/new>.
  This is the preferred path — it lets us coordinate the fix, CVE, and
  disclosure timeline in one place.
- **Email:** `dereuromark@gmail.com` with the subject prefix
  `[cakephp-passkeys SECURITY]`.

Please include enough detail to reproduce:

- Affected version(s) of the plugin and the host CakePHP version.
- WebAuthn library version (`composer info web-auth/webauthn-lib`).
- Browser / authenticator combination if browser-side.
- Proof-of-concept request, payload, or repro steps.
- Your expected disclosure timeline if any.

## What is in scope

- The PHP plugin's controller, service layer, and configuration surface:
  origin / RP-ID / signature / counter-rollback handling, challenge nonce
  store, session hand-off, rate-limiter contract, ACL boundaries.
- The npm package: ceremony helpers, framework adapters, the IIFE
  bundle's auto-binding.
- The bundled AAGUID label snapshot (only relevant if a label collision
  enabled credential confusion — unlikely but reportable).

## What is out of scope

- Vulnerabilities in `web-auth/webauthn-lib` itself — report those at
  <https://github.com/web-auth/webauthn-framework/security>.
- Issues that require a host misconfiguration the plugin's README
  explicitly warns against (e.g. running on plain HTTP, deploying with
  `Passkeys.enabled = true` but without binding a `RateLimiterInterface`
  in production).
- Browser-vendor bugs in WebAuthn implementations themselves.

## Disclosure window

Expect an initial acknowledgement within 7 days. A fix and coordinated
disclosure typically land within 30 days of acknowledgement, faster for
high-severity findings. If a CVE is warranted we will request one via
GitHub Security Advisories.

## Supported versions

Only the current minor line receives security fixes. As of this writing
that is `0.1.x`. Older lines may be supported on case-by-case basis for
high-severity findings only.

| Version | Supported |
| --- | --- |
| 0.1.x | yes |
| < 0.1 | no |

The supported-version table will be updated on every minor release.
