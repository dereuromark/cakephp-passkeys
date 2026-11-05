<?php

declare(strict_types=1);

namespace CakePasskeys\Test;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Migrations\Migrations;

/**
 * Users table plus the plugin schema for the command tests.
 */
trait PasskeyCommandFixtureTrait
{
    /**
     * @return void
     */
    protected function setUpSchema(): void
    {
        Configure::write('CakePasskeys.users.activeColumn', null);
        Configure::write('CakePasskeys.users.userHandle', null);

        $conn = ConnectionManager::get('test');
        assert($conn instanceof Connection);
        $conn->execute('DROP TABLE IF EXISTS users');
        $conn->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(255), name VARCHAR(255), is_active INTEGER DEFAULT 1)',
        );
        $conn->insert('users', ['id' => 1, 'email' => 'alice@example.com', 'name' => 'Alice', 'is_active' => 1]);
        $conn->insert('users', ['id' => 2, 'email' => 'bob@example.com', 'name' => 'Bob', 'is_active' => 1]);

        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'CakePasskeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();
        $this->getTableLocator()->clear();
    }

    /**
     * @param int $userId
     * @param string $name
     * @param array<string, mixed> $extra
     *
     * @return int
     */
    protected function seedPasskey(int $userId, string $name, array $extra = []): int
    {
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        $entity = $table->newEntity(
            $extra + [
                'user_id' => $userId,
                'credential_id' => random_bytes(16),
                'public_key' => random_bytes(77),
                'name' => $name,
                'sign_count' => 0,
                'created' => new DateTime('2026-11-01 10:00:00'),
            ],
            ['accessibleFields' => ['*' => true]],
        );
        $table->saveOrFail($entity);

        return (int)$entity->get('id');
    }

    /**
     * @param int $userId
     *
     * @return int
     */
    protected function countPasskeys(int $userId): int
    {
        return $this->getTableLocator()->get('CakePasskeys.Passkeys')->find()
            ->where(['user_id' => $userId])
            ->count();
    }
}
