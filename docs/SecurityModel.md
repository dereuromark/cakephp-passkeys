# Security model

## What is verified

For every registration, sign-in and re-confirmation, `web-auth/webauthn-lib` checks:

- the challenge is the one the plugin issued
- the response comes from an allowed origin
- the relying party id hash matches
- the signature is valid for the stored public key
- the user was present, and verified when required
- the signature counter did not go backwards

The plugin adds:

- **Single use.** A challenge is removed when it is used. On Redis, Memcached or APCu two requests racing on the same challenge cannot both pass; on engines without an atomic `add()` (File, for one) they can, and only the session-bound challenge list and the signature counter stand in the way.
- **Expiry.** Challenges live for `challengeTtl` seconds, 300 by default.
- **Binding.** A registration or re-confirmation challenge belongs to the user who started it. A sign-in challenge belongs to the browser session that started it.
- **Account state.** A sign-in is refused when the owner of the passkey no longer exists or is not eligible, for example because `users.activeColumn` is false.
- **Ownership.** `rename` and `delete` only act on passkeys of the signed-in user.
- **Session fixation.** The session id is renewed before the user id is written.

## What your application has to do

### Keep CSRF protection on

The plugin's routes work with CakePHP's CSRF middleware. `PasskeysHelper::endpointsMeta()` passes the token to the JavaScript, which sends it as `X-CSRF-Token`.

Do not exempt the routes. `rename` and `delete` carry no WebAuthn challenge, so nothing else stops a forged request. For `login/finish`, the session binding above is a second line of defense, not a replacement.

### Set the allowed origins

```php
'CakePasskeys' => [
    'rpId' => 'example.com',
    'allowedOrigins' => ['https://example.com'],
],
```

Without `allowedOrigins`, a response is accepted from any HTTPS origin whose host is the `rpId` or a subdomain of it, on any port. If other applications run on subdomains you do not fully trust, that is too wide.

`http://localhost` is accepted for development. Every other origin has to be HTTPS.

### Rate limiting

By default the plugin counts requests in the `passkeys_rate_limit` cache config (`CakePasskeys\Service\CacheRateLimiter`; a copy of the `cache` engine that lives an hour, so a bucket never expires inside its window), in a fixed window per bucket, and answers 429 once a bucket is full. On engines without atomic `increment()` (File, for one) the count is best effort. To use your application's own limiter:

```php
'CakePasskeys' => [
    'rateLimiter' => \App\Security\PasskeyRateLimiter::class,
],
```

The class implements `CakePasskeys\Contract\RateLimiterInterface`. `false` turns limiting off. The sign-in buckets are keyed by `ServerRequest::clientIp()`. Behind a reverse proxy, call `$request->setTrustedProxies([...])` with the proxies' addresses in an early middleware, or all visitors share one bucket; only trust proxies that overwrite the forwarded headers. The plugin calls it with these buckets:

| Bucket | Limit |
| --- | --- |
| `passkeys.login.<ip>` | 20 per minute |
| `passkeys.login_finish.<ip>` | 20 per minute |
| `passkeys.register.<user id>` | 10 per minute |
| `passkeys.register_finish.<user id>` | 10 per minute |
| `passkeys.reauth.<user id>` | 20 per minute |

### Say who the current user is

The endpoints for registering, renaming, deleting and re-confirming trust the user id they are given. See "Who is signed in" in the README. If you set `identityResolver`, it must return the id of the authenticated user of this request and nothing a client can choose.

## Design decisions

- **User handle.** By default the id sent to the authenticator is `hash_hmac('sha256', $userId, Security.salt)`, not the database id; `users.userHandle` replaces it, for passkeys registered under an older scheme. Changing `Security.salt` afterwards breaks sign-in for existing passkeys unless `users.userHandle` keeps the old handles.
- **Email hint off by default.** With `login.emailHint` enabled, `login/start` returns the credential ids for an email address. Anyone can then test whether an address has passkeys. It is only needed for security keys that store no account information.
- **Synced passkeys.** iCloud Keychain and Google Password Manager report a counter of 0. A counter of 0 is accepted every time; any other value has to increase.
- **Algorithms.** ES256 and RS256 are offered for new passkeys. EdDSA is also verified at sign-in when ext-sodium is loaded, so passkeys an app registered before using the plugin keep working.
- **Attestation.** Requested as `none`. Any authenticator is accepted, and its make is not verified. The device label shown in the manager comes from the AAGUID the authenticator reports and is informational.
- **Error messages.** A failed check answers with HTTP 400 and a fixed message; the reason is not sent to the browser. A full rate-limit bucket answers 429, a refusal by `beforeRegister` or `beforeDelete` 403.

## Not covered

- An attacker with write access to the cache can read or remove challenges.
- An attacker with write access to the `passkeys` table can add a credential for any user.
- Recovery after losing all passkeys is up to your application.
