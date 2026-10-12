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

    // === Issue #952: trends are cumulative stocks over a materialised window ===

    /**
     * Back-date the seeded person so the hire sits two months in the past.
     *
     * `created_at` is not fillable, so a plain `create()`/`update()` would
     * silently drop it — `forceFill()` is the only way to place the hire.
     */
    private function backdateHireToTwoMonthsAgo(): void
    {
        Person::firstOrFail()->forceFill([
            'created_at' => now()->startOfMonth()->subMonths(2),
        ])->saveQuietly();
    }

    /**
     * Expected `YYYY-MM` labels for a window of $months buckets ending with
     * the current month, oldest first.
     *
     * @return array<int, string>
     */
    private function expectedMonthLabels(int $months): array
    {
        $labels = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $labels[] = now()->startOfMonth()->subMonths($i)->format('Y-m');
        }

        return $labels;
    }

    public function test_headcount_trend_is_a_cumulative_stock_over_a_full_window(): void
    {
        $this->backdateHireToTwoMonthsAgo();
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/analytics/headcount-trend', $token);

        $response->assertStatus(200);
        $data = $response->json('data');

        // months=12 yields exactly 12 buckets — the old GROUP BY returned only
        // the months that contained a hire (here: a single bucket).
        $this->assertCount(12, $data);
        $this->assertSame($this->expectedMonthLabels(12), array_column($data, 'month'));

        // Hired two months ago: nine empty months, then the hire month and the
        // two months after it all report the headcount as 1.
        $this->assertSame(
            [0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1],
            array_column($data, 'count')
        );
    }

    public function test_headcount_trend_months_param_sets_exact_bucket_count(): void
    {
        $this->backdateHireToTwoMonthsAgo();
        $token = $this->createApiToken($this->user, ['hr:read']);

        $response = $this->apiGet('/api/hr/analytics/headcount-trend?months=3', $token);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(3, $data);
        $this->assertSame($this->expectedMonthLabels(3), array_column($data, 'month'));
        $this->assertSame([1, 1, 1], array_column($data, 'count'));

        $single = $this->apiGet('/api/hr/analytics/headcount-trend?months=1', $token);
        $single->assertStatus(200);
        $this->assertCount(1, $single->json('data'));
        $this->assertSame($this->expectedMonthLabels(1), array_column($single->json('data'), 'month'));
    }

    public function test_headcount_trend_rejects_out_of_range_months(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);

        $this->apiGet('/api/hr/analytics/headcount-trend?months=0', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['months']);

        $this->apiGet('/api/hr/analytics/headcount-trend?months=-6', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['months']);

        $this->apiGet('/api/hr/analytics/headcount-trend?months=25', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['months']);
    }

    public function test_vacancy_trend_counts_staffed_units_as_a_stock(): void
    {
        $this->backdateHireToTwoMonthsAgo();
        $token = $this->createApiToken($this->user, ['hr:read']);
        $response = $this->apiGet('/api/hr/analytics/vacancy-trend', $token);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(12, $data);
        $this->assertSame($this->expectedMonthLabels(12), array_column($data, 'month'));

        // Two units in scope, one staffed two months ago: vacant is 2 before
        // the hire month and 1 from the hire month on. The old query joined
        // hires on month equality, so every month after the hire reported the
        // whole organisation (2) as vacant.
        $this->assertSame(
            [2, 2, 2, 2, 2, 2, 2, 2, 2, 1, 1, 1],
            array_column($data, 'count')
        );
    }

    public function test_vacancy_trend_rejects_out_of_range_months(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);

        $this->apiGet('/api/hr/analytics/vacancy-trend?months=0', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['months']);

        $this->apiGet('/api/hr/analytics/vacancy-trend?months=-6', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['months']);
    }

    public function test_non_numeric_months_falls_back_to_the_default_window(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);

        $response = $this->apiGet('/api/hr/analytics/headcount-trend?months=abc', $token);

        // Junk input renders the default window, exactly like `?days=abc`
        // does under `ReportDays` — it must not error and must not collapse
        // to a single bucket.
        $response->assertStatus(200);
        $this->assertCount(12, $response->json('data'));
    }
}
