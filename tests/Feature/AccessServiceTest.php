<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class AccessServiceTest extends TestCase
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

    protected function createUserWithUnit(array $unitAttrs = []): array
    {
        $unit = Unit::create(array_merge(['name' => 'واحد تست'], $unitAttrs));
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return ['user' => $user, 'unit' => $unit];
    }

    // --- accessibleUnitIds ---

    public function test_returns_empty_array_when_no_user_is_authenticated(): void
    {
        $service = new AccessService();
        $this->assertEmpty($service->accessibleUnitIds());
    }

    public function test_returns_user_own_unit_when_session_has_current_unit_id(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Session::put('current_unit_id', $unit->id);

        $service = new AccessService();
        $result = $service->accessibleUnitIds($user);

        $this->assertContains($unit->id, $result);
    }

    public function test_returns_accessible_units_including_children_via_recursive_cte(): void
    {
        $parent = Unit::create(['name' => 'واحد والد']);
        $child1 = Unit::create(['name' => 'واحد فرزند ۱', 'parent_id' => $parent->id]);
        $child2 = Unit::create(['name' => 'واحد فرزند ۲', 'parent_id' => $parent->id]);
        $grandchild = Unit::create(['name' => 'واحد نوه', 'parent_id' => $child1->id]);

        ['user' => $user] = $this->createUserWithUnit(['name' => 'واحد والد']);
        // Override: attach to parent
        $user->units()->detach();
        $user->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $parent->id);

        $service = new AccessService();
        $result = $service->accessibleUnitIds($user);

        $this->assertCount(4, $result);
        $this->assertContains($parent->id, $result);
        $this->assertContains($child1->id, $result);
        $this->assertContains($child2->id, $result);
        $this->assertContains($grandchild->id, $result);
    }

    public function test_returns_only_session_unit_children_when_session_is_set(): void
    {
        $parent = Unit::create(['name' => 'واحد والد']);
        $child = Unit::create(['name' => 'فرزند', 'parent_id' => $parent->id]);
        $unrelated = Unit::create(['name' => 'بی‌ربط']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $parent->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);
        $user->units()->attach($unrelated->id, ['role' => 'staff', 'is_primary' => false]);

        // Session only has parent, not unrelated
        Session::put('current_unit_id', $parent->id);

        $service = new AccessService();
        $result = $service->accessibleUnitIds($user);

        $this->assertContains($parent->id, $result);
        $this->assertContains($child->id, $result);
        $this->assertNotContains($unrelated->id, $result);
    }

    public function test_falls_back_to_person_unit_when_user_has_no_units_pivot(): void
    {
        $unit = Unit::create(['name' => 'واحد شخص']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        Session::forget('current_unit_id');

        $service = new AccessService();
        $result = $service->accessibleUnitIds($user);

        $this->assertContains($unit->id, $result);
    }

    public function test_results_are_cached(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Session::put('current_unit_id', $unit->id);

        $service = new AccessService();
        $result1 = $service->accessibleUnitIds($user);
        $result2 = $service->accessibleUnitIds($user);

        $this->assertEquals($result1, $result2);
    }

    // --- clearCache ---

    public function test_clearCache_removes_cached_accessible_units(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Session::put('current_unit_id', $unit->id);

        $service = new AccessService();
        $service->accessibleUnitIds($user); // prime cache
        $service->clearCache($user);

        // Should still return correct data after cache clear
        $result = $service->accessibleUnitIds($user);
        $this->assertContains($unit->id, $result);
    }

    public function test_clearCache_with_no_user_does_nothing(): void
    {
        $service = new AccessService();
        $service->clearCache(null);
        $this->assertTrue(true);
    }

    // --- clearAllCaches ---

    public function test_clearAllCaches_increments_version_counters(): void
    {
        Cache::put('unit_hierarchy_version', 0);
        Cache::put('gis_version', 0);

        $service = new AccessService();
        $service->clearAllCaches();

        $this->assertEquals(1, Cache::get('unit_hierarchy_version'));
        $this->assertEquals(1, Cache::get('gis_version'));
    }
}
