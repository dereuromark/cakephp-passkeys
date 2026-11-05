<?php

declare(strict_types=1);

namespace CakePasskeys\View\Cell;

use Cake\Core\Configure;
use Cake\View\Cell;
use CakePasskeys\Service\IdentityResolver;
use CakePasskeys\Service\NudgePolicy;

/**
 * Banner that suggests adding a passkey to a signed-in user who has none,
 * or a second one when every passkey is bound to a single device.
 * Dismissal is remembered in the browser by the bundled JavaScript.
 */
class RegisterNudgeCell extends Cell
{
    /**
     * @return void
     */
    public function display(): void
    {
        $userId = (new IdentityResolver())->userId($this->request);
        if ($userId === null) {
            $this->set('hidden', true);

            return;
        }

        /** @var \CakePasskeys\Model\Table\PasskeysTable $table */
        $table = $this->fetchTable('CakePasskeys.Passkeys');
        $passkeyCount = $table->find()
            ->where(['user_id' => $userId])
            ->count();
        $deviceBoundCount = 0;
        if ($passkeyCount > 0) {
            $deviceBoundCount = $table->find()
                ->where(['user_id' => $userId, 'backup_eligible' => false])
                ->count();
        }

        $reason = (new NudgePolicy())->reason($passkeyCount, $deviceBoundCount);
        $this->set([
            'hidden' => $reason === null,
            'reason' => $reason,
            'redisplayAfterDays' => (int)Configure::read('CakePasskeys.nudge.redisplayAfterDays', 14),
        ]);
    }
}
