import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

describe('alpine directives', () => {
    let origAlpine: any;

    beforeEach(() => {
        origAlpine = (window as any).Alpine;
        vi.resetModules();
    });

    afterEach(() => {
        (window as any).Alpine = origAlpine;
    });

    it('registers three directives when window.Alpine is present', async () => {
        const directive = vi.fn();
        (window as any).Alpine = { directive };
        await import('../src/alpine');
        expect(directive).toHaveBeenCalledTimes(3);
        const names = directive.mock.calls.map((c) => c[0]);
        expect(names).toEqual(['passkey-register', 'passkey-authenticate', 'passkey-conditional']);
    });

    it('does nothing when window.Alpine is missing', async () => {
        delete (window as any).Alpine;
        await expect(import('../src/alpine')).resolves.toBeDefined();
    });
});
