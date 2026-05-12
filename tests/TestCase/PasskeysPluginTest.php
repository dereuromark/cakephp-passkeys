<?php
declare(strict_types=1);

namespace Passkeys\Test\TestCase;

use Cake\Core\Configure;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\TestSuite\TestCase;
use Passkeys\PasskeysPlugin;

class PasskeysPluginTest extends TestCase
{
    public function testBootstrapMergesDefaults(): void
    {
        Configure::delete('Passkeys');
        $app = new class (CONFIG) extends BaseApplication
        {
            public function middleware(MiddlewareQueue $m): MiddlewareQueue
            {
                return $m;
            }
        };
        $plugin = new PasskeysPlugin();
        $plugin->bootstrap($app);

        $this->assertSame('localhost', Configure::read('Passkeys.rpId'));
        $this->assertSame('Users', Configure::read('Passkeys.users.table'));
        $this->assertSame(5, Configure::read('Passkeys.maxPerUser'));
        $this->assertSame(300, Configure::read('Passkeys.challengeTtl'));
        $this->assertSame('required', Configure::read('Passkeys.ceremony.userVerification'));
        $this->assertNull(Configure::read('Passkeys.tenancy.column'));
    }

    public function testHostConfigOverridesDefaults(): void
    {
        Configure::delete('Passkeys');
        Configure::write('Passkeys.maxPerUser', 10);
        $app = new class (CONFIG) extends BaseApplication
        {
            public function middleware(MiddlewareQueue $m): MiddlewareQueue
            {
                return $m;
            }
        };
        (new PasskeysPlugin())->bootstrap($app);
        $this->assertSame(10, Configure::read('Passkeys.maxPerUser'));
    }

    public function testJsVersionConstantExists(): void
    {
        $this->assertNotEmpty(PasskeysPlugin::JS_VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', PasskeysPlugin::JS_VERSION);
    }
}
