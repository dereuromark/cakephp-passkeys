<?php

declare(strict_types=1);

namespace CakePasskeys\View\Cell;

use Cake\Core\Configure;
use Cake\View\Cell;
use CakePasskeys\Service\IdentityResolver;
use CakePasskeys\Service\Signals;
use CakePasskeys\Service\UserResolver;
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
        $userId = (new IdentityResolver())->userId($this->request);
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

        $signals = null;
        if (Signals::enabled()) {
            $user = (new UserResolver())->byId($userId);
            if ($user !== null) {
                $signals = (new Signals())->currentUser($user);
            }
        }

        $maxPerUser = (int)Configure::read('CakePasskeys.maxPerUser', 5);
        $this->set([
            'signals' => $signals,
            'hidden' => false,
            'passkeys' => $passkeys,
            'atCap' => count($passkeys) >= $maxPerUser,
            'maxPerUser' => $maxPerUser,
            'docsUrl' => Configure::read('CakePasskeys.docsUrl'),
            'title' => $this->viewBuilder()->getOption('title') ?? __d('passkeys', 'Passkeys'),
        ]);
    }
}
