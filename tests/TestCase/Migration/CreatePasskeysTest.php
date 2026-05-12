<?php

declare(strict_types=1);

namespace Passkeys\Test\TestCase\Migration;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class CreatePasskeysTest extends TestCase
{
    protected function tearDown(): void
    {
        Configure::write('Passkeys.tenancy.column', null);
        parent::tearDown();
    }

    public function testMigrationCreatesPasskeysTable(): void
    {
        Configure::write('Passkeys.tenancy.column', null);
        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'Passkeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');
        $schema = $connection->getSchemaCollection()->describe('passkeys');
        $cols = $schema->columns();

        $this->assertContains('user_id', $cols);
        $this->assertContains('credential_id', $cols);
        $this->assertContains('public_key', $cols);
        $this->assertContains('aaguid', $cols);
        $this->assertContains('aaguid_label', $cols);
        $this->assertContains('emoji', $cols);
        $this->assertContains('name', $cols);
        $this->assertContains('sign_count', $cols);
        $this->assertContains('last_used_at', $cols);
        $this->assertNotContains('account_id', $cols, 'tenancy column off by default');
    }

    public function testMigrationCreatesTenancyColumnWhenConfigured(): void
    {
        Configure::write('Passkeys.tenancy.column', 'account_id');
        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'Passkeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');
        $schema = $connection->getSchemaCollection()->describe('passkeys');
        $this->assertContains('account_id', $schema->columns());
    }
}
