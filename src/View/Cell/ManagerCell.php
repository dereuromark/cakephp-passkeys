<?php
declare(strict_types=1);

namespace Passkeys\View\Cell;

use Cake\Core\Configure;
use Cake\View\Cell;
use function Cake\I18n\__d;

/**
 * @extends \Cake\View\Cell<\Cake\View\View>
 */
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
        if (!Configure::read('Passkeys.enabled')) {
            $this->set('hidden', true);

            return;
        }
        $request = $this->request;
        $identity = $request !== null ? $request->getAttribute('identity') : null;
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

        /** @var \Passkeys\Model\Table\PasskeysTable $table */
        $table = $this->fetchTable('Passkeys.Passkeys');
        $passkeys = $table->find()
            ->where(['user_id' => $userId])
            ->orderBy(['created' => 'DESC'])
            ->all()
            ->toArray();

        $this->viewBuilder()->setHelpers(['Form']);

        $maxPerUser = (int)Configure::read('Passkeys.maxPerUser', 5);
        $this->set([
            'hidden' => false,
            'passkeys' => $passkeys,
            'atCap' => count($passkeys) >= $maxPerUser,
            'maxPerUser' => $maxPerUser,
            'docsUrl' => Configure::read('Passkeys.docsUrl'),
            'title' => $this->viewBuilder()->getOption('title') ?? __d('passkeys', 'Passkeys'),
        ]);
    }
}
