import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { authenticate, remove } from '../src/core';
import { bind } from '../src/attributes';
import { PasskeyServerError } from '../src/errors';
import { signalAcceptedCredentials, signalCurrentUser, signalUnknownCredential } from '../src/signals';

const endpoints = {
    registerStart: '/p/register/start',
    registerFinish: '/p/register/finish',
    loginStart: '/p/login/start',
    loginFinish: '/p/login/finish',
    reauthStart: '/p/reauth/start',
    reauthFinish: '/p/reauth/finish',
    delete: '/p/delete/__id__',
};

const assertion = {
    id: 'Y3JlZC0x', rawId: new ArrayBuffer(8), type: 'public-key',
    response: {
        authenticatorData: new ArrayBuffer(8),
        clientDataJSON: new ArrayBuffer(8),
        signature: new ArrayBuffer(8),
        userHandle: null,
    },
};

const accepted = { rpId: 'example.com', userHandle: 'aGFuZGxl', acceptedCredentialIds: ['a2VlcA'] };

function withSignals(): Record<string, ReturnType<typeof vi.fn>> {
    const methods = {
        signalUnknownCredential: vi.fn().mockResolvedValue(undefined),
        signalAllAcceptedCredentials: vi.fn().mockResolvedValue(undefined),
        signalCurrentUserDetails: vi.fn().mockResolvedValue(undefined),
    };
    (globalThis as any).PublicKeyCredential = Object.assign(function () {}, methods);
    return methods;
}

function mockLogin(finish: { ok: boolean; status?: number; body: unknown }): void {
    (globalThis.fetch as any)
        .mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ challenge: 'YWJj', rpId: 'example.com', allowCredentials: [], challengeKey: 'k' }),
        })
        .mockResolvedValueOnce({
            ok: finish.ok,
            status: finish.status ?? 200,
            json: () => Promise.resolve(finish.body),
            text: () => Promise.resolve(JSON.stringify(finish.body)),
        });
    (navigator.credentials.get as any).mockResolvedValueOnce(assertion);
}

describe('signals', () => {
    let origFetch: typeof fetch;
    let origPublicKeyCredential: any;

    beforeEach(() => {
        origFetch = globalThis.fetch;
        origPublicKeyCredential = (globalThis as any).PublicKeyCredential;
        (globalThis as any).PublicKeyCredential = function () {};
        Object.defineProperty(globalThis, 'navigator', {
            value: { credentials: { create: vi.fn(), get: vi.fn() } },
            writable: true,
            configurable: true,
        });
        globalThis.fetch = vi.fn() as any;
    });

    afterEach(() => {
        globalThis.fetch = origFetch;
        (globalThis as any).PublicKeyCredential = origPublicKeyCredential;
        document.body.innerHTML = '';
    });

    it('does nothing in browsers without the Signal API', async () => {
        expect(await signalUnknownCredential('example.com', 'abc')).toBe(false);
        expect(await signalAcceptedCredentials(accepted)).toBe(false);
        expect(await signalCurrentUser({ ...accepted, name: 'a@example.com' })).toBe(false);
    });

    it('swallows a rejected signal', async () => {
        const m = withSignals();
        m.signalUnknownCredential.mockRejectedValueOnce(new DOMException('nope', 'SecurityError'));
        expect(await signalUnknownCredential('example.com', 'abc')).toBe(false);
    });

    it('signals an unknown credential when the server reports one', async () => {
        const m = withSignals();
        mockLogin({ ok: false, status: 400, body: { message: 'Unknown passkey credential.', error: 'unknown_credential' } });

        const err = await authenticate({ endpoints }).catch((e) => e);

        expect(err).toBeInstanceOf(PasskeyServerError);
        expect(err.code).toBe('unknown_credential');
        expect(m.signalUnknownCredential).toHaveBeenCalledWith({ rpId: 'example.com', credentialId: 'Y3JlZC0x' });
    });

    it('does not signal for other sign-in failures', async () => {
        const m = withSignals();
        mockLogin({ ok: false, status: 400, body: { message: 'Passkey verification failed.' } });

        await expect(authenticate({ endpoints })).rejects.toBeInstanceOf(PasskeyServerError);
        expect(m.signalUnknownCredential).not.toHaveBeenCalled();
    });

    it('does not signal when signals are turned off', async () => {
        const m = withSignals();
        mockLogin({ ok: false, status: 400, body: { error: 'unknown_credential' } });

        await expect(authenticate({ endpoints: { ...endpoints, signals: false } })).rejects.toBeInstanceOf(PasskeyServerError);
        expect(m.signalUnknownCredential).not.toHaveBeenCalled();
    });

    it('still rejects with the server error when the browser lacks the Signal API', async () => {
        mockLogin({ ok: false, status: 400, body: { error: 'unknown_credential' } });

        const err = await authenticate({ endpoints }).catch((e) => e);
        expect(err).toBeInstanceOf(PasskeyServerError);
        expect(err.code).toBe('unknown_credential');
    });

    it('signals the remaining credentials after a delete', async () => {
        const m = withSignals();
        (globalThis.fetch as any).mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ deleted: true, ...accepted }) });

        const result = await remove({ endpoints, id: 7 });

        expect(result.deleted).toBe(true);
        expect(m.signalAllAcceptedCredentials).toHaveBeenCalledWith({
            rpId: 'example.com',
            userId: 'aGFuZGxl',
            allAcceptedCredentialIds: ['a2VlcA'],
        });
    });

    it('does not signal after a delete answer without signal data', async () => {
        const m = withSignals();
        (globalThis.fetch as any).mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ deleted: true }) });

        await remove({ endpoints, id: 7 });

        expect(m.signalAllAcceptedCredentials).not.toHaveBeenCalled();
    });

    it('signals credentials and user details from the manager markup', async () => {
        const m = withSignals();
        const data = { ...accepted, name: 'alice@example.com', displayName: 'Alice' };
        document.body.innerHTML = `
            <meta name="passkeys-endpoints" content='${JSON.stringify(endpoints)}'>
            <div data-passkey-signals='${JSON.stringify(data)}'></div>
        `;

        bind();
        bind();
        await Promise.resolve();

        expect(m.signalAllAcceptedCredentials).toHaveBeenCalledTimes(1);
        expect(m.signalAllAcceptedCredentials).toHaveBeenCalledWith({
            rpId: 'example.com', userId: 'aGFuZGxl', allAcceptedCredentialIds: ['a2VlcA'],
        });
        expect(m.signalCurrentUserDetails).toHaveBeenCalledWith({
            rpId: 'example.com', userId: 'aGFuZGxl', name: 'alice@example.com', displayName: 'Alice',
        });
    });

    it('ignores the manager markup when signals are turned off', () => {
        const m = withSignals();
        document.body.innerHTML = `
            <meta name="passkeys-endpoints" content='${JSON.stringify({ ...endpoints, signals: false })}'>
            <div data-passkey-signals='${JSON.stringify({ ...accepted, name: 'a@example.com' })}'></div>
        `;

        bind();

        expect(m.signalAllAcceptedCredentials).not.toHaveBeenCalled();
        expect(m.signalCurrentUserDetails).not.toHaveBeenCalled();
    });
});
