<?php

declare(strict_types=1);

namespace CakePasskeys\Command;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use CakePasskeys\Contract\PasskeyUserInterface;
use CakePasskeys\Event\PasskeyEvent;
use CakePasskeys\Model\Entity\Passkey;
use CakePasskeys\Service\ConventionUserAdapter;

/**
 * Deletes passkeys from a command and dispatches `CakePasskeys.afterDelete`.
 *
 * @mixin \Cake\Command\Command
 */
trait PasskeyDeletionTrait
{
    /**
     * @param \CakePasskeys\Model\Entity\Passkey $passkey
     * @param string $userHandle
     *
     * @return void
     */
    protected function deletePasskey(Passkey $passkey, string $userHandle): void
    {
        $this->fetchTable('CakePasskeys.Passkeys')->deleteOrFail($passkey);
        $this->dispatchAfterDelete($passkey, $userHandle);
    }

    /**
     * @param \CakePasskeys\Model\Entity\Passkey $passkey
     * @param string $userHandle
     *
     * @return void
     */
    protected function dispatchAfterDelete(Passkey $passkey, string $userHandle): void
    {
        EventManager::instance()->dispatch(new Event(
            'CakePasskeys.afterDelete',
            $this,
            ['event' => new PasskeyEvent($passkey, $userHandle)],
        ));
    }

    /**
     * The handle of a deactivated user still counts; a missing user row gives ''.
     *
     * @param string $userId
     *
     * @return string
     */
    protected function userHandle(string $userId): string
    {
        $table = $this->fetchTable((string)Configure::read('CakePasskeys.users.table', 'Users'));
        $column = (string)Configure::read('CakePasskeys.users.columns.id', 'id');
        $entity = $table->find()->where([$table->aliasField($column) => $userId])->first();
        if (!$entity instanceof EntityInterface) {
            return '';
        }
        $user = $entity instanceof PasskeyUserInterface ? $entity : new ConventionUserAdapter($entity);

        return $user->getPasskeyUserHandle();
    }
}
