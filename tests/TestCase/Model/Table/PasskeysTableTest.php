<?php

declare(strict_types=1);

namespace Passkeys\Test\TestCase\Model\Table;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use Passkeys\Model\Table\PasskeysTable;

class PasskeysTableTest extends TestCase
{
    private PasskeysTable $Passkeys;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Passkeys.tenancy.column', null);
        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'Passkeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();
        /** @var \Passkeys\Model\Table\PasskeysTable $table */
        $table = $this->getTableLocator()->get('Passkeys.Passkeys');
        $this->Passkeys = $table;
    }

    protected function tearDown(): void
    {
        $this->getTableLocator()->clear();
        parent::tearDown();
    }

    public function testCredentialIdReadsAsString(): void
    {
        $raw = random_bytes(32);
        $entity = $this->Passkeys->newEntity([
            'user_id' => 1,
            'credential_id' => $raw,
            'public_key' => random_bytes(77),
            'name' => 'My laptop',
            'sign_count' => 0,
        ], ['accessibleFields' => ['*' => true]]);
        $this->Passkeys->saveOrFail($entity);

        $loaded = $this->Passkeys->get($entity->id);
        $this->assertIsString($loaded->credential_id, 'binary column must come back as string, not resource');
        $this->assertSame($raw, $loaded->credential_id);
    }

    public function testValidationRejectsEmptyName(): void
    {
        $entity = $this->Passkeys->newEntity([
            'user_id' => 1,
            'credential_id' => random_bytes(8),
            'public_key' => random_bytes(8),
            'name' => '',
        ], ['accessibleFields' => ['*' => true]]);
        $this->assertNotEmpty($entity->getErrors()['name'] ?? []);
    }

    public function testHiddenFieldsNotInArray(): void
    {
        $entity = $this->Passkeys->newEntity([
            'user_id' => 1,
            'credential_id' => random_bytes(8),
            'public_key' => random_bytes(8),
            'name' => 'X',
            'sign_count' => 0,
        ], ['accessibleFields' => ['*' => true]]);
        $this->Passkeys->saveOrFail($entity);
        $loaded = $this->Passkeys->get($entity->id);
        $arr = $loaded->toArray();
        $this->assertArrayNotHasKey('credential_id', $arr);
        $this->assertArrayNotHasKey('public_key', $arr);
        $this->assertArrayNotHasKey('aaguid', $arr);
    }
}
