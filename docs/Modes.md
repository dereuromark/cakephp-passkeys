# Sign-in and re-confirmation

## Sign-in

A passkey sign-in is a complete sign-in. The user needs no password for it.

The flow:

1. The page calls `login/start`. The plugin creates a challenge, keeps it in the cache and remembers its key in the browser's session.
2. The browser asks the authenticator, which signs the challenge.
3. The page sends the result to `login/finish`. The plugin checks that this session started the challenge, verifies the signature, and checks that the owner of the passkey still exists and may sign in.

On success the plugin:

- renews the session id
- writes the user id to the session key in `CakePasskeys.session.userIdKey`
- sets the session flag `CakePasskeys.mfa_satisfied` to `true` when `ceremony.userVerification` is `required`
- dispatches `CakePasskeys.afterLogin`

Your application turns that into its own notion of "signed in". Two common ways:

```php
// config: write the id where your session authenticator reads it
'CakePasskeys' => [
    'session' => ['userIdKey' => 'Auth.id'],
],
```

```php
// or build the identity yourself, in Application::bootstrap()
EventManager::instance()->on('CakePasskeys.afterLogin', function (EventInterface $event): void {
    $userId = $event->getData('event')->getPasskey()->user_id;
    // load the user, write your session, record the login
});
```

Set `session.userIdKey` to an empty string if you only want the event.

### The `mfa_satisfied` flag

With user verification required, the authenticator checked a PIN, a fingerprint or a face before it signed. Possession of the device plus that check are two factors. If your application has a step that asks for a second factor after sign-in, it can skip it when the flag is set.

The flag is not set when you lower `ceremony.userVerification` to `preferred` or `discouraged`, because the sign-in then proves possession only.

### Passkey as a second factor

Not supported. The login endpoints are anonymous and do not know which account passed a first factor, so they cannot make sure the passkey belongs to that same account. Use [re-confirmation](#re-confirmation) for "prove it is still you" after a sign-in.

## Re-confirmation

Use it before an action that should not be possible on an unattended browser: changing the email address, deleting the account, showing recovery codes.

`reauth/start` and `reauth/finish` only accept the passkeys of the signed-in user and always require user verification. On success the plugin stores in the session, per action name, who confirmed and until when that counts. `Reauth::isFresh()` only answers true for that same user.

The window is `CakePasskeys.reauthWindow` seconds, 900 by default. Action names consist of letters, digits, `-` and `_`.

In the template:

```php
<?= $this->Form->create($user, $this->Passkeys->reauthAttributes('delete-account')) ?>
```

In the controller action that performs the change:

```php
use CakePasskeys\Service\Reauth;

if (!Reauth::isFresh($this->request, 'delete-account')) {
    throw new ForbiddenException();
}
```

The attribute makes the JavaScript run the ceremony before the form is submitted. The server-side check is what protects the action.

## Recovery

A user who loses every device with a passkey cannot sign in with one. Keep another way in, such as a password or a sign-in link sent by email, and let users delete lost passkeys in the manager afterwards.
