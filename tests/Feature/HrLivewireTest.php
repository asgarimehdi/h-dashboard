<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

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

        $nCode = (string) random_int(1000000000, 2147483647);
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
}
