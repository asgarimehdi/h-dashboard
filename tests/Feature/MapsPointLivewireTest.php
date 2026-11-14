<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\Unit;
use App\Models\UnitType;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UnitTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Unit::class);

class MapsPointLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(UnitTypeSeeder::class);
        $this->seedLookupTables();

        Cache::flush();
    }

    /**
     * Parse the marker set published in the inert JSON block.
     *
     * #1481: `$location` is no longer a public property — the block is the
     * only carrier, so these tests must read it from the rendered HTML.
     *
     * @return array<int, array<string, mixed>>
     */
    private function publishedLocations(Testable $component): array
    {
        preg_match('/id="point-locations-data"[^>]*>(.*?)<\/script>/s', $component->html(), $m);

        return json_decode($m[1] ?? '[]', true) ?? [];
    }

    /**
     * Bulk-insert located child units under $parent.
     *
     * Raw inserts on purpose: 2,000+ factory rows would dominate the suite's
     * runtime, and the page only needs rows the scope CTE can find.
     *
     * @return array{first: int, last: int}
     */
    private function seedLocatedUnits(int $parentId, int $count, string $prefix = 'واحد دارای موقعیت'): array
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'name' => $prefix.' '.$i,
                'parent_id' => $parentId,
                'lat' => 35 + ($i / 10000),
                'lng' => 51 + ($i / 10000),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Unit::insert($chunk);
        }

        return [
            'first' => (int) Unit::where('parent_id', $parentId)->min('id'),
            'last' => (int) Unit::where('parent_id', $parentId)->max('id'),
        ];
    }

    // ==================== Page load / auth ====================

    public function test_guest_302(): void
    {
        $this->get('/maps/point')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $this->get('/maps/point')->assertStatus(403);
    }

    public function test_renders(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        Livewire::test('maps/point')
            ->assertStatus(200)
            ->assertSee('نقاط لوکیشن');
    }

    // ==================== Interaction tests ====================

    public function test_unit_type_rename_invalidates_point_map_type_cache(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // #1439: the seeded names share prefixes (`… شهری` is a prefix of
        // `… شهری روستایی`), so assertSee/assertDontSee on them would be
        // vacuous or over-strict. Give every cached type a unique name.
        // Look the rows up by name: the seeder runs per test, so the Postgres
        // sequence (not transactional) gives them different ids each run.
        $type5 = UnitType::where('name', 'مرکز خدمات جامع سلامت شهری')->firstOrFail();
        UnitType::where('name', 'مرکز خدمات جامع سلامت شهری روستایی')->firstOrFail()
            ->update(['name' => 'نام تازه شش']);
        UnitType::where('name', 'مرکز خدمات جامع سلامت روستایی')->firstOrFail()
            ->update(['name' => 'نام تازه هفت']);
        $type5->update(['name' => 'نام تازه پنج']);

        // Warm `point_map:types` with the first name.
        Livewire::test('maps/point')->assertSee('نام تازه پنج');

        $type5->update(['name' => 'نام دیگر پنج']);

        // The rename must invalidate the `maps` namespace, so this mount
        // serves the new name and no longer the stale one.
        Livewire::test('maps/point')
            ->assertSee('نام دیگر پنج')
            ->assertDontSee('نام تازه پنج');
    }

    public function test_add_point(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Add lat/lng to the existing unit (simulating point creation)
        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        $component = Livewire::test('maps/point')
            ->assertStatus(200);

        $location = $this->publishedLocations($component);
        $ids = array_column($location, 'id');
        $this->assertContains($unit->id, $ids);
    }

    public function test_edit_point(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Set initial coordinates
        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        // Verify it's in the results
        $component1 = Livewire::test('maps/point');
        $location1 = $this->publishedLocations($component1);
        $found = collect($location1)->firstWhere('id', $unit->id);
        $this->assertEquals(35.6892, (float) $found['lat']);
        $this->assertEquals(51.3890, (float) $found['lng']);

        // Update coordinates
        $unit->update(['lat' => 36.0, 'lng' => 52.0]);
        Cache::flush();

        $component2 = Livewire::test('maps/point');
        $location2 = $this->publishedLocations($component2);
        $found = collect($location2)->firstWhere('id', $unit->id);
        $this->assertEquals(36.0, (float) $found['lat']);
        $this->assertEquals(52.0, (float) $found['lng']);
    }

    public function test_delete_point(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Set coordinates
        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        // Confirm it's present
        $component1 = Livewire::test('maps/point');
        $location1 = $this->publishedLocations($component1);
        $this->assertNotEmpty(collect($location1)->firstWhere('id', $unit->id));

        // Remove coordinates (delete the point)
        $unit->update(['lat' => null, 'lng' => null]);
        Cache::flush();

        $component2 = Livewire::test('maps/point');
        $location2 = $this->publishedLocations($component2);
        $this->assertEmpty(collect($location2)->firstWhere('id', $unit->id));
    }

    // ==================== Edge case tests ====================

    public function test_invalid_coords(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Remove coordinates to simulate a unit without location data
        $unit->update(['lat' => null, 'lng' => null]);
        Cache::flush();

        // Unit without coordinates should not appear in location
        $component = Livewire::test('maps/point');
        $location = $this->publishedLocations($component);
        $this->assertEmpty(collect($location)->firstWhere('id', $unit->id));
    }

    public function test_duplicate_points_handled(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Create a child unit at the same coordinates
        $child = Unit::create([
            'name' => 'واحد فرزند',
            'lat' => 35.6892,
            'lng' => 51.3890,
            'parent_id' => $unit->id,
        ]);

        // Update parent with same coords
        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        Cache::flush();

        $component = Livewire::test('maps/point');
        $location = $this->publishedLocations($component);
        $ids = array_column($location, 'id');
        $this->assertContains($unit->id, $ids);
        $this->assertContains($child->id, $ids);
    }

    public function test_missing_unit_rejected(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Create a unit the user has NO access to
        $orphan = Unit::create([
            'name' => 'واحد بیگانه',
            'lat' => 35.0,
            'lng' => 51.0,
        ]);

        Cache::flush();

        $component = Livewire::test('maps/point');
        $location = $this->publishedLocations($component);
        $this->assertEmpty(collect($location)->firstWhere('id', $orphan->id));
    }

    // ==================== #1437: region rename invalidates `maps` ====================

    public function test_region_rename_refreshes_the_cached_region_toggles(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // The point page excludes region id 1, so advance the sequence first.
        Region::create(['name' => 'استان رزرو', 'type' => 'province']);
        $region = Region::create(['name' => 'استان کهنه', 'type' => 'province']);

        // Warm `maps/point` (`point_map:regions`).
        Livewire::test('maps/point')->assertSee('استان کهنه');

        $region->update(['name' => 'استان تازه']);

        Livewire::test('maps/point')
            ->assertSee('استان تازه')
            ->assertDontSee('استان کهنه');
    }

    // ==================== #1481: payload out of the snapshot, bound disclosed ====================

    public function test_snapshot_stays_light_with_more_than_the_cap(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // The factory unit carries coordinates; null them so the marker set is
        // exactly the seeded children.
        $unit->update(['lat' => null, 'lng' => null]);

        // Lowest id among the children, so it lands inside the published slice.
        $uniqueName = 'واحد یکتای باند 1481';
        Unit::create([
            'name' => $uniqueName,
            'parent_id' => $unit->id,
            'lat' => 34.5,
            'lng' => 50.5,
        ]);
        $this->seedLocatedUnits($unit->id, 2049);

        $component = Livewire::test('maps/point')->assertOk();
        $snapshotJson = (string) json_encode($component->snapshot);

        // No per-marker property — the array must not travel in the snapshot.
        $this->assertArrayNotHasKey('location', $component->snapshot['data']);
        $this->assertStringNotContainsString($uniqueName, $snapshotJson);

        // ...and it IS published, so the absence above means "moved", not
        // "lost": the block is the carrier now.
        $this->assertStringContainsString($uniqueName, $component->html());

        // ~3 kB measured for this page's non-marker payload ($types +
        // $regions + meta); the old public $location snapshot measured
        // 158,261 B on the dev database. 8,000 leaves room for env noise,
        // not for a relapse.
        $this->assertLessThan(8000, strlen($snapshotJson), 'the snapshot payload grew — a marker array slipped back into public state');
    }

    public function test_cap_is_ordered_and_disclosed(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);
        $unit->update(['lat' => null, 'lng' => null]);

        $range = $this->seedLocatedUnits($unit->id, 2001);

        $component = Livewire::test('maps/point')->assertOk();
        $locations = $this->publishedLocations($component);
        $ids = array_column($locations, 'id');

        // A deterministic slice, not just "a" slice: ordered by id, the first
        // 2,000 rows win and the highest id — the 2,001st row — is the one
        // that is dropped.
        $this->assertCount(2000, $locations);
        $this->assertEquals($range['first'], $ids[0]);
        $this->assertEquals($range['first'] + 1999, $ids[1999]);
        $this->assertNotContains($range['last'], $ids);

        // And the bound is disclosed with the real numbers.
        $html = $component->html();
        $this->assertStringContainsString('data-point-truncation', $html);
        $this->assertStringContainsString('2,000', $html);
        $this->assertStringContainsString('2,001', $html);
    }

    public function test_no_indicator_below_the_cap(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);
        $unit->update(['lat' => null, 'lng' => null]);

        $this->seedLocatedUnits($unit->id, 3);

        $component = Livewire::test('maps/point')->assertOk();

        $this->assertCount(3, $this->publishedLocations($component));
        $this->assertStringNotContainsString('data-point-truncation', $component->html());
    }

    public function test_locations_published_once(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        $component = Livewire::test('maps/point')->assertOk();
        $html = $component->html();

        // Exactly one carrier (the county shape): the inert block, never
        // also the snapshot.
        $this->assertSame(1, substr_count($html, 'id="point-locations-data"'));
        $this->assertCount(1, $this->publishedLocations($component));
        $this->assertStringNotContainsString('"location"', (string) json_encode($component->snapshot));
    }

    public function test_parent_outside_scope_is_published_for_the_line(): void
    {
        // An ancestor of the session unit: outside the accessible set, but its
        // marker is what the child's connection line points at.
        $parent = Unit::create([
            'name' => 'والد بیرون از دامنه',
            'lat' => 34.0,
            'lng' => 50.0,
        ]);

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $unit->update(['parent_id' => $parent->id, 'lat' => 35.0, 'lng' => 51.0]);

        $component = Livewire::test('maps/point')->assertOk();
        $ids = array_column($this->publishedLocations($component), 'id');

        $this->assertContains($unit->id, $ids);
        $this->assertContains($parent->id, $ids);
    }
}
