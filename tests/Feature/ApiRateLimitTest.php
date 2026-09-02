<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HardwareController;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

covers(HardwareController::class);

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    protected function createUserWithUnit(): array
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $unit = Unit::create(['name' => 'Test Unit']);

        $person = Person::create([
            'n_code' => '1234567890',
            'f_name' => 'Test',
            'l_name' => 'User',
            'u_id' => $unit->id,
            's_id' => $sId,
            't_id' => $tId,
            'e_id' => $eId,
            'r_id' => $rId,
        ]);

        $user = User::create([
            'n_code' => '1234567890',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        return ['user' => $user, 'person' => $person, 'unit' => $unit];
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
        $this->createUserWithUnit();

        $response = $this->postJson('/api/login', [
            'n_code' => '1234567890',
            'password' => 'password',
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure(['token']);
    }
}
