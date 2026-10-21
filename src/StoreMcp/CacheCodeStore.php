<?php

declare(strict_types=1);

namespace OneTrace\Laravel\StoreMcp;

use Illuminate\Contracts\Cache\Repository;
use OneTrace\StoreMcp\CodeStore;

/**
 * Confirmation codes and their limits in the Laravel cache.
 */
final class CacheCodeStore implements CodeStore
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get($key);

        return \is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, int $ttl): void
    {
        $this->cache->put($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->forget($key);
    }
}
