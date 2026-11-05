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
        Configure::write('CakePasskeys.enabled', true);
        Configure::write('CakePasskeys.identityResolver', null);
        Configure::write('CakePasskeys.nudge', ['enabled' => true, 'redisplayAfterDays' => 14]);
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
    public function testHiddenWhenNudgeDisabled(): void
    {
        Configure::write('CakePasskeys.nudge.enabled', false);

        $this->assertSame('', trim($this->renderCell(userId: 1, loginCount: 0)));
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
     * @return void
     */
    public function testDeviceBoundOnlyShowsSecondPasskeyCopy(): void
    {
        $this->seedPasskey(1, ['backup_eligible' => false]);
        $html = $this->renderCell(userId: 1, loginCount: 5);
        $this->assertStringContainsString('data-passkey-nudge-reason="deviceBoundOnly"', $html);
        $this->assertStringContainsString('Add another passkey', $html);
        $this->assertStringContainsString('data-passkey-nudge-dismiss', $html);
    }

    /**
     * @return void
     */
    public function testNoPasskeyShowsDefaultCopy(): void
    {
        $html = $this->renderCell(userId: 1, loginCount: 5);
        $this->assertStringContainsString('data-passkey-nudge-reason="noPasskey"', $html);
        $this->assertStringContainsString('Sign in faster next time', $html);
    }

    /**
     * @return void
     */
    public function testHiddenOnceOnePasskeyMaySync(): void
    {
        $this->seedPasskey(1, ['backup_eligible' => false]);
        $this->assertStringContainsString('deviceBoundOnly', $this->renderCell(userId: 1, loginCount: 5));

        $this->seedPasskey(1, ['backup_eligible' => true, 'backed_up' => true]);
        $this->assertSame('', trim($this->renderCell(userId: 1, loginCount: 5)));
    }

    /**
     * Rows from before the backup flags were stored are not counted as device-bound.
     *
     * @return void
     */
    public function testUnknownBackupStateIsNotDeviceBound(): void
    {
        $this->seedPasskey(1, ['backup_eligible' => false]);
        $this->seedPasskey(1, ['backup_eligible' => null]);
        $this->assertSame('', trim($this->renderCell(userId: 1, loginCount: 5)));

        $this->getTableLocator()->get('CakePasskeys.Passkeys')->updateAll(['backup_eligible' => false], []);
        $this->assertStringContainsString('deviceBoundOnly', $this->renderCell(userId: 1, loginCount: 5));
    }

    /**
     * @return void
     */
    public function testDeviceBoundNudgeFollowsSwitch(): void
    {
        $this->seedPasskey(1, ['backup_eligible' => false]);
        $this->assertStringContainsString('deviceBoundOnly', $this->renderCell(userId: 1, loginCount: 5));

        Configure::write('CakePasskeys.nudge.deviceBoundOnly', false);
        $this->assertSame('', trim($this->renderCell(userId: 1, loginCount: 5)));
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
     * @param array<string, mixed> $overrides
     *
     * @return void
     */
    private function seedPasskey(int $userId, array $overrides = []): void
    {
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        $entity = $table->newEntity($overrides + [
            'user_id' => $userId,
            'credential_id' => random_bytes(16),
            'public_key' => random_bytes(77),
            'name' => 'X',
            'sign_count' => 0,
        ], ['accessibleFields' => ['*' => true]]);
        $table->saveOrFail($entity);
    }
}
