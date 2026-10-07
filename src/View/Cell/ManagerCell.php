<?php

declare(strict_types=1);

namespace CakePasskeys\View\Cell;

use Cake\Core\Configure;
use Cake\View\Cell;
use function Cake\I18n\__d;

class ManagerCell extends Cell
{
    /**
     * @var array<string>
     */
    protected array $_validCellOptions = ['title'];

    /**
     * @return void
     */
    public function display(): void
    {
        if (!Configure::read('CakePasskeys.enabled')) {
            $this->set('hidden', true);

            return;
        }
        $identity = $this->request->getAttribute('identity');
        $userId = null;
        if (is_object($identity)) {
            if (method_exists($identity, 'getIdentifier')) {
                $userId = $identity->getIdentifier();
            } elseif (isset($identity->id)) {
                /** @var mixed $userId */
                $userId = $identity->id;
            }
        }
        if ($userId === null) {
            $this->set('hidden', true);

            return;
        }

        /** @var \CakePasskeys\Model\Table\PasskeysTable $table */
        $table = $this->fetchTable('CakePasskeys.Passkeys');
        $passkeys = $table->find()
            ->where(['user_id' => $userId])
            ->orderBy(['created' => 'DESC'])
            ->all()
            ->toArray();

        $this->viewBuilder()->setHelpers(['Form']);

        $maxPerUser = (int)Configure::read('CakePasskeys.maxPerUser', 5);
        $this->set([
            'hidden' => false,
            'passkeys' => $passkeys,
            'atCap' => count($passkeys) >= $maxPerUser,
            'maxPerUser' => $maxPerUser,
            'docsUrl' => Configure::read('CakePasskeys.docsUrl'),
            'title' => $this->viewBuilder()->getOption('title') ?? __d('passkeys', 'Passkeys'),
        ]);
    }
}
