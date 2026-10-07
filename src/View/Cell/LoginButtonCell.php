<?php

declare(strict_types=1);

namespace CakePasskeys\View\Cell;

use Cake\Core\Configure;
use Cake\Routing\Router;
use Cake\View\Cell;
use function Cake\I18n\__d;

class LoginButtonCell extends Cell
{
    /**
     * @param string|null $class Optional CSS class override.
     * @param string|null $label Optional button label override.
     *
     * @return void
     */
    public function display(?string $class = null, ?string $label = null): void
    {
        if (!Configure::read('CakePasskeys.enabled')) {
            $this->set('hidden', true);

            return;
        }
        $this->set([
            'hidden' => false,
            'endpoints' => [
                'start' => Router::url([
                    'plugin' => 'CakePasskeys',
                    'controller' => 'Passkeys',
                    'action' => 'loginStart',
                ]),
                'finish' => Router::url([
                    'plugin' => 'CakePasskeys',
                    'controller' => 'Passkeys',
                    'action' => 'loginFinish',
                ]),
            ],
            'class' => $class ?? 'btn btn-outline-primary',
            'label' => $label ?? __d('passkeys', 'Sign in with a passkey'),
        ]);
    }
}
