import { describe, it, expect } from 'vitest';
import {
    PasskeyError,
    PasskeyNotSupportedError,
    PasskeyTimeoutError,
    PasskeyAbortedError,
    PasskeyInvalidStateError,
    PasskeyServerError,
    classify,
} from '../src/errors';

describe('PasskeyError hierarchy', () => {
    it('subclasses extend PasskeyError', () => {
        expect(new PasskeyNotSupportedError('x')).toBeInstanceOf(PasskeyError);
        expect(new PasskeyTimeoutError('x')).toBeInstanceOf(PasskeyError);
        expect(new PasskeyAbortedError('x')).toBeInstanceOf(PasskeyError);
        expect(new PasskeyInvalidStateError('x')).toBeInstanceOf(PasskeyError);
        expect(new PasskeyServerError('x', 500)).toBeInstanceOf(PasskeyError);
    });

    it('subclasses set name correctly', () => {
        expect(new PasskeyNotSupportedError('x').name).toBe('PasskeyNotSupportedError');
        expect(new PasskeyTimeoutError('x').name).toBe('PasskeyTimeoutError');
        expect(new PasskeyAbortedError('x').name).toBe('PasskeyAbortedError');
        expect(new PasskeyInvalidStateError('x').name).toBe('PasskeyInvalidStateError');
        expect(new PasskeyServerError('x', 500).name).toBe('PasskeyServerError');
    });

    it('PasskeyServerError carries status', () => {
        const e = new PasskeyServerError('boom', 503);
        expect(e.status).toBe(503);
        expect(e.message).toBe('boom');
    });

    it('classify maps DOMException to subclasses', () => {
        expect(classify(new DOMException('x', 'NotAllowedError'))).toBeInstanceOf(PasskeyAbortedError);
        expect(classify(new DOMException('x', 'AbortError'))).toBeInstanceOf(PasskeyAbortedError);
        expect(classify(new DOMException('x', 'TimeoutError'))).toBeInstanceOf(PasskeyTimeoutError);
        expect(classify(new DOMException('x', 'InvalidStateError'))).toBeInstanceOf(PasskeyInvalidStateError);
    });

    it('classify returns base PasskeyError for unknown', () => {
        expect(classify(new Error('random'))).toBeInstanceOf(PasskeyError);
        expect(classify('string error')).toBeInstanceOf(PasskeyError);
        expect(classify(null)).toBeInstanceOf(PasskeyError);
    });

    it('classify passes through existing PasskeyError', () => {
        const original = new PasskeyTimeoutError('original');
        expect(classify(original)).toBe(original);
    });
});
