import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { usePasskey } from '../src/react';
import { PasskeyNotSupportedError } from '../src/errors';

const endpoints = {
    registerStart: '/p/register/start',
    registerFinish: '/p/register/finish',
    loginStart: '/p/login/start',
    loginFinish: '/p/login/finish',
    reauthStart: '/p/reauth/start',
    reauthFinish: '/p/reauth/finish',
};

function renderHook<T>(cb: () => T): { current: T } {
    const ref = { current: undefined as unknown as T };
    function Wrapper() {
        ref.current = cb();
        return null;
    }
    act(() => {
        TestRenderer.create(createElement(Wrapper));
    });
    return ref;
}

describe('react usePasskey', () => {
    let origPublicKeyCredential: any;

    beforeEach(() => {
        origPublicKeyCredential = (globalThis as any).PublicKeyCredential;
    });

    afterEach(() => {
        (globalThis as any).PublicKeyCredential = origPublicKeyCredential;
    });

    it('returns register/authenticate/isLoading/error', () => {
        const hook = renderHook(() => usePasskey(endpoints));
        expect(typeof hook.current.register).toBe('function');
        expect(typeof hook.current.authenticate).toBe('function');
        expect(hook.current.isLoading).toBe(false);
        expect(hook.current.error).toBeNull();
    });

    it('captures errors thrown by core into error state', async () => {
        delete (globalThis as any).PublicKeyCredential;
        const hook = renderHook(() => usePasskey(endpoints));
        await act(async () => {
            await expect(hook.current.register({ name: 'X' }))
                .rejects.toBeInstanceOf(PasskeyNotSupportedError);
        });
        expect(hook.current.error).toBeInstanceOf(PasskeyNotSupportedError);
        expect(hook.current.isLoading).toBe(false);
    });
});
