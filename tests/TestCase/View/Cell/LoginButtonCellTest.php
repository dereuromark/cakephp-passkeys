<?php

declare(strict_types=1);

namespace Passkeys\Test\TestCase\View\Cell;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use Passkeys\PasskeysPlugin;

class LoginButtonCellTest extends TestCase
{
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
    }

    /**
     * @return void
     */
    public function testRendersHiddenWithEndpoints(): void
    {
        $html = (string)(new View(new ServerRequest()))->cell('Passkeys.LoginButton');
        $this->assertStringContainsString('data-passkey-authenticate', $html);
        $this->assertStringContainsString('hidden', $html);
        $this->assertStringContainsString('data-passkey-endpoints', $html);
        $this->assertStringContainsString('login/start', $html);
    }

    /**
     * @return void
     */
    public function testRendersCustomClassAndLabel(): void
    {
        $html = (string)(new View(new ServerRequest()))->cell('Passkeys.LoginButton', [
            'class' => 'my-btn primary',
            'label' => 'Use a passkey',
        ]);
        $this->assertStringContainsString('class="my-btn primary"', $html);
        $this->assertStringContainsString('Use a passkey', $html);
    }

    /**
     * @return void
     */
    public function testDisabledRendersNothing(): void
    {
        Configure::write('Passkeys.enabled', false);
        $html = (string)(new View(new ServerRequest()))->cell('Passkeys.LoginButton');
        $this->assertSame('', trim($html));
    }
}
