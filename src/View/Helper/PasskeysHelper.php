<?php

declare(strict_types=1);

namespace CakePasskeys\View\Helper;

use Cake\Core\Configure;
use Cake\Routing\Router;
use Cake\View\Helper;
use CakePasskeys\CakePasskeysPlugin;
use function Cake\Core\h;

/**
 * Template helpers for the passkey UI.
 *
 * @extends \Cake\View\Helper<\Cake\View\View>
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
class PasskeysHelper extends Helper
{
    /**
     * Placeholder for the passkey id in the rename and delete URLs. The
     * JavaScript replaces it with the id of the row it acts on.
     *
     * @var string
     */
    public const ID_PLACEHOLDER = '__id__';

    /**
     * @var array<string>
     */
    protected array $helpers = ['Html'];

    /**
     * Script tag for the bundled JavaScript, served from the plugin's webroot
     * like any other plugin asset. It binds the `data-passkey-*` attributes
     * once the page has loaded.
     *
     * @return string
     */
    public function script(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }
        $options = ['block' => false];
        $nonce = $this->getCspNonce();
        if ($nonce !== null) {
            $options['nonce'] = $nonce;
        }

        return (string)$this->Html->script('CakePasskeys.passkeys.min', $options);
    }

    /**
     * @param array<string, mixed> $opts Options for the LoginButton cell
     *
     * @return string
     */
    public function loginButton(array $opts = []): string
    {
        return (string)$this->_View->cell('CakePasskeys.LoginButton', $opts);
    }

    /**
     * Attributes for the username or email input that let the browser offer
     * passkeys in its autofill dropdown.
     *
     * @return array<string, mixed>
     */
    public function autofillAttribute(): array
    {
        return ['autocomplete' => 'username webauthn', 'data-passkey-conditional' => true];
    }

    /**
     * Attributes for a form that needs a fresh passkey confirmation before
     * it submits. Pass them to `Form->create()`.
     *
     * @param string $action Name the confirmation is recorded under
     *
     * @return array<string, string>
     */
    public function reauthAttributes(string $action): array
    {
        return ['data-passkey-reauth-required' => $action];
    }

    /**
     * Meta tag that tells the JavaScript where the endpoints are and which
     * CSRF token to send. Put it in the `<head>` of every page that shows a
     * passkey control.
     *
     * @return string
     */
    public function endpointsMeta(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $config = [];
        foreach (['registerStart', 'registerFinish', 'loginStart', 'loginFinish', 'reauthStart', 'reauthFinish'] as $action) {
            $config[$action] = Router::url(['plugin' => 'CakePasskeys', 'controller' => 'Passkeys', 'action' => $action]);
        }
        foreach (['rename', 'delete'] as $action) {
            $config[$action] = Router::url([
                'plugin' => 'CakePasskeys',
                'controller' => 'Passkeys',
                'action' => $action,
                static::ID_PLACEHOLDER,
            ]);
        }
        $csrfToken = $this->_View->getRequest()->getAttribute('csrfToken');
        if (is_string($csrfToken) && $csrfToken !== '') {
            $config['csrfToken'] = $csrfToken;
        }

        return sprintf(
            '<meta name="passkeys-endpoints" content="%s">',
            h((string)json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool)Configure::read('CakePasskeys.enabled');
    }

    /**
     * @return string
     */
    public function jsVersion(): string
    {
        return CakePasskeysPlugin::JS_VERSION;
    }

    /**
     * @return string|null
     */
    private function getCspNonce(): ?string
    {
        $nonce = $this->_View->getRequest()->getAttribute('cspScriptNonce')
            ?? $this->_View->getRequest()->getAttribute('csp_nonce');

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
