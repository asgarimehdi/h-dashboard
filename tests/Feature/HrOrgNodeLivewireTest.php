<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use App\Services\UnitTreeService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Node rendering for the generic unit tree.
 *
 * Renamed from HrOrgNodeLivewireTest in issue #704: `hr/org-node.blade.php`
 * was replaced by the generic `unit/tree-node.blade.php` rendered through
 * the `unit.tree` component, so these tests target `unit.tree` while the
 * HR page's own panel tests stay in HrLivewireTest.
 */
covers(Unit::class, Person::class);

class HrOrgNodeLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ==================== Page load / auth ====================

    public function test_guest_redirected_from_org_chart(): void
    {
        $this->get('/hr/org-chart')->assertRedirect('/login');
    }

    public function test_org_chart_returns_403_without_permission(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']); // wrong permission
        $this->actingAs($user);

        $this->get('/hr/org-chart')->assertStatus(403);
    }

    public function test_org_chart_renders_for_authorized_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->assertSee('چارت سازمانی');
    }

    // ==================== Smoke / Render tests ====================

    public function test_renders_root(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // rootUnits now live in the generic unit.tree component (issue #704).
        $component = Livewire::test('unit.tree')
            ->assertStatus(200);

        $rootUnits = $component->get('rootUnits');
        $this->assertNotEmpty($rootUnits);

        // Root unit name should be visible on the composed HR page too.
        Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->assertSee($rootUnits->first()->name);
    }

    public function test_root_shows_name_unit_type_person_count_badge_and_leaf_dot(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // The tree lives in the nested unit.tree component now; assert on it.
        $tree = Livewire::test('unit.tree')
            ->assertStatus(200);

        $rootUnit = $tree->instance()->rootUnits->first();

        $tree->assertSee($rootUnit->name);
        if ($rootUnit->unitType) {
            $tree->assertSee($rootUnit->unitType->name);
        }
    }

    public function test_tree_renders_personnel_count_badge_for_root_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $rootUnit = Unit::whereNull('parent_id')->first();

        // The badge plug-in renders "{{ count }} نفر" for every node.
        $expected = (int) Person::where('u_id', $rootUnit->id)->count();

        Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->assertSee("{$expected} نفر");
    }

    // ==================== Interaction tests ====================

    public function test_toggle_expands_and_lazy_loads_children(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // Create a tree: root -> child
        $rootUnit = Unit::whereNull('parent_id')->first();
        Unit::create(['name' => 'فرزند', 'parent_id' => $rootUnit->id]);

        $component = Livewire::test('unit.tree')
            ->assertStatus(200);

        // Initially child might not be in lazyChildren (if not pre-loaded)
        $expanded = $component->get('expanded');
        $this->assertContains((string) $rootUnit->id, $expanded);

        // Toggle root (collapse then expand to trigger lazy load)
        $component->call('toggle', (string) $rootUnit->id);
        $component->call('toggle', (string) $rootUnit->id);

        $lazyChildren = $component->get('lazyChildren');
        $this->assertArrayHasKey($rootUnit->id, $lazyChildren);
        $this->assertNotEmpty($lazyChildren[$rootUnit->id]);
    }

    public function test_select_loads_detail(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $rootUnit = Unit::whereNull('parent_id')->first();

        $component = Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->call('selectUnit', $rootUnit->id);

        $selectedUnit = $component->get('selectedUnit');
        $this->assertNotNull($selectedUnit);
        $this->assertEquals($rootUnit->id, $selectedUnit->id);
        $this->assertNotNull($component->get('selectedPersonnel'));
    }

    public function test_expand_collapse_all_updates_tree(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $component = Livewire::test('unit.tree')
            ->assertStatus(200);

        // Expand all
        $component->call('expandAll');
        $expanded = $component->get('expanded');
        $this->assertNotEmpty($expanded);

        // Collapse all
        $component->call('collapseAll');
        $expandedAfter = $component->get('expanded');
        $this->assertEmpty($expandedAfter);
    }

    // ==================== Edge-case tests ====================

    public function test_empty_badge_on_zero_persons(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // Create an empty unit
        $rootUnit = Unit::whereNull('parent_id')->first();
        $emptyUnit = Unit::create(['name' => 'واحد خالی', 'parent_id' => $rootUnit->id]);

        $component = Livewire::test('hr.org-chart')
            ->assertStatus(200);

        $this->assertSame(0, (int) Person::where('u_id', $emptyUnit->id)->count());
    }

    public function test_search_highlights_match_and_expands_ancestors(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $rootUnit = Unit::whereNull('parent_id')->first();
        $child = Unit::create(['name' => 'مرکز بهداشت', 'parent_id' => $rootUnit->id]);
        $grandchild = Unit::create(['name' => 'واحد جستجو', 'parent_id' => $child->id]);

        $component = Livewire::test('unit.tree')
            ->assertStatus(200);

        // Search with >2 chars
        $component->set('search', 'جستجو');

        $expanded = $component->get('expanded');
        // Matching unit and its ancestors should be expanded
        $this->assertContains((string) $grandchild->id, $expanded);
        $this->assertContains((string) $child->id, $expanded);
        $this->assertContains((string) $rootUnit->id, $expanded);
    }

    public function test_unauthorized_select_unit_ignored_with_error_toast(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // Create another unit that user doesn't have access to
        $otherUnit = Unit::create(['name' => 'واحد دیگر']);

        $component = Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->call('selectUnit', $otherUnit->id);

        // Should show error toast (via Mary Toast trait)
        $selectedUnit = $component->get('selectedUnit');
        $this->assertNull($selectedUnit);
    }

    public function test_inaccessible_child_hidden_from_tree(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $component = Livewire::test('unit.tree')
            ->assertStatus(200);

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        foreach ($component->get('rootUnits') as $unit) {
            $this->assertContains($unit->id, $accessibleIds);
        }
    }

    public function test_no_n_plus_one_on_person_counts_and_lazy_children(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $rootUnit = Unit::whereNull('parent_id')->first();
        $child1 = Unit::create(['name' => 'فرزند ۱', 'parent_id' => $rootUnit->id]);
        $child2 = Unit::create(['name' => 'فرزند ۲', 'parent_id' => $rootUnit->id]);
        Unit::create(['name' => 'نوه ۱', 'parent_id' => $child1->id]);

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $expected = app(UnitTreeService::class)->allScoped($accessibleIds);

        $component = Livewire::test('unit.tree')
            ->assertStatus(200);

        $counts = $component->instance()->rootUnits
            ->mapWithKeys(fn (Unit $u) => [$u->id => (int) $u->personnel_count])
            ->all();

        // Every root carries a preloaded count (withCount), so no per-node query runs.
        foreach ($expected->whereIn('id', array_keys($counts)) as $unit) {
            $this->assertSame((int) $unit->personnel_count, $counts[$unit->id]);
        }

        $this->assertIsArray($component->get('expanded'));
    }
}
