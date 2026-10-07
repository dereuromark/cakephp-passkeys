<?php

declare(strict_types=1);

use Cake\Core\Configure;
use Migrations\BaseMigration;

class CreatePasskeys extends BaseMigration
{
    /**
     * Column types `CakePasskeys.users.idType` may name, with their options.
     * Pick the one that matches the primary key of your users table.
     *
     * @var array<string, array<string, mixed>>
     */
    protected const USER_ID_TYPES = [
        'integer' => [],
        'biginteger' => [],
        'uuid' => [],
        'string' => ['limit' => 64],
    ];

    /**
     * WebAuthn allows credential ids of up to 1023 bytes.
     *
     * @var int
     */
    protected const CREDENTIAL_ID_LENGTH = 1023;

    /**
     * @throws \InvalidArgumentException
     *
     * @return void
     */
    public function up(): void
    {
        $userIdType = (string)Configure::read('CakePasskeys.users.idType', 'integer');
        if (!isset(static::USER_ID_TYPES[$userIdType])) {
            throw new InvalidArgumentException(sprintf(
                'CakePasskeys.users.idType must be one of %s, got `%s`.',
                implode(', ', array_keys(static::USER_ID_TYPES)),
                $userIdType,
            ));
        }

        $this->table('passkeys', ['id' => true, 'primary_key' => ['id']])
            ->addColumn('user_id', $userIdType, ['null' => false] + static::USER_ID_TYPES[$userIdType])
            ->addColumn('credential_id', 'binary', ['limit' => static::CREDENTIAL_ID_LENGTH, 'null' => false])
            ->addColumn('public_key', 'binary', ['null' => false])
            ->addColumn('aaguid', 'binary', ['limit' => 16, 'null' => true])
            ->addColumn('aaguid_label', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('transports', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('sign_count', 'biginteger', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('emoji', 'string', ['limit' => 8, 'null' => true])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['user_id'])
            // MySQL stores the column as a BLOB and needs a key length for it.
            // The length covers the whole column, so uniqueness is exact.
            ->addIndex(['credential_id'], [
                'unique' => true,
                'name' => 'uniq_credential_id',
                'limit' => static::CREDENTIAL_ID_LENGTH,
            ])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('passkeys')->drop()->save();
    }
}
