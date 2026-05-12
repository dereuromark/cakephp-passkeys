import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { PasskeyController } from '../src/stimulus';

const endpoints = {
    registerStart: '/p/register/start',
    registerFinish: '/p/register/finish',
    loginStart: '/p/login/start',
    loginFinish: '/p/login/finish',
    reauthStart: '/p/reauth/start',
    reauthFinish: '/p/reauth/finish',
};

// Build a controller without Stimulus' application bootstrap — assign
// endpointsValue directly via a writable property on a bare instance.
function makeController(): PasskeyController {
    const c = Object.create(PasskeyController.prototype) as PasskeyController;
    Object.defineProperty(c, 'endpointsValue', { value: endpoints, writable: false });
    return c;
}

describe('stimulus PasskeyController', () => {
    let origFetch: typeof fetch;
    let origPublicKeyCredential: any;
    let origLocation: Location;

    beforeEach(() => {
        origFetch = globalThis.fetch;
        origPublicKeyCredential = (globalThis as any).PublicKeyCredential;
        origLocation = window.location;
        globalThis.fetch = vi.fn() as any;
    });

    afterEach(() => {
        globalThis.fetch = origFetch;
        (globalThis as any).PublicKeyCredential = origPublicKeyCredential;
        Object.defineProperty(window, 'location', { value: origLocation, writable: true });
    });

    it('exposes endpointsValue static config', () => {
        expect(PasskeyController.values).toEqual({ endpoints: Object });
    });

    it('authenticate calls fetch on the loginStart endpoint', async () => {
        (globalThis as any).PublicKeyCredential = function () {};
        const c = makeController();
        const preventDefault = vi.fn();
        (globalThis.fetch as any).mockResolvedValueOnce({
            ok: false, status: 500, text: () => Promise.resolve('boom'),
        });
        await expect(
            c.authenticate({ preventDefault, currentTarget: document.createElement('button') } as unknown as Event),
        ).rejects.toThrow();
        expect(preventDefault).toHaveBeenCalled();
        expect((globalThis.fetch as any).mock.calls[0][0]).toBe(endpoints.loginStart);
    });
});
