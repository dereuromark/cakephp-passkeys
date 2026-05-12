<?php
declare(strict_types=1);

namespace Passkeys\Test\TestCase\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\TestSuite\TestCase;
use Passkeys\PasskeysPlugin;
use Passkeys\Service\ChallengeStore;

class ChallengeStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        Configure::write('Passkeys.cache', 'default');
        Configure::write('Passkeys.challengeTtl', 300);
        // The plugin bootstrap registers `passkeys_challenges` against the
        // base engine; re-register here so unit tests of ChallengeStore can
        // run without booting the full plugin.
        Cache::drop('passkeys_challenges');
        Cache::setConfig('passkeys_challenges', ['className' => 'Array', 'duration' => 300]);
    }

    protected function tearDown(): void
    {
        Cache::drop('passkeys_challenges');
        parent::tearDown();
    }

    public function testBootstrapRegistersChallengeCacheWithConfiguredTtl(): void
    {
        // Drop the test's pre-registration, then boot the plugin and re-assert.
        Cache::drop('passkeys_challenges');
        Configure::write('Passkeys.cache', 'default');
        Configure::write('Passkeys.challengeTtl', 300);

        $app = new class (CONFIG) extends BaseApplication
        {
            public function middleware(MiddlewareQueue $m): MiddlewareQueue
            {
                return $m;
            }
        };
        (new PasskeysPlugin())->bootstrap($app);

        $config = Cache::getConfig('passkeys_challenges');
        $this->assertIsArray($config);
        $this->assertSame(300, (int)($config['duration'] ?? 0));
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
