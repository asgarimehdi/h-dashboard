<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HrAnalyticsController;
use App\Http\Controllers\Api\HrStatsController;
use App\Http\Controllers\Api\OrgChartController;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(OrgChartController::class, HrStatsController::class, HrAnalyticsController::class);

class HrApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected $unit;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        $this->unit = Unit::create(['name' => 'مرکز بهداشت']);
        $childUnit = Unit::create(['name' => 'خانه بهداشت', 'parent_id' => $this->unit->id]);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::factory()->create([
            'n_code' => $nCode,
            'u_id' => $this->unit->id,
            'status' => 'active',
        ]);

        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);
        $this->user->givePermissionTo('view_hr_dashboard');
    }

    public function test_org_chart_returns_tree_with_counts(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/org-chart', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'personnel_count', 'children']]]);
        $this->assertEquals(1, $response->json('data.0.personnel_count'));
    }

    public function test_org_chart_emits_each_unit_once_with_exact_children(): void
    {
        // #933: the full-tree endpoint duplicated every subtree because it wrote
        // to `$unit->children`, which is the real hasMany relation (there is no
        // `children` column), lazy-loading each parent's children and appending on
        // top. A parent with N children returned > N, compounding with depth.
        $second = Unit::create(['name' => 'خانه بهداشت ۲', 'parent_id' => $this->unit->id]);
        Unit::create(['name' => 'خانه بهداشت ۳', 'parent_id' => $this->unit->id]);
        Unit::create(['name' => 'پایگاه', 'parent_id' => $second->id]);

        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/org-chart', $token);

        $response->assertStatus(200);

        $tree = $response->json('data');

        // One root (the accessible unit), with exactly three direct children.
        $this->assertCount(1, $tree);
        $this->assertSame($this->unit->id, $tree[0]['id']);
        $this->assertCount(3, $tree[0]['children']);

        // The nested child carries exactly one child — the duplication compounded
        // multiplicatively with depth, so this is the assertion the bug escaped.
        $secondNode = collect($tree[0]['children'])->firstWhere('id', $second->id);
        $this->assertNotNull($secondNode);
        $this->assertCount(1, $secondNode['children']);

        // Total emitted nodes equals the number of units in scope (5): the root,
        // its three direct children, and the grandchild.
        $flatten = function (array $nodes) use (&$flatten): array {
            $ids = [];
            foreach ($nodes as $node) {
                $ids[] = $node['id'];
                $ids = array_merge($ids, $flatten($node['children']));
            }

            return $ids;
        };

        $ids = $flatten($tree);
        $this->assertSame(Unit::count(), count($ids));
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_stats_returns_aggregations(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/stats', $token);

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
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/vacancies', $token);

        $response->assertStatus(200);
        // The child unit (خانه بهداشت) has no personnel
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('خانه بهداشت'));
    }

    public function test_personnel_list_with_filters(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/personnel?status=active', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['total']]);
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_personnel_detail_returns_profile(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $person = Person::first();
        $response = $this->apiGet("/api/hr/personnel/{$person->n_code}", $token);

        $response->assertStatus(200)
            ->assertJsonPath('data.n_code', $person->n_code)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_personnel_detail_scoped_to_org(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $otherUnit = Unit::create(['name' => 'Out of scope']);
        $other = Person::factory()->create(['u_id' => $otherUnit->id]);

        $response = $this->apiGet("/api/hr/personnel/{$other->n_code}", $token);
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
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/org-chart/expandable', $token);

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

        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/org-chart/expandable?initial_limit=2', $token);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.initial_limit'));
    }

    public function test_org_chart_expandable_returns_has_children_flag(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/org-chart/expandable', $token);

        $response->assertStatus(200);
        // The parent unit should have has_children = true
        $parentUnit = collect($response->json('data'))->firstWhere('id', $this->unit->id);
        $this->assertTrue($parentUnit['has_children']);
    }

    public function test_org_chart_subtree_returns_children(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet("/api/hr/org-chart/subtree/{$this->unit->id}", $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'children']]]);
        // Should have one child
        $this->assertCount(1, $response->json('data'));
    }

    public function test_org_chart_subtree_scoped_to_org(): void
    {
        $otherUnit = Unit::create(['name' => 'Out of scope']);

        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet("/api/hr/org-chart/subtree/{$otherUnit->id}", $token);

        $response->assertStatus(403);
    }

    // === Issue #444: HR Analytics ===

    public function test_headcount_trend_returns_monthly_data(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/analytics/headcount-trend', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['month', 'count']]]);
    }

    public function test_vacancy_trend_returns_monthly_data(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/analytics/vacancy-trend', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['month', 'count']]]);
    }

    public function test_staffing_ratio_returns_aggregations(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/analytics/staffing-ratio', $token);

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
