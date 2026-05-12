<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Passkeys\PasskeysPlugin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Application extends BaseApplication
{
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        parent::bootstrap();
        if (!$this->getPlugins()->has('Passkeys')) {
            $this->addPlugin(PasskeysPlugin::class);
        }
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new RoutingMiddleware($this))
            ->add(new \Cake\Http\Middleware\BodyParserMiddleware())
            ->add(new TestIdentityMiddleware());
    }
}

/**
 * Minimal session->identity bridge for the test suite. The plugin's
 * `PasskeysController::resolveCurrentUser()` expects an `identity`
 * request attribute that exposes either `getIdentifier()` or an `id`
 * property — we satisfy the former with a tiny anonymous class so the
 * test suite has no hard dependency on cakephp/authentication.
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
class TestIdentityMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = method_exists($request, 'getSession') ? $request->getSession() : null;
        $userId = $session !== null ? $session->read('Auth.id') : null;
        if ($userId !== null) {
            $identity = new class ($userId) {
                /**
                 * @param int|string $id
                 */
                public function __construct(public int|string $id)
                {
                }

                /**
                 * @return int|string
                 */
                public function getIdentifier(): int|string
                {
                    return $this->id;
                }
            };
            $request = $request->withAttribute('identity', $identity);
        }

        return $handler->handle($request);
    }
}
