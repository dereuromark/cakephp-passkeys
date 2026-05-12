<?php
declare(strict_types=1);

namespace Passkeys\Event;

use Passkeys\Model\Entity\Passkey;

class PasskeyEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private Passkey $passkey,
        private string $userHandle,
        private array $data = [],
    ) {
    }

    /**
     * @return \Passkeys\Model\Entity\Passkey
     */
    public function getPasskey(): Passkey
    {
        return $this->passkey;
    }

    /**
     * @return string
     */
    public function getUserHandle(): string
    {
        return $this->userHandle;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function getCredentialIdBase64Url(): string
    {
        $raw = (string)($this->passkey->credential_id ?? '');
        if ($raw === '') {
            return '';
        }

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
