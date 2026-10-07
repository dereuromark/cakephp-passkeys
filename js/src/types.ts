export interface Endpoints {
    registerStart: string;
    registerFinish: string;
    loginStart: string;
    loginFinish: string;
    reauthStart: string;
    reauthFinish: string;
    /** URL with an `__id__` placeholder for the passkey id. */
    rename?: string;
    /** URL with an `__id__` placeholder for the passkey id. */
    delete?: string;
    /** CSRF token of the application, sent with every request. */
    csrfToken?: string;
}

export interface PasskeyResult {
    id: number;
    name: string;
    emoji: string | null;
    aaguidLabel: string | null;
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
