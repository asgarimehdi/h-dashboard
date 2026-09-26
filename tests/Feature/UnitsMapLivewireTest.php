<?php

namespace Tests\Feature;

use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class UnitsMapLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function makePolygonGeoJson(float $lng = 48.0, float $lat = 36.0): string
    {
        return json_encode([
            'type' => 'Feature',
            'properties' => (object) [],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$lng, $lat],
                    [$lng + 0.1, $lat],
                    [$lng + 0.1, $lat + 0.1],
                    [$lng, $lat + 0.1],
                    [$lng, $lat],
                ]],
            ],
        ]);
    }

    protected function makeMultiPolygonGeoJson(): string
    {
        return json_encode([
            'type' => 'Feature',
            'properties' => (object) [],
            'geometry' => [
                'type' => 'MultiPolygon',
                'coordinates' => [[
                    [
                        [48.0, 36.0],
                        [48.1, 36.0],
                        [48.1, 36.1],
                        [48.0, 36.1],
                        [48.0, 36.0],
                    ],
                ], [
                    [
                        [49.0, 37.0],
                        [49.1, 37.0],
                        [49.1, 37.1],
                        [49.0, 37.1],
                        [49.0, 37.0],
                    ],
                ]],
            ],
        ]);
    }

    // ==================== S1: Guest 302 ====================

    public function test_guest_302(): void
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $this->get("/units/{$unit->id}/map")->assertRedirect('/login');
    }

    // ==================== S2: No-perm 403 ====================

    public function test_unauthorized_403(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $this->get("/units/{$unit->id}/map")->assertStatus(403);
    }

    // ==================== S3: Authed+perm renders ====================

    public function test_renders(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->assertStatus(200)
            ->assertSee($unit->name)
            ->assertSee('unitMap');
    }

    // ==================== S4: Mount unknown id ====================

    public function test_mount_unknown(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => 99999])
            ->assertSet('hasBoundary', false)
            ->assertSet('unit', null);
    }

    // ==================== S5: saveBoundary valid Polygon ====================

    public function test_save_polygon_creates(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson())
            ->assertSet('hasBoundary', true)
            ->assertDispatched('boundaryUpdated');

        $unit->refresh();
        $this->assertNotNull($unit->boundary_id);
        $this->assertDatabaseHas('boundaries', ['id' => $unit->boundary_id]);
    }

    // ==================== S6: Second polygon updates same row ====================

    public function test_save_second_updates(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson())
            ->assertSet('hasBoundary', true);

        $unit->refresh();
        $firstBoundaryId = $unit->boundary_id;

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson(49.0, 37.0))
            ->assertSet('hasBoundary', true);

        $unit->refresh();
        $this->assertEquals($firstBoundaryId, $unit->boundary_id);
        $this->assertEquals(1, DB::table('boundaries')->count());
    }

    // ==================== S7: Invalid geometry rejected ====================

    public function test_invalid_geometry_rejected(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $pointGeoJson = json_encode([
            'type' => 'Feature',
            'properties' => (object) [],
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [48.0, 36.0],
            ],
        ]);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $pointGeoJson)
            ->assertSet('hasBoundary', false);

        $this->assertDatabaseCount('boundaries', 0);
    }

    public function test_linestring_rejected(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $lineGeoJson = json_encode([
            'type' => 'Feature',
            'properties' => (object) [],
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => [[48.0, 36.0], [48.1, 36.1]],
            ],
        ]);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $lineGeoJson)
            ->assertSet('hasBoundary', false);

        $this->assertDatabaseCount('boundaries', 0);
    }

    public function test_malformed_json_rejected(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', 'not json at all')
            ->assertSet('hasBoundary', false);

        $this->assertDatabaseCount('boundaries', 0);
    }

    // ==================== S8: deleteBoundary clears ====================

    public function test_delete_clears(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson())
            ->assertSet('hasBoundary', true);

        $unit->refresh();
        $this->assertNotNull($unit->boundary_id);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('deleteBoundary')
            ->assertSet('hasBoundary', false)
            ->assertDispatched('boundaryUpdated');

        $unit->refresh();
        $this->assertNull($unit->boundary_id);
    }

    public function test_delete_noop_when_none(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('deleteBoundary')
            ->assertSet('hasBoundary', false);

        $unit->refresh();
        $this->assertNull($unit->boundary_id);
    }

    // ==================== E1: MultiPolygon accepted ====================

    public function test_multipolygon_accepted(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makeMultiPolygonGeoJson())
            ->assertSet('hasBoundary', true)
            ->assertDispatched('boundaryUpdated');

        $unit->refresh();
        $this->assertNotNull($unit->boundary_id);
        $this->assertDatabaseHas('boundaries', ['id' => $unit->boundary_id]);
    }

    // ==================== E2: Empty drawn geometry -> delete path ====================

    public function test_empty_geojson_triggers_delete_path(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        // Save a boundary first
        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson())
            ->assertSet('hasBoundary', true);

        $unit->refresh();
        $this->assertNotNull($unit->boundary_id);

        // Calling deleteBoundary (what happens client-side when no drawn geometry)
        Livewire::test('units.map', ['id' => $unit->id])
            ->call('deleteBoundary')
            ->assertSet('hasBoundary', false);

        $unit->refresh();
        $this->assertNull($unit->boundary_id);
    }

    // ==================== E3: Existing boundary pre-renders geojson ====================

    public function test_existing_boundary_renders_geojson(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        // Save a boundary first
        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson())
            ->assertSet('hasBoundary', true);

        // Mount again — should have geojson and hasBoundary
        Livewire::test('units.map', ['id' => $unit->id])
            ->assertSet('hasBoundary', true)
            ->assertSet('geojson', fn ($val) => $val !== null && str_contains($val, 'Polygon'));
    }

    // ==================== #702: deleteBoundary must not orphan the row ====================

    public function test_delete_boundary_removes_the_boundaries_row(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson())
            ->assertSet('hasBoundary', true);

        $unit->refresh();
        $boundaryId = $unit->boundary_id;
        $this->assertNotNull($boundaryId);
        $this->assertDatabaseHas('boundaries', ['id' => $boundaryId]);

        Livewire::test('units.map', ['id' => $unit->id])->call('deleteBoundary');

        // #702: nulling boundary_id alone left the geometry row behind forever.
        $this->assertDatabaseMissing('boundaries', ['id' => $boundaryId]);
    }

    public function test_delete_boundary_keeps_other_units_boundaries(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $otherUnit = Unit::create(['name' => 'واحد دیگر']);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson());

        $otherBoundaryId = DB::table('boundaries')->insertGetId([
            'boundary' => DB::raw("ST_GeomFromGeoJSON('".json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[50.0, 37.0], [50.1, 37.0], [50.1, 37.1], [50.0, 37.1], [50.0, 37.0]]],
            ])."' )"),
        ]);
        $otherUnit->update(['boundary_id' => $otherBoundaryId]);

        $unit->refresh();
        $mineId = $unit->boundary_id;

        Livewire::test('units.map', ['id' => $unit->id])->call('deleteBoundary');

        $this->assertDatabaseMissing('boundaries', ['id' => $mineId]);
        $this->assertDatabaseHas('boundaries', ['id' => $otherBoundaryId]);
    }

    public function test_delete_boundary_does_not_cascade_delete_the_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.map', ['id' => $unit->id])
            ->call('saveBoundary', $this->makePolygonGeoJson());

        $unit->refresh();
        $this->assertNotNull($unit->boundary_id);

        Livewire::test('units.map', ['id' => $unit->id])->call('deleteBoundary');

        // units.boundary_id is ON DELETE CASCADE — removing the `boundaries` row
        // first would take the unit (and its whole subtree) with it. Clearing the
        // boundary must never delete the unit it belonged to.
        $this->assertDatabaseHas('units', ['id' => $unit->id]);
        $this->assertNull($unit->fresh()->boundary_id);
    }
}
