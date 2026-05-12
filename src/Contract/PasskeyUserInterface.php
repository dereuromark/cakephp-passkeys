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
     * @return int|string
     */
    public function getUserId(): int|string;
}
