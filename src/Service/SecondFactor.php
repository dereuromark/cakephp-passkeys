<?php

declare(strict_types=1);

namespace CakePasskeys\Service;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Closure;

/**
 * Reads the host's "first factor passed" state for the passkey second factor.
 *
 * The host sets it only after a verified password: either a session key
 * (`CakePasskeys.secondFactor.pendingUserKey`) or a closure
 * (`CakePasskeys.secondFactor.pendingUserResolver`), which wins when set.
 */
class SecondFactor
{
    /**
     * @return bool Whether the host configured a pending-user source
     */
    public static function enabled(): bool
    {
        return self::resolver() !== null || self::pendingUserKey() !== null;
    }

    /**
     * @param \Cake\Http\ServerRequest $request
     *
     * @return string|int|null Id of the user waiting for the second factor
     */
    public static function pendingUserId(ServerRequest $request): string|int|null
    {
        $resolver = self::resolver();
        $id = null;
        if ($resolver !== null) {
            $id = $resolver($request);
        } else {
            $key = self::pendingUserKey();
            if ($key !== null) {
                $id = $request->getSession()->read($key);
            }
        }
        if (is_int($id) || (is_string($id) && $id !== '')) {
            return $id;
        }

        return null;
    }

    /**
     * Removes the pending state after the second factor. Only the session key
     * is cleared; with a resolver the host owns its state.
     *
     * @param \Cake\Http\ServerRequest $request
     *
     * @return void
     */
    public static function clearPending(ServerRequest $request): void
    {
        $key = self::pendingUserKey();
        if (self::resolver() !== null || $key === null) {
            return;
        }
        $request->getSession()->delete($key);
    }

    /**
     * @return \Closure|null
     */
    private static function resolver(): ?Closure
    {
        $resolver = Configure::read('CakePasskeys.secondFactor.pendingUserResolver');

        return $resolver instanceof Closure ? $resolver : null;
    }

    /**
     * @return string|null
     */
    private static function pendingUserKey(): ?string
    {
        $key = Configure::read('CakePasskeys.secondFactor.pendingUserKey');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
