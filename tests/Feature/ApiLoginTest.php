<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

#[CoversClass(\App\Http\Controllers\Api\HardwareController::class)]

class ApiLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);
    }

    protected function createUserWithUnit(): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    public function test_login_returns_token_with_valid_credentials(): void
    {
        $user = $this->createUserWithUnit();

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_login_returns_401_with_invalid_credentials(): void
    {
        $user = $this->createUserWithUnit();

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Credentials not match']);
    }

    public function test_login_returns_401_with_nonexistent_user(): void
    {
        $response = $this->postJson('/api/login', [
            'n_code' => '9999999999',
            'password' => 'password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Credentials not match']);
    }

    public function test_login_requires_n_code_and_password(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['n_code', 'password']);
    }

    public function test_login_token_can_access_protected_api(): void
    {
        $user = $this->createUserWithUnit();

        $loginResponse = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $token = $loginResponse->json('token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');

        $response->assertOk()
            ->assertJsonFragment(['n_code' => $user->n_code]);
    }

    public function test_token_created_with_flutter_app_name(): void
    {
        $user = $this->createUserWithUnit();

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // Token should be a Sanctum personal access token
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_unauthenticated_api_access_returns_401(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }
}
