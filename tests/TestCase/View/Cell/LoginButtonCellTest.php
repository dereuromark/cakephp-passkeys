<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\View\Cell;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use CakePasskeys\CakePasskeysPlugin;

class LoginButtonCellTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakePasskeys.enabled', true);
        Router::reload();
        $builder = Router::createRouteBuilder('/');
        (new CakePasskeysPlugin())->routes($builder);
    }

    /**
     * @return void
     */
    public function testRendersHiddenWithEndpoints(): void
    {
        $html = (string)(new View(new ServerRequest()))->cell('CakePasskeys.LoginButton');
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
        $html = (string)(new View(new ServerRequest()))->cell('CakePasskeys.LoginButton', [
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
        Configure::write('CakePasskeys.enabled', false);
        $html = (string)(new View(new ServerRequest()))->cell('CakePasskeys.LoginButton');
        $this->assertSame('', trim($html));
    }
}
