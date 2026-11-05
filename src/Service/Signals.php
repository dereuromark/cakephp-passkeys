<?php

declare(strict_types=1);

namespace CakePasskeys\Service;

use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use CakePasskeys\Contract\PasskeyUserInterface;

/**
 * Data for the WebAuthn Signal API, which keeps the authenticator's passkey
 * list in step with the server. Binary values are base64url, as in the
 * registration options.
 */
class Signals
{
    use LocatorAwareTrait;

    /**
     * Error code in the JSON body of a sign-in that failed on an unknown credential.
     *
     * @var string
     */
    public const UNKNOWN_CREDENTIAL = 'unknown_credential';

    /**
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool)Configure::read('CakePasskeys.signals', true);
    }

    /**
     * @param \CakePasskeys\Contract\PasskeyUserInterface $user
     *
     * @return array{rpId: string, userHandle: string, acceptedCredentialIds: array<string>}
     */
    public function acceptedCredentials(PasskeyUserInterface $user): array
    {
        $ids = [];
        $rows = $this->fetchTable('CakePasskeys.Passkeys')->find()
            ->select(['credential_id'])
            ->where(['user_id' => $user->getUserId()])
            ->orderBy(['id' => 'ASC'])
            ->all();
        foreach ($rows as $row) {
            $ids[] = static::base64Url((string)$row->get('credential_id'));
        }

        return [
            'rpId' => (string)Configure::read('CakePasskeys.rpId', ''),
            'userHandle' => static::base64Url($user->getPasskeyUserHandle()),
            'acceptedCredentialIds' => $ids,
        ];
    }

    /**
     * @param \CakePasskeys\Contract\PasskeyUserInterface $user
     *
     * @return array{rpId: string, userHandle: string, acceptedCredentialIds: array<string>, name: string, displayName: string}
     */
    public function currentUser(PasskeyUserInterface $user): array
    {
        return $this->acceptedCredentials($user) + [
            'name' => $user->getPasskeyDisplayEmail(),
            'displayName' => $user->getPasskeyDisplayName(),
        ];
    }

    /**
     * @param string $raw
     *
     * @return string
     */
    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
