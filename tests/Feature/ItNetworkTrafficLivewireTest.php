<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class ItNetworkTrafficLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ==================== Smoke / auth ====================

    public function test_component_is_registered_and_mountable(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'out_1',
            'inItemId' => 'in_1',
        ])
            ->assertStatus(200);
    }

    // ==================== Renders / mount ====================

    public function test_renders(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'out_1',
            'inItemId' => 'in_1',
            'title' => 'Test Traffic',
            'initialDuration' => 3600,
        ])
            ->assertStatus(200)
            ->assertSee('Test Traffic')
            ->assertSee('out_1')
            ->assertSee('in_1');
    }

    public function test_mount_sets_default_values(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'item_out',
            'inItemId' => 'item_in',
        ])
            ->assertSet('outItemId', 'item_out')
            ->assertSet('inItemId', 'item_in')
            ->assertSet('title', 'Traffic')
            ->assertSet('initialDuration', 3600);
    }

    public function test_custom_title_and_duration(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'o1',
            'inItemId' => 'i1',
            'title' => 'WAN Traffic',
            'initialDuration' => 7200,
        ])
            ->assertSet('title', 'WAN Traffic')
            ->assertSet('initialDuration', 7200)
            ->assertSee('WAN Traffic');
    }

    // ==================== Chart asset source (#1183) ====================

    public function test_chart_script_is_the_vendored_build(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        // The chart must load the vendored build (`public/js/chart/`), not a
        // public CDN: the CDN serves a different (newer) Highcharts major
        // version, rejects requests without a Referer header, and violates the
        // app CSP.
        //
        // Asserted on a page that renders the component itself: `/it/networks`
        // hosts it inside a lazy `@island`, so its `@assets` never reach the
        // initial page HTML, and `Livewire::test()` returns only the component
        // markup — the assets are injected into the page, not the component.
        Route::get('/__test/it-network-traffic-chart', fn () => Blade::render(<<<'BLADE'
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head><title>chart</title></head>
        <body>
        <livewire:it.network-traffic-chart out-item-id="out_1" in-item-id="in_1" title="Test Traffic" />
        </body>
        </html>
        BLADE));

        $response = $this->get('/__test/it-network-traffic-chart');

        $response->assertStatus(200);
        $response->assertSee('js/chart/highcharts.js');
        $response->assertDontSee('code.highcharts.com');
    }

    // ==================== Edge cases ====================

    public function test_empty_data_placeholder(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'empty_out',
            'inItemId' => 'empty_in',
            'title' => 'Empty Chart',
        ])
            ->assertStatus(200)
            ->assertSee('Empty Chart');
    }

    public function test_single_data_point_renders(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'single_out',
            'inItemId' => 'single_in',
            'title' => 'Single Point',
            'initialDuration' => 1800,
        ])
            ->assertStatus(200)
            ->assertSet('initialDuration', 1800);
    }

    public function test_missing_series_safe_with_different_ids(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'nonexistent_out',
            'inItemId' => 'nonexistent_in',
        ])
            ->assertStatus(200)
            ->assertSet('outItemId', 'nonexistent_out')
            ->assertSet('inItemId', 'nonexistent_in');
    }
}
