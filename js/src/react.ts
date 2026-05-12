/**
 * React adapter — `usePasskey()` hook.
 *
 * Thin wrapper around core register/authenticate that tracks loading +
 * error state with React state primitives. The hook is the only export
 * because that matches every other React auth library and keeps the
 * adapter free of provider/context boilerplate.
 */

import { useCallback, useState } from 'react';
import { register, authenticate } from './core';
import type { Endpoints, PasskeyResult, AuthResult } from './types';

export interface UsePasskey {
    register: (opts: { name: string; emoji?: string; csrfToken?: string }) => Promise<PasskeyResult>;
    authenticate: () => Promise<AuthResult>;
    isLoading: boolean;
    error: Error | null;
}

export function usePasskey(endpoints: Endpoints): UsePasskey {
    const [isLoading, setLoading] = useState(false);
    const [error, setError] = useState<Error | null>(null);

    const doRegister = useCallback(async (opts: { name: string; emoji?: string; csrfToken?: string }) => {
        setLoading(true);
        setError(null);
        try {
            return await register({ ...opts, endpoints });
        } catch (e) {
            setError(e as Error);
            throw e;
        } finally {
            setLoading(false);
        }
    }, [endpoints]);

    const doAuthenticate = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            return await authenticate({ endpoints });
        } catch (e) {
            setError(e as Error);
            throw e;
        } finally {
            setLoading(false);
        }
    }, [endpoints]);

    return { register: doRegister, authenticate: doAuthenticate, isLoading, error };
}
