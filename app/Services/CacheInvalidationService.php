<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheInvalidationService implements CacheInvalidationServiceInterface
{
    /**
     * Bump the version counter for a namespace.
     *
     * Cache keys follow the pattern: {namespace}:v{version}:{scopeHash}:{extra}
     * Incrementing the version makes all existing keys unreachable; they expire via TTL.
     */
    public function increment(string $namespace): int
    {
        return Cache::increment("{$namespace}_version");
    }

    public function getVersion(string $namespace): int
    {
        return Cache::get("{$namespace}_version", 0);
    }

    public function cacheKey(string $namespace, string $scopeHash, string $extra = 'none'): string
    {
        $version = $this->getVersion($namespace);

        return "{$namespace}:v{$version}:{$scopeHash}:{$extra}";
    }

    public function remember(string $namespace, string $scopeHash, \Closure $callback, int $ttlMinutes = 60, array $extra = []): mixed
    {
        $extraHash = empty($extra) ? 'none' : md5(serialize($extra));
        $key = $this->cacheKey($namespace, $scopeHash, $extraHash);

        return Cache::remember($key, now()->addMinutes($ttlMinutes), $callback);
    }
}
