<?php

declare(strict_types=1);

namespace Passkeys\Contract;

interface PasskeyUserInterface
{
    /**
     * Stable, opaque handle for WebAuthn `user.id`.
     *
     * MUST be stable across logins for the same user and MUST NOT leak the
     * raw primary key (privacy + reassignment safety).
     *
     * @return string
     */
    public function getPasskeyUserHandle(): string;

    /**
     * @return string
     */
    public function getPasskeyDisplayEmail(): string;

    /**
     * @return string
     */
    public function getPasskeyDisplayName(): string;

    /**
     * @return bool
     */
    public function isPasskeyEligible(): bool;

    /**
     * The host user's primary key.
     *
     * v0.1 limitation: integer primary keys only. UUID / string IDs are not
     * supported yet — the migration stores `user_id` as INTEGER and several
     * controller paths cast through `(int)` already; widening the contract
     * is a v2 enhancement.
     *
     * @return int
     */
    public function getUserId(): int;
}
