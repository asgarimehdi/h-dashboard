<?php

namespace Tests\Feature;

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

covers(OrgChartController::class);

/**
 * Issue #1290 (decision A) — the org chart reads `hr_stats_version`, not a
 * namespace of its own, and that is deliberate.
 *
 * `/api/hr/org-chart` renders unit names AND personnel counts, so both writers
 * of that data must invalidate it: `Person` (counts) and `Unit` (names, scope).
 * Both already bump `hr_stats`; a separate `orgchart` namespace would need its
 * own bumps in two models and would silently go stale the day one of them is
 * missed. This test pins the sharing, in both directions.
 */
class HrOrgChartCacheTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected Unit $unit;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        $this->unit = Unit::create(['name' => 'مرکز بهداشت']);

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

    public function test_the_org_chart_cache_is_invalidated_by_a_person_write(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);

        // Warm the cache.
        $this->apiGet('/api/hr/org-chart', $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.personnel_count', 1);

        Person::factory()->create([
            'u_id' => $this->unit->id,
            'status' => 'active',
        ]);

        // Person::saved bumps `hr_stats` — the same namespace the org chart reads.
        $this->apiGet('/api/hr/org-chart', $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.personnel_count', 2);
    }

    public function test_the_org_chart_cache_is_invalidated_by_a_unit_write(): void
    {
        $token = $this->createApiToken($this->user, ['hr:read']);

        // Warm the cache.
        $this->apiGet('/api/hr/org-chart', $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'مرکز بهداشت');

        $this->unit->update(['name' => 'مرکز بهداشت استان']);

        // Unit::updated bumps `hr_stats` too — the name in the tree must refresh.
        $this->apiGet('/api/hr/org-chart', $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'مرکز بهداشت استان');
    }
}
