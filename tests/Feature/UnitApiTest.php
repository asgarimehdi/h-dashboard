<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class UnitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(array $unitAttrs = []): array
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test Tahsil']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test Estekhdam']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test Semat']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test Radif']);

        $nCode = (string) rand(1000000000, 9999999999);
        Person::create([
            'n_code' => $nCode,
            'f_name' => 'Test',
            'l_name' => 'User',
            't_id' => $tId,
            'e_id' => $eId,
            's_id' => $sId,
            'r_id' => $rId,
            'u_id' => 1,
        ]);
        $user = User::create([
            'n_code' => $nCode,
            'password' => Hash::make('password'),
        ]);
        $unit = Unit::create(array_merge([
            'name' => 'Test Unit',
        ], $unitAttrs));
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_unauthenticated_user_cannot_access_units(): void
    {
        $response = $this->getJson('/api/units');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_units(): void
    {
        $this->createUserWithUnit();

        $response = $this->actingAs(User::first(), 'sanctum')->getJson('/api/units');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_unit_list_respects_accessible_scope(): void
    {
        $this->createUserWithUnit(['name' => 'Accessible']);
        $inaccessible = Unit::create(['name' => 'Inaccessible']);

        $response = $this->actingAs(User::first(), 'sanctum')->getJson('/api/units');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($inaccessible->id, $ids);
    }

    public function test_user_can_show_accessible_unit(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit();

        $response = $this->actingAs(User::first(), 'sanctum')->getJson("/api/units/{$unit->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name']]);
    }

    public function test_user_cannot_show_inaccessible_unit(): void
    {
        $this->createUserWithUnit();
        $inaccessible = Unit::create(['name' => 'Hidden']);

        $response = $this->actingAs(User::first(), 'sanctum')->getJson("/api/units/{$inaccessible->id}");

        $response->assertStatus(403);
    }

    public function test_user_can_create_unit(): void
    {
        $this->createUserWithUnit();
        $type = UnitType::create(['name' => 'Test Type']);

        $response = $this->actingAs(User::first(), 'sanctum')->postJson('/api/units', [
            'name' => 'New Unit',
            'unit_type_id' => $type->id,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('units', ['name' => 'New Unit']);
    }

    public function test_user_can_update_accessible_unit(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit();

        $response = $this->actingAs(User::first(), 'sanctum')->putJson("/api/units/{$unit->id}", [
            'name' => 'Updated Unit',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['name' => 'Updated Unit']]);
    }

    public function test_user_cannot_update_inaccessible_unit(): void
    {
        $this->createUserWithUnit();
        $inaccessible = Unit::create(['name' => 'Hidden']);

        $response = $this->actingAs(User::first(), 'sanctum')->putJson("/api/units/{$inaccessible->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_delete_accessible_unit(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit();

        $response = $this->actingAs(User::first(), 'sanctum')->deleteJson("/api/units/{$unit->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    }

    public function test_user_cannot_delete_unit_with_children(): void
    {
        ['unit' => $parent] = $this->createUserWithUnit();
        $child = Unit::create(['name' => 'Child', 'parent_id' => $parent->id]);

        $response = $this->actingAs(User::first(), 'sanctum')->deleteJson("/api/units/{$parent->id}");

        $response->assertStatus(422)
            ->assertJson(['message' => 'Cannot delete unit with children.']);
    }
}
