<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class HrApiTest extends TestCase
{
    use RefreshDatabase;

    protected $unit;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $tId = DB::table('tahsils')->insertGetId(['name' => 'کارشناسی']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'رسمی']);
        $sId = DB::table('semats')->insertGetId(['name' => 'کارشناس']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'رتبه ۱']);

        $this->unit = Unit::create(['name' => 'مرکز بهداشت']);
        $childUnit = Unit::create(['name' => 'خانه بهداشت', 'parent_id' => $this->unit->id]);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'علی', 'l_name' => 'محمدی',
            't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
            'u_id' => $this->unit->id, 'status' => 'active',
        ]);

        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);
        $this->seed(PermissionSeeder::class);
        $this->user->givePermissionTo('view_hr_dashboard');
    }

    public function test_org_chart_returns_tree_with_counts(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/org-chart');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'personnel_count', 'children']]]);
        $this->assertEquals(1, $response->json('data.0.personnel_count'));
    }

    public function test_stats_returns_aggregations(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'total_personnel',
                    'by_unit',
                    'by_semat',
                    'by_tahsil',
                    'by_estekhdam',
                    'by_radif',
                ],
            ]);
        $this->assertEquals(1, $response->json('data.total_personnel'));
    }

    public function test_vacancies_lists_empty_units(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/vacancies');

        $response->assertStatus(200);
        // The child unit (خانه بهداشت) has no personnel
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('خانه بهداشت'));
    }

    public function test_personnel_list_with_filters(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/personnel?status=active');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['total']]);
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_personnel_detail_returns_profile(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $person = Person::first();
        $response = $this->getJson("/api/hr/personnel/{$person->n_code}");

        $response->assertStatus(200)
            ->assertJsonPath('data.n_code', $person->n_code)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_personnel_detail_scoped_to_org(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $otherUnit = Unit::create(['name' => 'Out of scope']);
        $other = Person::create([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'f_name' => 'X', 'l_name' => 'Y', 'u_id' => $otherUnit->id,
            't_id' => DB::table('tahsils')->insertGetId(['name' => 'T']),
            'e_id' => DB::table('estekhdams')->insertGetId(['name' => 'E']),
            's_id' => DB::table('semats')->insertGetId(['name' => 'S']),
            'r_id' => DB::table('radifs')->insertGetId(['name' => 'R']),
        ]);

        $response = $this->getJson("/api/hr/personnel/{$other->n_code}");
        $response->assertStatus(403);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->getJson('/api/hr/stats');
        $response->assertStatus(401);
    }
}
