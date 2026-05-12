<?php
declare(strict_types=1);

namespace Passkeys\Service;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Utility\Security;
use Passkeys\Contract\PasskeyUserInterface;
use RuntimeException;

class ConventionUserAdapter implements PasskeyUserInterface
{
    /**
     * @param \Cake\Datasource\EntityInterface $entity
     */
    public function __construct(private EntityInterface $entity)
    {
    }

    /**
     * @return string
     */
    public function getPasskeyUserHandle(): string
    {
        $salt = Configure::read('Security.salt') ?: Security::getSalt();
        if (!$salt) {
            throw new RuntimeException('Security.salt must be set for stable passkey user handles');
        }
        $id = (string)$this->entity->get($this->col('id'));

        return hash_hmac('sha256', $id, (string)$salt);
    }

    /**
     * @return string
     */
    public function getPasskeyDisplayEmail(): string
    {
        return (string)$this->entity->get($this->col('email'));
    }

    /**
     * @return string
     */
    public function getPasskeyDisplayName(): string
    {
        $name = $this->entity->get($this->col('displayName'));

        return (string)($name ?: $this->getPasskeyDisplayEmail());
    }

    /**
     * @return bool
     */
    public function isPasskeyEligible(): bool
    {
        $activeCol = Configure::read('Passkeys.users.activeColumn');

        return !$activeCol || (bool)$this->entity->get($activeCol);
    }

    /**
     * @return int
     */
    public function getUserId(): int
    {
        return (int)$this->entity->get($this->col('id'));
    }

    /**
     * @param string $logical
     * @return string
     */
    private function col(string $logical): string
    {
        return (string)Configure::read("Passkeys.users.columns.$logical", $logical);
    }
}
