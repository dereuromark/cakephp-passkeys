<?php

declare(strict_types=1);

namespace Passkeys\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property int $user_id
 * @property string $credential_id
 * @property string $public_key
 * @property string|null $aaguid
 * @property string|null $aaguid_label
 * @property string|null $transports
 * @property int $sign_count
 * @property string $name
 * @property string|null $emoji
 * @property \Cake\I18n\DateTime|null $last_used_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class Passkey extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'emoji' => true,
        'last_used_at' => true,
    ];

    protected array $_hidden = ['credential_id', 'public_key', 'aaguid'];
}
