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

    /**
     * Build a parent/child pair plus a user scoped to the parent, for the
     * `loadSubtree` tests below (issue #1347).
     *
     * @return array{0: Unit, 1: Unit, 2: User, 3: string}
     */
    private function makeSubtreeScenario(): array
    {
        $parent = Unit::create(['name' => 'واحد والد']);
        $child = Unit::create(['name' => 'واحد فرزند', 'parent_id' => $parent->id]);

        $nCode = (string) fake()->unique()->numerify('##########');
        // `users.n_code` references `persons.n_code`: the person row must
        // exist before the user row.
        Person::factory()->create([
            'n_code' => $nCode,
            'u_id' => $parent->id,
            'status' => 'active',
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $parent->id);
        $user->givePermissionTo('view_hr_dashboard');

        return [$parent, $child, $user, $this->createApiToken($user, ['hr:read'])];
    }

    public function test_the_subtree_cache_is_invalidated_when_a_person_changes_unit(): void
    {
        [$parent, $child, $user, $token] = $this->makeSubtreeScenario();
        $other = Unit::create(['name' => 'واحد دیگر']);

        $person = Person::factory()->create([
            'u_id' => $child->id,
            'status' => 'active',
        ]);

        // Warm the cache.
        $this->apiGet("/api/hr/org-chart/subtree/{$parent->id}", $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.personnel_count', 1);

        $person->update(['u_id' => $other->id]);

        // Person::saved bumps `unit_hierarchy` when u_id changes — the
        // subtree count must drop at once, not after the 10-minute TTL.
        $this->apiGet("/api/hr/org-chart/subtree/{$parent->id}", $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.personnel_count', 0);
    }

    public function test_the_subtree_cache_is_invalidated_when_a_person_is_created(): void
    {
        [$parent, $child, $user, $token] = $this->makeSubtreeScenario();

        // Warm the cache.
        $this->apiGet("/api/hr/org-chart/subtree/{$parent->id}", $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.personnel_count', 0);

        Person::factory()->create([
            'u_id' => $child->id,
            'status' => 'active',
        ]);

        $this->apiGet("/api/hr/org-chart/subtree/{$parent->id}", $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.personnel_count', 1);
    }

    public function test_the_subtree_cache_is_invalidated_when_a_person_is_deleted(): void
    {
        [$parent, $child, $user, $token] = $this->makeSubtreeScenario();

        $person = Person::factory()->create([
            'u_id' => $child->id,
            'status' => 'active',
        ]);

        // Warm the cache.
        $this->apiGet("/api/hr/org-chart/subtree/{$parent->id}", $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.personnel_count', 1);

        $person->delete();

        $this->apiGet("/api/hr/org-chart/subtree/{$parent->id}", $token)
            ->assertStatus(200)
            ->assertJsonPath('data.0.personnel_count', 0);
    }

    public function test_the_subtree_cache_ttl_is_ten_minutes(): void
    {
        // Decision (ج): the explicit 10-minute TTL on `loadSubtree` stays.
        // Pinned statically: no behavioural probe can tell a 10-minute TTL
        // from a 60-minute one without waiting it out.
        $source = file_get_contents(app_path('Http/Controllers/Api/OrgChartController.php'));

        $this->assertStringContainsString(
            "}, 10, ['subtree' => \$unitId]);",
            $source,
            'loadSubtree must keep its explicit 10-minute TTL.'
        );
    }

    public function test_the_accessible_set_refreshes_when_a_unitless_users_person_changes_unit(): void
    {
        $unitA = Unit::create(['name' => 'واحد الف']);
        $unitB = Unit::create(['name' => 'واحد ب']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::factory()->create([
            'n_code' => $nCode,
            'u_id' => $unitA->id,
            'status' => 'active',
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo('view_hr_dashboard');
        $token = $this->createApiToken($user, ['hr:read']);

        // No user_units rows and no session unit: the fallback reads the
        // person's unit.
        Session::forget('current_unit_id');

        $this->apiGet("/api/hr/org-chart/subtree/{$unitA->id}", $token)->assertStatus(200);
        $this->apiGet("/api/hr/org-chart/subtree/{$unitB->id}", $token)->assertStatus(403);

        $user->person->update(['u_id' => $unitB->id]);

        // The `unit_hierarchy` version is part of the accessible-set cache
        // key, so the bump on the Person write must refresh the set at once.
        $this->apiGet("/api/hr/org-chart/subtree/{$unitA->id}", $token)->assertStatus(403);
        $this->apiGet("/api/hr/org-chart/subtree/{$unitB->id}", $token)->assertStatus(200);
    }
}
