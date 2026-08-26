<?php

namespace Tests\Feature;

use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneStaleCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_prune_stale_runs_successfully(): void
    {
        $this->artisan('cache:prune-stale')
            ->assertExitCode(0);
    }

    public function test_cache_prune_stale_dry_run_completes(): void
    {
        $this->artisan('cache:prune-stale', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);
    }

    public function test_cache_prune_stale_queries_version_for_each_namespace(): void
    {
        $cache = $this->mock(CacheInvalidationServiceInterface::class);
        $cache->shouldReceive('getVersion')
            ->times(10)
            ->andReturn(42);

        $this->artisan('cache:prune-stale')
            ->assertExitCode(0);
    }
}
