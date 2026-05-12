<?php

declare(strict_types=1);

namespace Passkeys\Service;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Passkeys\Contract\PasskeyUserInterface;

class UserResolver
{
    use LocatorAwareTrait;

    /**
     * @param string|int $id
     *
     * @return \Passkeys\Contract\PasskeyUserInterface|null
     */
    public function byId(int|string $id): ?PasskeyUserInterface
    {
        $entity = $this->baseQuery()->where([$this->col('id') => $id])->first();

        return $this->wrap($entity instanceof EntityInterface ? $entity : null);
    }

    /**
     * @param string $email
     *
     * @return \Passkeys\Contract\PasskeyUserInterface|null
     */
    public function byEmail(string $email): ?PasskeyUserInterface
    {
        $entity = $this->baseQuery()->where([$this->col('email') => $email])->first();

        return $this->wrap($entity instanceof EntityInterface ? $entity : null);
    }

    /**
     * @param string $handle
     *
     * @return \Passkeys\Contract\PasskeyUserInterface|null
     */
    public function byHandle(string $handle): ?PasskeyUserInterface
    {
        // Linear scan — the WebAuthn `user.id` only matters during
        // login start; hosts with > 100k users implement
        // PasskeyUserInterface directly with their own indexed handle store.
        foreach ($this->baseQuery()->all() as $entity) {
            if (!$entity instanceof EntityInterface) {
                continue;
            }
            $candidate = $this->wrap($entity);
            if ($candidate && hash_equals($candidate->getPasskeyUserHandle(), $handle)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array<array-key, mixed>>
     */
    private function baseQuery(): SelectQuery
    {
        $table = $this->fetchTable((string)Configure::read('Passkeys.users.table', 'Users'));
        $query = $table->find();
        $activeCol = Configure::read('Passkeys.users.activeColumn');
        if ($activeCol) {
            $query->where([$table->aliasField((string)$activeCol) => true]);
        }

        return $query;
    }

    /**
     * @param string $logical
     *
     * @return string
     */
    private function col(string $logical): string
    {
        return (string)Configure::read("Passkeys.users.columns.$logical", $logical);
    }

    /**
     * @param \Cake\Datasource\EntityInterface|null $entity
     *
     * @return \Passkeys\Contract\PasskeyUserInterface|null
     */
    private function wrap(?EntityInterface $entity): ?PasskeyUserInterface
    {
        if (!$entity) {
            return null;
        }
        if ($entity instanceof PasskeyUserInterface) {
            return $entity;
        }

        return new ConventionUserAdapter($entity);
    }
}
