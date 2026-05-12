import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { register, authenticate, isSupported } from '../src/core';
import { PasskeyServerError, PasskeyAbortedError, PasskeyNotSupportedError } from '../src/errors';

const mockEndpoints = {
    registerStart: '/passkeys/register/start',
    registerFinish: '/passkeys/register/finish',
    loginStart: '/passkeys/login/start',
    loginFinish: '/passkeys/login/finish',
    reauthStart: '/passkeys/reauth/start',
    reauthFinish: '/passkeys/reauth/finish',
};

describe('core', () => {
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
    });

    it('isSupported returns true when PublicKeyCredential is present', () => {
        expect(isSupported()).toBe(true);
    });

    it('isSupported returns false when PublicKeyCredential is missing', () => {
        delete (globalThis as any).PublicKeyCredential;
        expect(isSupported()).toBe(false);
    });

    it('register throws PasskeyNotSupportedError when WebAuthn unavailable', async () => {
        delete (globalThis as any).PublicKeyCredential;
        await expect(register({ endpoints: mockEndpoints, name: 'X' }))
            .rejects.toBeInstanceOf(PasskeyNotSupportedError);
    });

    it('register throws PasskeyServerError on non-2xx start', async () => {
        (globalThis.fetch as any).mockResolvedValueOnce({
            ok: false, status: 500, text: () => Promise.resolve('boom'),
        });
        await expect(register({ endpoints: mockEndpoints, name: 'X' }))
            .rejects.toBeInstanceOf(PasskeyServerError);
    });

    it('register propagates user-cancelled as PasskeyAbortedError', async () => {
        (globalThis.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({
                challenge: 'YWJj', user: { id: 'aGFuZGxl' },
                rp: { id: 'localhost' }, pubKeyCredParams: [],
                challengeKey: 'key-abc',
            }),
        });
        (navigator.credentials.create as any).mockRejectedValueOnce(
            new DOMException('cancelled', 'NotAllowedError'),
        );
        await expect(register({ endpoints: mockEndpoints, name: 'X' }))
            .rejects.toBeInstanceOf(PasskeyAbortedError);
    });

    it('register posts attestation to finish endpoint with challengeKey', async () => {
        (globalThis.fetch as any)
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    challenge: 'YWJj', user: { id: 'aGFuZGxl' },
                    rp: { id: 'localhost' }, pubKeyCredParams: [],
                    challengeKey: 'key-abc',
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ passkey: { id: 1, name: 'X', emoji: null, aaguidLabel: null, createdAt: '2026-05-12T00:00:00Z', lastUsedAt: null } }),
            });
        (navigator.credentials.create as any).mockResolvedValueOnce({
            id: 'cred-id',
            rawId: new ArrayBuffer(8),
            type: 'public-key',
            response: {
                attestationObject: new ArrayBuffer(8),
                clientDataJSON: new ArrayBuffer(8),
            },
        });
        const result = await register({ endpoints: mockEndpoints, name: 'My laptop' });
        expect(result.id).toBe(1);
        // verify the finish call included challengeKey
        const finishCall = (globalThis.fetch as any).mock.calls[1];
        const body = JSON.parse(finishCall[1].body);
        expect(body.challengeKey).toBe('key-abc');
        expect(body.name).toBe('My laptop');
    });

    it('authenticate posts assertion and returns redirectTo', async () => {
        (globalThis.fetch as any)
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ challenge: 'YWJj', allowCredentials: [], challengeKey: 'k' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ redirectTo: '/dashboard', mfaSatisfied: true, userId: 1 }),
            });
        (navigator.credentials.get as any).mockResolvedValueOnce({
            id: 'cred-id', rawId: new ArrayBuffer(8), type: 'public-key',
            response: {
                authenticatorData: new ArrayBuffer(8),
                clientDataJSON: new ArrayBuffer(8),
                signature: new ArrayBuffer(8),
                userHandle: new ArrayBuffer(8),
            },
        });
        const result = await authenticate({ endpoints: mockEndpoints });
        expect(result.redirectTo).toBe('/dashboard');
        expect(result.mfaSatisfied).toBe(true);
    });
});
