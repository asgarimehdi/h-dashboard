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

    /**
     * Flush all pending namespace increments collected during a batch.
     *
     * @return int Number of distinct namespaces that were incremented.
     */
    public function flushPending(): int;

    /**
     * Execute a callback inside a batch scope. All increment() calls inside
     * the callback are collected and flushed once at the end, deduplicating
     * repeated increments for the same namespace.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function batch(\Closure $callback): mixed;
}
