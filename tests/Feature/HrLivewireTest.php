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

/**
 * HR Livewire tests.
 *
 * Issue #704: `hr.org-chart` is now a thin composition over the generic
 * `unit.tree` component. Tree mechanics (expanded/lazyChildren/toggle/
 * expandAll/collapseAll/search) belong to `unit.tree` and are tested
 * there — see HrOrgChartCoverageTest and UnitTreeLivewireTest.
 * This file covers the HR page's own responsibility: rendering the
 * composed tree with the personnel badge, and the personnel detail panel.
 */
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
    public function test_org_chart_shows_personnel_count_via_the_badge_plug_in(): void
    {
        // The HR page renders the generic tree with badge-view=hr.personnel-badge.
        // This unit has exactly one person, so the count badge reads "1 نفر".
        Livewire::test('hr.org-chart')
            ->assertOk()
            ->assertSee('1 نفر');
    }

    /** @test */
    public function test_org_chart_vacancy_badge_on_empty_units(): void
    {
        $emptyUnit = Unit::create(['name' => 'واحد خالی', 'parent_id' => $this->unit->id]);

        $component = Livewire::test('hr.org-chart')->assertOk();

        // The «خالی» badge is the plug-in's job for a zero-count unit.
        $component->assertSee('خالی');

        $this->assertSame(0, (int) Person::where('u_id', $emptyUnit->id)->count());
    }
}
