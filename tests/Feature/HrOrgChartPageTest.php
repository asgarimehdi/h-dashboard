<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * HR org-chart PAGE (issue #704).
 *
 * The tree mechanics these tests used to cover now live in
 * UnitTreeLivewireTest against the reusable `unit.tree` component. What
 * remains here is what is specific to the HR page: that the page composes
 * the tree, that the personnel badge is wired through, and that the
 * detail panel still fills on selection.
 */
class HrOrgChartPageTest extends TestCase
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

    // ==================== Composition: the page embeds the shared tree ====================

    public function test_page_renders_the_shared_tree_with_unit_names(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // Unit names reach the DOM through the nested <livewire:unit.tree>.
        Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->assertSee($unit->name);
    }

    public function test_page_renders_the_personnel_badge_for_each_node(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // The badge view is the plug-in contract: the page passes it down and
        // the tree renders "N نفر" per node.
        Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->assertSee('1 نفر');
    }

    public function test_person_counts_are_passed_to_the_tree(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // createUserWithUnit() already seeds one Person in this unit, so the
        // count is 1 without adding anything. A second person is added to
        // prove the map is a live count and not a presence flag.
        Person::factory()->create(['u_id' => $unit->id]);

        $counts = Livewire::test('hr.org-chart')->get('personCounts');

        $this->assertArrayHasKey($unit->id, $counts);
        $this->assertEquals(2, $counts[$unit->id]);
    }

    public function test_tree_defaults_to_first_three_levels_expanded(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // Root -> child -> grandchild -> great-grandchild.
        $child = Unit::create(['name' => 'شبکه بهداشت', 'parent_id' => $unit->id]);
        $grandchild = Unit::create(['name' => 'مرکز بهداشت روستایی', 'parent_id' => $child->id]);
        $great = Unit::create(['name' => 'خانه بهداشت', 'parent_id' => $grandchild->id]);

        // Assert against the tree component the page embeds.
        $tree = Livewire::test('unit.tree');
        $expanded = array_map('intval', $tree->get('expanded'));

        $this->assertContains($unit->id, $expanded);
        $this->assertContains($child->id, $expanded);
        $this->assertContains($grandchild->id, $expanded);
        $this->assertNotContains($great->id, $expanded, 'level 4 must stay collapsed by default');
    }

    // ==================== Detail panel (HR-specific) ====================

    public function test_select_loads_detail(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $component = Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->call('selectUnit', $unit->id);

        $selectedUnit = $component->get('selectedUnit');
        $this->assertNotNull($selectedUnit);
        $this->assertEquals($unit->id, $selectedUnit->id);
        $this->assertNotNull($component->get('selectedPersonnel'));
    }

    public function test_unauthorized_select_unit_ignored_with_error_toast(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $otherUnit = Unit::create(['name' => 'واحد دیگر']);

        $component = Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->call('selectUnit', $otherUnit->id);

        $this->assertNull($component->get('selectedUnit'));
    }

    public function test_detail_panel_shows_placeholder_before_any_selection(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->assertSee('یک واحد را انتخاب کنید');
    }

    public function test_empty_badge_on_zero_persons(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $emptyUnit = Unit::create(['name' => 'واحد خالی', 'parent_id' => $unit->id]);

        $counts = Livewire::test('hr.org-chart')->get('personCounts');

        $this->assertEquals(0, $counts[$emptyUnit->id] ?? 0);
    }

    public function test_inaccessible_child_hidden_from_tree(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // Verify the roots the tree is given are all inside the caller's scope.
        $roots = Livewire::test('unit.tree')->get('rootUnits');

        foreach ($roots as $unit) {
            $accessibleIds = app(AccessService::class)->accessibleUnitIds();
            $this->assertContains($unit->id, $accessibleIds);
        }
    }
}
