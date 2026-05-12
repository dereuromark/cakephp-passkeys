/**
 * Stimulus adapter — controller class consumers register on their app.
 *
 * The host page wires endpoints via a Stimulus value (`data-passkey-endpoints-value`)
 * and uses `data-action="click->passkey#register"` / `passkey#authenticate`
 * to drive the ceremonies. We deliberately do not auto-register the
 * controller so apps stay in control of their identifier namespace.
 */

import { Controller } from '@hotwired/stimulus';
import { register, authenticate } from './core';
import type { Endpoints } from './types';

export class PasskeyController extends Controller<HTMLElement> {
    static values = { endpoints: Object };
    declare readonly endpointsValue: Endpoints;

    async register(event: Event): Promise<void> {
        event.preventDefault();
        const target = event.currentTarget as HTMLElement;
        const name = target.dataset.name ?? window.prompt('Name this passkey') ?? '';
        if (!name) return;
        await register({ endpoints: this.endpointsValue, name });
        window.location.reload();
    }

    async authenticate(event: Event): Promise<void> {
        event.preventDefault();
        const result = await authenticate({ endpoints: this.endpointsValue });
        if (result.redirectTo) window.location.assign(result.redirectTo);
    }
}
