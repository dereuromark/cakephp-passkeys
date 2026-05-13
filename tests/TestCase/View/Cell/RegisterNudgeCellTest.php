<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\View\Cell;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use Migrations\Migrations;

class RegisterNudgeCellTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakePasskeys.tenancy.column', null);
        Configure::write('CakePasskeys.nudge', [
            'enabled' => true,
            'afterLogins' => 3,
            'redisplayAfterDays' => 14,
        ]);
        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'CakePasskeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();
        $this->getTableLocator()->clear();
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
    public function testRendersWhenPolicyAllows(): void
    {
        $html = $this->renderCell(userId: 1, loginCount: 5);
        $this->assertStringContainsString('data-passkey-nudge', $html);
        $this->assertStringContainsString('data-passkey-register', $html);
    }

    /**
     * @return void
     */
    public function testHiddenWhenAnonymous(): void
    {
        $html = $this->renderCell(userId: null, loginCount: 5);
        $this->assertSame('', trim($html));
    }

    /**
     * @return void
     */
    public function testHiddenWhenPolicyRejects(): void
    {
        $html = $this->renderCell(userId: 1, loginCount: 1);
        $this->assertSame('', trim($html));
    }

    /**
     * @return void
     */
    public function testHiddenWhenUserHasPasskey(): void
    {
        $this->seedPasskey(1);
        $html = $this->renderCell(userId: 1, loginCount: 5);
        $this->assertSame('', trim($html));
    }

    /**
     * @param int|null $userId
     * @param int $loginCount
     *
     * @return string
     */
    private function renderCell(?int $userId, int $loginCount): string
    {
        $request = new ServerRequest();
        $session = $request->getSession();
        $session->write('CakePasskeys.loginCount', $loginCount);

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

        return (string)$view->cell('CakePasskeys.RegisterNudge');
    }

    /**
     * @param int $userId
     *
     * @return void
     */
    private function seedPasskey(int $userId): void
    {
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        $entity = $table->newEntity([
            'user_id' => $userId,
            'credential_id' => random_bytes(16),
            'public_key' => random_bytes(77),
            'name' => 'X',
            'sign_count' => 0,
        ], ['accessibleFields' => ['*' => true]]);
        $table->saveOrFail($entity);
    }
}
