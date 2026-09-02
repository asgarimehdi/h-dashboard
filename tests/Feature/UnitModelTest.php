<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

covers(Unit::class);

class UnitModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // --- Relationships ---

    public function test_unit_has_many_children(): void
    {
        $parent = Unit::create(['name' => 'والد']);
        $child1 = Unit::create(['name' => 'فرزند ۱', 'parent_id' => $parent->id]);
        $child2 = Unit::create(['name' => 'فرزند ۲', 'parent_id' => $parent->id]);

        $this->assertCount(2, $parent->children);
    }

    public function test_unit_belongs_to_parent(): void
    {
        $parent = Unit::create(['name' => 'والد']);
        $child = Unit::create(['name' => 'فرزند', 'parent_id' => $parent->id]);

        $this->assertNotNull($child->parent);
        $this->assertEquals($parent->id, $child->parent->id);
    }

    public function test_unit_has_many_persons(): void
    {
        $unit = Unit::create(['name' => 'واحد تست']);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);

        $this->assertCount(1, $unit->person);
    }

    public function test_unit_belongs_to_many_users(): void
    {
        $unit = Unit::create(['name' => 'واحد تست']);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        $this->assertCount(1, $unit->assignedUsers);
    }

    // --- ancestorIds ---

    public function test_ancestor_ids_returns_parent_ids(): void
    {
        $grandparent = Unit::create(['name' => 'پدربزرگ']);
        $parent = Unit::create(['name' => 'والد', 'parent_id' => $grandparent->id]);
        $child = Unit::create(['name' => 'فرزند', 'parent_id' => $parent->id]);

        // ancestorIds is a single JOIN — returns only direct parents
        $ancestors = Unit::ancestorIds([$child->id]);

        $this->assertContains($parent->id, $ancestors);
        $this->assertNotContains($grandparent->id, $ancestors);
        $this->assertNotContains($child->id, $ancestors);
    }

    public function test_ancestor_ids_returns_multiple_parents_for_multiple_children(): void
    {
        $parent1 = Unit::create(['name' => 'والد ۱']);
        $parent2 = Unit::create(['name' => 'والد ۲']);
        $child1 = Unit::create(['name' => 'فرزند ۱', 'parent_id' => $parent1->id]);
        $child2 = Unit::create(['name' => 'فرزند ۲', 'parent_id' => $parent2->id]);

        $ancestors = Unit::ancestorIds([$child1->id, $child2->id]);

        $this->assertContains($parent1->id, $ancestors);
        $this->assertContains($parent2->id, $ancestors);
    }

    public function test_ancestor_ids_returns_empty_for_empty_input(): void
    {
        $result = Unit::ancestorIds([]);
        $this->assertTrue($result->isEmpty());
    }

    public function test_ancestor_ids_returns_empty_for_root_unit(): void
    {
        $root = Unit::create(['name' => 'ریشه']);

        $ancestors = Unit::ancestorIds([$root->id]);

        $this->assertTrue($ancestors->isEmpty());
    }

    public function test_ancestor_ids_caches_results(): void
    {
        $unit = Unit::create(['name' => 'واحد']);
        $parent = Unit::create(['name' => 'والد', 'parent_id' => $unit->id]);

        Cache::put('unit_hierarchy_version', 0);

        $result1 = Unit::ancestorIds([$parent->id]);
        $result2 = Unit::ancestorIds([$parent->id]);

        $this->assertEquals($result1->toArray(), $result2->toArray());
    }

    // --- descendantIds ---

    public function test_descendant_ids_returns_all_descendants(): void
    {
        $parent = Unit::create(['name' => 'والد']);
        $child1 = Unit::create(['name' => 'فرزند ۱', 'parent_id' => $parent->id]);
        $child2 = Unit::create(['name' => 'فرزند ۲', 'parent_id' => $parent->id]);
        $grandchild = Unit::create(['name' => 'نوه', 'parent_id' => $child1->id]);

        $descendants = Unit::descendantIds([$parent->id]);

        $this->assertContains($parent->id, $descendants);
        $this->assertContains($child1->id, $descendants);
        $this->assertContains($child2->id, $descendants);
        $this->assertContains($grandchild->id, $descendants);
    }

    public function test_descendant_ids_with_single_id(): void
    {
        $unit = Unit::create(['name' => 'واحد']);
        $child = Unit::create(['name' => 'فرزند', 'parent_id' => $unit->id]);

        $descendants = Unit::descendantIds($unit->id);

        $this->assertContains($unit->id, $descendants);
        $this->assertContains($child->id, $descendants);
    }

    public function test_descendant_ids_returns_empty_for_empty_input(): void
    {
        $result = Unit::descendantIds([]);
        $this->assertTrue($result->isEmpty());
    }

    public function test_descendant_ids_caches_results(): void
    {
        $parent = Unit::create(['name' => 'والد']);
        $child = Unit::create(['name' => 'فرزند', 'parent_id' => $parent->id]);

        Cache::put('unit_hierarchy_version', 0);

        $result1 = Unit::descendantIds([$parent->id]);
        $result2 = Unit::descendantIds([$parent->id]);

        $this->assertEquals($result1->toArray(), $result2->toArray());
    }

    // --- withinBounds scope ---

    public function test_within_bounds_returns_units_in_bounding_box(): void
    {
        $inBounds = Unit::create(['name' => 'داخل', 'lat' => 35.5, 'lng' => 51.5]);
        $outOfBounds = Unit::create(['name' => 'خارج', 'lat' => 40.0, 'lng' => 60.0]);

        $results = Unit::withinBounds(35.0, 36.0, 51.0, 52.0)->get();

        $this->assertContains($inBounds->id, $results->pluck('id')->toArray());
        $this->assertNotContains($outOfBounds->id, $results->pluck('id')->toArray());
    }

    // --- nearby scope ---

    public function test_nearby_returns_units_within_radius(): void
    {
        $nearby = Unit::create(['name' => 'نزدیک', 'lat' => 35.5, 'lng' => 51.5]);
        $far = Unit::create(['name' => 'دور', 'lat' => 39.0, 'lng' => 60.0]);

        $results = Unit::nearby(35.5, 51.5, 10)->get();

        $this->assertContains($nearby->id, $results->pluck('id')->toArray());
        $this->assertNotContains($far->id, $results->pluck('id')->toArray());
    }

    // --- Fillable ---

    public function test_unit_allows_mass_assignment(): void
    {
        $unit = Unit::create([
            'name' => 'واحد تست',
            'description' => 'توضیحات',
            'lat' => 35.5,
            'lng' => 51.5,
        ]);

        $this->assertEquals('واحد تست', $unit->name);
        $this->assertEquals('توضیحات', $unit->description);
        $this->assertEquals(35.5, $unit->lat);
        $this->assertEquals(51.5, $unit->lng);
    }

    // --- childrenRecursive ---

    public function test_children_recursive_eager_loads_hierarchy(): void
    {
        $parent = Unit::create(['name' => 'والد']);
        $child = Unit::create(['name' => 'فرزند', 'parent_id' => $parent->id]);
        $grandchild = Unit::create(['name' => 'نوه', 'parent_id' => $child->id]);

        $loaded = Unit::with('childrenRecursive')->find($parent->id);

        $this->assertCount(1, $loaded->childrenRecursive);
        $this->assertCount(1, $loaded->childrenRecursive->first()->childrenRecursive);
    }
}
