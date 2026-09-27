<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HardwareController;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(HardwareController::class);

class HardwareApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected $tId;

    protected $eId;

    protected $sId;

    protected $rId;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        $this->tId = DB::table('tahsils')->first()->id;
        $this->eId = DB::table('estekhdams')->first()->id;
        $this->sId = DB::table('semats')->first()->id;
        $this->rId = DB::table('radifs')->first()->id;
    }

    /**
     * Verify that /hardware redirects unauthenticated users to login.
     * Issue #216: guests must NOT see sensitive hardware data (regression #124
     * was intentionally reverted — the redirect is now the expected behavior).
     */
    public function test_hardware_page_loads_without_auth(): void
    {
        $response = $this->get('/hardware');
        $response->assertStatus(302); // redirect to /login
        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_cannot_access_hardware(): void
    {
        $response = $this->getJson('/api/hardware');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_hardware(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $person = Person::first();
        Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-001', 'type' => 'desktop']);

        $token = $this->createApiToken($user, ['hardware:read']);
        $response = $this->apiGet('/api/hardware', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_user_can_create_hardware(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $person = Person::first();

        $token = $this->createApiToken($user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost('/api/hardware', [
            'n_code' => $person->n_code,
            'pc_name' => 'New PC',
            'type' => 'laptop',
        ], $token);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'New PC']);
    }

    public function test_user_can_update_hardware_with_partial_fields(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $person = Person::first();
        $hardware = Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'Original PC', 'cpu' => 'Intel i3', 'ram' => '8GB']);

        $token = $this->createApiToken($user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPut("/api/hardware/{$hardware->id}", [
            'cpu' => 'Intel i7',
            'ram' => '16GB',
        ], $token);

        $response->assertStatus(200);
        $this->assertDatabaseHas('hardwares', ['id' => $hardware->id, 'cpu' => 'Intel i7', 'ram' => '16GB', 'pc_name' => 'Original PC']);
    }

    public function test_user_can_update_hardware_n_code(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $person = Person::first();
        $hardware = Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-001']);

        $person2 = Person::create([
            'n_code' => '1111111111',
            'f_name' => 'Test2',
            'l_name' => 'User2',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $unit->id,
        ]);

        $token = $this->createApiToken($user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPut("/api/hardware/{$hardware->id}", [
            'n_code' => $person2->n_code,
        ], $token);

        $response = $this->apiPut("/api/hardware/{$hardware->id}", [
            'n_code' => $person2->n_code,
        ], $token);

        $response->assertStatus(200);
        $this->assertDatabaseHas('hardwares', ['id' => $hardware->id, 'n_code' => $person2->n_code]);
    }

    public function test_user_can_show_hardware(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $person = Person::first();
        $hardware = Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-001']);

        $token = $this->createApiToken($user, ['hardware:read']);
        $response = $this->apiGet("/api/hardware/{$hardware->id}", $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['pc_name' => 'PC-001']]);
    }

    public function test_user_can_delete_hardware(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $person = Person::first();
        $hardware = Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-001']);

        $token = $this->createApiToken($user, ['hardware:read', 'hardware:write']);
        $response = $this->apiDelete("/api/hardware/{$hardware->id}", $token);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('hardwares', ['id' => $hardware->id]);
    }

    public function test_create_hardware_requires_n_code_and_pc_name(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $token = $this->createApiToken($user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost('/api/hardware', [
            'type' => 'laptop',
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['n_code', 'pc_name']);
    }

    public function test_create_hardware_rejects_invalid_n_code(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $token = $this->createApiToken($user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost('/api/hardware', [
            'n_code' => '9999999999',
            'pc_name' => 'PC-002',
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['n_code']);
    }

    public function test_stats_endpoint_returns_aggregated_data(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $person = Person::first();
        $person2 = Person::create([
            'n_code' => '2222222222',
            'f_name' => 'Test2',
            'l_name' => 'User2',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $unit->id,
        ]);

        Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-001', 'type' => 'desktop', 'shutdown' => false]);
        Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-002', 'type' => 'laptop', 'shutdown' => true]);
        Hardware::create(['n_code' => $person2->n_code, 'pc_name' => 'PC-003', 'type' => 'desktop', 'shutdown' => false]);

        $token = $this->createApiToken($user, ['hardware:read']);
        $response = $this->apiGet('/api/hardware/stats', $token);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total' => 3,
                    'shutdown' => 1,
                    'by_type' => ['desktop' => 2, 'laptop' => 1],
                ],
            ]);
    }

    public function test_stats_endpoint_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA] = $this->createUserWithUnit(['manage_hardware']);
        $personA = Person::first();
        $personA2 = Person::create([
            'n_code' => '3333333333',
            'f_name' => 'Test3',
            'l_name' => 'User3',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $unitA->id,
        ]);

        // User B in Unit B (different unit)
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $unitB = Unit::create(['name' => 'Unit B']);
        $nCodeB = (string) fake()->unique()->numerify('##########');
        $personB = Person::create([
            'n_code' => $nCodeB,
            'f_name' => 'TestB',
            'l_name' => 'UserB',
            't_id' => $tId,
            'e_id' => $eId,
            's_id' => $sId,
            'r_id' => $rId,
            'u_id' => $unitB->id,
        ]);
        $userB = User::create(['n_code' => $nCodeB, 'password' => Hash::make('password')]);
        $userB->units()->attach($unitB->id, ['role' => 'staff', 'is_primary' => true]);

        // Create hardware for both units
        Hardware::create(['n_code' => $personA->n_code, 'pc_name' => 'PC-A1', 'type' => 'desktop', 'shutdown' => false]);
        Hardware::create(['n_code' => $personA2->n_code, 'pc_name' => 'PC-A2', 'type' => 'laptop', 'shutdown' => true]);
        Hardware::create(['n_code' => $personB->n_code, 'pc_name' => 'PC-B1', 'type' => 'server', 'shutdown' => false]);

        // User A should only see their unit's hardware (2 items) - set session to Unit A
        Session::put('current_unit_id', $unitA->id);
        $tokenA = $this->createApiToken($userA, ['hardware:read']);
        $responseA = $this->apiGet('/api/hardware/stats', $tokenA);
        $responseA->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total' => 2,
                    'shutdown' => 1,
                    'by_type' => ['desktop' => 1, 'laptop' => 1],
                ],
            ]);

        // User B should only see their unit's hardware (1 item) - set session to Unit B
        Session::put('current_unit_id', $unitB->id);
        $tokenB = $this->createApiToken($userB, ['hardware:read']);
        $responseB = $this->apiGet('/api/hardware/stats', $tokenB);
        $responseB->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total' => 1,
                    'shutdown' => 0,
                    'by_type' => ['server' => 1],
                ],
            ]);
    }

    /**
     * Issue #217: stats are cached, and a hardware write invalidates the cache
     * so subsequent reads reflect fresh data.
     */
    public function test_stats_cache_is_invalidated_on_write(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $person = Person::first();

        // Use a single token with both abilities to avoid Sanctum caching
        // issues when switching between tokens for the same user.
        $token = $this->createApiToken($user, ['hardware:read', 'hardware:write']);

        // Initial state: 1 device
        Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-CACHE-1', 'type' => 'desktop', 'shutdown' => false]);
        $this->apiGet('/api/hardware/stats', $token)
            ->assertJsonPath('data.total', 1);

        // Create a new device → cache must be invalidated
        $this->apiPost('/api/hardware', [
            'n_code' => $person->n_code,
            'pc_name' => 'PC-CACHE-2',
            'type' => 'laptop',
            'shutdown' => true,
        ], $token)->assertStatus(201);

        $this->apiGet('/api/hardware/stats', $token)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.shutdown', 1);

        // Delete one → cache invalidated again
        $target = Hardware::where('pc_name', 'PC-CACHE-1')->first();
        $this->apiDelete("/api/hardware/{$target->id}", $token)
            ->assertStatus(200);

        $this->apiGet('/api/hardware/stats', $token)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.shutdown', 1);
    }

    public function test_hardware_validation_rules_store_requires_n_code_and_pc_name(): void
    {
        $controller = new HardwareController;
        $reflection = new \ReflectionMethod($controller, 'hardwareValidationRules');

        $storeRules = $reflection->invoke($controller, required: true);

        $this->assertArrayHasKey('n_code', $storeRules);
        $this->assertArrayHasKey('pc_name', $storeRules);
        $this->assertStringContainsString('required', $storeRules['n_code']);
        $this->assertStringContainsString('required', $storeRules['pc_name']);
        $this->assertArrayNotHasKey('shutdown', $storeRules);
    }

    public function test_hardware_validation_rules_update_uses_sometimes_and_includes_shutdown(): void
    {
        $controller = new HardwareController;
        $reflection = new \ReflectionMethod($controller, 'hardwareValidationRules');

        $updateRules = $reflection->invoke($controller, required: false, includeShutdown: true);

        $this->assertStringContainsString('sometimes|required', $updateRules['n_code']);
        $this->assertStringContainsString('sometimes|required', $updateRules['pc_name']);
        $this->assertArrayHasKey('shutdown', $updateRules);
        $this->assertEquals('boolean', $updateRules['shutdown']);
    }

    public function test_hardware_validation_rules_has_all_expected_fields(): void
    {
        $controller = new HardwareController;
        $reflection = new \ReflectionMethod($controller, 'hardwareValidationRules');

        $rules = $reflection->invoke($controller, required: true);

        $expectedFields = [
            'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'vlan', 'motherboard', 'cpu', 'ram',
            'hdd', 'comments', 'mark', 'clean_at',
        ];

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $rules, "Missing rule for field: {$field}");
        }
    }
}
