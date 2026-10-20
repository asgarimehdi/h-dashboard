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

        // #1185 (Plan 022): no test reads the Vite manifest or the built
        // bundle, so the base TestCase opts the whole suite out of Vite.
        // That is what lets the PHP test jobs drop the Node install and the
        // Vite build — the build output was never asserted on, only paid for.
        //
        // The five per-file calls that already exist (SidebarActiveLink,
        // PageScriptSplitting, SidebarPermissions, AccessibilityInfrastructure,
        // LeafletCssScoping) stay: withoutVite() is idempotent, and they are a
        // safety belt for a test file that is ever moved out of this base.
        $this->withoutVite();

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

        // phpunit.xml pins QUEUE_CONNECTION=sync so the suite never talks to
        // Redis, but the scoped connections behind #[Connection] (#1010) are
        // driver `redis` in config/queue.php regardless of that default, so a
        // dispatched retention/reports job would be pushed to Redis instead of
        // running inline. Keep them on the same driver as the rest of the suite;
        // the pin itself is asserted in JobsTest against the real resolver.
        foreach (['redis-maintenance', 'redis-reports'] as $connection) {
            config(["queue.connections.{$connection}.driver" => 'sync']);
        }
    }
}
