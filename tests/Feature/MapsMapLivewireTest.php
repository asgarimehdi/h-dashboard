<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MapsMapLivewireTest extends TestCase
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

    protected function createUserWithUnit(string $perm = 'map'): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo($perm);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    // ==================== Standalone render ====================

    public function test_standalone_renders(): void
    {
        Livewire::test('maps.map')
            ->assertStatus(200)
            ->assertSee('wire:ignore')
            ->assertSeeHtml('id="map"')
            ->assertSee('h-[80lvh]');
    }

    // ==================== Mount defaults ====================

    public function test_mount_defaults(): void
    {
        Config::set('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');

        Livewire::test('maps.map')
            ->assertStatus(200)
            ->assertSet('map_tile_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png')
            ->assertSet('setview', '[36.558188, 48.716125]')
            ->assertSet('zoom', '8');
    }

    // ==================== Config override ====================

    public function test_config_override(): void
    {
        Config::set('map.tile_url_template', 'https://custom-tiles.example.com/{z}/{x}/{y}.png');

        Livewire::test('maps.map')
            ->assertSet('map_tile_template', 'https://custom-tiles.example.com/{z}/{x}/{y}.png');
    }

    // ==================== Parent embedding ====================

    public function test_parent_embedding(): void
    {
        Config::set('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');

        // maps.map is embedded via <livewire:maps.map/> in multiple parents
        // (maps.point, maps.county, maps.route, maps.route2,
        //  reports.map-no-boundary, maps.unit).
        // Verify the component renders consistently with its map container
        // regardless of parent context.
        Livewire::test('maps.map')
            ->assertStatus(200)
            ->assertSet('map_tile_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png')
            ->assertSeeHtml('id="map"');
    }

    // ==================== Assets present ====================

    public function test_assets_present(): void
    {
        $component = Livewire::test('maps.map')
            ->assertStatus(200);

        // The @script block (initMap, invalidateSize, resize listener,
        // _leaflet_id guard) and @assets style block are compiled into the
        // Blade view. Use the raw HTML to verify asset presence.
        $html = $component->html();

        $this->assertStringContainsString('initMap', $html, 'initMap script missing');
        $this->assertStringContainsString('invalidateSize', $html, 'invalidateSize missing');
        $this->assertStringContainsString('resize', $html, 'resize listener missing');
        $this->assertStringContainsString('_leaflet_id', $html, '_leaflet_id guard missing');
        $this->assertStringContainsString('styleModule', $html, 'style asset block missing');
    }

    // ==================== SPA re-render safe ====================

    public function test_spa_rerender_safe(): void
    {
        // First render — confirms component initialises without error.
        Livewire::test('maps.map')
            ->assertStatus(200);

        // Second render — SPA navigation re-mounts the same component;
        // the JS initMap guard (checking _leaflet_id) prevents double-init.
        Livewire::test('maps.map')
            ->assertStatus(200)
            ->assertSeeHtml('id="map"')
            ->assertSee('wire:ignore');
    }
}
