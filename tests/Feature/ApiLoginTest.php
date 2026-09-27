<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

class ApiLoginTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_login_returns_token_with_valid_credentials(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_login_returns_401_with_invalid_credentials(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

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
        ['user' => $user] = $this->createUserWithUnit();

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
        ['user' => $user] = $this->createUserWithUnit();

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

    public function test_token_has_expected_abilities(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk();

        $token = $user->tokens()->latest()->first();

        $this->assertNotNull($token);

        $expectedAbilities = [
            'units:read',
            'hardware:read', 'hardware:write',
            'tickets:read', 'tickets:write',
            'persons:read', 'persons:write',
            'todos:read', 'todos:write',
            'hr:read',
            'notifications:read',
            'gis:read',
            'reports:read',
            'traffic:read',
        ];

        $this->assertEquals($expectedAbilities, $token->abilities);
    }
}
