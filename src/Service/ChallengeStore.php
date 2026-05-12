<?php

declare(strict_types=1);

namespace Passkeys\Service;

use Cake\Cache\Cache;

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
     * The plugin's bootstrap clones the host's chosen `Passkeys.cache` engine
     * into a `passkeys_challenges` engine with `duration` pinned to
     * `Passkeys.challengeTtl`. Always write to that dedicated engine so
     * stale challenges expire on schedule.
     *
     * @return string
     */
    private function engine(): string
    {
        return 'passkeys_challenges';
    }
}
