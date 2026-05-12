/**
 * Vue 3 adapter — `usePasskey()` composable.
 *
 * Mirrors the React hook with ref()s in place of useState. No reactive
 * setup beyond two booleans is needed; consumers integrate the returned
 * refs into their own template state.
 */

import { ref } from 'vue';
import { register, authenticate } from './core';
import type { Endpoints } from './types';

export function usePasskey(endpoints: Endpoints) {
    const isLoading = ref(false);
    const error = ref<Error | null>(null);

    async function doRegister(opts: { name: string; emoji?: string; csrfToken?: string }) {
        isLoading.value = true;
        error.value = null;
        try { return await register({ ...opts, endpoints }); }
        catch (e) { error.value = e as Error; throw e; }
        finally { isLoading.value = false; }
    }

    async function doAuthenticate() {
        isLoading.value = true;
        error.value = null;
        try { return await authenticate({ endpoints }); }
        catch (e) { error.value = e as Error; throw e; }
        finally { isLoading.value = false; }
    }

    return { register: doRegister, authenticate: doAuthenticate, isLoading, error };
}
