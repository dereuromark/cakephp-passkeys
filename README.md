# CakePHP Passkeys Plugin

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A public-quality passkey/WebAuthn plugin for CakePHP 5. It ships a PHP plugin
and a companion npm package in lockstep, with a vanilla-JS core plus
React, Vue, Alpine, and Stimulus adapters. Drop-in `data-attribute` binding
works for zero-build users; tree-shakable imports work for framework users.
Both passwordless and 2FA modes are supported.

## Quick start

```bash
composer require dereuromark/cakephp-passkeys
bin/cake plugin load Passkeys
bin/cake migrations migrate -p Passkeys
```

In your settings template:

```php
<?= $this->cell('Passkeys.Manager') ?>
<?= $this->Passkeys->script() ?>
```

Visit `/settings`, see the empty state, click *Add a passkey*.

## Configuration

The canonical reference is [`config/app.example.php`](config/app.example.php).
Copy the keys you need into your app's `config/app_local.php` or `config/passkeys.php`.

Environment variables read at runtime:

| Variable | Purpose | Default |
| --- | --- | --- |
| `PASSKEYS_ENABLED` | Master switch — disables all routes and cells when `false`. | `true` |
| `WEBAUTHN_RP_ID` | Relying Party ID — your app's effective domain (no scheme, no port). | host of `App.fullBaseUrl` |
| `WEBAUTHN_RP_NAME` | Human-readable name shown by the authenticator UI. | `App.name` |
| `PASSKEYS_MAX_PER_USER` | Cap on credentials per user (UI hides *Add* once reached). | `10` |
| `PASSKEYS_CHALLENGE_TTL` | Seconds a registration/login challenge stays valid in the session. | `300` |

## The five host touchpoints

These are the only points where your app needs to know the plugin exists.

### 1. `config/auth_acl.ini`

Allow the user role on the eight authenticated actions, and anonymous on
the two login endpoints:

```ini
[Passkeys.Passkeys]
index = user
register_start = user
register_finish = user
rename = user
delete = user
login_start = *
login_finish = *
reauth_start = user
reauth_finish = user
```

### 2. `config/passkeys.php`

```php
return [
    'Passkeys' => [
        'enabled' => env('PASSKEYS_ENABLED', true),
        'rp' => [
            'id' => env('WEBAUTHN_RP_ID', 'example.com'),
            'name' => env('WEBAUTHN_RP_NAME', 'Example App'),
        ],
        'maxPerUser' => (int)env('PASSKEYS_MAX_PER_USER', 10),
        'challengeTtl' => (int)env('PASSKEYS_CHALLENGE_TTL', 300),
        // Where the plugin hands the authenticated user id to your host
        // on a successful passkey login. Match your auth middleware's
        // session shape:
        //   - cakephp/authentication (modern):  'Identity.id'
        //   - legacy AuthComponent (default):   'Auth.id'
        // The plugin also fires Passkeys.afterLogin — subscribe there
        // to skip the session write entirely and build identity yourself.
        'session' => ['userIdKey' => 'Auth.id'],
    ],
];
```

`config/app.example.php` in the plugin lists every supported key with
inline notes; copy from it rather than guessing.

### 3. CSRF + FormProtection skip

The WebAuthn challenge nonce is itself anti-replay; the eight JSON
endpoints don't need CSRF tokens.

```php
$csrf->skipCheckCallback(fn ($r) => str_starts_with($r->getPath(), '/passkeys/'));
```

Wire the same callback into `FormProtectionMiddleware` if you use it.

### 4. Event subscription

```php
public function beforeFilter(\Cake\Event\EventInterface $event): void
{
    parent::beforeFilter($event);

    $this->getEventManager()->on('Passkeys.afterLogin', function ($event) {
        $passkeyEvent = $event->getData('event');
        // write to your audit log / set identity / mark MFA satisfied / ...
    });
}
```

### 5. Demo / anonymous-route policy

If you expose a public demo route, gate it behind `Passkeys.enabled` and
unauthenticate the user before the WebAuthn ceremony — otherwise the
demo credential gets bound to a real account.

## Passwordless vs 2FA modes

> [!IMPORTANT]
> Pick one, document it in your own product docs, wire your auth
> middleware to match. The plugin supports both equally; the wrong
> default lands wrong defaults in production.

- **Passwordless.** A passkey login is the full auth ceremony. The
  session flag `Passkeys.mfa_satisfied = true` tells your MFA gate not
  to prompt for a 2nd factor.
- **2FA.** User logs in with email/password first, then a passkey acts
  as the 2nd factor. The same session flag means "2nd factor satisfied
  today; don't re-prompt."

## UI surfaces

```php
<?= $this->cell('Passkeys.Manager') ?>
```

Settings list. Handles empty / populated / at-cap states. Rename and
delete inline.

```php
<?= $this->cell('Passkeys.LoginButton') ?>
```

Feature-detected *Sign in with a passkey* button. Stays hidden if the
browser does not advertise WebAuthn.

```php
<input type="email" name="email"
    <?= $this->Passkeys->autofillAttribute() ?> />
```

Adds the `autocomplete="username webauthn"` attribute so the browser's
autofill chip can surface passkeys (conditional UI). No button click
required — the user picks a passkey from the email field's dropdown.

```php
<?= $this->cell('Passkeys.RegisterNudge') ?>
```

Post-login banner that suggests passkey enrollment to users who don't
yet have one. Dismissible; reappears after a cooldown set by
`NudgePolicy`.

```php
<?= $this->Passkeys->reauthGuard('change-email') ?>
```

Sensitive-action re-auth challenge. Renders an inline button that runs
the WebAuthn ceremony before the form submits, so you can require fresh
proof-of-presence for actions like email change or 2FA disable.

## JS package

```bash
npm install @dereuromark/cakephp-passkeys
```

Entry points:

```js
import { register, authenticate, conditional, reauth } from '@dereuromark/cakephp-passkeys';
import { bind } from '@dereuromark/cakephp-passkeys/attributes';
import { usePasskey } from '@dereuromark/cakephp-passkeys/react';
import { usePasskey } from '@dereuromark/cakephp-passkeys/vue';
import '@dereuromark/cakephp-passkeys/alpine';
import { PasskeyController } from '@dereuromark/cakephp-passkeys/stimulus';
```

For the zero-build path:

```php
<?= $this->Passkeys->script() ?>
```

serves a 6 kB IIFE bundle (2.18 kB gzipped) that auto-binds
`data-passkey-*` attributes on `DOMContentLoaded`.

> [!NOTE]
> `Passkeys.urlPrefix` controls **both** the route mount point AND the
> asset URL. If you set `'urlPrefix' => '/auth/passkeys'` to namespace
> the eight endpoints under a custom prefix, the helper's `script()`
> tag automatically points at `/auth/passkeys/dist/passkeys.min.js`.
> No additional asset config is required.

## Events

| Event | Payload class | Extra data |
| --- | --- | --- |
| `Passkeys.afterRegister` | `PasskeyEvent($passkey, $userHandle)` | — |
| `Passkeys.afterLogin` | `PasskeyEvent($passkey, $userHandle)` | — |
| `Passkeys.afterRename` | `PasskeyEvent($passkey, $userHandle, ['old' => $old, 'new' => $new])` | rename diff |
| `Passkeys.afterDelete` | `PasskeyEvent($passkey, $userHandle)` | — |

Subscribe via `$this->getEventManager()->on('Passkeys.afterX', ...)` —
see the touchpoints section above.

## Security model

- **RP-ID binding.** A passkey is bound to your origin; it cannot be
  used cross-site.
- **Library trust.** `web-auth/webauthn-lib ^5.0` performs origin
  validation, RP-ID hash check, signature verification, and counter
  rollback detection.
- **Synced passkeys.** iCloud Keychain and Google Password Manager
  always return `signCount=0`. Accepted unconditionally — the counter
  rollback check is skipped for these credentials by design.
- **User handle.** Hashed before transmission to the authenticator:
  `hash_hmac('sha256', $userId, Security::salt())`. The raw DB id never
  leaves the server.
- **Rate limiter.** The plugin ships `NullRateLimiter` (no-op). Bind
  your own `RateLimiterInterface` implementation in production —
  WebAuthn endpoints are unauthenticated for the login flow and need
  per-IP / per-handle throttling.
- **CSRF.** The eight endpoints skip CSRF. The WebAuthn challenge nonce
  is the anti-replay guard, mirroring the Stripe webhook pattern.
- **Attestation.** No attestation pinning by default. Any FIDO2
  authenticator is accepted. Override `WebAuthnService::buildOptions()`
  if you need enterprise attestation.

## Recovery flow

Account recovery is host-owned — the plugin deliberately does not ship
a recovery flow, because the right answer depends on your existing
auth stack.

Recommendation: combine with a magic-link login flow
(`cakephp/authentication`'s URL identifier or your own implementation).
If a user loses every passkey-bearing device, they request a magic link
by email, click it, sign in, then register new passkeys and delete the
dead ones from `/settings`.

## Compatibility

- **PHP** 8.2+ (matches the CakePHP 5.2 floor).
- **CakePHP** 5.2+.
- **Browsers:** Chrome/Edge 109+, Firefox 122+, Safari 16+ (macOS 13+,
  iOS 16+).

### Limitations (v0.1)

- **Integer user IDs only.** The migration stores `user_id` as INTEGER
  and `PasskeyUserInterface::getUserId()` returns `int`. UUID / string
  primary keys on the host's `Users` table are not yet supported —
  widening the contract is a v2 enhancement once we have a real-world
  ask. Open a tracker entry on GitHub if you need this.

> [!NOTE]
> iOS 16 known limitation. The prepare/finish synchronous
> user-activation pattern is not currently exposed at the JS API surface;
> iOS 16 may reject WebAuthn calls that happen after a `fetch()`. iOS 17+
> works. If field reports surface iOS 16 issues, the binder can be
> extended with a prefetch strategy.

## License

MIT. See [LICENSE](LICENSE).
