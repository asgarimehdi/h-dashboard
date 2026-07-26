<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
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
        // Create required FK records for persons table
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
        $response = $this->getJson('/api/unit');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_units(): void
    {
        $this->createUserWithUnit();

        $response = $this->actingAs(User::first(), 'sanctum')->getJson('/api/unit');

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

        $response = $this->actingAs(User::first(), 'sanctum')->getJson('/api/unit');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($inaccessible->id, $ids);
    }
}
