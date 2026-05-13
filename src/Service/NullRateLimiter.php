<?php

declare(strict_types=1);

namespace CakePasskeys\Service;

use CakePasskeys\Contract\RateLimiterInterface;

class NullRateLimiter implements RateLimiterInterface
{
    /**
     * @inheritDoc
     */
    public function hit(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return true;
    }
}
