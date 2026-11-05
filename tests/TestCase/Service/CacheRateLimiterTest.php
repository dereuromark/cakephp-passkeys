<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakePasskeys\Service\CacheRateLimiter;

class CacheRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('passkeys_rl_test');
        Cache::setConfig('passkeys_rl_test', ['className' => 'Array', 'duration' => 300]);
        Configure::write('CakePasskeys.cache', 'passkeys_rl_test');
    }

    protected function tearDown(): void
    {
        Cache::drop('passkeys_rl_test');
        Configure::write('CakePasskeys.cache', 'default');
        parent::tearDown();
    }

    public function testRefusesOnceTheBucketIsFull(): void
    {
        $limiter = new CacheRateLimiter();

        $this->assertTrue($limiter->hit('passkeys.login.1.2.3.4', 2, 60));
        $this->assertTrue($limiter->hit('passkeys.login.1.2.3.4', 2, 60));
        $this->assertFalse($limiter->hit('passkeys.login.1.2.3.4', 2, 60));
    }

    public function testBucketsAreSeparate(): void
    {
        $limiter = new CacheRateLimiter();
        $limiter->hit('passkeys.login.1.2.3.4', 1, 60);

        $this->assertTrue($limiter->hit('passkeys.login.5.6.7.8', 1, 60));
    }
}
