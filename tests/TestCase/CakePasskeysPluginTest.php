<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase;

use Cake\Core\Configure;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\I18n\I18n;
use Cake\TestSuite\TestCase;
use CakePasskeys\CakePasskeysPlugin;
use function Cake\I18n\__d;

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

    public function testBootstrapLoadsPluginTranslations(): void
    {
        $app = new class (CONFIG) extends BaseApplication
        {
            public function middleware(MiddlewareQueue $m): MiddlewareQueue
            {
                return $m;
            }
        };
        $app->addPlugin(CakePasskeysPlugin::class);
        // A host app's own locale folder, without any passkeys.po in it.
        $appLocales = TMP . 'app_locales' . DS;
        if (!is_dir($appLocales)) {
            mkdir($appLocales, 0777, true);
        }
        Configure::write('App.paths.locales', [$appLocales]);
        I18n::clear();
        (new CakePasskeysPlugin())->bootstrap($app);
        $locale = I18n::getLocale();
        I18n::setLocale('de_DE');
        try {
            $this->assertSame('Hinzugefügt', __d('passkeys', 'Added'));
        } finally {
            I18n::setLocale($locale);
            Configure::delete('App.paths.locales');
            I18n::clear();
        }
    }

    public function testJsVersionConstantExists(): void
    {
        $this->assertNotEmpty(CakePasskeysPlugin::JS_VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', CakePasskeysPlugin::JS_VERSION);
    }
}
