<?php
declare(strict_types=1);

namespace Passkeys\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;

class ChallengeStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function put(array $payload): string
    {
        $key = bin2hex(random_bytes(16));
        Cache::write($this->cacheKey($key), $payload, $this->engine());

        return $key;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consume(string $key): ?array
    {
        $cacheKey = $this->cacheKey($key);
        $payload = Cache::read($cacheKey, $this->engine());
        if ($payload === null) {
            return null;
        }
        Cache::delete($cacheKey, $this->engine());

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param string $k Opaque key returned from put().
     */
    private function cacheKey(string $k): string
    {
        return 'passkeys.challenge.' . $k;
    }

    /**
     * @return string Cache engine name configured under Passkeys.cache.
     */
    private function engine(): string
    {
        return (string)Configure::read('Passkeys.cache', 'default');
    }
}
