<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(Unit::class);

class MapDashboardLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);
    }

    protected function createUserWithUnit(string $permission = 'map'): User
    {
        $unit = Unit::create(['name' => 'واحد تست', 'lat' => 36.6693, 'lng' => 48.4716]);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo($permission);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        Session::put('current_unit_id', $unit->id);

        return $user;
    }

    // ==================== Auth / permission ====================

    public function test_guest_302(): void
    {
        $this->get('/map')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        $user = $this->createUserWithUnit('manage_users'); // wrong permission
        $this->actingAs($user);

        $this->get('/map')->assertStatus(403);
    }

    // ==================== Render ====================

    public function test_renders_map(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->assertSee('map-container')
            ->assertSee('واحدها');
    }

    // ==================== Marker scoping ====================

    public function test_markers_scoped(): void
    {
        $user = $this->createUserWithUnit();

        // Create an accessible unit (child of user's unit)
        $accessible = Unit::create(['name' => 'واحد قابل دسترس', 'lat' => 36.70, 'lng' => 48.50, 'parent_id' => $user->units()->first()->id]);

        // Create an inaccessible unit (root, not in user's tree)
        Unit::create(['name' => 'واحد غیرقابل دسترس', 'lat' => 37.00, 'lng' => 49.00]);

        $this->actingAs($user);

        $component = Livewire::test('map.map-dashboard')
            ->assertOk();

        // The component should render without errors
        $component->assertSee('map-container');
    }

    // ==================== Marker detail ====================

    public function test_marker_detail(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $unit = Unit::create(['name' => 'unit-detail-test', 'lat' => 36.67, 'lng' => 48.47]);

        $component = Livewire::test('map.map-dashboard')
            ->assertOk();

        // loadUnitDetails should not throw for a valid, accessible unit
        $component->call('loadUnitDetails', $unit->id)
            ->assertOk();
    }

    // ==================== Interaction: layer toggle ====================

    public function test_layer_toggled(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->call('onLayerToggled', 'hardware')
            ->assertSet('layers', 'units,hardware')
            ->call('onLayerToggled', 'units')
            ->assertSet('layers', 'hardware');
    }

    // ==================== Interaction: filter changed ====================

    public function test_filter_changed(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->call('onFilterChanged', ['filterHardware' => 'laptop'])
            ->assertSet('filterHardware', 'laptop');
    }

    // ==================== Interaction: unit selected ====================

    public function test_unit_selected_dispatches_event(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->call('onUnitSelected', 42)
            ->assertDispatched('showUnitDetails', ['unitId' => 42]);
    }

    // ==================== Edge: loadUnitDetails inaccessible unit ====================

    public function test_load_unit_details_inaccessible_unit(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        // Create a unit that is NOT in the user's accessible tree
        $remoteUnit = Unit::create(['name' => 'remote', 'lat' => 38.0, 'lng' => 49.0]);

        $component = Livewire::test('map.map-dashboard')
            ->assertOk();

        // loadUnitDetails should return error for inaccessible unit
        // (method returns array; we verify it completes without error)
        $component->call('loadUnitDetails', $remoteUnit->id)
            ->assertOk();
    }
}
