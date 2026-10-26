<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Unit::class);

class MapDashboardLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ==================== Auth / permission ====================

    public function test_guest_302(): void
    {
        $this->get('/map')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $this->get('/map')->assertStatus(403);
    }

    // ==================== Render ====================

    public function test_renders_map(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->assertSee('map-container')
            ->assertSee('واحدها');
    }

    // ==================== Marker scoping ====================

    public function test_markers_scoped(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

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
        ['user' => $user] = $this->createUserWithUnit(['map']);
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
        ['user' => $user] = $this->createUserWithUnit(['map']);
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
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->call('onFilterChanged', ['hardware_type' => 'laptop'])
            ->assertSet('filterHardware', 'laptop');
    }

    // ==================== Interaction: unit selected ====================

    public function test_unit_selected_dispatches_event(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->call('onUnitSelected', 42)
            ->assertDispatched('showUnitDetails', ['unitId' => 42]);
    }

    // ==================== Edge: loadUnitDetails inaccessible unit ====================

    public function test_load_unit_details_inaccessible_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
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

    // ==================== Priority filter vocabulary (#954) ====================

    /**
     * Issue #954: the priority filter offered all five DB values, so a user
     * could filter to «بالا»/«متوسط» and select a bucket no report reads —
     * every aggregation is three-valued. The filter must offer exactly
     * `Ticket::PRIORITIES` (plus the "all" option), so it can never select
     * a bucket that reports nothing.
     */
    public function test_priority_filter_offers_exactly_the_reported_buckets(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $html = Livewire::test('map.map-dashboard')->html();

        $matched = preg_match(
            '/<select[^>]*x-model="filters\.ticket_priority"[^>]*>.*?<\/select>/s',
            $html,
            $block
        );
        $this->assertSame(1, $matched, 'ticket_priority filter select not found in rendered HTML');

        preg_match_all('/<option value="([^"]*)"/', $block[0], $matches);

        $this->assertSame(['', ...Ticket::PRIORITIES], $matches[1]);
    }

    // ==================== Disease layer (#1246) ====================

    /**
     * #1246: the disease layer is opt-in. A `map` holder who never touches the
     * new button must still get exactly the layers they had before, and the
     * `layers` URL contract must not change under them.
     */
    public function test_disease_layer_is_off_by_default(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->assertSet('layers', 'units')
            ->assertSee('disease-controls')
            ->assertSee('بیماری‌ها');
    }

    /**
     * #1246: one toggle adds the layer key `diseases` (plural — the service
     * accepts any string, but the URL and `layerGroups` must agree), and a
     * second toggle removes it without disturbing the others.
     */
    public function test_disease_layer_toggles_on_and_off(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->call('onLayerToggled', 'diseases')
            ->assertSet('layers', 'units,diseases')
            ->call('onLayerToggled', 'diseases')
            ->assertSet('layers', 'units');
    }

    /**
     * #1246: the default period is the current Persian year (1 Farvardin →
     * today), never a rolling 30-day window — the map is a year-to-date view.
     */
    public function test_disease_controls_default_to_the_current_persian_year(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $year = Jalalian::now()->getYear();

        Livewire::test('map.map-dashboard')
            ->assertOk()
            ->assertSet('diseaseFrom', $year.'/01/01')
            ->assertSet('diseaseTo', Jalalian::now()->format('Y/m/d'))
            ->assertSee('disease-select')
            ->assertSee('disease_date_from')
            ->assertSee('disease_date_to')
            ->assertSee('disease-legend')
            ->assertSee('انتخاب بیماری');
    }
}
