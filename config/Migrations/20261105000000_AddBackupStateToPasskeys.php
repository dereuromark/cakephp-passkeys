<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class AddBackupStateToPasskeys extends BaseMigration
{
    /**
     * Null means unknown: rows from before this migration until their next sign-in.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('passkeys')
            ->addColumn('backup_eligible', 'boolean', ['null' => true, 'default' => null, 'after' => 'transports'])
            ->addColumn('backed_up', 'boolean', ['null' => true, 'default' => null, 'after' => 'backup_eligible'])
            ->update();
    }
}
