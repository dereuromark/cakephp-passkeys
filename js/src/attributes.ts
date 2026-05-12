/**
 * Data-attribute binder for the zero-build path.
 *
 * Host pages drop the vendored bundle into a <script> tag and sprinkle
 * data-passkey-* attributes on existing buttons / inputs; this module
 * wires them to the WebAuthn ceremonies in core.ts on DOMContentLoaded.
 *
 * iOS / iPad note: Safari 17+ accepts user-activation propagation across
 * intermediate awaits, so `click -> await register()` works even though
 * register() does a fetch before navigator.credentials.create(). iOS 16
 * and older are not a v1 target — if field reports surface issues there,
 * we switch to a prefetch-on-mouseenter strategy.
 */

import { authenticate, conditional, isConditionalSupported, isSupported, reauth, register } from './core';
import type { Endpoints } from './types';

export function bind(root: ParentNode = document): void {
    const endpoints = readEndpoints(root);
    if (!endpoints) return;

    if (isSupported()) {
        root.querySelectorAll<HTMLElement>('[data-passkey-authenticate]').forEach((el) => {
            if ('hidden' in el) (el as HTMLElement).hidden = false;
        });
    }

    root.querySelectorAll<HTMLButtonElement>('[data-passkey-register]').forEach((btn) => {
        if (btn.dataset.passkeyBound === '1') return;
        btn.dataset.passkeyBound = '1';
        btn.addEventListener('click', async () => {
            const name = btn.dataset.passkeyName ?? promptName(btn);
            if (!name) return;
            try {
                await register({ endpoints, name });
                window.location.reload();
            } catch (e) {
                announceError(e);
            }
        });
    });

    root.querySelectorAll<HTMLButtonElement>('[data-passkey-authenticate]').forEach((btn) => {
        if (btn.dataset.passkeyBound === '1') return;
        btn.dataset.passkeyBound = '1';
        btn.addEventListener('click', async () => {
            try {
                const result = await authenticate({ endpoints });
                if (result.redirectTo) window.location.assign(result.redirectTo);
            } catch (e) {
                announceError(e);
            }
        });
    });

    // Forms wrapped by PasskeysHelper::reauthGuard($action) carry a
    // `data-passkey-reauth-required` attribute naming the action. On submit
    // we run the reauth ceremony; on success we set a sentinel and call
    // form.submit() so the second pass goes through unblocked.
    root.querySelectorAll<HTMLFormElement>('[data-passkey-reauth-required]').forEach((form) => {
        if (form.dataset.passkeyBound === '1') return;
        form.dataset.passkeyBound = '1';
        form.addEventListener('submit', async (e) => {
            if (form.dataset.passkeyReauthDone === '1') {
                // Second pass after a successful ceremony — let the form go.
                return;
            }
            e.preventDefault();
            const action = form.dataset.passkeyReauthRequired || 'default';
            try {
                await reauth({ endpoints, action });
                form.dataset.passkeyReauthDone = '1';
                form.submit();
            } catch (err) {
                announceError(err);
            }
        });
    });

    root.querySelectorAll<HTMLInputElement>('[data-passkey-conditional]').forEach((input) => {
        if (input.dataset.passkeyBound === '1') return;
        input.dataset.passkeyBound = '1';

        // Conditional UI is best-effort — feature-detect, ignore errors silently.
        (async () => {
            if (!(await isConditionalSupported())) return;
            try {
                await conditional({
                    endpoints,
                    onMatch: (r) => {
                        if (r.redirectTo) window.location.assign(r.redirectTo);
                    },
                });
            } catch {
                /* swallow — autofill UX is best-effort */
            }
        })();
    });
}

function readEndpoints(root: ParentNode): Endpoints | null {
    const meta = root.querySelector('meta[name="passkeys-endpoints"]') as HTMLMetaElement | null;
    if (!meta) return null;
    try {
        return JSON.parse(meta.content) as Endpoints;
    } catch {
        return null;
    }
}

function promptName(btn: HTMLElement): string | null {
    if (btn.hasAttribute('data-passkey-name-prompt')) {
        return window.prompt('Name this passkey (e.g. "My laptop")') || null;
    }
    return 'Passkey';
}

function announceError(e: unknown): void {
    // Host can listen for this custom event and render their own toast.
    document.dispatchEvent(new CustomEvent('passkeys:error', { detail: e }));
    if (typeof console !== 'undefined') console.error('[passkeys]', e);
}

// Auto-bind on DOMContentLoaded when this module is loaded into the page
// (via PasskeysHelper->script()).
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bind());
    } else {
        bind();
    }
}
