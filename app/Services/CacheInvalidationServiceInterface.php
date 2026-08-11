<?php

namespace App\Services;

interface CacheInvalidationServiceInterface
{
    /**
     * Bump the version counter for a namespace (invalidates all cached keys under it).
     */
    public function increment(string $namespace): int;

    /**
     * Get the current version number for a namespace.
     */
    public function getVersion(string $namespace): int;

    /**
     * Build a versioned cache key: {namespace}:v{version}:{scopeHash}:{extra}.
     */
    public function cacheKey(string $namespace, string $scopeHash, string $extra = 'none'): string;

    /**
     * Remember a value with automatic versioned cache key generation.
     *
     * @param  array<string, mixed>  $extra  Extra parameters hashed into the cache key.
     */
    public function remember(string $namespace, string $scopeHash, \Closure $callback, int $ttlMinutes = 60, array $extra = []): mixed;
}
