<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

#[CoversNothing]

class ApiRateLimitTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        // tests/TestCase.php swaps ThrottleRequests for a no-op so bursts of
        // requests cannot 429 the suite (#705). These tests exist to assert the
        // login limiter itself, so restore the real middleware for this class.
        $this->app->forgetInstance(ThrottleRequests::class);
    }

    public function test_authenticated_api_route_has_throttle_middleware(): void
    {
        // Verify the API auth group has throttle:60,1 middleware
        $route = app('router')->getRoutes()->match(
            Request::create('/api/hardware', 'GET')
        );

        $this->assertNotNull($route);
        $middleware = collect($route->gatherMiddleware());
        $this->assertTrue(
            $middleware->contains(fn ($m) => str_contains((string) $m, 'throttle')),
            'API routes should have throttle middleware'
        );
    }

    public function test_login_route_has_throttle_middleware(): void
    {
        // #1666: the login route must use the named api-login limiter, which
        // throttles both per account and per IP.
        $route = app('router')->getRoutes()->match(
            Request::create('/api/login', 'POST')
        );

        $this->assertNotNull($route);
        $middleware = collect($route->gatherMiddleware())->all();

        $this->assertContains(
            'throttle:api-login',
            $middleware,
            'Login route must use the named api-login limiter'
        );
        $this->assertNotContains('throttle:5,1', $middleware);
    }

    public function test_distributed_ip_attempts_against_one_account_hit_429(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'n_code' => $user->n_code,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // New IP: a per-IP-only key would create a fresh budget here (401).
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);

        $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_successful_login_below_budget_still_returns_token(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/login', [
                'n_code' => $user->n_code,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_one_accounts_failures_do_not_lock_another_account(): void
    {
        ['user' => $locked] = $this->createUserWithUnit();
        ['user' => $other] = $this->createUserWithUnit();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'n_code' => $locked->n_code,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // Fresh IP on purpose: .30 also exhausted the per-IP budget, and this
        // test must isolate the ACCOUNT dimension.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.31']);

        $this->postJson('/api/login', [
            'n_code' => $other->n_code,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_missing_n_code_falls_back_to_ip_limit_and_429_wins_over_422(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['password' => 'x'])
                ->assertStatus(422);
        }

        // The limiter runs before validation: 429 wins over 422 here.
        $this->postJson('/api/login', ['password' => 'x'])->assertStatus(429);
    }

    public function test_numeric_n_code_uses_the_same_account_budget(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'n_code' => 1234567890,
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51']);
        $this->postJson('/api/login', [
            'n_code' => 1234567890,
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_limiter_falls_back_to_ip_only_for_non_scalar_n_code(): void
    {
        $limiter = RateLimiter::limiter('api-login');
        $this->assertNotNull($limiter);

        // Throttle runs before validation, so a non-scalar n_code must not
        // crash the limiter — it degrades to the IP-only limit.
        $limits = $limiter(Request::create('/api/login', 'POST', [
            'n_code' => ['x'],
        ]));
        $this->assertCount(1, $limits);
        $this->assertStringStartsWith('ip:', (string) $limits[0]->key);

        // A scalar (including a JSON integer) gets the account limit too, and
        // the int and string forms share one account key.
        $intLimits = $limiter(Request::create('/api/login', 'POST', [
            'n_code' => 1234567890,
        ]));
        $stringLimits = $limiter(Request::create('/api/login', 'POST', [
            'n_code' => '1234567890',
        ]));

        $this->assertCount(2, $intLimits);
        $this->assertSame(
            'account:1234567890',
            $stringLimits[1]->key
        );
        $this->assertSame($stringLimits[1]->key, $intLimits[1]->key);
        $this->assertSame(5, $intLimits[1]->maxAttempts);
        $this->assertSame(60, $intLimits[1]->decaySeconds);
    }

    public function test_limiter_clamps_oversized_n_code_to_ten_characters(): void
    {
        $limiter = RateLimiter::limiter('api-login');
        $this->assertNotNull($limiter);

        // The limiter runs before validation, so the raw key must be bounded
        // to the route's max:10 rather than transliterating unbounded input.
        $limits = $limiter(Request::create('/api/login', 'POST', [
            'n_code' => str_repeat('9', 5000),
        ]));

        $this->assertCount(2, $limits);
        $this->assertSame('account:9999999999', $limits[1]->key);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $response = $this->postJson('/api/login', [
            'n_code' => '0000000000',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Credentials not match']);
    }

    public function test_login_accepts_valid_credentials(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure(['token']);
    }
}
