# JS API

Two distribution paths share the same browser code:

1. **Zero-build path.** `<?= $this->Passkeys->script() ?>` emits a
   `<script>` tag pointing at the IIFE bundle the plugin ships in
   `webroot/dist/passkeys.min.js`. Auto-binds on `DOMContentLoaded`.
2. **npm path.** `npm install @dereuromark/cakephp-passkeys` for tree-
   shakable imports and framework adapters.

## Zero-build path

Drop the script tag once per page that uses passkeys, then sprinkle
`data-passkey-*` attributes on existing markup:

```html
<?= $this->Passkeys->script() ?>
<?= $this->Passkeys->endpointsMeta() ?>

<!-- Registration -->
<button data-passkey-register data-passkey-name-prompt>Add a passkey</button>

<!-- Login button -->
<?= $this->Passkeys->loginButton() ?>

<!-- Conditional UI on the email input -->
<input name="email" type="email"
       autocomplete="username webauthn"
       data-passkey-conditional>

<!-- Sensitive-action confirmation -->
<form data-passkey-reauth-required="delete-account" method="post" action="/account/delete">
    <button>Delete account</button>
</form>
```

The binder picks up these attributes once per element (idempotent —
re-running `bind()` is safe).

## npm path — entry points

```js
// Core ceremony helpers
import { register, authenticate, conditional, reauth } from '@dereuromark/cakephp-passkeys';

// Data-attribute binder (manual call if you build your own bundle)
import { bind } from '@dereuromark/cakephp-passkeys/attributes';

// Typed error classes for catch-and-handle
import {
    PasskeyError,
    PasskeyNotSupportedError,
    PasskeyAbortedError,
    PasskeyTimeoutError,
    PasskeyInvalidStateError,
    PasskeyServerError,
} from '@dereuromark/cakephp-passkeys/errors';
```

## Framework adapters

Each adapter is ~1 kB gzipped on top of the ~1.6 kB gzipped core.
SSR-safe where applicable (React, Vue): the hook returns no-ops on the
server, full functions after hydration.

### React

```tsx
import { usePasskey } from '@dereuromark/cakephp-passkeys/react';

function AddPasskeyButton({ endpoints }) {
    const { register, isLoading, error } = usePasskey(endpoints);
    return (
        <button
            disabled={isLoading}
            onClick={() => register({ name: 'My laptop' }).then(() => location.reload())}
        >
            {isLoading ? 'Adding...' : 'Add a passkey'}
        </button>
    );
}
```

### Vue (Composition API)

```vue
<script setup>
import { usePasskey } from '@dereuromark/cakephp-passkeys/vue';

const props = defineProps(['endpoints']);
const { register, isLoading, error } = usePasskey(props.endpoints);
</script>

<template>
    <button :disabled="isLoading" @click="register({ name: 'My laptop' }).then(() => location.reload())">
        {{ isLoading ? 'Adding...' : 'Add a passkey' }}
    </button>
</template>
```

### Alpine.js (CSP-strict)

```html
<script type="module">
    import '@dereuromark/cakephp-passkeys/alpine';
</script>

<button x-passkey-register data-passkey-name-prompt>Add a passkey</button>
<input x-passkey-conditional autocomplete="username webauthn">
```

### Stimulus

```html
<div data-controller="passkey" data-passkey-endpoints-value='{ "registerStart": "...", "registerFinish": "...", ... }'>
    <button data-action="passkey#register">Add a passkey</button>
    <button data-action="passkey#authenticate">Sign in with a passkey</button>
</div>
```

```js
import { Application } from '@hotwired/stimulus';
import { PasskeyController } from '@dereuromark/cakephp-passkeys/stimulus';

const app = Application.start();
app.register('passkey', PasskeyController);
```

## Error handling

All ceremony helpers throw subclasses of `PasskeyError`. Switch on the
class, not the message:

```ts
try {
    await register({ endpoints, name: 'My laptop' });
} catch (err) {
    if (err instanceof PasskeyAbortedError) {
        // User dismissed the OS dialog — silent retry, no error UI.
    } else if (err instanceof PasskeyNotSupportedError) {
        // Browser too old. Surface a banner once.
    } else if (err instanceof PasskeyServerError) {
        // Non-2xx from the controller. err.status carries the code.
    } else {
        // Genuine unknown error. Log + surface.
    }
}
```

## iOS / Safari note

iOS 17+ propagates user-activation across intermediate awaits, so
`click → await fetch → navigator.credentials.create()` works. iOS 16
and older may reject WebAuthn calls after a fetch — they are an
explicit non-target for v0.1. If field reports surface issues there,
the binder will be extended with a prefetch-on-mouseenter strategy.
