<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase;

use Cake\Core\Configure;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\TestSuite\TestCase;
use CakePasskeys\CakePasskeysPlugin;

class CakePasskeysPluginTest extends TestCase
{
    public function testBootstrapMergesDefaults(): void
    {
        Configure::delete('CakePasskeys');
        $app = new class (CONFIG) extends BaseApplication
        {
            public function middleware(MiddlewareQueue $m): MiddlewareQueue
            {
                return $m;
            }
        };
        $plugin = new CakePasskeysPlugin();
        $plugin->bootstrap($app);

        $this->assertSame('localhost', Configure::read('CakePasskeys.rpId'));
        $this->assertSame('Users', Configure::read('CakePasskeys.users.table'));
        $this->assertSame(5, Configure::read('CakePasskeys.maxPerUser'));
        $this->assertSame(300, Configure::read('CakePasskeys.challengeTtl'));
        $this->assertSame('required', Configure::read('CakePasskeys.ceremony.userVerification'));
        $this->assertNull(Configure::read('CakePasskeys.tenancy.column'));
    }

    public function testHostConfigOverridesDefaults(): void
    {
        Configure::delete('CakePasskeys');
        Configure::write('CakePasskeys.maxPerUser', 10);
        $app = new class (CONFIG) extends BaseApplication
        {
            public function middleware(MiddlewareQueue $m): MiddlewareQueue
            {
                return $m;
            }
        };
        (new CakePasskeysPlugin())->bootstrap($app);
        $this->assertSame(10, Configure::read('CakePasskeys.maxPerUser'));
    }

    public function testJsVersionConstantExists(): void
    {
        $this->assertNotEmpty(CakePasskeysPlugin::JS_VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', CakePasskeysPlugin::JS_VERSION);
    }
}
