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

class MapsUnitLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function seedRegionAndTypes(): Region
    {
        $region = Region::create(['name' => 'شهرستان تست', 'type' => 'county']);

        // UnitType::$fillable excludes 'id'; use DB::table for explicit IDs
        DB::table('unit_types')->insert([
            ['id' => 5, 'name' => 'مرکز جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'مرکز خدمات جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'name' => 'پایگاه سلامت', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::select("SELECT setval('unit_types_id_seq', GREATEST((SELECT COALESCE(MAX(id),0) FROM unit_types), 1))");

        return $region;
    }

    protected function createBoundary(): int
    {
        DB::statement(
            'INSERT INTO boundaries (boundary, created_at, updated_at) VALUES '
            ."(ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326), now(), now())"
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    // ==================== Auth / permissions ====================

    public function test_guest_302(): void
    {
        $this->get('/maps/unit')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $this->get('/maps/unit')->assertStatus(403);
    }

    public function test_renders(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->assertStatus(200)
            ->assertSee('نقشه واحد ها');
    }

    // ==================== Unit boundary / map display ====================

    public function test_unit_boundary(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();

        // Initially: region toggles are visible; center types only after selection
        Livewire::test('maps/unit')
            ->assertSee('شهرستان تست')
            ->assertDontSee('مرکز جامع سلامت')  // hidden until region selected
            ->set('selectedRegions', [$region->id])
            ->assertSee('مرکز جامع سلامت')       // now visible
            ->assertSee('مرکز خدمات جامع سلامت')
            ->assertSee('پایگاه سلامت');
    }

    // ==================== Scope filtering ====================

    public function test_scope_filtering(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();
        $boundaryId = $this->createBoundary();

        // Accessible unit in selected region with a boundary (required by component)
        $accessibleUnit = Unit::create([
            'name' => 'واحد قابل دسترس',
            'region_id' => $region->id,
            'unit_type_id' => 5,
            'boundary_id' => $boundaryId,
        ]);

        session(['current_unit_id' => $accessibleUnit->id]);

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->set('selectedCenterTypes', [5])
            ->assertSee('واحد قابل دسترس');
    }

    // ==================== Filters ====================

    public function test_search_filter(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->set('search', 'test')
            ->assertStatus(200);
    }

    public function test_region_filter_dispatches_events(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->assertDispatched('county-boundaries-loaded')
            ->assertDispatched('units-updated');
    }

    // ==================== Edge cases ====================

    public function test_empty_state_without_units(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = Region::create(['name' => 'شهرستان خالی', 'type' => 'county']);

        DB::table('unit_types')->insert([
            ['id' => 5, 'name' => 'مرکز جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Select region + center type but no matching units exist
        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->set('selectedCenterTypes', [5])
            ->assertSee('واحدی یافت نشد');
    }

    public function test_show_help_modal(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->assertSet('showHelpModal', false)
            ->call('$refresh')
            ->assertStatus(200);
    }

    // ==================== #1023: boundary GeoJSON is a single-pass select ====================

    public function test_county_boundary_queries_do_not_grow_with_region_count(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $regionIds = [];
        for ($i = 1; $i <= 21; $i++) {
            $regionIds[] = Region::create([
                'name' => sprintf('شهرستان %02d', $i),
                'type' => 'county',
                'boundary_id' => $this->createBoundary(),
            ])->id;
        }

        Cache::flush();

        // Non-vacuity: the county query really returns all 21 regions.
        Livewire::test('maps/unit')
            ->set('selectedRegions', $regionIds)
            ->assertDispatched('county-boundaries-loaded', function (string $name, array $params): bool {
                return count($params['counties']) === 21;
            });

        Cache::flush();

        // The old code fired one boundary query per region (the accessor); the
        // single-pass select stays flat regardless of region count.
        $this->assertNoNPlusOne(function () use ($regionIds): void {
            Livewire::test('maps/unit')->set('selectedRegions', $regionIds);
        }, 12);
    }

    public function test_unit_queries_do_not_grow_with_unit_count(): void
    {
        ['user' => $user, 'unit' => $baseUnit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();
        $boundaryId = $this->createBoundary();

        for ($i = 1; $i <= 21; $i++) {
            Unit::create([
                'name' => sprintf('واحد %02d', $i),
                'parent_id' => $baseUnit->id,
                'region_id' => $region->id,
                'unit_type_id' => 5,
                'boundary_id' => $boundaryId,
            ]);
        }

        Cache::flush();

        // Non-vacuity: all 21 units come back with their bare geometry.
        $units = collect(
            Livewire::test('maps/unit')
                ->set('selectedRegions', [$region->id])
                ->set('selectedCenterTypes', [5])
                ->get('units')
        );
        $this->assertCount(21, $units);

        Cache::flush();

        // The old code fired one boundary query per unit; the join stays flat.
        $this->assertNoNPlusOne(function () use ($region): void {
            Livewire::test('maps/unit')
                ->set('selectedRegions', [$region->id])
                ->set('selectedCenterTypes', [5]);
        }, 12);
    }

    public function test_unit_payload_geojson_is_a_bare_multipolygon(): void
    {
        ['user' => $user, 'unit' => $baseUnit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();
        $boundaryId = $this->createBoundary();

        Unit::create([
            'name' => 'واحد مرزدار',
            'parent_id' => $baseUnit->id,
            'region_id' => $region->id,
            'unit_type_id' => 5,
            'boundary_id' => $boundaryId,
        ]);

        // Boundary-less unit of the same region/type: omitted from the payload.
        Unit::create([
            'name' => 'واحد بدون مرز',
            'parent_id' => $baseUnit->id,
            'region_id' => $region->id,
            'unit_type_id' => 5,
        ]);

        // Boundary-bearing unit outside the caller's scope: fail-closed.
        Unit::create([
            'name' => 'واحد خارج از دسترس',
            'region_id' => $region->id,
            'unit_type_id' => 5,
            'boundary_id' => $boundaryId,
        ]);

        $units = collect(
            Livewire::test('maps/unit')
                ->set('selectedRegions', [$region->id])
                ->set('selectedCenterTypes', [5])
                ->get('units')
        );

        $this->assertCount(1, $units);
        $row = $units->first();
        $this->assertSame(['id', 'name', 'type_id', 'geojson'], array_keys($row));

        // #702: the map expects a BARE geometry, not a GeoJSON Feature.
        $decoded = json_decode($row['geojson'], true);
        $this->assertSame('MultiPolygon', $decoded['type']);
        $this->assertArrayNotHasKey('geometry', $decoded);
    }
}
