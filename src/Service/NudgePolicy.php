<?php

declare(strict_types=1);

namespace CakePasskeys\Service;

use Cake\Core\Configure;

/**
 * Decides whether a user should be offered to add a passkey.
 */
class NudgePolicy
{
    /**
     * @param int $passkeyCount Passkeys the user already has
     *
     * @return bool
     */
    public function shouldShow(int $passkeyCount): bool
    {
        return (bool)Configure::read('CakePasskeys.enabled')
            && (bool)Configure::read('CakePasskeys.nudge.enabled')
            && $passkeyCount === 0;
    }
}
