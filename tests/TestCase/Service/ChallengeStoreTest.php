<?php
declare(strict_types=1);

namespace Passkeys\Test\TestCase\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Passkeys\Service\ChallengeStore;

class ChallengeStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        Configure::write('Passkeys.cache', 'default');
        Configure::write('Passkeys.challengeTtl', 300);
    }

    public function testPutAndConsumeReturnsValue(): void
    {
        $store = new ChallengeStore();
        $key = $store->put(['challenge' => 'abc', 'origin' => 'https://localhost']);
        $this->assertNotEmpty($key);
        $this->assertSame(['challenge' => 'abc', 'origin' => 'https://localhost'], $store->consume($key));
    }

    public function testConsumeIsOneShot(): void
    {
        $store = new ChallengeStore();
        $key = $store->put(['x' => 1]);
        $store->consume($key);
        $this->assertNull($store->consume($key));
    }

    public function testConsumeUnknownKeyReturnsNull(): void
    {
        $this->assertNull((new ChallengeStore())->consume('does-not-exist'));
    }

    public function testKeysAreRandomAndOpaque(): void
    {
        $store = new ChallengeStore();
        $k1 = $store->put(['x' => 1]);
        $k2 = $store->put(['x' => 2]);
        $this->assertNotSame($k1, $k2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $k1);
    }
}
