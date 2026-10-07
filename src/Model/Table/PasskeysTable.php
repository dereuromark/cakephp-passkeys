<?php

declare(strict_types=1);

namespace CakePasskeys\Model\Table;

use Cake\Database\TypeFactory;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use CakePasskeys\Database\Type\BinaryStringType;
use CakePasskeys\Model\Entity\Passkey;

/**
 * @method \CakePasskeys\Model\Entity\Passkey get(mixed $primaryKey, array<string, mixed> $options = [])
 * @method \CakePasskeys\Model\Entity\Passkey newEmptyEntity()
 * @method \CakePasskeys\Model\Entity\Passkey newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 * @method \CakePasskeys\Model\Entity\Passkey saveOrFail(\Cake\Datasource\EntityInterface $entity, array<string, mixed> $options = [])
 */
class PasskeysTable extends Table
{
    /**
     * Name the byte-string column type is registered under.
     *
     * @var string
     */
    public const BINARY_TYPE = 'passkeys_binary';

    /**
     * @param array<string, mixed> $config
     *
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('passkeys');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Passkey::class);
        $this->addBehavior('Timestamp');

        // The core `binary` type returns a stream resource on read. The
        // WebAuthn library expects raw byte strings, and a resource cast to
        // string is "Resource id #N", which breaks CBOR parsing with
        // "Out of range. Expected: 18, read: 14.". BinaryStringType reads as
        // a string and still binds as binary, which PostgreSQL requires.
        TypeFactory::map(static::BINARY_TYPE, BinaryStringType::class);
        $schema = $this->getSchema();
        $schema->setColumnType('credential_id', static::BINARY_TYPE);
        $schema->setColumnType('public_key', static::BINARY_TYPE);
        $schema->setColumnType('aaguid', static::BINARY_TYPE);
        $this->setSchema($schema);
    }

    /**
     * @param \Cake\Validation\Validator $validator
     *
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->scalar('user_id')->requirePresence('user_id', 'create')->notEmptyString('user_id')
            ->scalar('name')->maxLength('name', 80)->requirePresence('name', 'create')->notEmptyString('name')
            ->scalar('emoji')->maxLength('emoji', 8)->allowEmptyString('emoji')
            ->scalar('aaguid_label')->maxLength('aaguid_label', 60)->allowEmptyString('aaguid_label');
    }
}
