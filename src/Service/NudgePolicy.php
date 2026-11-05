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
     * The user has no passkey yet.
     *
     * @var string
     */
    public const REASON_NO_PASSKEY = 'noPasskey';

    /**
     * Every passkey of the user is known to be bound to one device.
     *
     * @var string
     */
    public const REASON_DEVICE_BOUND_ONLY = 'deviceBoundOnly';

    /**
     * @param int $passkeyCount Passkeys the user already has
     * @param int $deviceBoundCount Of those, the ones known to be device-bound (backup_eligible false)
     *
     * @return bool
     */
    public function shouldShow(int $passkeyCount, int $deviceBoundCount = 0): bool
    {
        return $this->reason($passkeyCount, $deviceBoundCount) !== null;
    }

    /**
     * @param int $passkeyCount Passkeys the user already has
     * @param int $deviceBoundCount Of those, the ones known to be device-bound (backup_eligible false)
     *
     * @return string|null One of the REASON_* constants, null for no nudge
     */
    public function reason(int $passkeyCount, int $deviceBoundCount = 0): ?string
    {
        if (!Configure::read('CakePasskeys.enabled') || !Configure::read('CakePasskeys.nudge.enabled')) {
            return null;
        }
        if ($passkeyCount === 0) {
            return static::REASON_NO_PASSKEY;
        }
        if (
            $deviceBoundCount >= $passkeyCount
            && (bool)Configure::read('CakePasskeys.nudge.deviceBoundOnly', true)
        ) {
            return static::REASON_DEVICE_BOUND_ONLY;
        }

        return null;
    }
}
