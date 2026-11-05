/**
 * WebAuthn Signal API (Chrome 132+): tells the authenticator which passkeys
 * the server still knows, so deleted ones disappear from the passkey list.
 * Every call is feature-detected and never throws.
 */

import type { Endpoints, SignalData, UserSignalData } from './types';

/** `error` in the JSON body of a sign-in that used a credential the server does not store. */
export const UNKNOWN_CREDENTIAL = 'unknown_credential';

export function signalsEnabled(endpoints?: Pick<Endpoints, 'signals'>): boolean {
    return endpoints?.signals !== false;
}

async function call(method: string, payload: Record<string, unknown>): Promise<boolean> {
    const pkc = (globalThis as any).PublicKeyCredential;
    if (!pkc || typeof pkc[method] !== 'function') return false;
    try {
        await pkc[method](payload);
        return true;
    } catch {
        // Best effort: a refused signal must not break the page.
        return false;
    }
}

export function signalUnknownCredential(rpId: string, credentialId: string): Promise<boolean> {
    if (!rpId || !credentialId) return Promise.resolve(false);
    return call('signalUnknownCredential', { rpId, credentialId });
}

export function signalAcceptedCredentials(data: SignalData): Promise<boolean> {
    if (!data.rpId || !data.userHandle || !Array.isArray(data.acceptedCredentialIds)) return Promise.resolve(false);
    return call('signalAllAcceptedCredentials', {
        rpId: data.rpId,
        userId: data.userHandle,
        allAcceptedCredentialIds: data.acceptedCredentialIds,
    });
}

export function signalCurrentUser(data: UserSignalData): Promise<boolean> {
    if (!data.rpId || !data.userHandle || !data.name) return Promise.resolve(false);
    return call('signalCurrentUserDetails', {
        rpId: data.rpId,
        userId: data.userHandle,
        name: data.name,
        displayName: data.displayName ?? data.name,
    });
}
