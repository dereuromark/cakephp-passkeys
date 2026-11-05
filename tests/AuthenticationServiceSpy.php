<?php

declare(strict_types=1);

namespace CakePasskeys\Test;

use Cake\Http\Response;
use Cake\Http\ServerRequest;

/**
 * Stand-in for cakephp/authentication's AuthenticationService, which the
 * plugin does not depend on.
 */
class AuthenticationServiceSpy
{
    /**
     * @var array<int, mixed>
     */
    public array $persisted = [];

    /**
     * @param string|null $sessionKey Where to store the identity, like the Session authenticator does. Null stores nothing.
     */
    public function __construct(public ?string $sessionKey = 'Auth')
    {
    }

    /**
     * @param \Cake\Http\ServerRequest $request
     * @param \Cake\Http\Response $response
     * @param mixed $identity
     *
     * @return array<string, mixed>
     */
    public function persistIdentity(ServerRequest $request, Response $response, mixed $identity): array
    {
        $this->persisted[] = $identity;
        // Like the Session authenticator: an identity already stored stays.
        if ($this->sessionKey !== null && !$request->getSession()->check($this->sessionKey)) {
            $request->getSession()->write($this->sessionKey, $identity);
        }

        return [
            'request' => $request->withAttribute('identity', $identity),
            'response' => $response->withHeader('X-Spy', 'persisted'),
        ];
    }

    /**
     * @param \Cake\Http\ServerRequest $request
     * @param \Cake\Http\Response $response
     *
     * @return array<string, mixed>
     */
    public function clearIdentity(ServerRequest $request, Response $response): array
    {
        if ($this->sessionKey !== null) {
            $request->getSession()->delete($this->sessionKey);
        }

        return ['request' => $request->withoutAttribute('identity'), 'response' => $response];
    }
}
