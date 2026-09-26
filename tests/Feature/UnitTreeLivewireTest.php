<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Tree mechanics for the generic `unit.tree` component (issue #704).
 *
 * Split out of the original HrOrgNodeLivewireTest (now HrOrgChartPageTest):
 * the tree UI moved out of hr/org-chart into unit/tree, and
 * hr/org-node.blade.php no longer exists. What this file covers — roots, lazy
 * children, search, expand/collapse — is the generic component's contract,
 * independent of the HR page that consumes it.
 */
class UnitTreeLivewireTest extends TestCase
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

    // ==================== Plug-in contract ====================

    public function test_renders_with_a_custom_badge_view(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        Livewire::test('unit.tree', [
            'badgeView' => 'livewire.hr.personnel-badge',
            'badgeData' => [],
        ])
            ->assertStatus(200);
    }

    public function test_falls_back_to_no_badge_when_none_supplied(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // No badgeView at all — the tree must still render its nodes.
        Livewire::test('unit.tree')
            ->assertStatus(200);
    }

    public function test_badge_receives_the_unit_it_renders_for(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // One person in this unit — the badge must render "1 نفر" for it.
        Person::factory()->create(['u_id' => $unit->id]);

        Livewire::test('unit.tree', [
            'badgeView' => 'livewire.hr.personnel-badge',
            'badgeData' => [$unit->id => 1],
        ])
            ->assertStatus(200)
            ->assertSee('1 نفر');
    }

    public function test_rendering_stays_query_bounded(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // A four-level chain plus siblings: every relation the render touches
        // must be eager/batched. If any of them degrades to a per-node query
        // (the classic tree N+1 — e.g. unitType per node), the counter blows
        // past the cap. Replaces the vacuous assertIsArray test that was
        // dropped with hr/org-node.
        $top = Unit::query()->firstOrFail();
        Unit::create(['name' => 'شعبه الف', 'parent_id' => $top->id]);
        $mid = Unit::create(['name' => 'سطح دوم', 'parent_id' => $top->id]);
        $deep = Unit::create(['name' => 'سطح سوم', 'parent_id' => $mid->id]);
        Unit::create(['name' => 'سطح چهارم', 'parent_id' => $deep->id]);

        $this->assertNoNPlusOne(function (): void {
            Livewire::test('unit.tree')->assertStatus(200);
        }, 12);
    }

    // ==================== Selection event ====================

    public function test_select_node_dispatches_unit_selected(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        Livewire::test('unit.tree')
            ->call('selectNode', $unit->id)
            ->assertDispatched('unit-selected', unitId: $unit->id);
    }

    public function test_select_node_forwards_any_id_without_scope_checking(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $outsider = Unit::create(['name' => 'بیرونی']);

        // The tree forwards whatever it is told; scoping is the embedding
        // page's job (it re-checks against its own accessibleUnitIds()).
        // Asserted here so that contract stays explicit rather than implied.
        Livewire::test('unit.tree')
            ->call('selectNode', $outsider->id)
            ->assertDispatched('unit-selected', unitId: $outsider->id);
    }

    // ==================== Tree mechanics ====================

    public function test_roots_are_loaded_on_mount(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $component = Livewire::test('unit.tree');

        $this->assertNotEmpty($component->get('rootUnits'));
    }

    public function test_load_children_populates_lazy_children(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $child = Unit::create(['name' => 'فرزند', 'parent_id' => $unit->id]);

        $component = Livewire::test('unit.tree')
            ->call('loadChildren', $unit->id);

        $this->assertArrayHasKey($unit->id, $component->get('lazyChildren'));
        $this->assertTrue($component->get('lazyChildren')[$unit->id]->contains('id', $child->id));
    }

    public function test_load_children_ignores_out_of_scope_units(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $outsider = Unit::create(['name' => 'بیرونی']);

        $component = Livewire::test('unit.tree')
            ->call('loadChildren', $outsider->id);

        $this->assertArrayNotHasKey($outsider->id, $component->get('lazyChildren'));
    }

    public function test_toggle_expands_then_collapses(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $component = Livewire::test('unit.tree');

        $component->call('toggle', (string) $unit->id);
        $this->assertNotContains((string) $unit->id, $component->get('expanded'));

        $component->call('toggle', (string) $unit->id);
        $this->assertContains((string) $unit->id, $component->get('expanded'));
    }

    public function test_collapse_all_clears_expansion_and_children(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $component = Livewire::test('unit.tree')
            ->call('collapseAll');

        $this->assertEmpty($component->get('expanded'));
        $this->assertEmpty($component->get('lazyChildren'));
    }

    public function test_expand_all_opens_every_level_not_just_the_first(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $child = Unit::create(['name' => 'سطح دوم', 'parent_id' => $unit->id]);
        $grand = Unit::create(['name' => 'سطح سوم', 'parent_id' => $child->id]);
        $deep = Unit::create(['name' => 'سطح چهارم', 'parent_id' => $grand->id]);

        $component = Livewire::test('unit.tree')
            ->call('expandAll');

        // The pre-#704 collectAllIds() only ever collected the ROOT ids, so
        // "باز کردن همه" restored a single level. Every depth must open.
        $expanded = array_map('intval', $component->get('expanded'));

        $this->assertContains($unit->id, $expanded);
        $this->assertContains($child->id, $expanded);
        $this->assertContains($grand->id, $expanded);
        $this->assertContains($deep->id, $expanded);
    }

    public function test_search_expands_the_ancestor_chain_of_a_deep_match(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $child = Unit::create(['name' => 'میانه', 'parent_id' => $unit->id]);
        $leaf = Unit::create(['name' => 'برگ عمیق', 'parent_id' => $child->id]);

        $component = Livewire::test('unit.tree')
            ->set('search', 'برگ');

        $expanded = array_map('intval', $component->get('expanded'));

        $this->assertContains($leaf->id, $expanded);
        $this->assertContains($child->id, $expanded);
        $this->assertContains($unit->id, $expanded);
    }

    public function test_search_finds_a_name_containing_a_zwnj(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        Unit::create(['name' => "حرفه\u{200C}ای", 'parent_id' => $unit->id]);

        $component = Livewire::test('unit.tree')
            ->set('search', 'حرفه ای');

        $expanded = array_map('intval', $component->get('expanded'));

        $this->assertContains($unit->id, $expanded);
    }

    public function test_short_search_resets_expansion_without_matching(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $component = Livewire::test('unit.tree');

        $this->assertNotEmpty($component->get('lazyChildren'));

        $component->set('search', 'ب');

        $this->assertEmpty($component->get('lazyChildren'));
        $this->assertEmpty($component->get('expanded'));
    }

    public function test_search_never_leaks_out_of_scope_units(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // A unit outside the caller's scope whose name the search term matches.
        $outsider = Unit::create(['name' => 'واحد کاملا بیرونی']);

        $component = Livewire::test('unit.tree')
            ->set('search', 'کاملا بیرونی');

        $this->assertNotContains($outsider->id, array_map('intval', $component->get('expanded')));
    }
}
