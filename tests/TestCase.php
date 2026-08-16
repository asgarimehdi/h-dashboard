<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Closure;

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
