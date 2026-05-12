# Documentation

User-facing docs for `dereuromark/cakephp-passkeys`. For installation,
configuration, and the full host-touchpoint walkthrough, see the
top-level [README.md](../README.md).

## Topics

- [Adoption](Adoption.md) — checklist for adding the plugin to an
  existing CakePHP 5 app, including how to handle a host that already
  has its own `passkeys` table from a pre-plugin migration.
- [Modes](Modes.md) — passwordless vs 2FA mode. Picking one is a host
  policy decision; the plugin supports both equally.
- [JS API](JsApi.md) — npm package entry points, framework adapters,
  the zero-build IIFE bundle, the `data-passkey-*` attribute contract.
- [Events](Events.md) — the four lifecycle events the plugin fires and
  how to subscribe from your `AppController`.
- [Security model](SecurityModel.md) — what the plugin verifies, what
  it leaves to the host, the rate-limiter contract, CSRF skip rationale.

## See also

- Vulnerability reporting: [SECURITY.md](../SECURITY.md)
- Contribution + release flow: [CONTRIBUTING.md](../CONTRIBUTING.md)
- Versioned changes: [CHANGELOG.md](../CHANGELOG.md)
