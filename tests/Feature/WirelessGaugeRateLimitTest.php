<?php

namespace Tests\Feature;

use App\Models\ZabbixDevice;
use App\Services\CacheInvalidationServiceInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ZabbixDeviceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Regression tests for the `/it/wireless` «Too Many Attempts» (HTTP 429) bug.
 *
 * The Zabbix read endpoints sit behind `throttle:api-user`, which allows
 * 60 requests per minute **per user, across the whole API** — not per page.
 * `/it/wireless` used to render one `it.multi-gauge` per device, and each gauge
 * ran its own `fetch('/api/zabbix/multi-latest')` on load and every 30s. Worse,
 * the gauge's `x-init="init()"` fired *in addition* to Alpine's automatic
 * `init()` call, so the first load issued two requests per gauge.
 *
 * With the 14 seeded wireless devices that is 28 requests/minute steady-state
 * and 42 in the first minute — over the limit within seconds of page load.
 *
 * These tests pin the fix at the layer that actually caused it: the page now
 * batches every gauge into ONE request per refresh cycle, and the gauge is
 * presentational only.
 */
class WirelessGaugeRateLimitTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        $this->seed(ZabbixDeviceSeeder::class);
    }

    // ==================== Batching contract ====================

    public function test_item_ids_cover_every_device_without_duplicates(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $ids = Livewire::test('it.wireless')->assertOk()->instance()->itemIds();

        // 14 wireless devices × 3 items each.
        $this->assertCount(42, $ids);
        $this->assertSame(array_unique($ids), $ids, 'itemIds() must not repeat an id.');
        $this->assertContains('75297', $ids); // first device's signal item
    }

    public function test_a_single_request_carries_every_gauge_within_the_endpoint_limit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $ids = Livewire::test('it.wireless')->assertOk()->instance()->itemIds();

        // MultiLatestValueController validates `item_ids` as `array|max:100`, so
        // one request is only enough while the page fits inside that ceiling.
        $this->assertLessThanOrEqual(100, count($ids));
    }

    public function test_item_ids_is_empty_when_no_wireless_device_exists(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        ZabbixDevice::query()->where('type', 'wireless')->update(['is_active' => false]);

        $component = Livewire::test('it.wireless')->assertOk();

        $this->assertSame([], $component->instance()->itemIds());
    }

    // ==================== The 429 itself ====================

    public function test_the_page_issues_one_multi_latest_request_per_cycle(): void
    {
        // `@script` is not part of the initial render output, so the fetch has
        // to be counted in the Blade source.
        $source = $this->bladeSource('livewire/it/wireless.blade.php');

        // The only `fetch()` on the page is the single batched poller. If a
        // per-gauge fetch ever comes back, this count climbs with the device
        // count and the page starts 429-ing again.
        $this->assertSame(
            1,
            $this->countEndpointFetches($source),
            'The wireless page must call /api/zabbix/multi-latest exactly once per refresh cycle.'
        );
        $this->assertSame(1, substr_count($source, 'setInterval('));
    }

    public function test_the_gauge_no_longer_fetches_or_polls_on_its_own(): void
    {
        $source = $this->bladeSource('livewire/it/multi-gauge.blade.php');

        $this->assertStringNotContainsString('/api/zabbix/', $source);
        $this->assertStringNotContainsString('setInterval', $source);
        $this->assertStringNotContainsString('fetch(', $source);
    }

    public function test_the_gauge_markup_carries_no_endpoint_of_its_own(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        // Guards the *rendered* output too, so a future `x-data` that inlines a
        // URL cannot slip past a source-only check.
        $this->assertStringNotContainsString('/api/zabbix/', Livewire::test('it.wireless')->assertOk()->html());
    }

    public function test_the_gauge_subscribes_to_the_shared_state_instead(): void
    {
        $source = $this->bladeSource('livewire/it/multi-gauge.blade.php');

        $this->assertStringContainsString('window.zabbixGauges', $source);
        $this->assertStringContainsString('state.subscribers', $source);
    }

    public function test_a_refresh_cycle_stays_inside_the_api_user_budget(): void
    {
        $source = $this->bladeSource('livewire/it/wireless.blade.php');

        // One request now, one 30s later: 2 per minute regardless of how many
        // devices exist. The limiter allows 60/min, so the page can no longer
        // be the thing that exhausts it.
        $this->assertSame(1, $this->countEndpointFetches($source));

        $interval = $this->extractIntervalMs($source);
        $this->assertNotNull($interval, 'The page must schedule a refresh interval.');
        $this->assertLessThanOrEqual(
            60,
            (int) ceil($interval / 60000),
            'Two refreshes per minute must fit inside the 60/min throttle budget.'
        );
    }

    public function test_the_refresh_interval_is_never_tied_to_the_gauge_count(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $withAllDevices = count(Livewire::test('it.wireless')->assertOk()->instance()->itemIds());

        ZabbixDevice::query()->where('type', 'wireless')->limit(13)->update(['is_active' => false]);
        $this->flushZabbixCache();
        $withOneDevice = count(Livewire::test('it.wireless')->assertOk()->instance()->itemIds());

        $this->assertSame(42, $withAllDevices);
        $this->assertSame(3, $withOneDevice);

        // One poller for the page either way — the request count is a property
        // of the page, not of the number of gauges on screen.
        $source = $this->bladeSource('livewire/it/wireless.blade.php');
        $this->assertSame(1, $this->countEndpointFetches($source));
    }

    // ==================== Sibling page shares the same limiter ====================

    public function test_the_networks_charts_do_not_poll_faster_than_the_budget_allows(): void
    {
        $source = $this->bladeSource('livewire/it/network-traffic-chart.blade.php');

        $this->assertSame(1, substr_count($source, 'setInterval'));
        $this->assertStringNotContainsString('10000', $source);

        // 25 seeded networks × 1 request per cycle must stay under 60/min.
        preg_match('/setInterval\(\(\) => this\.load\(\), (\d+)\)/', $source, $matches);
        $this->assertNotEmpty($matches, 'Could not read the traffic chart poll interval.');

        $requestsPerMinute = 25 * (int) ceil((int) $matches[1] / 60000);
        $this->assertLessThanOrEqual(60, $requestsPerMinute);
    }

    // ==================== Helpers ====================

    /**
     * Read a Blade view with its `{{-- … --}}` comments removed, so assertions
     * only ever see executable code and never the prose around it.
     */
    private function bladeSource(string $view): string
    {
        $source = file_get_contents(resource_path('views/'.$view));

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    /**
     * Count real `fetch()` call sites for the multi-latest endpoint.
     *
     * Deliberately matches the call, not the bare URL, so a PHPDoc or `//`
     * comment that merely *mentions* the endpoint cannot inflate the count.
     */
    private function countEndpointFetches(string $source): int
    {
        return preg_match_all('/fetch\(\s*[`\'"]\/api\/zabbix\/multi-latest/', $source);
    }

    private function flushZabbixCache(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment(ZabbixDevice::CACHE_NAMESPACE);
    }

    private function extractIntervalMs(string $source): ?int
    {
        return preg_match('/REFRESH_MS\s*=\s*(\d+)/', $source, $matches)
            ? (int) $matches[1]
            : null;
    }
}
