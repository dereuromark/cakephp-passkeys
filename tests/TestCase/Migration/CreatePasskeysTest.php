<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Migration;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class CreatePasskeysTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testMigrationCreatesPasskeysTable(): void
    {
        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'CakePasskeys']);
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
    }

    /**
     * An application with UUID primary keys sets the type before migrating.
     *
     * @return void
     */
    public function testUserIdTypeFollowsConfig(): void
    {
        Configure::write('CakePasskeys.users.idType', 'uuid');
        try {
            $migrations = new Migrations(['connection' => 'test', 'plugin' => 'CakePasskeys']);
            $migrations->rollback(['target' => 0]);
            $migrations->migrate();
        } finally {
            Configure::write('CakePasskeys.users.idType', 'integer');
        }

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');
        $schema = $connection->getSchemaCollection()->describe('passkeys');
        $this->assertSame('uuid', $schema->getColumnType('user_id'));
    }
}
