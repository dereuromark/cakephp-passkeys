export interface Endpoints {
    registerStart: string;
    registerFinish: string;
    loginStart: string;
    loginFinish: string;
    reauthStart: string;
    reauthFinish: string;
    /** Second factor after a password; only answers when the host configured it. */
    secondFactorStart?: string;
    secondFactorFinish?: string;
    /** URL with an `__id__` placeholder for the passkey id. */
    rename?: string;
    /** URL with an `__id__` placeholder for the passkey id. */
    delete?: string;
    /** CSRF token of the application, sent with every request. */
    csrfToken?: string;
    /** `false` turns the WebAuthn Signal API calls off. */
    signals?: boolean;
}

/** Signal API data the server sends; ids are base64url. */
export interface SignalData {
    rpId: string;
    userHandle: string;
    acceptedCredentialIds: string[];
}

export interface UserSignalData extends SignalData {
    name: string;
    displayName?: string;
}

export interface DeleteResult extends Partial<SignalData> {
    deleted: boolean;
}

export interface PasskeyResult {
    id: number;
    name: string;
    emoji: string | null;
    aaguidLabel: string | null;
    /** Whether the passkey can sync to other devices; null when not known yet. */
    backupEligible: boolean | null;
    /** Whether the passkey is synced right now; null when not known yet. */
    backedUp: boolean | null;
    createdAt: string;
    lastUsedAt: string | null;
}

export interface AuthResult {
    redirectTo: string;
    mfaSatisfied: boolean;
    userId: number | string;
}

export type Messages = Partial<{
    namePrompt: string;
    cancelled: string;
    notSupported: string;
    serverError: string;
}>;
