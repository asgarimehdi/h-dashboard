<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class HardwareApiTest extends TestCase
{
    use RefreshDatabase;

    protected $tId;
    protected $eId;
    protected $sId;
    protected $rId;
    protected $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(): array
    {
        $this->tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $this->eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $this->sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $this->rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) random_int(1000000000, 2147483647);
        $this->unit = Unit::create(['name' => 'Test Unit']);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId, 'r_id' => $this->rId, 'u_id' => $this->unit->id]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);

        return ['user' => $user, 'unit' => $this->unit];
    }

    /**
     * Verify that /hardware and /hardware/ai load for unauthenticated users.
     * Issue #124: Regression — pages were redirecting to /login.
     */
    public function test_hardware_page_loads_without_auth(): void
    {
        $response = $this->get('/hardware');
        $response->assertStatus(200);
    }

    public function test_hardware_ai_page_loads_without_auth(): void
    {
        $response = $this->get('/hardware/ai');
        $response->assertStatus(200);
    }

    public function test_unauthenticated_user_cannot_access_hardware(): void
    {
        $response = $this->getJson('/api/hardware');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_hardware(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $person = Person::first();
        Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-001', 'type' => 'desktop']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/hardware');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_user_can_create_hardware(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $person = Person::first();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/hardware', [
            'n_code' => $person->n_code,
            'pc_name' => 'New PC',
            'type' => 'laptop',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'New PC']);
    }

    public function test_user_can_update_hardware_with_partial_fields(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $person = Person::first();
        $hardware = Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'Original PC', 'cpu' => 'Intel i3', 'ram' => '8GB']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/hardware/{$hardware->id}", [
            'cpu' => 'Intel i7',
            'ram' => '16GB',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('hardwares', ['id' => $hardware->id, 'cpu' => 'Intel i7', 'ram' => '16GB', 'pc_name' => 'Original PC']);
    }

    public function test_user_can_update_hardware_n_code(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
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
            'u_id' => $this->unit->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/hardware/{$hardware->id}", [
            'n_code' => $person2->n_code,
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/hardware/{$hardware->id}", [
            'n_code' => $person2->n_code,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('hardwares', ['id' => $hardware->id, 'n_code' => $person2->n_code]);
    }

    public function test_user_can_show_hardware(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $person = Person::first();
        $hardware = Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-001']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/hardware/{$hardware->id}");

        $response->assertStatus(200)
            ->assertJson(['data' => ['pc_name' => 'PC-001']]);
    }

    public function test_user_can_delete_hardware(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $person = Person::first();
        $hardware = Hardware::create(['n_code' => $person->n_code, 'pc_name' => 'PC-001']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/hardware/{$hardware->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('hardwares', ['id' => $hardware->id]);
    }

    public function test_create_hardware_requires_n_code_and_pc_name(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/hardware', [
            'type' => 'laptop',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['n_code', 'pc_name']);
    }

    public function test_create_hardware_rejects_invalid_n_code(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/hardware', [
            'n_code' => '9999999999',
            'pc_name' => 'PC-002',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['n_code']);
    }

    public function test_stats_endpoint_returns_aggregated_data(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
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

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/hardware/stats');

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
        ['user' => $userA, 'unit' => $unitA] = $this->createUserWithUnit();
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
        $nCodeB = (string) random_int(1000000000, 2147483647);
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
        $responseA = $this->actingAs($userA, 'sanctum')->getJson('/api/hardware/stats');
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
        $responseB = $this->actingAs($userB, 'sanctum')->getJson('/api/hardware/stats');
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
}