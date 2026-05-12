<?php

declare(strict_types=1);

namespace Passkeys\Contract;

interface RateLimiterInterface
{
    /**
     * Register a hit against the given key. Returns true when the request is
     * allowed, false when the limit is exhausted and the caller must block.
     *
     * @param string $key Stable key for the bucket (e.g. "passkeys.login.$ip").
     * @param int $maxAttempts Allowed attempts inside the decay window.
     * @param int $decaySeconds Length of the rolling window in seconds.
     *
     * @return bool True if allowed, false if rate-limited.
     */
    public function hit(string $key, int $maxAttempts, int $decaySeconds): bool;
}
