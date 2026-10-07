<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\View\Cell;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use CakePasskeys\CakePasskeysPlugin;
use Migrations\Migrations;

class ManagerCellTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakePasskeys.enabled', true);
        Configure::write('CakePasskeys.maxPerUser', 5);
        Configure::write('CakePasskeys.docsUrl', null);

        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'CakePasskeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();
        $this->getTableLocator()->clear();

        // FormHelper::postLink resolves URLs through Router; the cell test
        // exercises that path without an IntegrationTestTrait-bootstrapped
        // app, so we set up the plugin's routes explicitly.
        Router::reload();
        $builder = Router::createRouteBuilder('/');
        (new CakePasskeysPlugin())->routes($builder);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->getTableLocator()->clear();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testEmptyStateRenders(): void
    {
        $html = $this->renderCell(userId: 1);
        $this->assertStringContainsString('data-passkey-register', $html);
        $this->assertStringContainsString('haven', $html);
    }

    /**
     * @return void
     */
    public function testEmptyStateHidesDocsLinkWhenNull(): void
    {
        $html = $this->renderCell(userId: 1);
        $this->assertStringNotContainsString('What', $html);
    }

    /**
     * @return void
     */
    public function testEmptyStateShowsDocsLinkWhenConfigured(): void
    {
        Configure::write('CakePasskeys.docsUrl', 'https://docs.example.com/passkeys');
        $html = $this->renderCell(userId: 1);
        $this->assertStringContainsString('docs.example.com/passkeys', $html);
    }

    /**
     * @return void
     */
    public function testPopulatedStateShowsRows(): void
    {
        $this->seedPasskey(1, ['name' => 'My laptop', 'aaguid_label' => 'iCloud Keychain', 'emoji' => '💻']);
        $html = $this->renderCell(userId: 1);
        $this->assertStringContainsString('My laptop', $html);
        $this->assertStringContainsString('iCloud Keychain', $html);
        $this->assertStringContainsString('💻', $html);
    }

    /**
     * @return void
     */
    public function testDisabledRendersNothing(): void
    {
        Configure::write('CakePasskeys.enabled', false);
        $this->assertSame('', trim($this->renderCell(userId: 1)));
    }

    /**
     * @return void
     */
    public function testAnonymousRendersNothing(): void
    {
        $this->assertSame('', trim($this->renderCell(userId: null)));
    }

    /**
     * @return void
     */
    public function testAddCtaHiddenAtCap(): void
    {
        Configure::write('CakePasskeys.maxPerUser', 1);
        $this->seedPasskey(1, ['name' => 'Only one']);
        $html = $this->renderCell(userId: 1);
        $this->assertStringNotContainsString('data-passkey-register', $html);
        $this->assertStringContainsString('reached', $html);
    }

    /**
     * @param int|null $userId
     *
     * @return string
     */
    private function renderCell(?int $userId): string
    {
        $request = new ServerRequest();
        if ($userId !== null) {
            $identity = new class ($userId) {
                public function __construct(private int $id)
                {
                }

                public function getIdentifier(): int
                {
                    return $this->id;
                }
            };
            $request = $request->withAttribute('identity', $identity);
        }
        $view = new View($request);

        return (string)$view->cell('CakePasskeys.Manager');
    }

    /**
     * @param int $userId
     * @param array<string, mixed> $overrides
     *
     * @return int
     */
    private function seedPasskey(int $userId, array $overrides = []): int
    {
        $data = array_merge([
            'user_id' => $userId,
            'credential_id' => random_bytes(16),
            'public_key' => random_bytes(77),
            'name' => 'Passkey ' . $userId,
            'sign_count' => 0,
        ], $overrides);
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        $entity = $table->newEntity($data, ['accessibleFields' => ['*' => true]]);
        $table->saveOrFail($entity);

        return (int)$entity->id;
    }
}
