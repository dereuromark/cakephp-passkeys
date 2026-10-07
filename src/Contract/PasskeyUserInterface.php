<?php

declare(strict_types=1);

namespace CakePasskeys\Contract;

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
     * The primary key of the user row, as stored in `passkeys.user_id`.
     * An integer or a string such as a UUID.
     *
     * @return string|int
     */
    public function getUserId(): string|int;
}
