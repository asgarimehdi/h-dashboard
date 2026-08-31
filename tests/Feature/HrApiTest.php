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

covers(\App\Http\Controllers\Api\HrController::class);

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

    // === Issue #444: Expandable Org Chart ===

    public function test_org_chart_expandable_returns_root_units(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/org-chart/expandable');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'parent_id', 'personnel_count', 'has_children', 'level']],
                'meta' => ['initial_limit'],
            ]);
    }

    public function test_org_chart_expandable_respects_initial_limit(): void
    {
        // Create additional root units
        Unit::create(['name' => 'واحد ۲']);
        Unit::create(['name' => 'واحد ۳']);
        Unit::create(['name' => 'واحد ۴']);

        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/org-chart/expandable?initial_limit=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.initial_limit'));
    }

    public function test_org_chart_expandable_returns_has_children_flag(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/org-chart/expandable');

        $response->assertStatus(200);
        // The parent unit should have has_children = true
        $parentUnit = collect($response->json('data'))->firstWhere('id', $this->unit->id);
        $this->assertTrue($parentUnit['has_children']);
    }

    public function test_org_chart_subtree_returns_children(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson("/api/hr/org-chart/subtree/{$this->unit->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'children']]]);
        // Should have one child
        $this->assertCount(1, $response->json('data'));
    }

    public function test_org_chart_subtree_scoped_to_org(): void
    {
        $otherUnit = Unit::create(['name' => 'Out of scope']);

        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson("/api/hr/org-chart/subtree/{$otherUnit->id}");

        $response->assertStatus(403);
    }

    // === Issue #444: HR Analytics ===

    public function test_headcount_trend_returns_monthly_data(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/analytics/headcount-trend');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['month', 'count']]]);
    }

    public function test_vacancy_trend_returns_monthly_data(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/analytics/vacancy-trend');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['month', 'count']]]);
    }

    public function test_staffing_ratio_returns_aggregations(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $response = $this->getJson('/api/hr/analytics/staffing-ratio');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['by_unit_type', 'by_semat'],
            ]);
    }

    public function test_analytics_unauthenticated_gets_401(): void
    {
        $response = $this->getJson('/api/hr/analytics/headcount-trend');
        $response->assertStatus(401);
    }
}
