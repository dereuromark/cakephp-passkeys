<?php
declare(strict_types=1);

namespace Passkeys\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Passkeys\Model\Entity\Passkey;

/**
 * @method \Passkeys\Model\Entity\Passkey get(mixed $primaryKey, array $options = [])
 * @method \Passkeys\Model\Entity\Passkey newEmptyEntity()
 * @method \Passkeys\Model\Entity\Passkey newEntity(array $data, array $options = [])
 * @method \Passkeys\Model\Entity\Passkey saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class PasskeysTable extends Table
{
    /**
     * @param array<string, mixed> $config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('passkeys');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Passkey::class);
        $this->addBehavior('Timestamp');

        // CRITICAL: Binary columns default to the `binary` type which returns
        // a stream resource on read. The WebAuthn library expects raw byte
        // strings (CBOR + COSE parsing reads them via StringStream), and
        // casting a stream resource to (string) yields "Resource id #N"
        // instead of the bytes, which then breaks parsing with
        // "Out of range. Expected: 18, read: 14.".
        //
        // Force-map to `string` so the bytes come back as a PHP string.
        // Storage is unchanged (still BLOB / VARBINARY on the DB side).
        // This is the exact bug that took two debug rounds in RentCraft.
        $schema = $this->getSchema();
        $schema->setColumnType('credential_id', 'string');
        $schema->setColumnType('public_key', 'string');
        $schema->setColumnType('aaguid', 'string');
        $this->setSchema($schema);
    }

    /**
     * @param \Cake\Validation\Validator $validator
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->integer('user_id')->requirePresence('user_id', 'create')->notEmptyString('user_id')
            ->scalar('name')->maxLength('name', 80)->requirePresence('name', 'create')->notEmptyString('name')
            ->scalar('emoji')->maxLength('emoji', 8)->allowEmptyString('emoji')
            ->scalar('aaguid_label')->maxLength('aaguid_label', 60)->allowEmptyString('aaguid_label');
    }
}
