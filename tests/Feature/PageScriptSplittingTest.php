<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #984 — page-specific JS libraries must ship only with the pages that
 * use them.
 *
 * The global layout used to load three libraries on ALL ~30 pages:
 *   leaflet-routing-machine  564,710 B  -> /maps/route, /maps/route2
 *   leaflet.draw              67,484 B  -> /units/{id}/map
 *   full-calendar            281,849 B  -> /todo
 * = 914,043 B of render-blocking JS (~85% of the layout's 1,081,303 B) that the
 * other 27 pages never executed. Each page now pushes its own library into
 * `@stack('scripts')`.
 *
 * These assertions run against the rendered HTML of a real page load, so they
 * prove three things at once: the library left the layout, it did NOT leave the
 * page that needs it (a split where the map page silently loses its routing
 * control is a regression, not a win), and no page picked up a library it never
 * uses. Every test asserts 200 plus a page-specific marker first: a 403 or 500
 * would satisfy each `assertDontSee` vacuously.
 */
class PageScriptSplittingTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const ROUTING = 'js/leaflet/leaflet-routing-machine.min.js';

    private const DRAW = 'js/leaflet/leaflet.draw.js';

    private const FULL_CALENDAR = 'js/other/full-calendar.min.js';

    /** Libraries consumed broadly enough to stay in the layout. */
    private const GLOBAL_LIBS = ['js/leaflet/leaflet.js', 'js/other/jalalidatepicker.min.js'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        $this->withoutVite();
    }

    public function test_dashboard_ships_none_of_the_page_specific_libraries(): void
    {
        $result = $this->createUserWithUnit(['map', 'calendar', 'organization']);
        $this->actingAs($result['user']);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('داشبورد مدیریت اطلاعات سلامت');

        $response->assertDontSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
        $response->assertDontSee(self::FULL_CALENDAR);

        // The libraries that ARE used across pages must still be global.
        foreach (self::GLOBAL_LIBS as $lib) {
            $response->assertSee($lib);
        }
    }

    public function test_maps_route_pushes_routing_machine_only(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        $response = $this->get('/maps/route');

        $response->assertStatus(200);
        $response->assertSee('محاسبه فاصله جاده‌ای');
        $response->assertSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
        $response->assertDontSee(self::FULL_CALENDAR);
    }

    public function test_maps_route2_pushes_routing_machine_only(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        $response = $this->get('/maps/route2');

        $response->assertStatus(200);
        $response->assertSee('محاسبه فاصله جاده‌ای');
        $response->assertSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
        $response->assertDontSee(self::FULL_CALENDAR);
    }

    public function test_unit_map_pushes_leaflet_draw_only(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $this->actingAs($result['user']);

        $response = $this->get('/units/'.$result['unit']->id.'/map');

        $response->assertStatus(200);
        $response->assertSee('id="unitMap"', false);
        $response->assertSee(self::DRAW);
        $response->assertDontSee(self::ROUTING);
        $response->assertDontSee(self::FULL_CALENDAR);
    }

    public function test_todo_page_pushes_fullcalendar_only(): void
    {
        $result = $this->createUserWithUnit(['calendar']);
        $this->actingAs($result['user']);

        $response = $this->get('/todo');

        $response->assertStatus(200);
        $response->assertSee('تقویم سازمانی');
        $response->assertSee(self::FULL_CALENDAR);
        $response->assertDontSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
    }

    /**
     * Every other maps page renders a Leaflet map too — it must NOT pull the
     * routing/draw libraries back in, or the split silently costs what it saved.
     */
    public function test_plain_map_pages_stay_light(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        foreach (['/maps/county', '/maps/point'] as $url) {
            $response = $this->get($url);

            $response->assertStatus(200);
            $response->assertSee(self::GLOBAL_LIBS[0]);
            $response->assertDontSee(self::ROUTING);
            $response->assertDontSee(self::DRAW);
            $response->assertDontSee(self::FULL_CALENDAR);
        }
    }
}
