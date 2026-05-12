<?php

declare(strict_types=1);

namespace Passkeys\View\Helper;

use Cake\Core\Configure;
use Cake\Routing\Router;
use Cake\View\Helper;
use Passkeys\PasskeysPlugin;

/**
 * @extends \Cake\View\Helper<\Cake\View\View>
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
class PasskeysHelper extends Helper
{
    /**
     * @var array<string>
     */
    protected array $helpers = ['Html'];

    /**
     * @return string
     */
    public function script(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }
        $nonce = $this->getCspNonce();
        // Honor `Passkeys.urlPrefix` — the routes mount under that prefix,
        // and so does the bundled JS asset served from /webroot/dist. A
        // hard-coded /passkeys path 404s on hosts that remapped to
        // /auth/passkeys or similar.
        $prefix = rtrim((string)Configure::read('Passkeys.urlPrefix', '/passkeys'), '/');
        $src = Router::url($prefix . '/dist/passkeys.min.js', true);
        $nonceAttr = $nonce !== null ? sprintf(' nonce="%s"', $this->escape($nonce)) : '';

        return sprintf('<script src="%s"%s></script>', $this->escape($src), $nonceAttr);
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return string
     */
    public function loginButton(array $opts = []): string
    {
        return (string)$this->_View->cell('Passkeys.LoginButton', $opts);
    }

    /**
     * @return array<string, mixed>
     */
    public function autofillAttribute(): array
    {
        return ['autocomplete' => 'username webauthn', 'data-passkey-conditional' => true];
    }

    /**
     * @param string $action
     *
     * @return string
     */
    public function reauthGuard(string $action): string
    {
        return sprintf('<form data-passkey-reauth-required="%s"></form>', $this->escape($action));
    }

    /**
     * @return string
     */
    public function endpointsMeta(): string
    {
        $endpoints = [
            'registerStart' => Router::url([
                'plugin' => 'Passkeys',
                'controller' => 'Passkeys',
                'action' => 'registerStart',
            ]),
            'registerFinish' => Router::url([
                'plugin' => 'Passkeys',
                'controller' => 'Passkeys',
                'action' => 'registerFinish',
            ]),
            'loginStart' => Router::url([
                'plugin' => 'Passkeys',
                'controller' => 'Passkeys',
                'action' => 'loginStart',
            ]),
            'loginFinish' => Router::url([
                'plugin' => 'Passkeys',
                'controller' => 'Passkeys',
                'action' => 'loginFinish',
            ]),
            'reauthStart' => Router::url([
                'plugin' => 'Passkeys',
                'controller' => 'Passkeys',
                'action' => 'reauthStart',
            ]),
            'reauthFinish' => Router::url([
                'plugin' => 'Passkeys',
                'controller' => 'Passkeys',
                'action' => 'reauthFinish',
            ]),
        ];

        return sprintf(
            '<meta name="passkeys-endpoints" content=\'%s\'>',
            $this->escape((string)json_encode($endpoints, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool)Configure::read('Passkeys.enabled');
    }

    /**
     * @return string
     */
    public function jsVersion(): string
    {
        return PasskeysPlugin::JS_VERSION;
    }

    /**
     * Resolve the CSP nonce for the current request.
     *
     * Integrates with dereuromark/cakephp-csp when its Csp helper is loaded;
     * otherwise falls back to the `csp_nonce` request attribute.
     *
     * @return string|null
     */
    private function getCspNonce(): ?string
    {
        $csp = $this->_View->helpers()->has('Csp') ? $this->_View->helpers()->get('Csp') : null;
        if (is_object($csp) && method_exists($csp, 'nonce')) {
            /** @var mixed $value */
            $value = $csp->nonce();

            return $value !== null ? (string)$value : null;
        }
        $nonce = $this->_View->getRequest()->getAttribute('csp_nonce');

        return $nonce !== null ? (string)$nonce : null;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
