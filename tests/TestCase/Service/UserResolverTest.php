<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Service;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\TestSuite\TestCase;
use CakePasskeys\Service\UserResolver;
use RuntimeException;

class UserResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Security.salt is already set by tests/bootstrap.php
        Configure::write('CakePasskeys.users', [
            'table' => 'Users',
            'columns' => ['id' => 'id', 'email' => 'email', 'displayName' => 'name'],
            'activeColumn' => null,
        ]);
        $conn = ConnectionManager::get('test');
        assert($conn instanceof Connection);
        $conn->execute('DROP TABLE IF EXISTS users');
        $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(255), name VARCHAR(255), is_active INTEGER DEFAULT 1)');
        $conn->insert('users', ['id' => 1, 'email' => 'alice@example.com', 'name' => 'Alice', 'is_active' => 1]);
        $conn->insert('users', ['id' => 2, 'email' => 'bob@example.com', 'name' => null, 'is_active' => 0]);
        $this->getTableLocator()->clear();
    }

    protected function tearDown(): void
    {
        $this->getTableLocator()->clear();
        Configure::write('CakePasskeys.users.activeColumn', null);
        parent::tearDown();
    }

    public function testResolveByIdReturnsAdapter(): void
    {
        $user = (new UserResolver())->byId(1);
        $this->assertNotNull($user);
        $this->assertSame('alice@example.com', $user->getPasskeyDisplayEmail());
        $this->assertSame('Alice', $user->getPasskeyDisplayName());
        $this->assertSame(1, $user->getUserId());
        $this->assertTrue($user->isPasskeyEligible());
    }

    public function testDisplayNameFallsBackToEmailWhenNull(): void
    {
        $user = (new UserResolver())->byId(2);
        $this->assertNotNull($user);
        $this->assertSame('bob@example.com', $user->getPasskeyDisplayName());
    }

    public function testHandleIsStableHashNotRawId(): void
    {
        $user = (new UserResolver())->byId(1);
        $this->assertNotNull($user);
        $handle = $user->getPasskeyUserHandle();
        $this->assertNotSame('1', $handle);
        $this->assertSame(64, strlen($handle));
        // stability
        $again = (new UserResolver())->byId(1);
        $this->assertNotNull($again);
        $this->assertSame($handle, $again->getPasskeyUserHandle());
        // distinct from another user's handle
        $other = (new UserResolver())->byId(2);
        $this->assertNotNull($other);
        $this->assertNotSame($handle, $other->getPasskeyUserHandle());
    }

    public function testActiveColumnFiltersInactiveUsers(): void
    {
        Configure::write('CakePasskeys.users.activeColumn', 'is_active');
        $this->assertNotNull((new UserResolver())->byId(1));
        $this->assertNull((new UserResolver())->byId(2));
    }

    public function testByEmailLookupWorks(): void
    {
        $user = (new UserResolver())->byEmail('alice@example.com');
        $this->assertNotNull($user);
        $this->assertSame(1, $user->getUserId());
    }

    public function testByHandleRoundTrip(): void
    {
        $user = (new UserResolver())->byId(1);
        $this->assertNotNull($user);
        $handle = $user->getPasskeyUserHandle();
        $found = (new UserResolver())->byHandle($handle);
        $this->assertNotNull($found);
        $this->assertSame(1, $found->getUserId());
    }

    public function testCustomUserHandleClosureIsUsed(): void
    {
        Configure::write('CakePasskeys.users.userHandle', fn (EntityInterface $user): string => pack('J', $user->get('id')));
        $user = (new UserResolver())->byId(1);
        $this->assertNotNull($user);
        $this->assertSame(pack('J', 1), $user->getPasskeyUserHandle());
        $this->assertSame(1, (new UserResolver())->byHandle(pack('J', 1))?->getUserId());
    }

    public function testCustomUserHandleLongerThan64BytesIsRejected(): void
    {
        Configure::write('CakePasskeys.users.userHandle', fn (EntityInterface $user): string => str_repeat('x', 65));
        $user = (new UserResolver())->byId(1);
        $this->assertNotNull($user);
        $this->expectException(RuntimeException::class);
        $user->getPasskeyUserHandle();
    }

    public function testUnknownIdReturnsNull(): void
    {
        $this->assertNull((new UserResolver())->byId(999));
    }
}
