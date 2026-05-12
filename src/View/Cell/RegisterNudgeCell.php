<?php

declare(strict_types=1);

namespace Passkeys\View\Cell;

use Cake\View\Cell;
use DateTimeImmutable;
use Passkeys\Service\NudgePolicy;

/**
 * @extends \Cake\View\Cell<\Cake\View\View>
 */
class RegisterNudgeCell extends Cell
{
    /**
     * @return void
     */
    public function display(): void
    {
        $identity = $this->request->getAttribute('identity');
        if (!$identity) {
            $this->set('hidden', true);

            return;
        }
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
        $passkeyCount = $table->find()
            ->where(['user_id' => $userId])
            ->count();

        $session = $this->request->getSession();
        $loginCount = (int)$session->read('Passkeys.loginCount', 0);
        $dismissedAtStr = $session->read('Passkeys.nudgeDismissedAt');
        $dismissedAt = $dismissedAtStr !== null
            ? new DateTimeImmutable((string)$dismissedAtStr)
            : null;

        $show = (new NudgePolicy())->shouldShow($passkeyCount, $loginCount, $dismissedAt);
        $this->set('hidden', !$show);
    }
}
