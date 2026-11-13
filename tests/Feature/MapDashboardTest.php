<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
            'hardware_type' => 'laptop',
            'ticket_priority' => 'urgent',
            'ticket_status' => 'created',
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
            'hardware_type' => 'desktop',
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
    public function test_map_page_uses_shared_map_component(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $response = $this->actingAs($user)->get('/map');
        $response->assertStatus(200);
        $response->assertSee('map-container');
        // The map (and its Leaflet assets) comes from the shared maps.map
        // component; the page no longer loads its own copy from the unpkg CDN.
        $response->assertDontSee('unpkg.com/leaflet');
    }

    /** @test */
    public function test_map_dashboard_render_has_required_elements(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $component = Livewire::actingAs($user)
            ->test('map.map-dashboard');

        $component->assertSee('mapDashboard');
        $component->assertSeeLivewire('maps.map');
        $component->assertSee('toggleLayer');
        $component->assertSee('setFilter');

        // Issue #1352: the layer/filter logic lives in
        // resources/js/map-dashboard.js (Alpine.data('mapDashboard')) now —
        // the template carries x-data plus data attributes only. The pins
        // for the store handshake and the layer builders follow the module.
        $module = (string) file_get_contents(resource_path('js/map-dashboard.js'));
        $this->assertStringContainsString('onReady', $module);
        $this->assertStringContainsString('loadLayers', $module);
        $this->assertStringContainsString('renderGeoJSON', $module);
    }

    /**
     * Issue #1399 — the P0 pin: the page must ship no bearer credential at all.
     *
     * Covers BOTH leak paths deliberately. The `data-api-token` attribute was
     * the visible one, but the same plaintext also rode in `wire:snapshot` (the
     * token lived on a `#[Locked] public` property, which Livewire serialises),
     * so deleting the attribute alone would have left the credential in the DOM.
     *
     * `html_entity_decode` is not optional: the snapshot JSON is entity-encoded
     * (`&quot;mapToken&quot;`), so `assertSee`/a raw regex both miss it.
     *
     * @test
     */
    public function test_map_page_renders_no_plaintext_api_token_in_the_dom(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $response = $this->actingAs($user)->get('/map');
        $response->assertStatus(200);

        $html = html_entity_decode((string) $response->getContent());

        $this->assertStringNotContainsString('data-api-token', $html);
        $this->assertStringNotContainsString('mapToken', $html);
        $this->assertSame(0, preg_match('/\b\d+\|[A-Za-z0-9]{40,}\b/', $html));
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * Issue #1399 — the `/api/gis/*` endpoints already authenticate from the
     * web session cookie, so the page needs no bearer token.
     *
     * Login goes through the real web flow (the Livewire login component
     * regenerates the session exactly as a browser does), then each endpoint is
     * called with NO `Authorization` header at all.
     *
     * LIMITATION: Laravel's test client shares one session store across calls,
     * so this proves the stateful pipeline accepts the cookie but does NOT
     * exercise `EnsureFrontendRequestsAreStateful::fromFrontend()`'s
     * Referer/Origin gate. Exercising that gate is the E2E spec's job.
     *
     * @test
     */
    public function test_gis_endpoints_accept_the_session_cookie_without_a_bearer_token(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $disease = Disease::factory()->create();

        Livewire::test('auth.login')
            ->set('n_code', $user->n_code)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);

        $bbox = '48.0,36.0,49.0,37.0';
        $paths = [
            "/api/gis/units?bbox={$bbox}",
            "/api/gis/hardware?bbox={$bbox}",
            "/api/gis/tickets?bbox={$bbox}",
            "/api/gis/stats?bbox={$bbox}",
            '/api/gis/diseases',
            "/api/gis/disease-map?disease={$disease->id}&zoom=10",
        ];

        foreach ($paths as $path) {
            $this->getJson($path, ['Referer' => url('/map')])->assertOk();
        }

        // Drop the web session: the same endpoints must now reject the guest.
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/gis/units?bbox={$bbox}", ['Referer' => url('/map')])
            ->assertUnauthorized();
    }

    /**
     * Issue #1399 — deployment trap regression pin.
     *
     * `config/sanctum.php` appends `Sanctum::currentApplicationUrlWithPort()`
     * (derived from `APP_URL`) to the hardcoded localhost defaults ONLY when
     * `SANCTUM_STATEFUL_DOMAINS` is unset. A deployment that sets that variable
     * without the real app host silently 401s every cookie-authenticated
     * `/api/gis/*` fetch and the map goes blank.
     *
     * Not every environment leaves the variable unset — CI's `.env.testing`
     * inherits `.env.example`'s explicit `h-dashboard.test`. So the assertion
     * that must hold everywhere is that the fallback append is still wired;
     * when no explicit list is configured, the resolved list must also contain
     * the app host.
     *
     * @test
     */
    public function test_sanctum_stateful_defaults_cover_the_app_url_host(): void
    {
        $appHost = ltrim(Sanctum::currentApplicationUrlWithPort(), ',');

        $this->assertNotSame('', $appHost, 'APP_URL must carry a host for Sanctum stateful matching.');

        $explicit = env('SANCTUM_STATEFUL_DOMAINS');

        if (is_string($explicit) && $explicit !== '') {
            // The operator pinned a list; the app controls only its fallback.
            $this->assertStringContainsString(
                'Sanctum::currentApplicationUrlWithPort()',
                (string) file_get_contents(config_path('sanctum.php')),
                'config/sanctum.php must keep appending the APP_URL host when SANCTUM_STATEFUL_DOMAINS is unset.',
            );

            return;
        }

        $this->assertContains(
            $appHost,
            config('sanctum.stateful'),
            "SANCTUM_STATEFUL_DOMAINS must include the app host ({$appHost}) or cookie-authenticated /api/gis/* fetches 401.",
        );
    }
}
