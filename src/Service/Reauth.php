<?php

declare(strict_types=1);

namespace CakePasskeys\Service;

use Cake\Http\ServerRequest;
use DateTimeImmutable;
use Throwable;

/**
 * Server-side check for a recent passkey confirmation.
 *
 * `reauth/finish` records who confirmed and until when that counts as fresh. The
 * application asks here before it performs the sensitive action; the
 * JavaScript that triggers the ceremony is a convenience, not the gate.
 */
class Reauth
{
    /**
     * @param \Cake\Http\ServerRequest $request
     * @param string $action Name the confirmation was recorded under
     *
     * @return bool Whether the user confirmed with a passkey recently enough
     */
    public static function isFresh(ServerRequest $request, string $action = 'default'): bool
    {
        $userId = (new IdentityResolver())->userId($request);
        $record = $request->getSession()->read('CakePasskeys.recent_reauth.' . $action);
        if ($userId === null || !is_array($record)) {
            return false;
        }
        $until = $record['until'] ?? null;
        if (!is_string($until) || $until === '' || ($record['userId'] ?? null) !== (string)$userId) {
            return false;
        }
        try {
            return new DateTimeImmutable($until) > new DateTimeImmutable();
        } catch (Throwable) {
            return false;
        }
    }
}
