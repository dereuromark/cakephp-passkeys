/**
 * Passkey / WebAuthn ceremony helpers.
 *
 * Drives both registration and login round-trips. The server endpoints speak
 * base64url-encoded binary (matching the WebAuthn JS API), so this file's
 * job is mostly: fetch JSON -> call navigator.credentials.{create,get} ->
 * repackage the resulting ArrayBuffers as base64url -> POST back.
 */

import {
    classify,
    PasskeyAbortedError,
    PasskeyNotSupportedError,
    PasskeyServerError,
} from './errors';
import type { AuthResult, Endpoints, Messages, PasskeyResult } from './types';

// --- base64url helpers (ported verbatim from passkey.js) ------------------

function b64ToBytes(b64url: string): Uint8Array {
    const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
    const pad = b64.length % 4 ? '='.repeat(4 - (b64.length % 4)) : '';
    const bin = atob(b64 + pad);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
}

function bytesToB64(bytes: ArrayBuffer | Uint8Array): string {
    const arr = bytes instanceof ArrayBuffer ? new Uint8Array(bytes) : bytes;
    let s = '';
    for (let i = 0; i < arr.length; i++) s += String.fromCharCode(arr[i]!);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function reviveCreateOptions(publicKey: any): any {
    publicKey.challenge = b64ToBytes(publicKey.challenge);
    if (publicKey.user && typeof publicKey.user.id === 'string') {
        publicKey.user.id = b64ToBytes(publicKey.user.id);
    }
    if (Array.isArray(publicKey.excludeCredentials)) {
        publicKey.excludeCredentials = publicKey.excludeCredentials.map((c: any) => ({ ...c, id: b64ToBytes(c.id) }));
    }
    return publicKey;
}

function reviveGetOptions(publicKey: any): any {
    publicKey.challenge = b64ToBytes(publicKey.challenge);
    if (Array.isArray(publicKey.allowCredentials)) {
        publicKey.allowCredentials = publicKey.allowCredentials.map((c: any) => ({ ...c, id: b64ToBytes(c.id) }));
    }
    return publicKey;
}

function serializeAttestation(cred: PublicKeyCredential): any {
    const r = cred.response as AuthenticatorAttestationResponse;
    const transports = typeof (r as any).getTransports === 'function' ? (r as any).getTransports() : [];
    return {
        id: cred.id,
        rawId: bytesToB64(cred.rawId),
        type: cred.type,
        response: {
            clientDataJSON: bytesToB64(r.clientDataJSON),
            attestationObject: bytesToB64(r.attestationObject),
            transports,
        },
    };
}

function serializeAssertion(cred: PublicKeyCredential): any {
    const r = cred.response as AuthenticatorAssertionResponse;
    return {
        id: cred.id,
        rawId: bytesToB64(cred.rawId),
        type: cred.type,
        response: {
            clientDataJSON: bytesToB64(r.clientDataJSON),
            authenticatorData: bytesToB64(r.authenticatorData),
            signature: bytesToB64(r.signature),
            userHandle: r.userHandle ? bytesToB64(r.userHandle) : null,
        },
    };
}

async function postJson(url: string, csrfToken?: string, body?: unknown): Promise<any> {
    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
    const init: RequestInit = { method: 'POST', credentials: 'same-origin', headers };
    if (body !== undefined) init.body = JSON.stringify(body);
    const res = await fetch(url, init);
    if (!res.ok) throw new PasskeyServerError(await res.text(), res.status);
    return res.json();
}

// --- capability probes ----------------------------------------------------

export function isSupported(): boolean {
    return typeof globalThis !== 'undefined' && 'PublicKeyCredential' in globalThis;
}

export async function isConditionalSupported(): Promise<boolean> {
    if (!isSupported()) return false;
    const c = (globalThis as any).PublicKeyCredential;
    return typeof c.isConditionalMediationAvailable === 'function'
        && await c.isConditionalMediationAvailable();
}

export async function isPlatformAuthenticatorAvailable(): Promise<boolean> {
    if (!isSupported()) return false;
    const c = (globalThis as any).PublicKeyCredential;
    return typeof c.isUserVerifyingPlatformAuthenticatorAvailable === 'function'
        && await c.isUserVerifyingPlatformAuthenticatorAvailable();
}

// --- option types ---------------------------------------------------------

export interface RegisterOpts {
    endpoints: Endpoints;
    name: string;
    emoji?: string;
    csrfToken?: string;
    messages?: Messages;
}

export interface AuthOpts {
    endpoints: Endpoints;
    emailHint?: string;
    csrfToken?: string;
    messages?: Messages;
}

export interface ConditionalOpts {
    endpoints: Endpoints;
    signal?: AbortSignal;
    onMatch: (r: AuthResult) => void;
    csrfToken?: string;
}

export interface ReauthOpts {
    endpoints: Endpoints;
    action?: string;
    csrfToken?: string;
}

// --- ceremonies -----------------------------------------------------------

function token(opts: { endpoints: Endpoints; csrfToken?: string }): string | undefined {
    return opts.csrfToken ?? opts.endpoints.csrfToken;
}

function notSupported(): never {
    throw new PasskeyNotSupportedError('Passkeys are not supported in this browser.');
}

export async function register(opts: RegisterOpts): Promise<PasskeyResult> {
    if (!isSupported()) notSupported();

    const options = await postJson(opts.endpoints.registerStart, token(opts));
    const publicKey = reviveCreateOptions(options);

    let credential: PublicKeyCredential;
    try {
        const got = await navigator.credentials.create({ publicKey });
        if (!got) throw new PasskeyAbortedError('Passkey creation cancelled.');
        credential = got as PublicKeyCredential;
    } catch (e) {
        throw classify(e);
    }

    const data = await postJson(opts.endpoints.registerFinish, token(opts), {
        response: serializeAttestation(credential),
        name: opts.name,
        emoji: opts.emoji,
        challengeKey: options.challengeKey,
    });
    return data.passkey;
}

export async function authenticate(opts: AuthOpts): Promise<AuthResult> {
    if (!isSupported()) notSupported();

    const startBody = opts.emailHint ? { emailHint: opts.emailHint } : undefined;
    const options = await postJson(opts.endpoints.loginStart, token(opts), startBody);
    const publicKey = reviveGetOptions(options);

    let credential: PublicKeyCredential;
    try {
        const got = await navigator.credentials.get({ publicKey });
        if (!got) throw new PasskeyAbortedError('Passkey sign-in cancelled.');
        credential = got as PublicKeyCredential;
    } catch (e) {
        throw classify(e);
    }

    return await postJson(opts.endpoints.loginFinish, token(opts), {
        response: serializeAssertion(credential),
        challengeKey: options.challengeKey,
    }) as AuthResult;
}

export async function conditional(opts: ConditionalOpts): Promise<void> {
    if (!isSupported()) return;

    const options = await postJson(opts.endpoints.loginStart, token(opts));
    const publicKey = reviveGetOptions(options);

    let credential: PublicKeyCredential | null;
    try {
        credential = await navigator.credentials.get({
            publicKey,
            mediation: 'conditional',
            signal: opts.signal,
        } as CredentialRequestOptions) as PublicKeyCredential | null;
    } catch (e) {
        if (opts.signal?.aborted) return;
        if (e instanceof DOMException && e.name === 'AbortError') return;
        throw classify(e);
    }
    if (!credential) return;

    const result = await postJson(opts.endpoints.loginFinish, token(opts), {
        response: serializeAssertion(credential),
        challengeKey: options.challengeKey,
    }) as AuthResult;
    opts.onMatch(result);
}

export async function reauth(opts: ReauthOpts): Promise<{ ok: true; until: string }> {
    if (!isSupported()) notSupported();

    const options = await postJson(opts.endpoints.reauthStart, token(opts), { action: opts.action });
    const publicKey = reviveGetOptions(options);

    let credential: PublicKeyCredential;
    try {
        const got = await navigator.credentials.get({ publicKey });
        if (!got) throw new PasskeyAbortedError('Passkey reauth cancelled.');
        credential = got as PublicKeyCredential;
    } catch (e) {
        throw classify(e);
    }

    return await postJson(opts.endpoints.reauthFinish, token(opts), {
        response: serializeAssertion(credential),
        challengeKey: options.challengeKey,
        action: opts.action,
    }) as { ok: true; until: string };
}

// --- management -----------------------------------------------------------

export interface ManageOpts {
    endpoints: Endpoints;
    id: number | string;
    csrfToken?: string;
}

function withId(template: string | undefined, id: number | string): string {
    if (!template) throw new PasskeyServerError('Endpoint is not configured.', 0);
    return template.replace('__id__', encodeURIComponent(String(id)));
}

export async function rename(opts: ManageOpts & { name: string; emoji?: string | null }): Promise<PasskeyResult> {
    const body: Record<string, unknown> = { name: opts.name };
    if (opts.emoji !== undefined) body.emoji = opts.emoji;
    const data = await postJson(withId(opts.endpoints.rename, opts.id), opts.csrfToken ?? opts.endpoints.csrfToken, body);
    return data.passkey;
}

export async function remove(opts: ManageOpts): Promise<void> {
    await postJson(withId(opts.endpoints.delete, opts.id), opts.csrfToken ?? opts.endpoints.csrfToken, {});
}
