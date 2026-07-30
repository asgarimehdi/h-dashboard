<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class ZoneApiTest extends TestCase
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

        $unit = Unit::create(['name' => 'Test Unit']);

        $nCode = (string) random_int(1000000000, 2147483647);
        Person::create([
            'n_code' => $nCode,
            'f_name' => 'T',
            'l_name' => 'U',
            't_id' => $tId,
            'e_id' => $eId,
            's_id' => $sId,
            'r_id' => $rId,
            'u_id' => $unit->id,
        ]);

        $user = User::create([
            'n_code' => $nCode,
            'password' => Hash::make('password'),
        ]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_unauthenticated_user_cannot_access_zones(): void
    {
        $response = $this->getJson('/api/zones');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_zones(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        Zone::create(['name' => 'Zone A']);
        Zone::create(['name' => 'Zone B']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zones');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data']);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_user_can_create_zone(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/zones', [
            'name' => 'Central Zone',
            'description' => 'Main buildings',
            'color' => '#FF5733',
            'unit_ids' => [$unit->id],
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('zones', ['name' => 'Central Zone']);
    }

    public function test_user_can_show_zone(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $zone = Zone::create(['name' => 'Zone X']);
        $zone->units()->attach($unit->id);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/zones/{$zone->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_user_can_update_zone(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $zone = Zone::create(['name' => 'Old Name', 'color' => '#000000']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/zones/{$zone->id}", [
            'name' => 'New Name',
            'color' => '#FFFFFF',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'name' => 'New Name', 'color' => '#FFFFFF']);
    }

    public function test_user_can_delete_zone(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $zone = Zone::create(['name' => 'Delete Me']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/zones/{$zone->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('zones', ['id' => $zone->id]);
    }

    public function test_zone_units_are_synced(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        // Create zone with unit
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/zones', [
            'name' => 'Zone With Units',
            'unit_ids' => [$unit->id],
        ]);

        $zoneId = $response->json('data.id');
        $this->assertDatabaseHas('zone_unit', ['zone_id' => $zoneId, 'unit_id' => $unit->id]);

        // Update to remove units
        $this->actingAs($user, 'sanctum')->putJson("/api/zones/{$zoneId}", [
            'unit_ids' => [],
        ]);

        $this->assertDatabaseMissing('zone_unit', ['zone_id' => $zoneId]);
    }
}