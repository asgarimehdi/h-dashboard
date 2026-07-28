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

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(): array
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) rand(1000000000, 9999999999);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => 1]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $unit = Unit::create(['name' => 'Test Unit']);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
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
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => 1,
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
}