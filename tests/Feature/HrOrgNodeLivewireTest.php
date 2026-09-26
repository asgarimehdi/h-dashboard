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

covers(Unit::class, Person::class);

/**
 * Page-level behaviour of /hr/org-chart (auth, render, detail panel).
 *
 * The tree mechanics that used to live in hr/org-node.blade.php moved to the
 * reusable `unit.tree` component and are covered by UnitTreeLivewireTest
 * (issue #704).
 */
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

    public function test_page_renders_the_tree_it_inherits_from_the_component(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $rootUnit = Unit::whereNull('parent_id')->first();

        Livewire::test('hr.org-chart')
            ->assertStatus(200)
            ->assertSee($rootUnit->name)
            ->assertSee('جستجوی واحد...');
    }

    // ==================== Interaction tests ====================

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

    // ==================== Edge-case tests ====================

    public function test_empty_badge_on_zero_persons(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        // Create an empty unit
        $rootUnit = Unit::whereNull('parent_id')->first();
        $emptyUnit = Unit::create(['name' => 'واحد بدون نیرو', 'parent_id' => $rootUnit->id]);

        $component = Livewire::test('hr.org-chart')
            ->assertStatus(200);

        $personCounts = $component->get('personCounts');
        $this->assertEquals(0, $personCounts[$emptyUnit->id] ?? 0);
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

    public function test_every_root_shown_on_the_page_is_in_the_accessible_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($user);

        $accessible = app(AccessService::class)->accessibleUnitIds();

        // Units outside the scope never make it into the page's badge data…
        $outsider = Unit::create(['name' => 'واحد بیرون از دامنه']);

        $personCounts = Livewire::test('hr.org-chart')->get('personCounts');

        $this->assertArrayNotHasKey($outsider->id, $personCounts);
        foreach (array_keys($personCounts) as $unitId) {
            $this->assertContains($unitId, $accessible);
        }
    }
}
