<?php
declare(strict_types=1);

namespace Passkeys\Test\TestCase\View\Helper;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use Passkeys\PasskeysPlugin;
use Passkeys\View\Helper\PasskeysHelper;

class PasskeysHelperTest extends TestCase
{
    private PasskeysHelper $helper;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Passkeys.enabled', true);
        Router::reload();
        $builder = Router::createRouteBuilder('/');
        (new PasskeysPlugin())->routes($builder);

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
     * @return void
     */
    public function testScriptEmptyWhenDisabled(): void
    {
        Configure::write('Passkeys.enabled', false);
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
    public function testReauthGuardEmitsForm(): void
    {
        $html = $this->helper->reauthGuard('delete-account');
        $this->assertStringContainsString('data-passkey-reauth-required="delete-account"', $html);
    }

    /**
     * @return void
     */
    public function testJsVersionMatchesPluginConstant(): void
    {
        $this->assertSame(PasskeysPlugin::JS_VERSION, $this->helper->jsVersion());
    }

    /**
     * @return void
     */
    public function testIsEnabledReflectsConfig(): void
    {
        $this->assertTrue($this->helper->isEnabled());
        Configure::write('Passkeys.enabled', false);
        $this->assertFalse($this->helper->isEnabled());
    }
}
