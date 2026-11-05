<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;
use CakePasskeys\Command\PasskeysRevokeCommand;
use CakePasskeys\Event\PasskeyEvent;
use CakePasskeys\Test\PasskeyCommandFixtureTrait;

class PasskeysRevokeCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use PasskeyCommandFixtureTrait;

    /**
     * @var array<\Cake\Event\EventInterface<\CakePasskeys\Command\PasskeysRevokeCommand>>
     */
    protected array $events = [];

    /**
     * @var \Closure
     */
    protected $listener;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchema();
        $this->events = [];
        $this->listener = function (EventInterface $event): void {
            $this->events[] = $event;
        };
        EventManager::instance()->on('CakePasskeys.afterDelete', $this->listener);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        EventManager::instance()->off('CakePasskeys.afterDelete', $this->listener);
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testRevokeOnePasskey(): void
    {
        $laptop = $this->seedPasskey(1, 'Laptop');
        $this->seedPasskey(1, 'Phone');

        $this->exec('passkeys revoke 1 ' . $laptop);

        $this->assertExitSuccess();
        $this->assertOutputContains('Deleted passkey ' . $laptop . ' (Laptop).');
        $this->assertSame(1, $this->countPasskeys(1));
        $this->assertCount(1, $this->events);
        $this->assertInstanceOf(PasskeysRevokeCommand::class, $this->events[0]->getSubject());
        $payload = $this->events[0]->getData('event');
        $this->assertInstanceOf(PasskeyEvent::class, $payload);
        $this->assertSame($laptop, $payload->getPasskey()->id);
        $this->assertSame(64, strlen($payload->getUserHandle()));
    }

    /**
     * @return void
     */
    public function testRevokeRefusesPasskeyOfAnotherUser(): void
    {
        $bob = $this->seedPasskey(2, 'Bob key');

        $this->exec('passkeys revoke 1 ' . $bob);

        $this->assertExitError();
        $this->assertErrorContains('User 1 has no passkey ' . $bob . '.');
        $this->assertSame(1, $this->countPasskeys(2));
        $this->assertCount(0, $this->events);
    }

    /**
     * @return void
     */
    public function testRevokeAllAsksFirst(): void
    {
        $this->seedPasskey(1, 'Laptop');
        $this->seedPasskey(1, 'Phone');

        $this->exec('passkeys revoke 1', ['n']);

        $this->assertExitSuccess();
        $this->assertOutputContains('Delete all 2 passkeys of user 1?');
        $this->assertOutputContains('Nothing deleted.');
        $this->assertSame(2, $this->countPasskeys(1));

        $this->exec('passkeys revoke 1', ['y']);

        $this->assertExitSuccess();
        $this->assertSame(0, $this->countPasskeys(1));
        $this->assertCount(2, $this->events);
    }

    /**
     * @return void
     */
    public function testRevokeAllWithYesSkipsPrompt(): void
    {
        $this->seedPasskey(1, 'Laptop');
        $this->seedPasskey(1, 'Phone');
        $this->seedPasskey(2, 'Bob key');

        $this->exec('passkeys revoke 1 --yes');

        $this->assertExitSuccess();
        $this->assertOutputNotContains('Delete all');
        $this->assertSame(0, $this->countPasskeys(1));
        $this->assertSame(1, $this->countPasskeys(2));
        $this->assertCount(2, $this->events);
    }

    /**
     * @return void
     */
    public function testRevokeForMissingUserPassesEmptyHandle(): void
    {
        $id = $this->seedPasskey(2, 'Orphan');
        $conn = ConnectionManager::get('test');
        assert($conn instanceof Connection);
        $conn->delete('users', ['id' => 2]);

        $this->exec('passkeys revoke 2 ' . $id);

        $this->assertExitSuccess();
        $this->assertSame(0, $this->countPasskeys(2));
        $this->assertCount(1, $this->events);
        $this->assertSame('', $this->events[0]->getData('event')->getUserHandle());
    }

    /**
     * @return void
     */
    public function testRevokeWithoutPasskeys(): void
    {
        $this->exec('passkeys revoke 2 --yes');

        $this->assertExitSuccess();
        $this->assertOutputContains('No passkeys found for user 2.');
        $this->assertCount(0, $this->events);
    }
}
