# Adoption

How to add `dereuromark/cakephp-passkeys` to an existing CakePHP 5 app.

## Fresh installs

For an app that has never had passkeys before:

```bash
composer require dereuromark/cakephp-passkeys
bin/cake plugin load Passkeys                        # or addPlugin() in Application.php
bin/cake migrations migrate -p Passkeys              # creates the passkeys table
```

Then wire the five host touchpoints documented in the
[README — Host touchpoints](../README.md#the-five-host-touchpoints)
section. The plugin's `CreatePasskeys` migration creates the full
schema including `aaguid_label` and `emoji`.

## Adopting on a host that already has a `passkeys` table

A common case for apps that rolled their own WebAuthn implementation
first (RentCraft's path). The plugin's `CreatePasskeys` migration would
collide with the existing table, so the host owns the schema going
forward and the plugin's own migration is not invoked.

Steps:

1. Leave the host's original `CreatePasskeys` migration in place.
   It is recorded in the host's `phinxlog` / `cake_migrations` ledger;
   deleting the file would orphan that row.

2. Add a host-side bridge migration that adds the two columns the
   plugin introduced:

```php
<?php
declare(strict_types=1);

use Cake\Datasource\ConnectionManager;
use Migrations\BaseMigration;

class PasskeysPluginBridge extends BaseMigration
{
    public function up(): void
    {
        $connection = ConnectionManager::get('default');
        $tables = $connection->getSchemaCollection()->listTables();
        if (!in_array('passkeys', $tables, true)) {
            return;
        }

        $existing = $connection->getSchemaCollection()->describe('passkeys')->columns();
        $table = $this->table('passkeys');
        $touched = false;

        if (!in_array('aaguid_label', $existing, true)) {
            $table->addColumn('aaguid_label', 'string', ['limit' => 60, 'null' => true]);
            $touched = true;
        }
        if (!in_array('emoji', $existing, true)) {
            $table->addColumn('emoji', 'string', ['limit' => 8, 'null' => true]);
            $touched = true;
        }
        if ($touched) {
            $table->update();
        }
    }

    public function down(): void
    {
        // Intentional no-op: columns are owned by the plugin going forward.
    }
}
```

3. Run `bin/cake migrations migrate` (host migrations only — do NOT
   run `migrations migrate -p Passkeys` on these installs).

4. Delete the host's inlined controller / service / table / entity /
   client-side JS. The plugin's classes take over via PSR-4 + composer
   autoload.

5. Replace inline UI:

```php
<!-- templates/Settings/security.php (or wherever passkeys lived) -->
<?= $this->cell('Passkeys.Manager') ?>

<!-- templates/Users/login.php — replace inline WebAuthn JS -->
<?= $this->Passkeys->script() ?>
<?= $this->Passkeys->endpointsMeta() ?>
<?= $this->Form->control('email', $this->Passkeys->autofillAttribute() + ['type' => 'email']) ?>
<?= $this->Passkeys->loginButton() ?>
```

6. Subscribe to plugin events from `AppController::beforeFilter()` so
   your existing audit-log writes continue:

```php
$this->getEventManager()->on('Passkeys.afterRegister', function ($event) {
    $passkeyEvent = $event->getData('event');
    $this->fetchTable('AuditLogs')->writeLog(
        'passkey_registered',
        $passkeyEvent->getPasskey()->user_id,
        ['passkey_id' => $passkeyEvent->getPasskey()->id, 'name' => $passkeyEvent->getPasskey()->name],
    );
});
// repeat for afterLogin / afterRename / afterDelete
```

7. Run the test suite, verify in a real browser (browser DoD), open
   the PR.

## Vendor strategy during plugin development

If you're iterating on the plugin against a local checkout, use a
composer path repository in your host app's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../cakephp-passkeys",
        "options": { "symlink": false }
    }
],
"require": {
    "dereuromark/cakephp-passkeys": "@dev"
}
```

Use `"symlink": false` (copy mode) instead of true if the host runs
inside a container that can't follow symlinks outside its bind-mount —
DDEV, for example. Plugin edits then require `composer update` to
propagate; manageable workflow vs the symlink-broken alternative.
