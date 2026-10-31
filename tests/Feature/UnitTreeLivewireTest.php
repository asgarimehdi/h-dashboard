<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
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

    public function test_initial_paint_batches_one_query_per_level(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // The exact N+1 shape from issue #722: 1 → 6 → 36. The old preload
        // issued one childrenOf() per node while expanding PLUS one query per
        // expanded level-3 node in loadExpandedChildren (~44 queries here);
        // the batched paint costs one query per level and stays flat.
        for ($i = 0; $i < 6; $i++) {
            $mid = Unit::create(['name' => "سطح دوم {$i}", 'parent_id' => $unit->id]);

            for ($j = 0; $j < 6; $j++) {
                Unit::create(['name' => "سطح سوم {$i}-{$j}", 'parent_id' => $mid->id]);
            }
        }

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

    // ==================== Issue #1013: expand-all batches per level ====================

    public function test_expand_all_queries_are_bound_by_depth_not_by_unit_count(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // The #722 shape again: 1 → 6 → 36 (43 units, depth 3). `expandAll()`
        // used to issue one `childrenOf()` per node — ~43 queries for this one
        // click; the level-wise walk costs one query per level.
        for ($i = 0; $i < 6; $i++) {
            $mid = Unit::create(['name' => "سطح دوم {$i}", 'parent_id' => $unit->id]);

            for ($j = 0; $j < 6; $j++) {
                Unit::create(['name' => "سطح سوم {$i}-{$j}", 'parent_id' => $mid->id]);
            }
        }

        $component = Livewire::test('unit.tree');

        // Count the CLICK only — the mount's own paint is covered by
        // test_initial_paint_batches_one_query_per_level.
        $this->assertNoNPlusOne(function () use ($component): void {
            $component->call('expandAll');
        }, 12);

        // Emit-once, and every level opened: 1 + 6 + 36.
        $this->assertCount(43, $component->get('expanded'));
    }

    public function test_expand_all_membership_and_lazy_children_are_exact(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $childA = Unit::create(['name' => 'شعبه الف', 'parent_id' => $unit->id]);
        $childB = Unit::create(['name' => 'شعبه ب', 'parent_id' => $unit->id]);
        $grand = Unit::create(['name' => 'زیرشعبه', 'parent_id' => $childA->id]);

        $component = Livewire::test('unit.tree')->call('expandAll');

        $expanded = array_map('intval', $component->get('expanded'));
        $expected = [$unit->id, $childA->id, $childB->id, $grand->id];
        sort($expanded);
        sort($expected);

        $this->assertSame($expected, $expanded);

        // #933: every in-scope unit appears exactly ONCE. A `$node->children`
        // read anywhere in the walk multiplies the subtree instead.
        $this->assertSame(count($expected), count(array_unique($expanded)));

        // Childless units are cached as EMPTY collections: `childrenOfMany()`
        // gives parents with no children NO key, so the walk must read with
        // `->get($id, collect())` (a bare `[$id]` access would throw).
        $lazy = $component->get('lazyChildren');

        $rootChildren = $lazy[$unit->id]->pluck('id')->map(static fn ($id) => (int) $id)->all();
        sort($rootChildren);
        $this->assertSame([$childA->id, $childB->id], $rootChildren);

        $this->assertTrue($lazy[$childA->id]->contains('id', $grand->id));
        $this->assertCount(0, $lazy[$childB->id]);
        $this->assertCount(0, $lazy[$grand->id]);
    }

    public function test_collect_all_ids_terminates_on_a_parent_id_cycle(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // A ↔ B. Neither is reachable from roots() (each has an in-scope
        // parent), so the walk is seeded with the cycle head directly — the
        // shape the `$visited` guard exists for. It must terminate, not hang.
        $a = $unit;
        $b = Unit::create(['name' => 'چرخه ب', 'parent_id' => $a->id]);
        $a->update(['parent_id' => $b->id]);

        $component = Livewire::test('unit.tree');
        $instance = $component->instance();

        // Livewire's `call()` re-hydrates public props from the snapshot, so a
        // seeded `rootUnits` would be discarded; invoke the walk itself.
        $walk = new \ReflectionMethod($instance, 'collectAllIds');
        $walk->setAccessible(true);

        $ids = $walk->invoke($instance, collect([$a->fresh()]), $instance->accessibleIds(), []);

        $this->assertCount(2, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_expand_all_is_idempotent(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        Unit::create(['name' => 'شعبه', 'parent_id' => $unit->id]);

        $component = Livewire::test('unit.tree');

        $component->call('expandAll');
        $first = $component->get('expanded');

        $component->call('expandAll');
        $second = $component->get('expanded');

        // `expandAll()` runs `array_values(array_unique(...))` — preserve it.
        $this->assertSame($first, $second);
    }

    public function test_expand_all_with_an_empty_scope_stays_fail_closed(): void
    {
        // No session unit, no user unit, no person unit → scope is `[]`, which
        // means "in scope of nothing", never "unrestricted".
        Session::forget('current_unit_id');

        $person = Person::factory()->create(['u_id' => null]);
        $user = User::factory()->create(['n_code' => $person->n_code]);
        $user->givePermissionTo('view_hr_dashboard');
        $this->actingAs($user);

        $component = Livewire::test('unit.tree');

        // `childrenOfMany()` short-circuits on an empty intersection without
        // touching the database, and there are no roots to walk: zero queries.
        $this->assertNoNPlusOne(function () use ($component): void {
            $component->call('expandAll');
        }, 0);

        $this->assertSame([], $component->get('expanded'));
        $this->assertSame([], $component->get('lazyChildren'));
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

    public function test_search_query_count_does_not_grow_with_distinct_parents(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // 30 DISTINCT parents, one matching leaf each: depth 2, 61 units.
        // A single-match fixture would pass both before and after the fix
        // (its chain shares one parent); the N+1 only shows up when every
        // match hangs off a different branch.
        for ($i = 0; $i < 30; $i++) {
            $mid = Unit::create(['name' => "میانی جستجو {$i}", 'parent_id' => $unit->id]);
            Unit::create(['name' => "برگ جستجو {$i}", 'parent_id' => $mid->id]);
        }

        // Count the SEARCH update only — the mount's own paint is covered by
        // test_initial_paint_batches_one_query_per_level.
        $component = Livewire::test('unit.tree');

        // Measured: 5 = 3 search queries (units + 2 eager parent levels) +
        // 1 batched childrenOfMany + 1 scope re-resolution on rehydrate.
        // The same fixture cost 34 before the fix, so the cap discriminates.
        $this->assertNoNPlusOne(function () use ($component): void {
            $component->set('search', 'برگ جستجو');
        }, 5);

        // Every rendered node (root + 30 mids + 30 leaves) is expanded, so
        // tree-node.blade.php reads the lazy cache and never calls
        // hasChildren(); the count above stays a search+chain measurement.
        $expanded = array_map('intval', $component->get('expanded'));
        $this->assertContains($unit->id, $expanded);
        $this->assertCount(61, $expanded);
    }
}
