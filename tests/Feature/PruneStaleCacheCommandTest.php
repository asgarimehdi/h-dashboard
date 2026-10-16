<?php

namespace Tests\Feature;

use App\Console\Commands\PruneStaleCache;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

covers(PruneStaleCache::class);

class PruneStaleCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_prune_stale_runs_successfully(): void
    {
        $this->artisan('cache:prune-stale')
            ->assertExitCode(0);
    }

    public function test_cache_prune_stale_reports_an_honest_audit(): void
    {
        // Issue #981: the command is an audit, not a prune — its output must
        // say so, so the hourly run can never again read as a deletion.
        $this->artisan('cache:prune-stale')
            ->expectsOutputToContain('Cache version audit')
            ->expectsOutputToContain('nothing was deleted')
            ->assertExitCode(0);
    }

    public function test_cache_prune_stale_queries_version_for_each_namespace(): void
    {
        $cache = $this->mock(CacheInvalidationServiceInterface::class);
        // one version lookup per registered namespace
        $cache->shouldReceive('getVersion')
            ->times(count(PruneStaleCache::NAMESPACES))
            ->andReturn(42);

        $this->artisan('cache:prune-stale')
            ->assertExitCode(0);
    }
}
