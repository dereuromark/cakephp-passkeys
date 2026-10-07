<?php

declare(strict_types=1);

namespace CakePasskeys\Service;

use ArrayAccess;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Closure;

/**
 * Finds out which user the current request belongs to.
 *
 * Applications authenticate in different ways, so this looks in three places:
 *
 * 1. `CakePasskeys.identityResolver`, a closure that gets the request and
 *    returns the user id or null. Set it when neither default fits.
 * 2. The `identity` request attribute, as set by cakephp/authentication and
 *    compatible middleware.
 * 3. The session key in `CakePasskeys.session.userIdKey`, which is also
 *    where a passkey login writes the user id.
 */
class IdentityResolver
{
    /**
     * @param \Cake\Http\ServerRequest $request
     *
     * @return string|int|null The id of the signed-in user, or null for a guest
     */
    public function userId(ServerRequest $request): string|int|null
    {
        $resolver = Configure::read('CakePasskeys.identityResolver');
        if ($resolver instanceof Closure) {
            return $this->scalarId($resolver($request));
        }

        $id = $this->fromIdentity($request->getAttribute('identity'));
        if ($id !== null) {
            return $id;
        }

        $sessionKey = (string)Configure::read('CakePasskeys.session.userIdKey', '');
        if ($sessionKey === '') {
            return null;
        }

        return $this->scalarId($request->getSession()->read($sessionKey));
    }

    /**
     * @param mixed $identity Value of the `identity` request attribute
     *
     * @return string|int|null
     */
    private function fromIdentity(mixed $identity): string|int|null
    {
        if (is_object($identity) && method_exists($identity, 'getIdentifier')) {
            return $this->scalarId($identity->getIdentifier());
        }
        if (is_array($identity) || $identity instanceof ArrayAccess) {
            return $this->scalarId($identity['id'] ?? null);
        }
        if (is_object($identity) && isset($identity->id)) {
            return $this->scalarId($identity->id);
        }

        return null;
    }

    /**
     * @param mixed $id
     *
     * @return string|int|null
     */
    private function scalarId(mixed $id): string|int|null
    {
        if (is_int($id) || (is_string($id) && $id !== '')) {
            return $id;
        }

        return null;
    }
}
