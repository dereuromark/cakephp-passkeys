# CakePHP Passkeys Plugin

[![CI](https://github.com/dereuromark/cakephp-passkeys/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dereuromark/cakephp-passkeys/actions/workflows/ci.yml?query=branch%3Amain)
[![JS](https://github.com/dereuromark/cakephp-passkeys/actions/workflows/js.yml/badge.svg?branch=main)](https://github.com/dereuromark/cakephp-passkeys/actions/workflows/js.yml?query=branch%3Amain)
[![E2E](https://github.com/dereuromark/cakephp-passkeys/actions/workflows/e2e.yml/badge.svg?branch=main)](https://github.com/dereuromark/cakephp-passkeys/actions/workflows/e2e.yml?query=branch%3Amain)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat)](https://phpstan.org/)
[![Latest Stable Version](https://poser.pugx.org/dereuromark/cakephp-passkeys/v/stable.svg)](https://packagist.org/packages/dereuromark/cakephp-passkeys)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.2-8892BF.svg)](https://php.net/)
[![License](https://poser.pugx.org/dereuromark/cakephp-passkeys/license.svg)](LICENSE)
[![Total Downloads](https://poser.pugx.org/dereuromark/cakephp-passkeys/d/total.svg)](https://packagist.org/packages/dereuromark/cakephp-passkeys)
[![Coding Standards](https://img.shields.io/badge/cs-PhpCollective-blue.svg?style=flat-square)](https://github.com/php-collective/code-sniffer)

Passkey (WebAuthn) sign-in for CakePHP 5.

- Register, sign in and re-confirm with a passkey. Eight JSON endpoints, backed by `web-auth/webauthn-lib`.
- A JavaScript bundle that binds `data-passkey-*` attributes. No build step needed. The same code is on npm with React, Vue, Alpine and Stimulus adapters.
- Cells for a passkey list, a sign-in button and an "add a passkey" banner.
- No assumptions about your authentication stack: you tell the plugin who the current user is, and it tells you who signed in.

## Requirements

- PHP 8.2+, CakePHP 5.3+
- HTTPS in production. Browsers only offer WebAuthn in a secure context; `http://localhost` counts as one for development.
- A cache engine that keeps data between two requests (not `Null` or `Array`) for the challenges.

## Installation

```bash
composer require dereuromark/cakephp-passkeys
bin/cake plugin load CakePasskeys
bin/cake migrations migrate -p CakePasskeys
```

If the primary key of your users table is not an integer, set `CakePasskeys.users.idType` before you run the migration. See [Configuration](#configuration).

## Configuration

At minimum, set the relying party id. It is the domain your users see, without scheme and port.

```php
// config/app_local.php
'CakePasskeys' => [
    'rpId' => 'example.com',
    'rpName' => 'Example App',
    'allowedOrigins' => ['https://example.com'],
],
```

[`config/app.example.php`](config/app.example.php) lists every key with its default. The ones you are most likely to touch:

| Key | Default | Purpose |
| --- | --- | --- |
| `rpId` | `'localhost'` | Relying party id. A passkey is bound to it for good, so choose it before users enroll. |
| `rpName` | `'My App'` | Name the authenticator shows. |
| `allowedOrigins` | `null` | Origins a response may come from. Without a list, any HTTPS origin on the `rpId` or a subdomain of it is accepted, on any port. Set it in production. |
| `users.table` | `'Users'` | Table your users live in. |
| `users.columns` | `id`, `email`, `name` | Column names for the id, the email and the display name. |
| `users.activeColumn` | `null` | Boolean column. Users where it is false cannot register or sign in. |
| `users.userHandle` | `null` | Closure `fn (EntityInterface $user): string` for the WebAuthn user handle, at most 64 bytes. Set it when your app already has passkeys registered under its own handle. See [Moving existing passkeys](#moving-existing-passkeys). |
| `users.idType` | `'integer'` | Type of `passkeys.user_id`: `integer`, `biginteger`, `uuid` or `string`. Read by the migration. |
| `identityResolver` | `null` | Closure that returns the id of the signed-in user. See [Who is signed in](#who-is-signed-in). |
| `session.persistIdentity` | `true` | Hand the signed-in user to `cakephp/authentication` when it is loaded. See [Pick up the signed-in user](#4-pick-up-the-signed-in-user). |
| `session.userIdKey` | `'Auth.id'` | Session key the user id is written to after a passkey sign-in. |
| `afterLoginRedirect` | `'/'` | URL the JavaScript navigates to after a sign-in. |
| `maxPerUser` | `5` | Passkeys per user. `0` for no limit. |
| `login.emailHint` | `false` | Accept an email on `login/start`. See [Security](#security). |
| `rateLimiter` | `null` | Class or instance implementing `RateLimiterInterface`. |
| `urlPrefix` | `'/passkeys'` | Where the endpoints are mounted. |

## Usage

### 1. Load the script and the endpoint list

In the `<head>` of the layout, or on the pages that show a passkey control:

```php
<?= $this->Passkeys->endpointsMeta() ?>
<?= $this->Passkeys->script() ?>
```

Load the helper in your `AppView`:

```php
$this->addHelper('CakePasskeys.Passkeys');
```

`script()` serves the bundle as a plugin asset. If your web server serves plugin assets itself, run `bin/cake plugin assets symlink` once.

### 2. Let signed-in users manage their passkeys

```php
<?= $this->cell('CakePasskeys.Manager') ?>
```

Shows the user's passkeys with rename and delete, and an "Add a passkey" button until the limit is reached.

### 3. Offer the sign-in

On the login page:

```php
<?= $this->Passkeys->loginButton() ?>

<?= $this->Form->control('email', $this->Passkeys->autofillAttribute()) ?>
```

The button stays hidden in browsers without WebAuthn. `autofillAttribute()` lets the browser offer passkeys in the autofill dropdown of the input, without a click on the button.

### 4. Pick up the signed-in user

After a successful sign-in the plugin:

1. renews the session id
2. signs the user in (see below)
3. dispatches `CakePasskeys.afterLogin`
4. answers with `{"redirectTo": ..., "userId": ...}`, and the JavaScript navigates to `redirectTo`

How the user gets signed in:

- With `cakephp/authentication` loaded, the plugin loads the user row from `users.table` and passes it to the authentication service's `persistIdentity()`. With its Session authenticator, the next request sees the full user entity as its identity, the same as after a password login. Nothing to configure.
- The plugin also writes the user id to the session key in `session.userIdKey`, for your own login code to read. It skips that write when the persisted identity already sits under the same root key (`Auth.id` next to the Session authenticator's `Auth`). Set `session.userIdKey` to an empty string if nothing reads it.
- `session.persistIdentity` set to `false` turns off the hand-over; then only `session.userIdKey` is written.

## Who is signed in

Registering, renaming, deleting and re-confirming need the current user. The plugin looks in this order:

1. `CakePasskeys.identityResolver`, if set:

    ```php
    'identityResolver' => fn (\Cake\Http\ServerRequest $request) => $request->getAttribute('authUser')?->id,
    ```

2. The `identity` request attribute, as set by `cakephp/authentication`. Objects with `getIdentifier()`, objects with an `id` property and arrays with an `id` key all work.
3. The session key in `session.userIdKey`.

The user row is then loaded from `users.table`. If your user entity implements `CakePasskeys\Contract\PasskeyUserInterface`, the plugin uses it as is. Otherwise it wraps the entity and reads the configured columns.

## Re-confirming before a sensitive action

Ask for a fresh passkey confirmation before, say, changing the email address.

In the template, mark the form:

```php
<?= $this->Form->create($user, $this->Passkeys->reauthAttributes('change-email')) ?>
```

The JavaScript runs the ceremony when the form is submitted and submits it afterwards. That is a convenience for the user. The check that counts is on the server:

```php
use CakePasskeys\Service\Reauth;

if (!Reauth::isFresh($this->request, 'change-email')) {
    throw new ForbiddenException();
}
```

A confirmation stays fresh for `reauthWindow` seconds (default 900).

## Events

Dispatched on the global event manager. The payload is a `CakePasskeys\Event\PasskeyEvent` under the key `event`.

| Event | When | Extra data |
| --- | --- | --- |
| `CakePasskeys.afterRegister` | a passkey was stored | |
| `CakePasskeys.afterLogin` | a user signed in | |
| `CakePasskeys.afterRename` | a passkey was renamed | `old`, `new` |
| `CakePasskeys.afterDelete` | a passkey was deleted | |

`CakePasskeys.beforeRegister` runs before both register steps, with the user under `user` instead of a payload. Stop it to refuse the registration with a 403; a string result becomes the message:

```php
EventManager::instance()->on('CakePasskeys.beforeRegister', function (EventInterface $event): void {
    $session = $event->getSubject()->getRequest()->getSession();
    // wherever your login code records the sign-in time
    if ((int)$session->read('Login.time') < time() - 900) {
        $event->stopPropagation();
        $event->setResult(__('Please sign in again to add a passkey.'));
    }
});
```

Registering a passkey gives lasting access to the account, so asking for a recent sign-in here is a good default. `CakePasskeys.beforeDelete` works the same way for deleting one (with the passkey under `passkey`), so a stolen session cannot remove the owner's passkeys either.

Attach listeners where they exist for every request, such as `Application::bootstrap()`:

```php
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

EventManager::instance()->on('CakePasskeys.afterLogin', function (EventInterface $event): void {
    /** @var \CakePasskeys\Event\PasskeyEvent $passkeyEvent */
    $passkeyEvent = $event->getData('event');
    // $passkeyEvent->getPasskey()->user_id
});
```

A listener attached in your `AppController` does not run: the plugin's controller does not extend it. See [docs/Events.md](docs/Events.md).

## JavaScript

`script()` loads an 8 kB bundle that binds these attributes when the page has loaded:

| Attribute | On | Effect |
| --- | --- | --- |
| `data-passkey-register` | button | registers a passkey, then reloads |
| `data-passkey-authenticate` | button | signs in, then navigates to `redirectTo` |
| `data-passkey-conditional` | input | offers passkeys in the autofill dropdown |
| `data-passkey-reauth-required="action"` | form | asks for a passkey before submitting |
| `data-passkey-rename`, `data-passkey-delete` | button inside `[data-passkey-row]` | renames or deletes that passkey |

Errors are dispatched as a `passkeys:error` event on `document`.

The same code is on npm, for bundlers and frameworks:

```bash
npm install @dereuromark/cakephp-passkeys
```

```js
import { register, authenticate, conditional, reauth, rename, remove } from '@dereuromark/cakephp-passkeys';
```

Adapters for React, Vue, Alpine and Stimulus are documented in [docs/JsApi.md](docs/JsApi.md).

## Security

- **CSRF.** Leave your CSRF protection on for the plugin's routes. `endpointsMeta()` hands the token to the JavaScript, which sends it with every request. `rename` and `delete` carry no WebAuthn challenge, so exempting them would open them to forged requests.
- **Origin.** Set `allowedOrigins`. See the table above for what is accepted without it.
- **Deactivated users.** A sign-in is refused when the user row is gone or `users.activeColumn` is false, even though the passkey itself is still valid.
- **Rate limiting.** None by default. `login/start` is open to anonymous callers, so bind a `RateLimiterInterface` in production.
- **Email hint.** With `login.emailHint` enabled, `login/start` returns the credential ids of the account behind an email address. That tells anyone whether the address has passkeys. Leave it off unless you need sign-in with security keys that hold no account information.
- **User verification.** `ceremony.userVerification` defaults to `required`, so a sign-in proves a PIN or biometric check. The session flag `CakePasskeys.mfa_satisfied` is only set in that case, and removed on a sign-in without it. Clear it in your logout too; the plugin does not see the logout.
- **Replay.** A login challenge is consumed with `Cache::add()`, which is only atomic on Redis, Memcached and APCu. With the File engine two finishes racing on the same challenge can both pass the cache check (the session-bound challenge list and the signature counter still apply). Point `cache` at an atomic engine in production.
- **`Security.salt`.** The default user handle is an HMAC of the user id with the salt. Changing the salt makes every existing passkey unusable. If you must rotate it, set `users.userHandle` to keep the old handles.
- **Authorization.** With `cakephp/authorization`, the plugin marks its own actions as checked, because they verify the user themselves. Request-level policies (a request policy, TinyAuth) still run before them; allow the plugin routes there.
- **Synced passkeys** from iCloud Keychain or Google Password Manager report a signature counter of 0. That is accepted. A counter that goes backwards is refused.

More in [docs/SecurityModel.md](docs/SecurityModel.md). Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

## Moving existing passkeys

If your app stored passkeys before it used this plugin, the authenticators know those users by the handle your old code chose, and a sign-in is checked against it. Keep that handle with `users.userHandle`:

```php
'users' => [
    // the old code used the packed integer id
    'userHandle' => fn (\Cake\Datasource\EntityInterface $user): string => pack('J', $user->get('id')),
],
```

New passkeys then get the same handle. Existing EdDSA passkeys (common on hardware keys) sign in as long as PHP has the sodium extension; new passkeys are created with ES256 or RS256.

If your app already has a `passkeys` table, do not run the plugin's migration. Add any column the plugin's `config/Migrations/*_CreatePasskeys.php` has and yours lacks in a migration of your own (keep the binary types for `credential_id`, `public_key` and `aaguid`), then record the plugin migration as done with `bin/cake migrations mark_migrated -p CakePasskeys`.

## Recovery

The plugin has no recovery flow. A user who loses every device with a passkey needs another way in, such as a password or a sign-in link by email. Keep one.

## Limitations

- A passkey sign-in is a complete sign-in. Using a passkey as a second factor after a password is not supported: the login endpoints are anonymous and do not tie the passkey to the account that passed the first factor.
- No attestation policy. Any authenticator is accepted.

## License

MIT. See [LICENSE](LICENSE).
