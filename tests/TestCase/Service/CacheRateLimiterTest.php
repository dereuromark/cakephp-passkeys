<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Service;

use Cake\Cache\Cache;
use Cake\Cache\CacheEngine;
use Cake\TestSuite\TestCase;
use CakePasskeys\Service\CacheRateLimiter;
use DateInterval;
use LogicException;

class CacheRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop(CacheRateLimiter::CACHE_CONFIG);
        Cache::setConfig(CacheRateLimiter::CACHE_CONFIG, ['className' => 'Array', 'duration' => 3600]);
    }

    protected function tearDown(): void
    {
        Cache::drop(CacheRateLimiter::CACHE_CONFIG);
        parent::tearDown();
    }

    public function testRefusesOnceTheBucketIsFull(): void
    {
        $limiter = $this->limiterAt(1_000_000);

        $this->assertTrue($limiter->hit('passkeys.login.1.2.3.4', 2, 60));
        $this->assertTrue($limiter->hit('passkeys.login.1.2.3.4', 2, 60));
        $this->assertFalse($limiter->hit('passkeys.login.1.2.3.4', 2, 60));
    }

    public function testBucketsAreSeparate(): void
    {
        $limiter = $this->limiterAt(1_000_000);
        $limiter->hit('passkeys.login.1.2.3.4', 1, 60);

        $this->assertTrue($limiter->hit('passkeys.login.5.6.7.8', 1, 60));
    }

    public function testCountsWithoutIncrementSupport(): void
    {
        Cache::drop(CacheRateLimiter::CACHE_CONFIG);
        Cache::setConfig(CacheRateLimiter::CACHE_CONFIG, [
            'className' => 'File',
            'path' => TMP . 'passkeys_rl_file' . DS,
            'duration' => 3600,
        ]);
        Cache::clear(CacheRateLimiter::CACHE_CONFIG);
        $limiter = $this->limiterAt(1_000_000);

        $this->assertTrue($limiter->hit('passkeys.login.file', 2, 60));
        $this->assertTrue($limiter->hit('passkeys.login.file', 2, 60));
        $this->assertFalse($limiter->hit('passkeys.login.file', 2, 60));
    }

    public function testRefusesWhenTheCountCannotBeStored(): void
    {
        Cache::drop(CacheRateLimiter::CACHE_CONFIG);
        $broken = new class extends CacheEngine {
            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                return false;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function increment(string $key, int $offset = 1): int|false
            {
                throw new LogicException('No increment.');
            }

            public function decrement(string $key, int $offset = 1): int|false
            {
                return false;
            }

            public function delete(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return false;
            }

            public function clearGroup(string $group): bool
            {
                return false;
            }
        };
        Cache::setConfig(CacheRateLimiter::CACHE_CONFIG, ['className' => $broken]);

        $this->assertFalse($this->limiterAt(1_000_000)->hit('passkeys.login.null', 5, 60));
    }

    public function testNewWindowStartsAFreshCount(): void
    {
        $this->limiterAt(1_000_020)->hit('passkeys.login.w', 1, 60);

        $this->assertFalse($this->limiterAt(1_000_030)->hit('passkeys.login.w', 1, 60));
        $this->assertTrue($this->limiterAt(1_000_080)->hit('passkeys.login.w', 1, 60));
    }

    /**
     * An engine that accepts writes but never returns them would restart the
     * count on every hit.
     *
     * @throws \LogicException
     */
    public function testRefusesWhenTheCountCannotBeReadBack(): void
    {
        $forgetful = new class extends CacheEngine {
            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function increment(string $key, int $offset = 1): int|false
            {
                throw new LogicException('No increment.');
            }

            public function decrement(string $key, int $offset = 1): int|false
            {
                return false;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function clearGroup(string $group): bool
            {
                return true;
            }
        };
        Cache::drop(CacheRateLimiter::CACHE_CONFIG);
        Cache::setConfig(CacheRateLimiter::CACHE_CONFIG, ['className' => $forgetful]);

        $this->assertFalse($this->limiterAt(1_000_000)->hit('passkeys.login.forgetful', 5, 60));
    }

    private function limiterAt(int $time): CacheRateLimiter
    {
        return new class ($time) extends CacheRateLimiter {
            public function __construct(private int $time)
            {
            }

            protected function now(): int
            {
                return $this->time;
            }
        };
    }
}
