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
    it('renames a passkey with the CSRF token and updates the row', async () => {
        document.body.innerHTML = `
            <meta name="passkeys-endpoints" content='${JSON.stringify({ ...endpoints, rename: '/passkeys/rename/__id__', csrfToken: 'tok' })}'>
            <table><tr data-passkey-row data-passkey-id="7">
                <td><span data-passkey-name>Old</span><button data-passkey-rename>r</button></td>
            </tr></table>
        `;
        const calls: any[] = [];
        const origFetch = globalThis.fetch;
        const origPrompt = window.prompt;
        (globalThis as any).fetch = async (url: string, init: any) => {
            calls.push({ url, init });
            return { ok: true, json: async () => ({ passkey: { id: 7, name: 'New' } }) };
        };
        window.prompt = () => 'New';
        bind();
        const renameBtn = document.querySelector('[data-passkey-rename]') as HTMLButtonElement;
        renameBtn.click();
        expect(renameBtn.disabled).toBe(true);
        await new Promise((r) => setTimeout(r, 0));
        expect(renameBtn.disabled).toBe(false);
        globalThis.fetch = origFetch;
        window.prompt = origPrompt;

        expect(calls[0].url).toBe('/passkeys/rename/7');
        expect(calls[0].init.headers['X-CSRF-Token']).toBe('tok');
        expect(JSON.parse(calls[0].init.body)).toEqual({ name: 'New' });
        expect(document.querySelector('[data-passkey-name]')!.textContent).toBe('New');
    });

    it('shows the nudge unless it was dismissed within the configured days', () => {
        const html = `
            <meta name="passkeys-endpoints" content='${JSON.stringify(endpoints)}'>
            <div data-passkey-nudge data-passkey-nudge-days="14" hidden>
                <button data-passkey-nudge-dismiss>Not now</button>
            </div>
        `;
        window.localStorage.clear();
        document.body.innerHTML = html;
        bind();
        const nudge = document.querySelector('[data-passkey-nudge]') as HTMLElement;
        expect(nudge.hidden).toBe(false);

        (nudge.querySelector('[data-passkey-nudge-dismiss]') as HTMLButtonElement).click();
        expect(nudge.hidden).toBe(true);

        document.body.innerHTML = html;
        bind();
        expect((document.querySelector('[data-passkey-nudge]') as HTMLElement).hidden).toBe(true);
    });
});
