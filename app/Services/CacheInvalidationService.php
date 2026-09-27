<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheInvalidationService implements CacheInvalidationServiceInterface
{
    /**
     * Pending namespace increments during a batch scope.
     *
     * @var array<string, true>
     */
    private array $pending = [];

    /**
     * Whether a batch scope is currently active.
     */
    private bool $batching = false;

    /**
     * Bump the version counter for a namespace.
     *
     * Cache keys follow the pattern: {namespace}:v{version}:{scopeHash}:{extra}
     * Incrementing the version makes all existing keys unreachable; they expire via TTL.
     *
     * When inside a batch scope, increments are collected and flushed once
     * at the end — repeated increments for the same namespace are deduplicated.
     */
    public function increment(string $namespace): int
    {
        if ($this->batching) {
            $this->pending[$namespace] = true;

            return $this->getVersion($namespace);
        }

        return Cache::increment("{$namespace}_version");
    }

    public function flushPending(): int
    {
        $count = count($this->pending);

        foreach (array_keys($this->pending) as $ns) {
            Cache::increment("{$ns}_version");
        }
        $this->pending = [];

        return $count;
    }

    public function batch(\Closure $callback): mixed
    {
        $this->batching = true;
        $this->pending = [];

        try {
            $result = $callback();
        } finally {
            $this->flushPending();
            $this->batching = false;
        }

        return $result;
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
