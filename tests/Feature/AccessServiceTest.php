<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/** @covers \App\Services\AccessService */
class AccessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        // Ensure cache driver is array for predictable assertions.
        config(['cache.default' => 'array']);
    }

    private function makeUserInUnit(Unit $unit): User
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
        $sId = DB::table('semats')->insertGetId(['name' => 'S']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'R']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'A', 'l_name' => 'B',
            't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
            'u_id' => $unit->id,
        ]);

        return User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    }

    public function test_accessible_unit_ids_returns_units_with_descendants(): void
    {
        $parent = Unit::create(['name' => 'Parent']);
        $child = Unit::create(['name' => 'Child', 'parent_id' => $parent->id]);
        $user = $this->makeUserInUnit($parent);
        $user->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $parent->id);
        $this->actingAs($user);

        $ids = app(AccessService::class)->accessibleUnitIds($user);

        $this->assertContains($parent->id, $ids);
        $this->assertContains($child->id, $ids);
    }

    public function test_accessible_unit_ids_returns_empty_for_guest(): void
    {
        $ids = app(AccessService::class)->accessibleUnitIds(null);

        $this->assertEmpty($ids);
    }

    public function test_accessible_unit_ids_falls_back_to_person_unit_when_no_session(): void
    {
        $unit = Unit::create(['name' => 'Only Unit']);
        $user = $this->makeUserInUnit($unit);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        // No current_unit_id set in session -> should fall back to user's person unit.
        $this->actingAs($user);

        $ids = app(AccessService::class)->accessibleUnitIds($user);

        $this->assertContains($unit->id, $ids);
    }

    public function test_clear_cache_bumps_version(): void
    {
        $unit = Unit::create(['name' => 'CU']);
        $user = $this->makeUserInUnit($unit);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);
        $this->actingAs($user);

        $before = app(\App\Services\CacheInvalidationServiceInterface::class)->getVersion('unit_hierarchy');

        app(AccessService::class)->clearCache($user);

        $after = app(\App\Services\CacheInvalidationServiceInterface::class)->getVersion('unit_hierarchy');

        $this->assertGreaterThan($before, $after);
    }

    public function test_clear_all_caches_bumps_multiple_versions(): void
    {
        $unit = Unit::create(['name' => 'CAU']);
        $user = $this->makeUserInUnit($unit);

        $gisBefore = app(\App\Services\CacheInvalidationServiceInterface::class)->getVersion('gis');
        $unitBefore = app(\App\Services\CacheInvalidationServiceInterface::class)->getVersion('unit_hierarchy');

        app(AccessService::class)->clearAllCaches();

        $gisAfter = app(\App\Services\CacheInvalidationServiceInterface::class)->getVersion('gis');
        $unitAfter = app(\App\Services\CacheInvalidationServiceInterface::class)->getVersion('unit_hierarchy');

        $this->assertGreaterThan($gisBefore, $gisAfter);
        $this->assertGreaterThan($unitBefore, $unitAfter);
    }

    public function test_accessible_unit_ids_is_cached_per_user(): void
    {
        $unit = Unit::create(['name' => 'Cached Unit']);
        $user = $this->makeUserInUnit($unit);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);
        $this->actingAs($user);

        $first = app(AccessService::class)->accessibleUnitIds($user);
        $second = app(AccessService::class)->accessibleUnitIds($user);

        $this->assertEquals($first, $second);
        $this->assertCount(1, $first);
    }
}
