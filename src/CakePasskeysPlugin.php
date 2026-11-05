<?php

declare(strict_types=1);

namespace CakePasskeys;

use Cake\Cache\Cache;
use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use Cake\I18n\ChainMessagesLoader;
use Cake\I18n\I18n;
use Cake\I18n\MessagesFileLoader;
use Cake\I18n\Package;
use Cake\Routing\RouteBuilder;
use CakePasskeys\Command\PasskeysListCommand;
use CakePasskeys\Command\PasskeysPruneCommand;
use CakePasskeys\Command\PasskeysRevokeCommand;
use InvalidArgumentException;

class CakePasskeysPlugin extends BasePlugin
{
    /**
     * @var string
     */
    public const JS_VERSION = '0.1.3';

    protected ?string $name = 'CakePasskeys';

    /**
     * @inheritDoc
     *
     * @throws \InvalidArgumentException
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        $defaults = require dirname(__DIR__) . '/config/bootstrap.php';
        Configure::write(
            'CakePasskeys',
            array_replace_recursive(
                $defaults['CakePasskeys'],
                (array)Configure::read('CakePasskeys', []),
            ),
        );

        // Cake maps a text domain to a plugin by camelizing it, and `passkeys`
        // does not camelize to `CakePasskeys`, so without this loader the
        // plugin's own translations are never found.
        I18n::config('passkeys', static function (string $domain, string $locale): Package {
            return (new ChainMessagesLoader([
                new MessagesFileLoader('CakePasskeys.passkeys', $locale, 'mo'),
                new MessagesFileLoader('CakePasskeys.passkeys', $locale, 'po'),
            ]))();
        });

        // Register a dedicated cache engine for WebAuthn challenges so the
        // configured `CakePasskeys.challengeTtl` is actually honored. Cake's
        // `Cache::write()` does not accept a per-write TTL; cloning the base
        // engine config with `duration` overridden is the cleanest fix.
        // The `CakePasskeys.cache` key now means "base engine config to clone
        // from" rather than the engine the store writes to directly.
        $baseEngine = (string)Configure::read('CakePasskeys.cache', 'default');
        $baseConfig = Cache::getConfig($baseEngine);
        $needsBase = !Cache::getConfig('passkeys_challenges') || !Cache::getConfig('passkeys_rate_limit');
        if ($needsBase && !$baseConfig) {
            throw new InvalidArgumentException(sprintf(
                'CakePasskeys.cache names the cache config `%s`, which does not exist.',
                $baseEngine,
            ));
        }
        if (!Cache::getConfig('passkeys_challenges')) {
            Cache::setConfig('passkeys_challenges', array_merge(
                (array)$baseConfig,
                ['duration' => (int)Configure::read('CakePasskeys.challengeTtl', 300)],
            ));
        }
        // Rate-limit buckets must outlive their window (at most a minute), or
        // a short base duration would reset a full bucket early.
        if (!Cache::getConfig('passkeys_rate_limit')) {
            Cache::setConfig('passkeys_rate_limit', array_merge(
                (array)$baseConfig,
                ['duration' => '+1 hour'],
            ));
        }
    }

    /**
     * @inheritDoc
     */
    public function routes(RouteBuilder $routes): void
    {
        $prefix = Configure::read('CakePasskeys.urlPrefix', '/passkeys');
        $routes->plugin('CakePasskeys', ['path' => $prefix], function (RouteBuilder $r): void {
            $r->setExtensions(['json']);
            $r->connect('/register/start', ['controller' => 'Passkeys', 'action' => 'registerStart']);
            $r->connect('/register/finish', ['controller' => 'Passkeys', 'action' => 'registerFinish']);
            $r->connect('/login/start', ['controller' => 'Passkeys', 'action' => 'loginStart']);
            $r->connect('/login/finish', ['controller' => 'Passkeys', 'action' => 'loginFinish']);
            $r->connect('/reauth/start', ['controller' => 'Passkeys', 'action' => 'reauthStart']);
            $r->connect('/reauth/finish', ['controller' => 'Passkeys', 'action' => 'reauthFinish']);
            $r->connect('/second-factor/start', ['controller' => 'Passkeys', 'action' => 'secondFactorStart']);
            $r->connect('/second-factor/finish', ['controller' => 'Passkeys', 'action' => 'secondFactorFinish']);
            $r->connect('/rename/{id}', ['controller' => 'Passkeys', 'action' => 'rename'])->setPass(['id']);
            $r->connect('/delete/{id}', ['controller' => 'Passkeys', 'action' => 'delete'])->setPass(['id']);
        });
        $routes->plugin('CakePasskeys', ['path' => '/'], function (RouteBuilder $r): void {
            $r->connect('/.well-known/passkey-endpoints', ['controller' => 'Passkeys', 'action' => 'wellKnown'])
                ->setMethods(['GET']);
        });
    }

    /**
     * @inheritDoc
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands
            ->add(PasskeysListCommand::defaultName(), PasskeysListCommand::class)
            ->add(PasskeysRevokeCommand::defaultName(), PasskeysRevokeCommand::class)
            ->add(PasskeysPruneCommand::defaultName(), PasskeysPruneCommand::class);
    }
}
