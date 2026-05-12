<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Migrations\BaseMigration;

class CreatePasskeys extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('passkeys', ['id' => true, 'primary_key' => ['id']]);
        $table
            ->addColumn('user_id', 'integer', ['null' => false])
            // CakePHP normalizes binary/255 → TINYBLOB; we keep the type
            // but cap the unique index at 191 bytes so MySQL accepts the
            // index (BLOB columns need an explicit key-length). WebAuthn
            // credential IDs are <= 1023 bytes per spec, but in practice
            // every shipping authenticator stays well under 191 bytes.
            ->addColumn('credential_id', 'binary', ['limit' => 255, 'null' => false])
            ->addColumn('public_key', 'blob', ['null' => false])
            ->addColumn('aaguid', 'binary', ['limit' => 16, 'null' => true])
            ->addColumn('aaguid_label', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('transports', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('sign_count', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('emoji', 'string', ['limit' => 8, 'null' => true])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['user_id'])
            ->addIndex(['credential_id'], [
                'unique' => true,
                'name' => 'uniq_credential_id',
                // MySQL requires a key length for BLOB index parts; the
                // host-side migrations we ported this from used 191
                // bytes (under the 767-byte utf8mb4 limit) — keep the
                // same value for portability across MySQL 5.7 / 8.0.
                'limit' => 191,
            ]);

        $tenancyColumn = Configure::read('Passkeys.tenancy.column');
        if ($tenancyColumn) {
            $table->addColumn($tenancyColumn, 'integer', ['null' => true])
                ->addIndex([$tenancyColumn]);
        }

        $table->create();
    }

    public function down(): void
    {
        $this->table('passkeys')->drop()->save();
    }
}
