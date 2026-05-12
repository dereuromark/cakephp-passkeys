/**
 * Alpine.js adapter — registers `x-passkey-*` directives.
 *
 * Side-effect module: importing it once (e.g. via `import '...passkeys/alpine'`
 * after Alpine.start()) wires up three CSP-strict directives that the host
 * page applies via plain HTML attributes. Each directive sets the matching
 * data-* attribute so the existing attributes.ts binder can take over —
 * no logic is duplicated.
 */

import { bind } from './attributes';

declare global {
    interface Window {
        Alpine?: { directive(name: string, fn: (el: HTMLElement) => void): void };
    }
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.directive('passkey-register', (el: HTMLElement) => {
        el.setAttribute('data-passkey-register', '');
        el.setAttribute('data-passkey-name-prompt', '');
        bind(el.parentElement ?? document);
    });
    window.Alpine.directive('passkey-authenticate', (el: HTMLElement) => {
        el.setAttribute('data-passkey-authenticate', '');
        bind(el.parentElement ?? document);
    });
    window.Alpine.directive('passkey-conditional', (el: HTMLElement) => {
        el.setAttribute('data-passkey-conditional', '');
        el.setAttribute('autocomplete', 'username webauthn');
        bind(el.parentElement ?? document);
    });
}
