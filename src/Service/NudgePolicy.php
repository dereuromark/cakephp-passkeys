<?php

declare(strict_types=1);

namespace Passkeys\Service;

use Cake\Core\Configure;
use DateTimeImmutable;
use DateTimeInterface;

class NudgePolicy
{
    public function shouldShow(int $passkeyCount, int $loginCount, ?DateTimeInterface $dismissedAt): bool
    {
        if (!Configure::read('Passkeys.nudge.enabled')) {
            return false;
        }
        if ($passkeyCount > 0) {
            return false;
        }
        if ($loginCount < (int)Configure::read('Passkeys.nudge.afterLogins', 3)) {
            return false;
        }
        if ($dismissedAt) {
            $window = (int)Configure::read('Passkeys.nudge.redisplayAfterDays', 14);
            $cutoff = new DateTimeImmutable("-{$window} days");
            if ($dismissedAt > $cutoff) {
                return false;
            }
        }

        return true;
    }
}
