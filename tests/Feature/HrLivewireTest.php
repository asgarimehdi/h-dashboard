<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HrAnalyticsController;
use App\Http\Controllers\Api\HrStatsController;
use App\Http\Controllers\Api\OrgChartController;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(OrgChartController::class, HrStatsController::class, HrAnalyticsController::class);

class HrLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
        $sId = DB::table('semats')->insertGetId(['name' => 'S']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'R']);

        $this->unit = Unit::create(['name' => 'مرکز بهداشت']);
        Unit::create(['name' => 'خانه بهداشت', 'parent_id' => $this->unit->id]);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'علی', 'l_name' => 'محمدی',
            't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
            'u_id' => $this->unit->id, 'status' => 'active',
        ]);

        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);
        $this->actingAs($this->user);
    }

    public function test_dashboard_renders_with_stats(): void
    {
        Livewire::test('hr.dashboard')
            ->assertOk()
            ->assertSee('داشبورد منابع انسانی')
            ->assertSee('کل پرسنل');
    }

    public function test_org_chart_renders_tree(): void
    {
        Livewire::test('hr.org-chart')
            ->assertOk()
            ->assertSee('چارت سازمانی')
            ->assertSee('مرکز بهداشت');
    }

    public function test_org_chart_toggle_collapses(): void
    {
        $component = Livewire::test('hr.org-chart')
            ->assertOk();

        $expandedBefore = $component->get('expanded');
        $this->assertNotEmpty($expandedBefore);

        // Collapse the root unit
        $rootId = $this->unit->id;
        $component->call('toggle', (string) $rootId);
        $expandedAfter = $component->get('expanded');
        $this->assertNotContains((string) $rootId, $expandedAfter);
    }

    public function test_org_chart_collapse_all(): void
    {
        Livewire::test('hr.org-chart')
            ->assertOk()
            ->call('collapseAll')
            ->assertSet('expanded', []);
    }

    public function test_org_chart_expand_all(): void
    {
        $component = Livewire::test('hr.org-chart')
            ->assertOk()
            ->call('collapseAll')
            ->call('expandAll');

        $this->assertNotEmpty($component->get('expanded'));
    }

    /** @test */
    public function test_org_chart_default_expand_only_first_three_levels(): void
    {
        // Create a deeper tree: unit -> child -> grandchild -> great-grandchild
        $child = Unit::create(['name' => 'شبکه بهداشت', 'parent_id' => $this->unit->id]);
        $grandchild = Unit::create(['name' => 'مرکز بهداشت روستایی', 'parent_id' => $child->id]);

        $component = Livewire::test('hr.org-chart')
            ->assertOk();

        $expanded = $component->get('expanded');

        // Root unit (level 1) should be expanded
        $this->assertContains((string) $this->unit->id, $expanded);
        // Child (level 2) should be expanded
        $this->assertContains((string) $child->id, $expanded);
        // Grandchild (level 3) should be expanded
        $this->assertContains((string) $grandchild->id, $expanded);
    }

    /** @test */
    public function test_org_chart_collapses_beyond_level_3(): void
    {
        // Create a deep tree: unit -> child (l2) -> grandchild (l3) -> great-grandchild (l4)
        $child = Unit::create(['name' => 'شبکه', 'parent_id' => $this->unit->id]);
        $grandchild = Unit::create(['name' => 'مرکز', 'parent_id' => $child->id]);
        $greatGrandchild = Unit::create(['name' => 'خانه بهداشت', 'parent_id' => $grandchild->id]);

        $component = Livewire::test('hr.org-chart')
            ->assertOk();

        $expanded = $component->get('expanded');

        // Level 1, 2 and 3 should be expanded
        $this->assertContains((string) $this->unit->id, $expanded);
        $this->assertContains((string) $child->id, $expanded);
        $this->assertContains((string) $grandchild->id, $expanded);

        // Level 4 should NOT be expanded (collapsed by default)
        $this->assertNotContains((string) $greatGrandchild->id, $expanded);
    }

    /** @test */
    public function test_org_chart_select_unit_returns_personnel(): void
    {
        $component = Livewire::test('hr.org-chart')
            ->assertOk()
            ->call('selectUnit', $this->unit->id);

        // Should have personnel
        $selectedPersonnel = $component->get('selectedPersonnel');
        $this->assertNotNull($selectedPersonnel);
        $this->assertCount(1, $selectedPersonnel);

        // Personnel should have user status
        $firstPersonnel = collect($selectedPersonnel)->first();
        $this->assertNotNull($firstPersonnel);
    }

    /** @test */
    public function test_org_chart_personnel_without_user_highlighted_red(): void
    {
        // Create a person WITHOUT a user account
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بدون', 'l_name' => 'کاربر',
            't_id' => DB::table('tahsils')->insertGetId(['name' => 'T']),
            'e_id' => DB::table('estekhdams')->insertGetId(['name' => 'E']),
            's_id' => DB::table('semats')->insertGetId(['name' => 'S']),
            'r_id' => DB::table('radifs')->insertGetId(['name' => 'R']),
            'u_id' => $this->unit->id, 'status' => 'active',
        ]);

        $component = Livewire::test('hr.org-chart')
            ->assertOk()
            ->call('selectUnit', $this->unit->id);

        $selectedPersonnel = $component->get('selectedPersonnel');

        // One person has user account, the other doesn't
        $this->assertCount(2, $selectedPersonnel);

        // The one without user account should have user = null
        $withoutUser = collect($selectedPersonnel)->firstWhere('n_code', $nCode);
        $this->assertNull($withoutUser['user']);
    }

    /** @test */
    public function test_org_chart_shows_personnel_count_on_tree(): void
    {
        $component = Livewire::test('hr.org-chart')
            ->assertOk();

        $personCounts = $component->get('personCounts');
        $this->assertNotEmpty($personCounts);

        // The unit should have 1 personnel
        $this->assertEquals(1, $personCounts[$this->unit->id] ?? 0);
    }

    /** @test */
    public function test_org_chart_vacancy_badge_on_empty_units(): void
    {
        // Create a unit with no personnel
        $emptyUnit = Unit::create(['name' => 'واحد خالی', 'parent_id' => $this->unit->id]);

        $component = Livewire::test('hr.org-chart')
            ->assertOk();

        $expanded = $component->get('expanded');
        $this->assertContains((string) $emptyUnit->id, $expanded);

        $personCounts = $component->get('personCounts');
        $this->assertEquals(0, $personCounts[$emptyUnit->id] ?? 0);
    }
}
