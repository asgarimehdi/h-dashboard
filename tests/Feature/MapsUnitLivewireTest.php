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

    /**
     * An accessible unit sitting in $region. The caller's scope is the session
     * unit's subtree, so a region only becomes reachable once a unit there is
     * reachable — the fixture the #1032 scope tests are built on. unit_type_id
     * is left null: the scope queries key on region_id only.
     */
    protected function createUnitInRegion(Region $region): Unit
    {
        return Unit::create([
            'name' => 'واحد '.$region->name,
            'region_id' => $region->id,
        ]);
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

        // The region toggle is scoped (#1032): it only renders when a unit in
        // the region is reachable, so put one in scope before asserting.
        session(['current_unit_id' => $this->createUnitInRegion($region)->id]);

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
        ['user' => $user, 'unit' => $baseUnit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $regionIds = [];
        for ($i = 1; $i <= 21; $i++) {
            $region = Region::create([
                'name' => sprintf('شهرستان %02d', $i),
                'type' => 'county',
                'boundary_id' => $this->createBoundary(),
            ]);
            $regionIds[] = $region->id;

            // #1032 scoped the county list to the caller's accessible units:
            // without an in-scope unit every region is out of scope, the payload
            // is empty, and the count assertion below would be vacuous.
            Unit::create([
                'name' => sprintf('واحد شهرستان %02d', $i),
                'parent_id' => $baseUnit->id,
                'region_id' => $region->id,
            ]);
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

    // ==================== #1032: region boundary scope ====================

    public function test_selected_region_outside_scope_returns_no_geometry(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $accessibleRegion = $this->seedRegionAndTypes();
        session(['current_unit_id' => $this->createUnitInRegion($accessibleRegion)->id]);

        $foreignRegion = Region::create([
            'name' => 'شهرستان بیگانه',
            'type' => 'county',
            'boundary_id' => $this->createBoundary(),
        ]);

        // The foreign region HAS a boundary, so the only reason its geometry is
        // missing from the payload is the scope filter.
        Livewire::test('maps/unit')
            ->set('selectedRegions', [$foreignRegion->id])
            ->assertDispatched('county-boundaries-loaded', counties: []);
    }

    public function test_region_list_only_shows_regions_inside_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $accessibleRegion = $this->seedRegionAndTypes();
        session(['current_unit_id' => $this->createUnitInRegion($accessibleRegion)->id]);

        Region::create(['name' => 'شهرستان بیگانه', 'type' => 'county']);

        Livewire::test('maps/unit')
            ->assertSee('شهرستان تست')          // in scope: a unit sits there
            ->assertDontSee('شهرستان بیگانه');   // out of scope: no unit there
    }

    public function test_county_boundaries_cache_does_not_leak_between_scopes(): void
    {
        $accessibleRegion = $this->seedRegionAndTypes();
        $foreignRegion = Region::create([
            'name' => 'شهرستان بیگانه',
            'type' => 'county',
            'boundary_id' => $this->createBoundary(),
        ]);

        $inScopeUnit = $this->createUnitInRegion($accessibleRegion);
        $foreignUnit = $this->createUnitInRegion($foreignRegion);

        $broadUser = $this->createUserWithUnit(['map'])['user'];
        $narrowUser = $this->createUserWithUnit(['map'])['user'];

        Cache::flush();

        // Broad scope: an accessible unit in the foreign region, so its boundary
        // is returned — and written to the cache.
        $this->actingAs($broadUser);
        session(['current_unit_id' => $foreignUnit->id]);

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$foreignRegion->id])
            ->assertDispatched('county-boundaries-loaded', function (string $name, array $params): bool {
                return count($params['counties']) === 1;
            });

        // Narrow scope: same region id, same selection. If the cache key ignores
        // the scope, the boundary written above is served here and the query
        // filter never runs — the leak this test exists to prevent.
        $this->actingAs($narrowUser);
        session(['current_unit_id' => $inScopeUnit->id]);

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$foreignRegion->id])
            ->assertDispatched('county-boundaries-loaded', counties: []);
    }

    public function test_out_of_scope_region_shows_no_boundary_and_no_units(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $accessibleRegion = $this->seedRegionAndTypes();
        session(['current_unit_id' => $this->createUnitInRegion($accessibleRegion)->id]);

        $foreignRegion = Region::create([
            'name' => 'شهرستان بیگانه',
            'type' => 'county',
            'boundary_id' => $this->createBoundary(),
        ]);

        // A boundary-bearing unit in the foreign region, but outside the scope.
        Unit::create([
            'name' => 'واحد بیگانه',
            'region_id' => $foreignRegion->id,
            'unit_type_id' => 5,
            'boundary_id' => $this->createBoundary(),
        ]);

        // Both update paths agree: an out-of-scope selection yields neither a
        // boundary nor a unit, so the empty state shows instead of a boundary
        // with nothing inside it. assertDispatched reads the state right after
        // set('selectedRegions') — a later set() replaces the last state.
        Livewire::test('maps/unit')
            ->set('selectedRegions', [$foreignRegion->id])
            ->assertDispatched('county-boundaries-loaded', counties: [])
            ->set('selectedCenterTypes', [5])
            ->assertSee('واحدی یافت نشد');
    }

    public function test_region_cache_keys_are_gated_on_the_access_scope(): void
    {
        $view = file_get_contents(resource_path('views/livewire/maps/unit.blade.php'));

        // #1032: BOTH region caches — the mount() county list and the
        // showSelectedCounties() geometry — must key on the caller's
        // accessible-region set. A scope-free key lets a payload written by a
        // broad-scope user be served to a narrower one on the next hit,
        // bypassing the query-time scope check.
        $scopeKey = "md5(implode(',', \$accessibleRegionIds->all()))";
        $this->assertSame(
            2,
            substr_count($view, $scopeKey),
            'Both region cache keys must be gated on the accessible-region scope.'
        );

        // The pre-#1032 scope-free spellings must not survive a refactor back.
        $this->assertStringNotContainsString("'unit_map:regions:v' . \$v, 300", $view);
        $this->assertStringNotContainsString(
            "'county_boundaries_v' . Cache::get('maps_version', 0) . '_' . md5(implode(',', \$this->selectedRegions))",
            $view
        );
    }
}
