<?php

namespace Tests;

use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    /**
     * Disable HTTP throttling while running the test suite so bursted
     * requests (e.g. several login attempts in one test class) do not
     * receive 429 responses.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // #705: Postgres sequences are non-transactional, so RefreshDatabase
        // restarts ids at 1 for every test. AccessService caches
        // "accessible_units:v{version}:{user_id}:{session_unit_id}:{md5(ids)}",
        // which is then byte-identical across tests — the first test to run
        // populates it and later ones read a stale answer, making suites that
        // filter by name or person order-dependent.
        //
        // Done here rather than per test class: any class that builds a user and
        // a unit can write the same key, so a class-level fix would only move
        // the failure to whichever class was missed.
        cache()->flush();

        $this->app->instance(
            ThrottleRequests::class,
            new class
            {
                public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1)
                {
                    return $next($request);
                }
            }
        );
    }
}
