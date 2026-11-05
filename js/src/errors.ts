export class PasskeyError extends Error {
    constructor(message: string) {
        super(message);
        this.name = new.target.name;
    }
}

export class PasskeyNotSupportedError extends PasskeyError {}
export class PasskeyTimeoutError extends PasskeyError {}
export class PasskeyAbortedError extends PasskeyError {}
export class PasskeyInvalidStateError extends PasskeyError {}

export class PasskeyServerError extends PasskeyError {
    /** Machine-readable `error` from a JSON error body, e.g. `unknown_credential`. */
    public code?: string;

    constructor(message: string, public status: number, code?: string) {
        super(message);
        if (code !== undefined) this.code = code;
    }
}

export function classify(err: unknown): PasskeyError {
    if (err instanceof PasskeyError) return err;
    if (err instanceof DOMException) {
        switch (err.name) {
            case 'NotAllowedError':
            case 'AbortError':
                return new PasskeyAbortedError(err.message);
            case 'TimeoutError':
                return new PasskeyTimeoutError(err.message);
            case 'InvalidStateError':
                return new PasskeyInvalidStateError(err.message);
        }
    }
    if (err instanceof Error) return new PasskeyError(err.message);
    return new PasskeyError(typeof err === 'string' ? err : 'Unknown passkey error');
}
