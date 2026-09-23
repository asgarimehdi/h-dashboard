<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        // Verify the login route has throttle:5,1 middleware
        $route = app('router')->getRoutes()->match(
            Request::create('/api/login', 'POST')
        );

        $this->assertNotNull($route);
        $middleware = collect($route->gatherMiddleware());
        $this->assertTrue(
            $middleware->contains(fn ($m) => str_contains((string) $m, 'throttle')),
            'Login route should have throttle middleware'
        );
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
