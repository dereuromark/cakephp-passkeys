import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { bind } from '../src/attributes';

const endpoints = {
    registerStart: '/passkeys/register/start',
    registerFinish: '/passkeys/register/finish',
    loginStart: '/p/login/start',
    loginFinish: '/p/login/finish',
    reauthStart: '/p/reauth/start',
    reauthFinish: '/p/reauth/finish',
};

describe('attributes binder', () => {
    let origPublicKeyCredential: any;

    beforeEach(() => {
        origPublicKeyCredential = (globalThis as any).PublicKeyCredential;
        document.body.innerHTML = `
            <meta name="passkeys-endpoints" content='${JSON.stringify(endpoints)}'>
            <button data-passkey-authenticate hidden>Sign in</button>
            <button data-passkey-register>Add</button>
            <input name="email" autocomplete="username webauthn" data-passkey-conditional />
        `;
        (globalThis as any).PublicKeyCredential = function () {};
    });

    afterEach(() => {
        (globalThis as any).PublicKeyCredential = origPublicKeyCredential;
        document.body.innerHTML = '';
    });

    it('removes hidden from authenticate button on supported browsers', () => {
        bind();
        const btn = document.querySelector('[data-passkey-authenticate]') as HTMLButtonElement;
        expect(btn.hidden).toBe(false);
    });

    it('leaves hidden when PublicKeyCredential is missing', () => {
        delete (globalThis as any).PublicKeyCredential;
        bind();
        const btn = document.querySelector('[data-passkey-authenticate]') as HTMLButtonElement;
        expect(btn.hidden).toBe(true);
    });

    it('marks register button as bound to prevent double-binding', () => {
        const btn = document.querySelector('[data-passkey-register]') as HTMLButtonElement;
        bind();
        expect(btn.dataset.passkeyBound).toBe('1');
        // second bind should NOT add another listener
        bind();
        expect(btn.dataset.passkeyBound).toBe('1');
    });

    it('marks conditional input as bound', () => {
        const input = document.querySelector('[data-passkey-conditional]') as HTMLInputElement;
        bind();
        expect(input.dataset.passkeyBound).toBe('1');
    });

    it('does nothing when endpoints meta tag is missing', () => {
        document.body.innerHTML = '<button data-passkey-register></button>';
        expect(() => bind()).not.toThrow();
        const btn = document.querySelector('[data-passkey-register]') as HTMLButtonElement;
        expect(btn.dataset.passkeyBound).toBeUndefined();
    });

    it('binds a submit listener on reauth-required forms', () => {
        document.body.innerHTML = `
            <meta name="passkeys-endpoints" content='${JSON.stringify(endpoints)}'>
            <form data-passkey-reauth-required="delete-account">
                <button type="submit">Delete</button>
            </form>
        `;
        bind();
        const form = document.querySelector('form[data-passkey-reauth-required]') as HTMLFormElement;
        expect(form.dataset.passkeyBound).toBe('1');
        // Submitting the form without a passkey ceremony should be prevented
        // (we don't have navigator.credentials.get to mock here cleanly, but
        // verifying preventDefault is called is enough to confirm the handler
        // intercepted the event).
        const ev = new Event('submit', { cancelable: true, bubbles: true });
        form.dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(true);
    });
});
