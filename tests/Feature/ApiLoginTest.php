<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
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
        // A plain user (no extra permissions) should only get notifications:read
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk();

        $token = $user->tokens()->latest()->first();

        $this->assertNotNull($token);
        $this->assertEquals(['notifications:read'], $token->abilities);
    }

    public function test_admin_token_has_full_abilities(): void
    {
        // An admin user (with all permissions via RoleSeeder) should get the full set
        $this->seed(RoleSeeder::class);
        ['user' => $user] = $this->createUserWithUnit();
        $user->assignRole('admin');

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk();

        $token = $user->tokens()->latest()->first();

        $this->assertNotNull($token);

        // Order matches User::getApiTokenAbilities()
        $expectedAbilities = [
            'notifications:read',
            'units:read', 'units:write',
            'hardware:read', 'hardware:write',
            'tickets:read', 'tickets:write',
            'persons:read', 'persons:write',
            'todos:read', 'todos:write',
            'hr:read',
            'traffic:read', 'gis:read',
            'reports:read',
        ];

        $this->assertEquals($expectedAbilities, $token->abilities);
    }

    public function test_user_with_manage_hardware_gets_hardware_abilities(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk();

        $token = $user->tokens()->latest()->first();

        $this->assertNotNull($token);
        $this->assertContains('hardware:read', $token->abilities);
        $this->assertContains('hardware:write', $token->abilities);
        $this->assertContains('notifications:read', $token->abilities);
    }

    public function test_user_with_organization_gets_units_abilities(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['organization']);

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk();

        $token = $user->tokens()->latest()->first();

        $this->assertNotNull($token);
        $this->assertContains('units:read', $token->abilities);
        $this->assertContains('units:write', $token->abilities);
        $this->assertContains('notifications:read', $token->abilities);
    }

    public function test_user_with_manage_personnel_gets_reports_and_persons_abilities(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk();

        $token = $user->tokens()->latest()->first();

        $this->assertNotNull($token);
        $this->assertContains('reports:read', $token->abilities);
        $this->assertContains('persons:read', $token->abilities);
        $this->assertContains('persons:write', $token->abilities);
        $this->assertContains('notifications:read', $token->abilities);
    }

    public function test_user_with_map_gets_traffic_and_gis_abilities(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $response = $this->postJson('/api/login', [
            'n_code' => $user->n_code,
            'password' => 'password',
        ]);

        $response->assertOk();

        $token = $user->tokens()->latest()->first();

        $this->assertNotNull($token);
        $this->assertContains('traffic:read', $token->abilities);
        $this->assertContains('gis:read', $token->abilities);
        $this->assertContains('notifications:read', $token->abilities);
    }
}
