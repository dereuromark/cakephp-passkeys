import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { usePasskey } from '../src/vue';
import { PasskeyNotSupportedError } from '../src/errors';

const endpoints = {
    registerStart: '/p/register/start',
    registerFinish: '/p/register/finish',
    loginStart: '/p/login/start',
    loginFinish: '/p/login/finish',
    reauthStart: '/p/reauth/start',
    reauthFinish: '/p/reauth/finish',
};

describe('vue usePasskey', () => {
    let origPublicKeyCredential: any;

    beforeEach(() => {
        origPublicKeyCredential = (globalThis as any).PublicKeyCredential;
    });

    afterEach(() => {
        (globalThis as any).PublicKeyCredential = origPublicKeyCredential;
    });

    it('returns refs for isLoading and error plus two async functions', () => {
        const api = usePasskey(endpoints);
        expect(typeof api.register).toBe('function');
        expect(typeof api.authenticate).toBe('function');
        expect(api.isLoading.value).toBe(false);
        expect(api.error.value).toBeNull();
    });

    it('captures errors into error.value and clears isLoading', async () => {
        delete (globalThis as any).PublicKeyCredential;
        const api = usePasskey(endpoints);
        await expect(api.register({ name: 'X' }))
            .rejects.toBeInstanceOf(PasskeyNotSupportedError);
        expect(api.error.value).toBeInstanceOf(PasskeyNotSupportedError);
        expect(api.isLoading.value).toBe(false);
    });
});
