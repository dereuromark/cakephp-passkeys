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

The class implements `CakePasskeys\Contract\RateLimiterInterface`. `false` turns limiting off. The sign-in buckets are keyed by `ServerRequest::clientIp()`; behind a reverse proxy set `App.trustProxy`, or all visitors share one bucket. The plugin calls it with these buckets:

| Bucket | Limit |
| --- | --- |
| `passkeys.login.<ip>` | 20 per minute |
| `passkeys.login_finish.<ip>` | 20 per minute |
| `passkeys.register.<user id>` | 10 per minute |
| `passkeys.register_finish.<user id>` | 10 per minute |
| `passkeys.reauth.<user id>` | 20 per minute |
| `passkeys.reauth_finish.<user id>` | 20 per minute |
| `passkeys.manage.<user id>` (rename and delete together) | 30 per minute |
| `passkeys.second_factor.<pending user id>` | 10 per minute |
| `passkeys.second_factor_finish.<pending user id>` | 10 per minute |

### Authenticator allowlist

`allowedAaguids` lists the AAGUIDs (authenticator models) a new passkey may come from:

```php
'CakePasskeys' => [
    'allowedAaguids' => [
        'cb69481e-8ff7-4039-93ec-0a2729a154a8', // YubiKey 5
        'ee882879-721c-4913-9775-3dfcce97072a', // YubiKey 5 Series
    ],
],
```

Values are UUIDs, compared case-insensitively. `null` (the default) or an empty list accepts any authenticator. With a list set, `register/finish` refuses any other AAGUID with a 400, including the all-zero AAGUID that authenticators report when they share no model information, since it cannot be matched. Only new registrations are checked: passkeys stored before the list was set, or before a model was removed from it, keep signing in. Delete them yourself if they must go.

What it does not do: prove the make. The AAGUID is a value the authenticator writes into its own response. With attestation `none` nothing signs it, so a modified or software authenticator can report any AAGUID it likes. The list is a policy filter that keeps honest users on the devices you support; it is not attestation-backed evidence.

The plugin does not verify attestation statements. It registers only the `none` attestation format with `web-auth/webauthn-lib` and checks no certificates against trust anchors. Setting `ceremony.attestation` to `direct` does not change that: a response that carries a statement in another format (`packed`, `tpm`, `apple`, ...) is refused, so registrations fail. If you need guarantees about the hardware, you have to verify attestation yourself: request `direct` attestation, add the attestation statement formats to the library and check the statements against the FIDO Metadata Service or your vendor's roots. That is outside this plugin.

### Tell users about new passkeys

Anyone holding a signed-in session can add a passkey and keep access after the session ends. Asking for a recent sign-in in `beforeRegister` makes that harder; `notify.onRegister` mails the owner after every registration, so it does not go unnoticed. The mail is best effort: a failure is logged and does not undo the registration.

### Passkey as a second factor

The second-factor endpoints trust the pending user id your password step records. Write `secondFactor.pendingUserKey` only after the password was verified, never from request data, and clear it when the user starts over or signs out. A `pendingUserResolver` must answer the same way: the user who passed the first factor in this session, or null.

The plugin binds each second-factor challenge to the session that started it and to the pending user: `finish` refuses a challenge from another session, a challenge started for another user, a login or reauth challenge, and a passkey of anyone but the pending user. Counter and signature are checked as for a sign-in, and user verification follows `ceremony.userVerification`.

### Say who the current user is

The endpoints for registering, renaming, deleting and re-confirming trust the user id they are given. See "Who is signed in" in the README. If you set `identityResolver`, it must return the id of the authenticated user of this request and nothing a client can choose.

## Design decisions

- **User handle.** By default the id sent to the authenticator is `hash_hmac('sha256', $userId, Security.salt)`, not the database id; `users.userHandle` replaces it, for passkeys registered under an older scheme. Changing `Security.salt` afterwards breaks sign-in for existing passkeys unless `users.userHandle` keeps the old handles.
- **Email hint off by default.** With `login.emailHint` enabled, `login/start` returns the credential ids for an email address. Anyone can then test whether an address has passkeys. It is only needed for security keys that store no account information.
- **Synced passkeys.** iCloud Keychain and Google Password Manager report a counter of 0. A counter of 0 is accepted every time; any other value has to increase.
- **Backup flags.** The backup eligible flag is stored at registration and must not change afterwards; a sign-in where it does is refused, as WebAuthn Level 3 requires. The backup state may change and is updated on every sign-in. Both are informational for the user and do not affect what a passkey may do.
- **Algorithms.** ES256 and RS256 are offered for new passkeys. EdDSA is also verified at sign-in when ext-sodium is loaded, so passkeys an app registered before using the plugin keep working.
- **Attestation.** Requested as `none`, and not verified. Any authenticator is accepted unless `allowedAaguids` is set, and its make is not proven either way. The device label shown in the manager comes from the AAGUID the authenticator reports and is informational.
- **Error messages.** A failed check answers with HTTP 400 and a fixed message; the reason is not sent to the browser. A full rate-limit bucket answers 429, a refusal by `beforeRegister` or `beforeDelete` 403.

## Not covered

- An attacker with write access to the cache can read or remove challenges.
- An attacker with write access to the `passkeys` table can add a credential for any user.
- Recovery after losing all passkeys is up to your application.
