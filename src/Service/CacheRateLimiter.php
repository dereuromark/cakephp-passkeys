<?php

declare(strict_types=1);

namespace CakePasskeys\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use CakePasskeys\Contract\RateLimiterInterface;
use Throwable;

/**
 * Default limiter: a fixed window per bucket, counted in the
 * `passkeys_rate_limit` cache config (a copy of `CakePasskeys.cache` that
 * lives an hour, registered by the plugin). Counting is atomic on engines
 * that support increment() (Redis, Memcached, APCu); elsewhere it is best
 * effort.
 */
class CacheRateLimiter implements RateLimiterInterface
{
    /**
     * @var string
     */
    public const CACHE_CONFIG = 'passkeys_rate_limit';

    /**
     * @inheritDoc
     */
    public function hit(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $engine = Cache::getConfig(static::CACHE_CONFIG)
            ? static::CACHE_CONFIG
            : (string)Configure::read('CakePasskeys.cache', 'default');
        $window = intdiv(time(), max(1, $decaySeconds));
        $bucket = 'passkeys_rl_' . sha1($key) . '_' . $window;

        try {
            Cache::add($bucket, 0, $engine);
            $count = Cache::increment($bucket, 1, $engine);
        } catch (Throwable) {
            $count = false;
        }
        if ($count === false) {
            $count = (int)Cache::read($bucket, $engine) + 1;
            Cache::write($bucket, $count, $engine);
        }

        return $count <= $maxAttempts;
    }
}
