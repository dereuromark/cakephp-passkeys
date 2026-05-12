# Modes — Passwordless vs 2FA

The plugin supports both modes equally. **Pick one explicitly** and
wire your auth middleware to match. The wrong default lands wrong
defaults in production.

## Passwordless mode

Passkey login is the full auth ceremony. The browser performs user
verification (biometric / PIN) as part of the WebAuthn call, which the
FIDO2 spec defines as multi-factor in a single step. The plugin writes
`$session->write('Passkeys.mfa_satisfied', true)` on a UV-verified
login, and your MFA gate should accept that as "already multi-factor."

Typical wiring:

```php
// AppController::beforeFilter()
$mfaSatisfied = $this->getRequest()->getSession()->read(
    (string)\Cake\Core\Configure::read('Passkeys.mfa.sessionFlag', 'Passkeys.mfa_satisfied'),
);

if ($this->requires2fa() && !$mfaSatisfied) {
    return $this->redirect(['controller' => 'Mfa', 'action' => 'prompt']);
}
```

When to pick this: most consumer apps. Removes a friction layer for
users; keeps phishing resistance because the passkey is bound to your
origin.

## 2FA mode

User logs in with email + password first. The passkey is the second
factor that satisfies the MFA gate. Same session flag, different
sequencing: the password-login flow is the user-experience entry point,
the passkey ceremony fires after.

Typical wiring:

```php
// UsersController::login() — after password verification
if ($passwordOk && $user->totp_enabled || $user->passkeys_count > 0) {
    return $this->redirect(['controller' => 'Mfa', 'action' => 'choose']);
}
```

Where the user picks "passkey" → kicks the plugin's `loginStart` ceremony
→ on success the session flag is set → main app sees both factors
satisfied.

When to pick this: regulated environments where password is a policy
requirement and passkey is an enhancement, not a replacement.

## Re-auth (sensitive-action confirmation)

Independent of mode. For sensitive actions (delete account, rotate
recovery codes, change email), guard the form with:

```php
<?= $this->Passkeys->reauthGuard('delete-account') ?>
<form data-passkey-reauth-required="delete-account" method="post" action="/account/delete">
    ...
</form>
```

The JS binder intercepts the form's first submit, runs the WebAuthn
reauth ceremony, then resubmits the form when the ceremony succeeds.
The session records `Passkeys.recent_reauth.delete-account = ISO8601`
until `Passkeys.reauthWindow` seconds pass.

## Recovery — host-owned

The plugin deliberately ships no recovery flow. If a user loses every
passkey-bearing device:

1. Recommended: pair with a magic-link login (`cakephp/authentication`'s
   URL identifier or equivalent). User requests a one-time email link,
   clicks it, lands signed-in, then registers a fresh passkey and
   deletes the dead ones from `/settings`.
2. Alternative: keep email/password as a permanent fallback even in
   passwordless mode.

Whatever you pick, document it in your product's help text so users
know what to do.
