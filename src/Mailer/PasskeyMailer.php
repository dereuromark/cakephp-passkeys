<?php

declare(strict_types=1);

namespace CakePasskeys\Mailer;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Mailer\Mailer;
use Cake\Mailer\Message;
use Cake\Routing\Router;
use CakePasskeys\Contract\PasskeyUserInterface;
use CakePasskeys\Model\Entity\Passkey;
use function Cake\I18n\__d;

/**
 * Security alerts about the passkeys of an account.
 *
 * Templates: `CakePasskeys.registered` in `templates/email/{html,text}/`,
 * layout `CakePasskeys.default`. Override them in the app under
 * `templates/plugin/CakePasskeys/`.
 */
class PasskeyMailer extends Mailer
{
    /**
     * A passkey was added to the account of `$user`.
     *
     * @param \CakePasskeys\Contract\PasskeyUserInterface $user
     * @param \CakePasskeys\Model\Entity\Passkey $passkey
     *
     * @return void
     */
    public function registered(PasskeyUserInterface $user, Passkey $passkey): void
    {
        $appName = (string)Configure::read('CakePasskeys.rpName', '');
        $this
            ->setTo($user->getPasskeyDisplayEmail(), $user->getPasskeyDisplayName())
            ->setSubject(__d('passkeys', '{0}: a passkey was added to your account', $appName))
            ->setEmailFormat(Message::MESSAGE_BOTH);

        $this->viewBuilder()
            ->setTemplate('CakePasskeys.registered')
            ->setLayout('CakePasskeys.default')
            ->setVars([
                'appName' => $appName,
                'userName' => $user->getPasskeyDisplayName(),
                'passkeyName' => (string)$passkey->name,
                'deviceLabel' => $passkey->aaguid_label,
                'addedAt' => $passkey->created ?? DateTime::now(),
                'manageUrl' => $this->manageUrl(),
            ]);
    }

    /**
     * @return string|null
     */
    protected function manageUrl(): ?string
    {
        $url = Configure::read('CakePasskeys.notify.manageUrl');
        if (is_array($url)) {
            return Router::url($url, true);
        }

        return is_string($url) && $url !== '' ? $url : null;
    }
}
