<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\View\Helper;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use CakePasskeys\CakePasskeysPlugin;
use CakePasskeys\View\Helper\PasskeysHelper;

class PasskeysHelperTest extends TestCase
{
    private PasskeysHelper $helper;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakePasskeys.enabled', true);
        Configure::write('App.jsBaseUrl', 'js/');
        // Reset the URL prefix to the default — one test toggles it to
        // /auth/passkeys to prove the helper honors the override.
        Configure::write('CakePasskeys.urlPrefix', '/passkeys');
        Router::reload();
        $builder = Router::createRouteBuilder('/');
        (new CakePasskeysPlugin())->routes($builder);

        $request = (new ServerRequest())->withAttribute('csp_nonce', 'abc123');
        $view = new View($request);
        $this->helper = new PasskeysHelper($view);
    }

    /**
     * @return void
     */
    public function testScriptTagEmitsNonce(): void
    {
        $html = $this->helper->script();
        $this->assertStringContainsString('passkeys.min.js', $html);
        $this->assertStringContainsString('nonce="abc123"', $html);
    }

    /**
     * The bundle is a plugin asset, served from the plugin's webroot.
     *
     * @return void
     */
    public function testScriptPointsAtThePluginAsset(): void
    {
        $this->assertStringContainsString('/cake_passkeys/js/passkeys.min.js', $this->helper->script());
    }

    /**
     * @return void
     */
    public function testScriptEmptyWhenDisabled(): void
    {
        Configure::write('CakePasskeys.enabled', false);
        $this->assertSame('', $this->helper->script());
    }

    /**
     * @return void
     */
    public function testAutofillAttributeReturnsArray(): void
    {
        $a = $this->helper->autofillAttribute();
        $this->assertSame('username webauthn', $a['autocomplete']);
        $this->assertTrue($a['data-passkey-conditional']);
    }

    /**
     * @return void
     */
    public function testEndpointsMetaContainsUrls(): void
    {
        $html = $this->helper->endpointsMeta();
        $this->assertStringContainsString('passkeys-endpoints', $html);
        $this->assertStringContainsString('login/start', $html);
    }

    /**
     * @return void
     */
    public function testReauthAttributesNameTheAction(): void
    {
        $this->assertSame(
            ['data-passkey-reauth-required' => 'delete-account'],
            $this->helper->reauthAttributes('delete-account'),
        );
    }

    /**
     * The JavaScript sends this token, so the endpoints work with the
     * application's CSRF protection left on.
     *
     * @return void
     */
    public function testEndpointsMetaCarriesCsrfTokenAndManagementUrls(): void
    {
        $request = (new ServerRequest())->withAttribute('csrfToken', 'tok<en');
        $helper = new PasskeysHelper(new View($request));

        $html = $helper->endpointsMeta();

        $this->assertSame(1, preg_match('/content="([^"]*)"/', $html, $match));
        $config = json_decode(html_entity_decode($match[1] ?? ''), true);
        $this->assertSame('tok<en', $config['csrfToken']);
        $this->assertSame('/passkeys/rename/__id__', $config['rename']);
        $this->assertSame('/passkeys/delete/__id__', $config['delete']);
        $this->assertStringNotContainsString('tok<en', $html);
    }

    /**
     * @return void
     */
    public function testJsVersionMatchesPluginConstant(): void
    {
        $this->assertSame(CakePasskeysPlugin::JS_VERSION, $this->helper->jsVersion());
    }

    /**
     * @return void
     */
    public function testIsEnabledReflectsConfig(): void
    {
        $this->assertTrue($this->helper->isEnabled());
        Configure::write('CakePasskeys.enabled', false);
        $this->assertFalse($this->helper->isEnabled());
    }
}
