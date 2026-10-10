<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class UnitsIndexLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        DB::table('unit_types')->insert([
            ['id' => 1, 'name' => 'وزارت بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'دانشگاه علوم پزشکی', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'معاونت بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'شبکه بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'مرکز خدمات جامع سلامت شهری', 'created_at' => now(), 'updated_at' => now()],
            // #875: the two seeded self-referencing types. They encode real
            // intent (ستادی under ستادی, فوریت under فوریت) and stay in the
            // seeder — the walker is what has to terminate on them.
            ['id' => 17, 'name' => 'فوریت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'name' => 'واحد ستادی', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('unit_type_relationships')->insert([
            ['child_unit_type_id' => 2, 'allowed_parent_unit_type_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 3, 'allowed_parent_unit_type_id' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 4, 'allowed_parent_unit_type_id' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 5, 'allowed_parent_unit_type_id' => 4, 'created_at' => now(), 'updated_at' => now()],
            // #875: the cycle that hung getAllowedUnitTypes() for every
            // account whose unit carries one of these types.
            ['child_unit_type_id' => 22, 'allowed_parent_unit_type_id' => 22, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 17, 'allowed_parent_unit_type_id' => 17, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('regions')->insert([
            ['id' => 1, 'name' => 'استان تست', 'type' => 'province', 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'شهرستان الف', 'type' => 'county', 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'شهرستان ب', 'type' => 'county', 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->resetSequence('unit_types', 'id');
        $this->resetSequence('regions', 'id');
        $this->resetSequence('unit_type_relationships', 'id');

        // The component treats unit id 1 as the ministry HQ (userUnitLevel =
        // 'ministry'). Postgres sequences are non-transactional, so whether a
        // test's first unit lands on id=1 depends on random test order.
        // Pin the sequence so created units start at id 2 and level logic
        // falls through to the region-based province/county branches.
        DB::statement("SELECT setval('units_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM units), 1), 1))");
    }

    protected function resetSequence(string $table, string $column): void
    {
        try {
            $maxId = DB::table($table)->max($column);
            if ($maxId !== null) {
                DB::statement("SELECT setval('\"{$table}_{$column}_seq\"', {$maxId}, true)");
            }
        } catch (\Exception $e) {
            // Sequence might not exist — safe to ignore
        }
    }

    // ==================== #857: empty unit scope must not list the org ====================

    public function test_index_leaks_nothing_on_an_empty_scope(): void
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بدون واحد', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => null,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo('organization');

        $foreignUnit = Unit::create(['name' => 'واحد بیرونی تست']);

        $this->actingAs($user);

        $this->assertSame([], app(AccessService::class)->accessibleUnitIds(), 'the fixture must really be an empty scope');

        Livewire::test('units.index')
            ->assertDontSee('واحد بیرونی تست');

        $component = Livewire::test('units.index');

        $this->assertSame(0, $component->instance()->units()->total(), 'the paginator must fail closed on an empty scope');
    }

    public function test_build_tree_with_empty_accessible_ids_returns_empty(): void
    {
        $parent = Unit::create(['name' => 'والد درخت']);
        $child = Unit::create(['name' => 'فرزند درخت', 'parent_id' => $parent->id]);

        // [] means "in scope of nothing" — fail closed (#857)
        $roots = Unit::buildTree([$parent->id], []);
        $this->assertCount(0, $roots);

        // null keeps the caller-wants-no-filter contract
        $roots = Unit::buildTree([$parent->id], null);
        $this->assertCount(1, $roots);
    }

    // ==================== Smoke tests ====================

    public function test_authorized_renders(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        Livewire::test('units.index')->assertStatus(200);
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $this->get('/units')->assertStatus(403);
    }

    // ==================== Search ====================

    public function test_search_by_name(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        Unit::create(['name' => 'بیمارستان امیرالمؤمنین', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        Unit::create(['name' => 'خانه بهداشت ولیعصر', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        Livewire::test('units.index')
            ->set('search', 'امیر')
            ->assertSee('بیمارستان امیرالمؤمنین')
            ->assertDontSee('خانه بهداشت ولیعصر');
    }

    // ==================== Per-page toggle ====================

    public function test_perpage_toggle(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        foreach (range(1, 15) as $i) {
            Unit::create(['name' => "واحد {$i}", 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        }

        Livewire::test('units.index')->set('perPage', 10)->assertStatus(200);
    }

    // ==================== Sorting ====================

    public function test_sorting(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        Unit::create(['name' => 'edelta', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        Unit::create(['name' => 'alpha', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        Livewire::test('units.index')
            ->set('sortBy', ['column' => 'name', 'direction' => 'asc'])
            ->assertStatus(200);
    }

    // ==================== Dropdown cascading ====================

    public function test_dropdown_cascading(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $unit->update(['unit_type_id' => 1]);
        $this->actingAs($user);

        Livewire::test('units.index')
            ->set('unit_type_id', 5)
            ->assertSet('province_id', null)
            ->assertSet('region_id', null)
            ->assertSet('parent_id', null);

        Livewire::test('units.index')
            ->set('province_id', 1)
            ->assertSet('region_id', null)
            ->assertSet('parent_id', null);

        Livewire::test('units.index')
            ->set('region_id', 2)
            ->assertSet('parent_id', null);
    }

    // ==================== #875: self-referencing unit types must not hang the walker ====================

    /**
     * A user in a ستادی (type 22) unit. The seeded [22,22] relationship is a
     * self-edge, so a walker with no visited set never empties its frontier.
     * The unit must also carry a county region, otherwise determineUserLevel()
     * falls through to the branch that leaves userUnitLevel null.
     */
    private function userInSelfReferencingUnit(int $unitTypeId): User
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => $unitTypeId, 'region_id' => 2]);
        $this->actingAs($user);

        return $user;
    }

    public function test_allowed_unit_types_terminate_on_self_referencing_type_22(): void
    {
        $this->userInSelfReferencingUnit(22);

        // Does not return at all without the fix.
        Livewire::test('units.index')->assertStatus(200);
    }

    public function test_allowed_unit_types_terminate_on_self_referencing_type_17(): void
    {
        $this->userInSelfReferencingUnit(17);

        Livewire::test('units.index')->assertStatus(200);
    }

    public function test_self_referencing_type_is_deduped_not_truncated(): void
    {
        $this->userInSelfReferencingUnit(22);

        $unitTypes = Livewire::test('units.index')->instance()->unitTypes;

        // The [22,22] row encodes real intent, so a ستادی manager must still
        // be offered ستادی — the cycle is deduped, not cut away. Asserting the
        // member (rather than only "no hang") is what separates a visited-set
        // fix from a depth cap that silently truncates the dropdown.
        $this->assertCount(1, $unitTypes);
        $this->assertSame(22, $unitTypes->first()->id);
    }

    public function test_allowed_unit_types_are_unique_on_an_acyclic_chain(): void
    {
        // The fixture chain is 1 → 2 → 3 → 4 → 5, so a type-2 user reaches the
        // descendants {3, 4, 5} — not their own type. Nothing may repeat.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'region_id' => 1]);
        $this->actingAs($user);

        $ids = Livewire::test('units.index')->instance()->unitTypes->pluck('id')->all();

        $this->assertSame([3, 4, 5], $ids);
        $this->assertSame($ids, array_values(array_unique($ids)), 'the dropdown must not repeat a type');
    }

    public function test_allowed_unit_types_stay_finite_on_the_dropdown_reentry_path(): void
    {
        $this->userInSelfReferencingUnit(22);

        // updatedUnitTypeId() re-runs loadDropdowns(), so the same walk runs
        // again on every cascading change of the form.
        Livewire::test('units.index')
            ->set('unit_type_id', 22)
            ->assertStatus(200);
    }

    public function test_depth_cap_truncates_rather_than_hanging_on_a_long_chain(): void
    {
        // A chain far deeper than any real hierarchy (100 → 101 → … → 139),
        // with the seeded self-edges removed so this exercises the cap alone.
        // Ids start at 100 to clear the fixture rows (1-5, 17, 22) — and the
        // matching unit_types rows must exist too, otherwise the final
        // UnitType::whereIn() would filter the answer back down and the
        // assertion would pass for the wrong reason.
        DB::table('unit_type_relationships')->delete();

        $base = 100;
        $length = 40;
        $types = [];
        $chain = [];
        for ($i = 0; $i < $length; $i++) {
            $types[] = ['id' => $base + $i, 'name' => 'نوع '.($base + $i), 'created_at' => now(), 'updated_at' => now()];
        }
        for ($i = 0; $i < $length - 1; $i++) {
            $chain[] = [
                'child_unit_type_id' => $base + $i + 1,
                'allowed_parent_unit_type_id' => $base + $i,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('unit_types')->insert($types);
        DB::table('unit_type_relationships')->insert($chain);

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => $base, 'region_id' => 1]);
        $this->actingAs($user);

        $ids = Livewire::test('units.index')->instance()->unitTypes->pluck('id')->all();

        // All 39 descendants are reachable, but the guard must truncate the
        // walk rather than return them: a future data error degrades into a
        // short dropdown instead of a hung request.
        $this->assertNotEmpty($ids);
        $this->assertLessThan(
            $length - 1,
            count($ids),
            'the depth cap must bound the walk rather than return every descendant'
        );
    }

    // ==================== Create unit ====================

    public function test_create_unit(): void
    {
        // Parent = type 2 (university). Allowed child = type 3 (deputy).
        ['user' => $user, 'unit' => $parent] = $this->createUserWithUnit(['organization']);
        $parent->update(['unit_type_id' => 2]);
        $this->actingAs($user);

        Livewire::test('units.index')
            ->set('name', 'unit_test_name')
            ->set('unit_type_id', 3) // deputy — allowed child of university (type 2)
            ->set('parent_id', $parent->id)
            ->call('saveUnit');

        $this->assertDatabaseHas('units', ['name' => 'unit_test_name', 'unit_type_id' => 3]);
    }

    // ==================== Edit unit ====================

    public function test_edit_unit(): void
    {
        // Parent = type 2 (university). Child = type 3 (deputy).
        ['user' => $user, 'unit' => $parent] = $this->createUserWithUnit(['organization']);
        $parent->update(['unit_type_id' => 2]);
        $this->actingAs($user);

        $target = Unit::create([
            'name' => 'واحد قبل', 'unit_type_id' => 3,
            'region_id' => 2, 'parent_id' => $parent->id,
        ]);

        Livewire::test('units.index')
            ->call('editUnit', $target->id)
            ->assertSet('editingId', $target->id)
            ->assertSet('name', 'واحد قبل')
            ->assertSet('modal', true);

        Livewire::test('units.index')
            ->call('editUnit', $target->id)
            ->set('name', 'unit_updated_name')
            ->call('saveUnit');

        $this->assertDatabaseHas('units', ['id' => $target->id, 'name' => 'unit_updated_name']);
    }

    // ==================== Validation errors ====================

    public function test_validation_errors(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        Livewire::test('units.index')
            ->call('saveUnit')
            ->assertHasErrors(['name', 'unit_type_id']);
    }

    // ==================== Delete unit ====================

    public function test_delete_unit(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        // #817: in-scope child of the caller's unit (the old version created
        // a rootless unit outside the subtree and asserted the delete — i.e.
        // it pinned the vulnerable behaviour).
        $target = Unit::create([
            'name' => 'واحد قابل حذف', 'unit_type_id' => 5,
            'region_id' => 2, 'parent_id' => $unit->id,
        ]);

        Livewire::test('units.index')->call('deleteUnit', $target->id);

        $this->assertDatabaseMissing('units', ['id' => $target->id]);
    }

    // ==================== #817: out-of-scope delete refused ====================

    public function test_delete_unit_denied_out_of_scope(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        $outsider = Unit::create([
            'name' => 'واحد خارجی', 'unit_type_id' => 5, 'region_id' => 2,
        ]);

        Livewire::test('units.index')->call('deleteUnit', $outsider->id);

        $this->assertDatabaseHas('units', ['id' => $outsider->id]);
    }

    // ==================== #817: edit refused out of scope ====================

    public function test_edit_unit_denied_out_of_scope(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        $outsider = Unit::create([
            'name' => 'واحد خارجی', 'unit_type_id' => 5, 'region_id' => 2,
        ]);

        Livewire::test('units.index')
            ->call('editUnit', $outsider->id)
            ->assertSet('editingId', null)
            ->assertSet('modal', false);
    }

    // ==================== #817: cross-unit parent_id refused ====================

    public function test_save_unit_denied_with_out_of_scope_parent(): void
    {
        ['user' => $user, 'unit' => $parent] = $this->createUserWithUnit(['organization']);
        $parent->update(['unit_type_id' => 2]);
        $this->actingAs($user);

        $outsider = Unit::create(['name' => 'والد خارجی', 'unit_type_id' => 4]);

        Livewire::test('units.index')
            ->set('name', 'واحد نباید ساخته شود')
            ->set('unit_type_id', 3)
            ->set('parent_id', $outsider->id)
            ->call('saveUnit');

        $this->assertDatabaseMissing('units', ['name' => 'واحد نباید ساخته شود']);
        $this->assertEquals($outsider->id, Unit::find($outsider->id)->id);
    }

    // ==================== #817: self/descendant parent (cycle) refused ====================

    public function test_save_unit_denied_with_descendant_parent(): void
    {
        ['user' => $user, 'unit' => $parent] = $this->createUserWithUnit(['organization']);
        $parent->update(['unit_type_id' => 2]);
        $this->actingAs($user);

        $child = Unit::create([
            'name' => 'فرزند', 'unit_type_id' => 3,
            'region_id' => 2, 'parent_id' => $parent->id,
        ]);
        $grandchild = Unit::create([
            'name' => 'نوه', 'unit_type_id' => 4,
            'region_id' => 2, 'parent_id' => $child->id,
        ]);
        $originalParentId = $child->parent_id;

        Livewire::test('units.index')
            ->call('editUnit', $child->id)
            ->set('parent_id', $grandchild->id)
            ->call('saveUnit');

        // Tree unchanged: the cycle write was rejected.
        $this->assertEquals($originalParentId, $child->fresh()->parent_id);
    }

    // ==================== #817: toggle refused out of scope ====================

    public function test_toggle_ticket_capability_denied_out_of_scope(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $user->givePermissionTo('manage_unit_tickets');
        $this->actingAs($user);

        $outsider = Unit::create([
            'name' => 'واحد خارجی', 'unit_type_id' => 5,
            'region_id' => 2, 'can_receive_tickets' => false,
        ]);

        Livewire::test('units.index')->call('toggleTicketCapability', $outsider->id);

        $this->assertDatabaseHas('units', ['id' => $outsider->id, 'can_receive_tickets' => false]);
    }

    // ==================== #817: delete with children refused ====================

    public function test_delete_unit_with_children_denied_with_message(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        $parentUnit = Unit::create([
            'name' => 'والد درون محدوده', 'unit_type_id' => 4,
            'region_id' => 2, 'parent_id' => $unit->id,
        ]);
        Unit::create([
            'name' => 'فرزند درون محدوده', 'unit_type_id' => 5,
            'region_id' => 2, 'parent_id' => $parentUnit->id,
        ]);

        Livewire::test('units.index')->call('deleteUnit', $parentUnit->id);

        // Explicit children guard keeps both rows instead of relying on the
        // swallowed FK exception.
        $this->assertDatabaseHas('units', ['id' => $parentUnit->id]);
    }

    // ==================== Delete FK blocked ====================

    public function test_delete_fk_blocked(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        // Create a parent with a child unit (FK constraint on parent_id)
        $parent = Unit::create(['name' => 'والد', 'unit_type_id' => 4, 'region_id' => 2]);
        Unit::create(['name' => 'فرزند', 'unit_type_id' => 5, 'region_id' => 2, 'parent_id' => $parent->id]);

        // Attempting to delete a unit that has FK references should throw.
        // We test the FK constraint behavior directly since Livewire's
        // catch-and-toast pattern leaves Postgres in a failed-transaction
        // state that prevents further assertions in the same test.
        $this->expectException(QueryException::class);
        $parent->delete();
    }

    // ==================== Level logic ====================

    public function test_level_logic(): void
    {
        // Province-level: unit has region with type=province
        ['user' => $user1, 'unit' => $provUnit] = $this->createUserWithUnit(['organization']);
        $provUnit->update(['unit_type_id' => 2, 'region_id' => 1]);
        $this->actingAs($user1);

        Livewire::test('units.index')
            ->assertSet('userUnitLevel', 'province')
            ->assertSet('userRegionId', 1);

        // County-level: unit has region with type=county
        ['user' => $user2, 'unit' => $countyUnit] = $this->createUserWithUnit(['organization']);
        $countyUnit->update(['unit_type_id' => 3, 'region_id' => 2]);
        $this->actingAs($user2);

        Livewire::test('units.index')
            ->assertSet('userUnitLevel', 'county')
            ->assertSet('fixedRegionId', 2);
    }

    // ==================== Toggle ticket capability ====================

    public function test_toggle_ticket_capability(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $user = $result['user'];
        $unit = $result['unit'];
        $user->givePermissionTo('manage_unit_tickets');
        $this->actingAs($user);

        // #817: in-scope child of the caller's unit (the old version toggled
        // a rootless unit outside the subtree and pinned the hole).
        $target = Unit::create([
            'name' => 'واحد تیکت', 'unit_type_id' => 5,
            'region_id' => 2, 'parent_id' => $unit->id, 'can_receive_tickets' => false,
        ]);

        Livewire::test('units.index')->call('toggleTicketCapability', $target->id);
        $this->assertDatabaseHas('units', ['id' => $target->id, 'can_receive_tickets' => true]);

        Livewire::test('units.index')->call('toggleTicketCapability', $target->id);
        $this->assertDatabaseHas('units', ['id' => $target->id, 'can_receive_tickets' => false]);
    }

    // ==================== #949 step 1: deactivation is the primary path ====================

    /**
     * The counter-test to `test_delete_unit_strips_personnel_when_unguarded`
     * documents what deactivation does NOT do: it does not touch a single
     * staff row. `persons.u_id` stays set, so `applyOrgScope()` keeps matching
     * and the assets behind those people stay reachable.
     */
    public function test_deactivating_unit_keeps_its_person_and_hardware(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $person = Person::factory()->create(['u_id' => $unit->id]);
        $hardware = $this->createHardware(['n_code' => $person->n_code]);

        Livewire::test('units.index')->call('setInactive', $unit->id, false);

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'is_active' => false]);

        // The whole point: retiring a unit is not a delete. Nothing is nulled,
        // nothing cascades, the assets are still attached to their owner.
        $this->assertDatabaseHas('persons', ['n_code' => $person->n_code, 'u_id' => $unit->id]);
        $this->assertDatabaseHas('hardwares', ['id' => $hardware->id, 'n_code' => $person->n_code]);
    }

    /**
     * "…and in scope". An account assigned to the retired unit keeps that unit
     * in `accessibleUnitIds()`: `Unit::descendantIds()` seeds the CTE from the
     * caller's own base ids with no `is_active` filter, so only the *descendants*
     * of a retired unit drop out — the unit's own staff do not lose their
     * dashboard. This is the exact failure #949 measured after a delete
     * (155 of 318 accounts silently seeing an empty dashboard).
     */
    public function test_deactivating_unit_keeps_the_account_in_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $hardware = $this->createHardware(['n_code' => $user->person->n_code]);

        Livewire::test('units.index')->call('setInactive', $unit->id, false);

        $accessible = app(AccessService::class)->accessibleUnitIds();
        $this->assertContains($unit->id, $accessible);

        // And the org scope still resolves the unit's hardware — the query
        // shape `hardware/index.blade.php::applyOrgScope()` uses.
        $visible = Hardware::query()
            ->whereHas('person', fn ($q) => $q->whereIn('u_id', $accessible))
            ->pluck('id')
            ->all();
        $this->assertContains($hardware->id, $visible);
    }

    public function test_activating_a_retired_unit_restores_it(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);
        $unit->update(['is_active' => false]);

        Livewire::test('units.index')->call('setInactive', $unit->id, true);

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'is_active' => true]);
    }

    /**
     * #817 parity: the permission gate (`organization`) is not a scope check.
     * `setInactive` is a public Livewire method, so an out-of-scope id must be
     * refused the same way `editUnit` / `deleteUnit` are.
     */
    public function test_set_inactive_denied_out_of_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $outsider = Unit::create(['name' => 'واحد خارجی', 'unit_type_id' => 5, 'region_id' => 2]);

        Livewire::test('units.index')->call('setInactive', $outsider->id, false);

        $this->assertDatabaseHas('units', ['id' => $outsider->id, 'is_active' => true]);
    }
}
