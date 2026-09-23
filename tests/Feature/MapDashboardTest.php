<?php

namespace Tests\Feature;

use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Unit::class);

class MapDashboardTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /** @test */
    public function test_unauthenticated_user_cannot_access_map_page(): void
    {
        $response = $this->get('/map');
        $response->assertStatus(302);
        $response->assertRedirect(route('login'));
    }

    /** @test */
    public function test_authenticated_user_can_view_map_page(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $response = $this->actingAs($user)->get('/map');
        $response->assertStatus(200);
        $response->assertSee('map-container');
    }

    /** @test */
    public function test_map_dashboard_mounts_with_token(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $unit->update(['lat' => 36.669343, 'lng' => 48.47163]);

        Livewire::actingAs($user)
            ->test('map.map-dashboard')
            ->assertSet('mapToken', fn ($v) => is_string($v) && strlen($v) >= 40)
            ->assertSet('mapZoom', 10)
            ->assertSet('mapCenterLat', 36.669343)
            ->assertSet('mapCenterLng', 48.47163)
            ->assertSet('layers', 'units')
            ->assertSet('filterHardware', '')
            ->assertSet('filterPriority', '')
            ->assertSet('filterStatus', '')
            ->assertSet('statsUnits', 0)
            ->assertSet('statsHardware', 0)
            ->assertSet('statsOpenTickets', 0);
    }

    /** @test */
    public function test_on_map_moved_updates_coordinates(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        $component->dispatch('mapMoved', [
            'center' => [36.9, 48.7],
            'zoom' => 8,
            'bbox' => '48.0,36.0,49.0,37.0',
        ]);

        $component->assertSet('mapCenterLat', 36.9)
            ->assertSet('mapCenterLng', 48.7)
            ->assertSet('mapZoom', 8)
            ->assertSet('bbox', '48.0,36.0,49.0,37.0');
    }

    /** @test */
    public function test_on_layer_toggled_toggles_layer(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        // Initial: only 'units'
        $component->assertSet('layers', 'units');

        // Add 'hardware'
        $component->dispatch('layerToggled', 'hardware');
        $component->assertSet('layers', 'units,hardware');

        // Add 'tickets'
        $component->dispatch('layerToggled', 'tickets');
        $component->assertSet('layers', 'units,hardware,tickets');

        // Remove 'units'
        $component->dispatch('layerToggled', 'units');
        $component->assertSet('layers', 'hardware,tickets');
    }

    /** @test */
    public function test_on_filter_changed_updates_filters(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        $component->dispatch('filterChanged', [
            'filterHardware' => 'laptop',
            'filterPriority' => 'urgent',
            'filterStatus' => 'created',
        ]);

        $component->assertSet('filterHardware', 'laptop')
            ->assertSet('filterPriority', 'urgent')
            ->assertSet('filterStatus', 'created');
    }

    /** @test */
    public function test_on_filter_changed_only_updates_known_properties(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        // Should not throw and should not set unknown properties
        $component->dispatch('filterChanged', [
            'filterHardware' => 'desktop',
            'malicious_key' => 'hack',
        ]);

        $component->assertSet('filterHardware', 'desktop');
        // Ensure property does not exist
        $this->assertFalse(property_exists($component->instance(), 'malicious_key'));
    }

    /** @test */
    public function test_on_unit_selected_dispatches_event(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        $component->dispatch('unitSelected', unitId: 42);
        $component->assertDispatched('showUnitDetails');
    }

    /** @test */
    public function test_load_unit_details_returns_unit_data(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);

        // Add a child unit
        Unit::create([
            'name' => 'Child Unit',
            'parent_id' => $unit->id,
            'lat' => 36.7,
            'lng' => 48.5,
        ]);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        $result = $component->instance()->loadUnitDetails($unit->id);

        $this->assertIsArray($result);
        $this->assertEquals($unit->id, $result['id']);
        $this->assertEquals($unit->name, $result['name']);
        $this->assertArrayHasKey('children_count', $result);
        $this->assertGreaterThanOrEqual(1, $result['children_count']);
    }

    /** @test */
    public function test_load_unit_details_returns_error_for_invalid_id(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        $result = $component->instance()->loadUnitDetails(99999);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Unit not found', $result['error']);
    }

    /** @test */
    public function test_load_unit_details_blocks_units_outside_org_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        // Unit NOT in the user's org scope (no relation to user's unit tree)
        $outsideUnit = Unit::create([
            'name' => 'Outside Unit',
            'lat' => 35.0,
            'lng' => 51.0,
        ]);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        $result = $component->instance()->loadUnitDetails($outsideUnit->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Unit not found', $result['error']);
    }

    /** @test */
    public function test_map_page_renders_leaflet_assets(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $response = $this->actingAs($user)->get('/map');
        $response->assertStatus(200);
        $response->assertSee('leaflet');
        $response->assertSee('unpkg.com/leaflet');
        $response->assertSee('OpenStreetMap');
        $response->assertSee('map-container');
    }

    /** @test */
    public function test_map_dashboard_render_has_required_elements(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        $component->assertSee('mapDashboard()');
        $component->assertSee('initMap()');
        $component->assertSee('toggleLayer');
        $component->assertSee('setFilter');
        $component->assertSee('loadLayers');
        $component->assertSee('renderGeoJSON');
    }
}
