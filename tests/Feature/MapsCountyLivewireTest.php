<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1173 — /maps/county carried the county boundary geometry TWICE in the
 * initial HTML: once inside the public Livewire snapshot (`public $regions`)
 * and once in the `@script` block that publishes `allregions` to the map.
 *
 * The page has no Livewire interaction at all (no wire:model / wire:click), so
 * the snapshot copy was pure weight. These tests lock the single-publication
 * contract: the geometry reaches the page exactly once, through the map
 * layer, and never through the public component state.
 */
class MapsCountyLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function createBoundary(): int
    {
        DB::statement(
            'INSERT INTO boundaries (boundary, created_at, updated_at) VALUES '
            ."(ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326), now(), now())"
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * A county region that owns a boundary polygon.
     */
    protected function seedRegionWithBoundary(): Region
    {
        return Region::create([
            'name' => 'شهرستان تست',
            'type' => 'county',
            'boundary_id' => $this->createBoundary(),
        ]);
    }

    /**
     * A unit inside the region. The county list is scoped to the regions of
     * the caller's accessible units, so the region only shows up once a unit
     * there sits in scope.
     */
    protected function createUnitInRegion(Region $region): Unit
    {
        return Unit::create([
            'name' => 'واحد '.$region->name,
            'region_id' => $region->id,
        ]);
    }

    /**
     * Log in as a map-permitted user whose current unit sits in a region that
     * owns a boundary — the exact shape the county page renders geometry for.
     */
    protected function actingAsUserInRegionWithBoundary(): Region
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionWithBoundary();

        session(['current_unit_id' => $this->createUnitInRegion($region)->id]);

        Cache::flush();

        return $region;
    }

    // ==================== #1173: geometry out of the public state ====================

    public function test_county_snapshot_carries_no_boundary_geometry(): void
    {
        $this->actingAsUserInRegionWithBoundary();

        $component = Livewire::test('maps.county');
        $component->assertStatus(200);

        $snapshot = json_encode($component->snapshot);

        // The boundary geometry belongs to the map layer, not to the
        // component's public state: it must not be dehydrated into the
        // snapshot that every Livewire request round-trips.
        $this->assertStringNotContainsString('MultiPolygon', $snapshot);
        $this->assertStringNotContainsString('Polygon', $snapshot);
    }

    public function test_county_publishes_the_geometry_exactly_once(): void
    {
        $this->actingAsUserInRegionWithBoundary();

        $html = Livewire::test('maps.county')->assertStatus(200)->html();

        // The map still needs the geometry: it is published once, through the
        // `@script` block that feeds the Leaflet layer.
        $this->assertStringContainsString('allregions', $html);
        $this->assertSame(
            1,
            substr_count($html, 'MultiPolygon'),
            'The county geometry must be published exactly once (the map script), not duplicated into the snapshot.'
        );
    }

    public function test_county_still_lists_the_scoped_regions(): void
    {
        $region = $this->actingAsUserInRegionWithBoundary();

        // The toggle menu reads the same data as the script block. Moving the
        // data out of the public state must not empty the menu.
        Livewire::test('maps.county')
            ->assertStatus(200)
            ->assertSee($region->name)
            ->assertSeeHtml('toggleGeoJson('.$region->id.')');
    }
}
