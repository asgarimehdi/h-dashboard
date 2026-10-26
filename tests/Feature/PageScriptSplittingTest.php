<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
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
 * other 27 pages never executed. Each page now loads its own library instead —
 * `@assets` since #1182, which Livewire injects into <head> (a `@push('scripts')`
 * tag lands at the end of <body>, too late for a component that needs the library
 * in its Alpine `init()` on a `wire:navigate` visit).
 *
 * Issue #1182 — the last two global libraries left the layout the same way:
 *   leaflet                ~142 KB -> 8 pages (7 `maps.map` hosts + units/map)
 *   jalalidatepicker        ~25 KB -> 6 pages with Persian date fields
 * Leaflet is the dangerous half: `maps/map.blade.php` builds the map when its
 * Alpine `init()` runs, so a host page that forgets the asset renders an EMPTY
 * map instead of failing loudly (the #028 failure mode). The host list comes
 * from `grep -rln "livewire:maps.map" resources/views` — county, point and
 * map-no-boundary never mention "leaflet" and were missing from the first plan.
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

    private const LEAFLET = 'js/leaflet/leaflet.js';

    private const JALALI_DATEPICKER = 'js/other/jalalidatepicker.min.js';

    private const ROUTING = 'js/leaflet/leaflet-routing-machine.min.js';

    private const DRAW = 'js/leaflet/leaflet.draw.js';

    private const FULL_CALENDAR = 'js/other/full-calendar.min.js';

    /**
     * Views we do not own: the OPcache GUI is vendored verbatim and pulls
     * react/axios from cdnjs on its own route (`routes/web.php`). It is not
     * part of the app's own script budget — see #1183.
     */
    private const THIRD_PARTY_VIEWS = ['views/op/index.php'];

    /**
     * The table-driven tests walk pages from several permission groups, so the
     * actor needs all of them: maps (`map`), reports (`manage_personnel`),
     * tickets, the calendar, the activity log and the unit detail map.
     *
     * @var array<int, string>
     */
    private const PAGE_PERMISSIONS = [
        'map',
        'organization',
        'manage_personnel',
        'manage_users',
        'calendar',
        'view_all_tickets',
        'view_assigned_tickets',
    ];

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

        $response = $this->visit('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('داشبورد مدیریت اطلاعات سلامت');

        $response->assertDontSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
        $response->assertDontSee(self::FULL_CALENDAR);

        // #1182: Leaflet and jalalidatepicker are no longer global either — the
        // dashboard is neither a map nor a date page.
        $response->assertDontSee(self::LEAFLET);
        $response->assertDontSee(self::JALALI_DATEPICKER);
    }

    /**
     * #1182 — every page that hosts a Leaflet map must push Leaflet core itself.
     *
     * The seven `maps.map` hosts plus `units/map` (which builds its own
     * `#unitMap`) are the complete list; grep for `<livewire:maps.map` produced
     * it, and a page missing here means a blank map in production.
     */
    public function test_every_leaflet_page_pushes_leaflet_core(): void
    {
        $result = $this->createUserWithUnit(self::PAGE_PERMISSIONS);
        $this->actingAs($result['user']);

        foreach ($this->leafletPages($result['unit']->id) as $url => $marker) {
            $response = $this->visit($url);

            $response->assertStatus(200);
            $response->assertSee($marker, false);

            $this->assertLoads($response, self::LEAFLET, $url);

            // #1246: `/map` gained a Persian period boundary — the disease
            // layer's Jalali from/to — so it is now the one map page that must
            // load the datepicker itself. Every other map page still has no
            // Persian date boundary and must not pull it back in.
            if ($url === '/map') {
                $this->assertLoads($response, self::JALALI_DATEPICKER, $url);

                continue;
            }

            $response->assertDontSee(self::JALALI_DATEPICKER);
        }
    }

    public function test_maps_route_pushes_routing_machine_only(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        $response = $this->visit('/maps/route');

        $response->assertStatus(200);
        $response->assertSee('محاسبه فاصله جاده‌ای');
        $response->assertSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
        $response->assertDontSee(self::FULL_CALENDAR);
        $response->assertDontSee(self::JALALI_DATEPICKER);

        // Leaflet core must land before routing-machine patches `L`.
        $this->assertAppearsBefore($response, self::LEAFLET, self::ROUTING, '/maps/route');
    }

    public function test_maps_route2_pushes_routing_machine_only(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        $response = $this->visit('/maps/route2');

        $response->assertStatus(200);
        $response->assertSee('محاسبه فاصله جاده‌ای');
        $response->assertSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
        $response->assertDontSee(self::FULL_CALENDAR);
        $response->assertDontSee(self::JALALI_DATEPICKER);

        $this->assertAppearsBefore($response, self::LEAFLET, self::ROUTING, '/maps/route2');
    }

    public function test_unit_map_pushes_leaflet_draw_only(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $this->actingAs($result['user']);

        $response = $this->visit('/units/'.$result['unit']->id.'/map');

        $response->assertStatus(200);
        $response->assertSee('id="unitMap"', false);
        $response->assertSee(self::DRAW);
        $response->assertDontSee(self::ROUTING);
        $response->assertDontSee(self::FULL_CALENDAR);
        $response->assertDontSee(self::JALALI_DATEPICKER);

        // `L.Control.Draw` is registered at parse time, so core comes first.
        $this->assertAppearsBefore($response, self::LEAFLET, self::DRAW, '/units/{id}/map');
    }

    public function test_todo_page_pushes_fullcalendar_only(): void
    {
        $result = $this->createUserWithUnit(['calendar']);
        $this->actingAs($result['user']);

        $response = $this->visit('/todo');

        $response->assertStatus(200);
        $response->assertSee('تقویم سازمانی');
        $response->assertSee(self::FULL_CALENDAR);
        $response->assertDontSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
        $response->assertDontSee(self::LEAFLET);

        // #1182: the calendar page filters by Persian date, so it keeps the
        // datepicker — in the same asset block as FullCalendar.
        $response->assertSee(self::JALALI_DATEPICKER);
    }

    /**
     * Every other maps page renders a Leaflet map too — it must NOT pull the
     * routing/draw libraries back in, or the split silently costs what it saved.
     *
     * This is also the #1182 STOP #4 guard: `/maps/county` and `/maps/point`
     * never mention "leaflet" in their own markup and were missing from the
     * first consumer list, so before #1182 they only had Leaflet because it was
     * global. Now they must load it themselves.
     */
    public function test_plain_map_pages_stay_light(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        foreach (['/maps/county', '/maps/point'] as $url) {
            $response = $this->visit($url);

            $response->assertStatus(200);
            $this->assertLoads($response, self::LEAFLET, $url);
            $response->assertDontSee(self::ROUTING);
            $response->assertDontSee(self::DRAW);
            $response->assertDontSee(self::FULL_CALENDAR);
            $response->assertDontSee(self::JALALI_DATEPICKER);
        }
    }

    /**
     * #1182 — the six pages with Persian date fields are the complete
     * jalaliDatepicker consumer list.
     */
    public function test_every_date_page_pushes_jalalidatepicker(): void
    {
        $result = $this->createUserWithUnit(self::PAGE_PERMISSIONS);
        $this->actingAs($result['user']);

        foreach ($this->datePages() as $url => [$marker]) {
            $response = $this->visit($url);

            $response->assertStatus(200);
            $response->assertSee($marker);

            $this->assertLoads($response, self::JALALI_DATEPICKER, $url);

            // None of them is a map page.
            $response->assertDontSee(self::LEAFLET);
        }
    }

    /**
     * #1182 — the order is the whole point: `jalaliDatepicker` is a plain global
     * declared by the loaded file, and every date page calls `startWatch()` from
     * its own init. If the asset ever lands after that init, the page throws
     * `jalaliDatepicker is not defined` and every date field on it is inert.
     *
     * Asserted against the view source, not the rendered HTML: Livewire 4
     * compiles a component's `@script` into a deferred effect and its plain
     * `<script>` into a module (`storage/framework/views/livewire/scripts/*.js`),
     * so both run AFTER the `@stack('scripts')` block and the rendered document
     * order says nothing about them. What pins the runtime order is the asset
     * sitting above the init in the same file — which is what this checks.
     */
    public function test_jalalidatepicker_is_loaded_before_the_init_that_uses_it(): void
    {
        foreach ($this->datePages() as $url => [, $view]) {
            $contents = (string) file_get_contents(resource_path('views/'.$view));

            $assetAt = strpos($contents, self::JALALI_DATEPICKER);
            $initAt = strpos($contents, 'jalaliDatepicker.startWatch');

            $this->assertNotFalse($assetAt, "{$url}: the view never loads ".self::JALALI_DATEPICKER.'.');
            $this->assertNotFalse($initAt, "{$url}: the view never calls jalaliDatepicker.startWatch().");
            $this->assertLessThan(
                $initAt,
                $assetAt,
                "{$url}: the jalalidatepicker asset must sit above the init in the view.",
            );
        }
    }

    /**
     * The control case for #1182: a page with neither a map nor a date field
     * ships neither library.
     */
    public function test_an_unrelated_page_ships_neither_library(): void
    {
        $result = $this->createUserWithUnit(self::PAGE_PERMISSIONS);
        $this->actingAs($result['user']);

        $response = $this->visit('/settings');

        $response->assertStatus(200);
        $response->assertSee('تنظیمات');

        $response->assertDontSee(self::LEAFLET);
        $response->assertDontSee(self::JALALI_DATEPICKER);
        $response->assertDontSee(self::ROUTING);
        $response->assertDontSee(self::DRAW);
        $response->assertDontSee(self::FULL_CALENDAR);
    }

    // ==================== No CDN scripts in the views (#1183) ====================

    /**
     * Issue #1183 — the Zabbix traffic chart loaded Highcharts from
     * `https://code.highcharts.com/highcharts.js` while the other six chart
     * pages used the vendored copy in `public/js/chart/`. A CDN script tag
     * needs the network at render time, is not covered by the app CSP, and
     * silently changes the library version under the app.
     *
     * This locks the CDN out of every view so the dependency cannot creep
     * back in — the one page that used it now loads the vendored build.
     */
    public function test_no_view_loads_a_script_from_an_external_origin(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (in_array($this->relativePath($file->getPathname()), self::THIRD_PARTY_VIEWS, true)) {
                continue;
            }

            foreach ($this->externalScriptSources($file->getContents()) as $source) {
                $offenders[] = $this->relativePath($file->getPathname()).' -> '.$source;
            }
        }

        expect($offenders)->toBe([]);
    }

    /**
     * Render a page with Livewire's per-request asset state reset first.
     *
     * `@assets` (the mechanism these pages use since #1182) is collected in a
     * static so one page's head assets are never injected twice. In a real
     * request that static starts empty, but the test process renders several
     * pages in one PHP process, so without the reset page N would inherit page
     * N-1's assets and every `assertDontSee` below would pass or fail by test
     * order. `flushState()` is Livewire's own reset (`flush-state`).
     */
    private function visit(string $url): TestResponse
    {
        Livewire::flushState();

        return $this->get($url);
    }

    /**
     * Every Leaflet consumer: the seven `maps.map` hosts plus `units/map`.
     *
     * @return array<string, string> url => marker that proves the page rendered
     */
    private function leafletPages(int $unitId): array
    {
        return [
            '/map' => 'id="map"',
            '/maps/route' => 'id="map"',
            '/maps/route2' => 'id="map"',
            '/maps/county' => 'id="map"',
            '/maps/unit' => 'id="map"',
            '/maps/point' => 'id="map"',
            '/reports/map-no-boundary' => 'id="map"',
            '/units/'.$unitId.'/map' => 'id="unitMap"',
        ];
    }

    /**
     * Every jalaliDatepicker consumer: url => [page marker, view file].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function datePages(): array
    {
        return [
            '/activity-log' => ['گزارش فعالیت سیستم', 'livewire/activity-log/index.blade.php'],
            '/reports/tickets' => ['گزارش تیکت‌ها', 'livewire/reports/advanced.blade.php'],
            '/reports/todos' => ['گزارش وظایف', 'livewire/reports/todos.blade.php'],
            '/tickets/inbox' => ['صندوق تیکت‌های پشتیبانی', 'livewire/tickets/⚡inbox.blade.php'],
            '/monitoring' => ['مانیتورینگ تیکت‌ها', 'livewire/tickets/⚡monitoring.blade.php'],
            '/todo' => ['تقویم سازمانی', 'livewire/todo/todo.blade.php'],
        ];
    }

    /** Assert the rendered page loads `$library`, with the URL in the message. */
    private function assertLoads(TestResponse $response, string $library, string $url): void
    {
        $this->assertStringContainsString(
            $library,
            (string) $response->getContent(),
            "{$url} must load {$library} itself — it is no longer in the global layout.",
        );
    }

    /** Assert `$first` appears in the HTML before the first `$second`. */
    private function assertAppearsBefore(TestResponse $response, string $first, string $second, string $url): void
    {
        $html = (string) $response->getContent();

        $firstAt = strpos($html, $first);
        $secondAt = strpos($html, $second);

        $this->assertNotFalse($firstAt, "{$url}: '{$first}' never appears in the page HTML.");
        $this->assertNotFalse($secondAt, "{$url}: '{$second}' never appears in the page HTML.");
        $this->assertLessThan(
            $secondAt,
            $firstAt,
            "{$url}: '{$first}' must come before '{$second}', or the init runs against an undefined global.",
        );
    }

    /**
     * Script sources that point at a foreign host: absolute (`https://…`) or
     * protocol-relative (`//…`). Same-origin sources — a path, `asset()`,
     * `route()`, `@vite` — are left alone.
     *
     * HTML comments are stripped first: a commented-out snippet is not a
     * request the browser ever makes.
     *
     * @return array<int, string>
     */
    private function externalScriptSources(string $contents): array
    {
        $contents = preg_replace('/<!--.*?-->/s', '', $contents) ?? $contents;

        preg_match_all('/<script\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/i', $contents, $matches);

        $sources = [];

        foreach ($matches[2] ?? [] as $source) {
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', trim($source))) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    private function relativePath(string $path): string
    {
        return str_replace(resource_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
