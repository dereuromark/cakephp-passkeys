<?php
declare(strict_types=1);

namespace Passkeys;

use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Routing\RouteBuilder;
use Passkeys\Contract\RateLimiterInterface;
use Passkeys\Service\NullRateLimiter;

class PasskeysPlugin extends BasePlugin
{
    public const JS_VERSION = '1.0.0';

    protected ?string $name = 'Passkeys';

    /**
     * @inheritDoc
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        $defaults = require dirname(__DIR__) . '/config/bootstrap.php';
        Configure::write(
            'Passkeys',
            array_replace_recursive(
                $defaults['Passkeys'],
                (array)Configure::read('Passkeys', []),
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function routes(RouteBuilder $routes): void
    {
        $prefix = Configure::read('Passkeys.urlPrefix', '/passkeys');
        $routes->plugin('Passkeys', ['path' => $prefix], function (RouteBuilder $r): void {
            $r->setExtensions(['json']);
            $r->connect('/register/start', ['controller' => 'Passkeys', 'action' => 'registerStart']);
            $r->connect('/register/finish', ['controller' => 'Passkeys', 'action' => 'registerFinish']);
            $r->connect('/login/start', ['controller' => 'Passkeys', 'action' => 'loginStart']);
            $r->connect('/login/finish', ['controller' => 'Passkeys', 'action' => 'loginFinish']);
            $r->connect('/reauth/start', ['controller' => 'Passkeys', 'action' => 'reauthStart']);
            $r->connect('/reauth/finish', ['controller' => 'Passkeys', 'action' => 'reauthFinish']);
            $r->connect('/rename/{id}', ['controller' => 'Passkeys', 'action' => 'rename'])->setPass(['id']);
            $r->connect('/delete/{id}', ['controller' => 'Passkeys', 'action' => 'delete'])->setPass(['id']);
        });
    }

    /**
     * @inheritDoc
     */
    public function services(ContainerInterface $container): void
    {
        $impl = Configure::read('Passkeys.rateLimiter');
        $container->add(RateLimiterInterface::class, $impl ?: NullRateLimiter::class);
    }
}
