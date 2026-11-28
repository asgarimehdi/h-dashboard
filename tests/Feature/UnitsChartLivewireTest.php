<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class UnitsChartLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ==================== Page load / auth ====================

    public function test_guest_302(): void
    {
        $this->get('/units/chart')->assertRedirect('/login');
    }

    public function test_renders_tree(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.chart')
            ->assertStatus(200)
            ->assertSee('ساختار درختی واحدها')
            ->assertSee($unit->name);
    }

    public function test_returns_403_without_permission(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $this->get('/units/chart')->assertStatus(403);
    }

    // ==================== Interaction tests ====================

    public function test_scope_roots(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        // Create a second root unit that the user should NOT see
        Unit::create(['name' => 'واحد دیگر']);

        $component = Livewire::test('units.chart')
            ->assertStatus(200);

        $rootUnits = $component->get('rootUnits');
        $this->assertCount(1, $rootUnits);
        $this->assertEquals($unit->name, $rootUnits[0]->name);
    }

    public function test_toggle(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        // Create a child unit
        $child = Unit::create(['name' => 'زیرمجموعه', 'parent_id' => $user->person->u_id]);
        $component = Livewire::test('units.chart')
            ->assertStatus(200);

        // Initially root is expanded by default (level < 3)
        $expandedBefore = $component->get('expanded');
        $this->assertContains((string) $user->person->u_id, $expandedBefore);

        // Collapse the root
        $component->call('toggle', (string) $user->person->u_id);
        $expandedAfter = $component->get('expanded');
        $this->assertNotContains((string) $user->person->u_id, $expandedAfter);

        // Expand again
        $component->call('toggle', (string) $user->person->u_id);
        $expandedAgain = $component->get('expanded');
        $this->assertContains((string) $user->person->u_id, $expandedAgain);
    }

    public function test_search_expands(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        // Create a deep hierarchy: root -> child -> grandchild
        $child = Unit::create(['name' => 'شبکه', 'parent_id' => $user->person->u_id]);
        $grandchild = Unit::create(['name' => 'مرکز', 'parent_id' => $child->id]);

        $component = Livewire::test('units.chart')
            ->assertStatus(200);

        // Search with <= 2 chars: should NOT auto-expand
        $component->set('search', 'مر');
        $expanded = $component->get('expanded');
        $this->assertNotContains((string) $grandchild->id, $expanded);

        // Search with > 2 chars: should auto-expand parent chain
        $component->set('search', 'مرکز');
        $expanded = $component->get('expanded');
        $this->assertContains((string) $child->id, $expanded);
        $this->assertContains((string) $user->person->u_id, $expanded);
    }

    public function test_two_persian_letters_do_not_expand(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $this->actingAs($result['user']);

        $child = Unit::create(['name' => 'شبکه', 'parent_id' => $result['user']->person->u_id]);
        Unit::create(['name' => 'مرکز', 'parent_id' => $child->id]);

        $component = Livewire::test('units.chart');
        $component->set('search', 'مر');   // 2 characters / 4 bytes

        $this->assertSame([], $component->get('expanded'));
    }

    public function test_wildcard_search_does_not_expand(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $this->actingAs($result['user']);

        $child = Unit::create(['name' => 'شبکه', 'parent_id' => $result['user']->person->u_id]);
        Unit::create(['name' => 'مرکز', 'parent_id' => $child->id]);

        $component = Livewire::test('units.chart');
        $component->set('search', '%%%');  // 3 characters, passes the guard, must match nothing

        $this->assertSame([], $component->get('expanded'));
    }

    public function test_select_unit(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        // Create another unit in the same hierarchy
        $child = Unit::create(['name' => 'زیرمجموعه', 'parent_id' => $user->person->u_id]);

        // Create a person/user in the child unit
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::factory()->create([
            'n_code' => $nCode,
            'u_id' => $child->id,
        ]);
        $user2 = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user2->units()->attach($child->id, ['role' => 'staff', 'is_primary' => true]);

        $component = Livewire::test('units.chart')
            ->assertStatus(200)
            ->call('selectUnit', $child->id);

        $selectedUnit = $component->get('selectedUnit');
        $this->assertNotNull($selectedUnit);
        $this->assertEquals($child->id, $selectedUnit->id);
        $this->assertEquals('زیرمجموعه', $selectedUnit->name);

        // Check user counts
        $directCount = $component->get('directUserCount');
        $descendantCount = $component->get('descendantUserCount');
        $this->assertEquals(1, $directCount);
        $this->assertEquals(0, $descendantCount); // No deeper descendants
    }

    public function test_select_unauthorized(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        // Create an OUT-OF-SCOPE unit (different root, not in user's accessible units)
        $otherRoot = Unit::create(['name' => 'واحد دیگر']);
        $otherChild = Unit::create(['name' => 'فرزند دیگر', 'parent_id' => $otherRoot->id]);

        $component = Livewire::test('units.chart')
            ->assertStatus(200)
            ->call('selectUnit', $otherChild->id);

        // Should NOT select the unauthorized unit
        $selectedUnit = $component->get('selectedUnit');
        $this->assertNull($selectedUnit);
    }

    // ==================== Tree cache (#1999) ====================

    /**
     * A toggle round-trip must not rebuild the tree: hydrate() reads the
     * versioned `unit_hierarchy` cache, so the `units` table is untouched.
     *
     * The CTE is already cached by descendantIds(), so counting only
     * `with recursive` would pass before the fix — count the table instead.
     */
    public function test_toggle_round_trip_does_not_query_the_unit_tree(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Unit::create(['name' => 'زیرمجموعه شمارش', 'parent_id' => $unit->id]);

        // mount warms both the scope cache and the tree cache.
        $component = Livewire::test('units.chart')->assertStatus(200);

        $unitQueries = 0;
        DB::listen(function ($query) use (&$unitQueries): void {
            if (str_contains($query->sql, '"units"')) {
                $unitQueries++;
            }
        });

        $component->call('toggle', (string) $unit->id);

        $this->assertSame(0, $unitQueries, "Unit-table queries in one toggle round-trip: {$unitQueries}");
    }

    /**
     * A search round-trip must not re-run the tree fetch, so no
     * `unit_types` eager-load happens. The LIKE query on `units` is the
     * search itself and is allowed.
     */
    public function test_search_round_trip_does_not_reload_unit_types(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $type = UnitType::create(['name' => 'شبکه بهداشت']);
        Unit::create(['name' => 'شبکه جستجو', 'parent_id' => $unit->id, 'unit_type_id' => $type->id]);

        $component = Livewire::test('units.chart');

        $typeQueries = 0;
        DB::listen(function ($query) use (&$typeQueries): void {
            if (str_contains($query->sql, '"unit_types"')) {
                $typeQueries++;
            }
        });

        $component->set('search', 'شبکه جستجو');

        $this->assertSame(0, $typeQueries, "unit_types eager-loads in one search round-trip: {$typeQueries}");
    }

    /**
     * The cached tree carries the `unit_hierarchy` version in its key, so a
     * unit write (create/update/delete) makes it unreachable.
     */
    public function test_tree_cache_is_invalidated_by_a_unit_write(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $component = Livewire::test('units.chart'); // warms the tree cache

        // Unit::created → AppServiceProvider → increment('unit_hierarchy')
        Unit::create(['name' => 'واحد تازه‌ساخته', 'parent_id' => $unit->id]);

        $component->refresh()->assertSee('واحد تازه‌ساخته');
    }

    // ==================== Edge cases ====================

    public function test_empty_state(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];

        // Create a parent root that the test user does NOT belong to
        $parentRoot = Unit::create(['name' => 'واحد ریشه']);
        // User2 has a unit that is a CHILD (not a root), so rootUnits will be empty
        $unit2 = Unit::create(['name' => 'واحد تهی', 'parent_id' => $parentRoot->id]);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::factory()->create([
            'n_code' => $nCode,
            'u_id' => $unit2->id,
        ]);
        $user2 = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user2->givePermissionTo('organization');
        $user2->units()->attach($unit2->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit2->id);

        // Override the actingAs user
        $this->actingAs($user2);

        $component = Livewire::test('units.chart')
            ->assertStatus(200)
            ->assertSee('موردی یافت نشد.');
    }
}
