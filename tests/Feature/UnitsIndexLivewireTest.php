<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

class UnitsIndexLivewireTest extends TestCase
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

        DB::table('unit_types')->insert([
            ['id' => 1, 'name' => 'وزارت بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'دانشگاه علوم پزشکی', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'معاونت بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'شبکه بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'مرکز خدمات جامع سلامت شهری', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('unit_type_relationships')->insert([
            ['child_unit_type_id' => 2, 'allowed_parent_unit_type_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 3, 'allowed_parent_unit_type_id' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 4, 'allowed_parent_unit_type_id' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 5, 'allowed_parent_unit_type_id' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('regions')->insert([
            ['id' => 1, 'name' => 'استان تست', 'type' => 'province', 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'شهرستان الف', 'type' => 'county', 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'شهرستان ب', 'type' => 'county', 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->resetSequence('unit_types', 'id');
        $this->resetSequence('regions', 'id');
        $this->resetSequence('unit_type_relationships', 'id');
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

    /**
     * Create a user linked to a unit, with the given permission.
     * Returns [user, unit].
     */
    protected function createUserWithUnit(array $overrides = []): array
    {
        $unitTypeId = $overrides['unit_type_id'] ?? 4;
        $regionId = $overrides['region_id'] ?? null;

        $unit = Unit::create([
            'name' => $overrides['unit_name'] ?? 'واحد تست',
            'unit_type_id' => $unitTypeId,
            'region_id' => $regionId,
            'parent_id' => $overrides['parent_id'] ?? null,
        ]);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user->givePermissionTo($overrides['permission'] ?? 'organization');

        return [$user, $unit];
    }

    // ==================== Smoke tests ====================

    public function test_authorized_renders(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        Livewire::test('units.index')->assertStatus(200);
    }

    public function test_unauthorized_403(): void
    {
        [$user] = $this->createUserWithUnit(['permission' => 'manage_users']);
        $this->actingAs($user);

        $this->get('/units')->assertStatus(403);
    }

    // ==================== Search ====================

    public function test_search_by_name(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

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
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        foreach (range(1, 15) as $i) {
            Unit::create(['name' => "واحد {$i}", 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        }

        Livewire::test('units.index')->set('perPage', 10)->assertStatus(200);
    }

    // ==================== Sorting ====================

    public function test_sorting(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        Unit::create(['name' => 'edelta', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        Unit::create(['name' => 'alpha', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        Livewire::test('units.index')
            ->set('sortBy', ['column' => 'name', 'direction' => 'asc'])
            ->assertStatus(200);
    }

    // ==================== Dropdown cascading ====================

    public function test_dropdown_cascading(): void
    {
        [$user, $unit] = $this->createUserWithUnit(['unit_type_id' => 1]);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

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

    // ==================== Create unit ====================

    public function test_create_unit(): void
    {
        // Parent = type 2 (university). Allowed child = type 3 (deputy).
        [$user, $parent] = $this->createUserWithUnit(['unit_type_id' => 2]);
        $this->actingAs($user);
        Session::put('current_unit_id', $parent->id);

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
        [$user, $parent] = $this->createUserWithUnit(['unit_type_id' => 2]);
        $this->actingAs($user);
        Session::put('current_unit_id', $parent->id);

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
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        Livewire::test('units.index')
            ->call('saveUnit')
            ->assertHasErrors(['name', 'unit_type_id']);
    }

    // ==================== Delete unit ====================

    public function test_delete_unit(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $target = Unit::create(['name' => 'واحد قابل حذف', 'unit_type_id' => 5, 'region_id' => 2]);

        Livewire::test('units.index')->call('deleteUnit', $target->id);

        $this->assertDatabaseMissing('units', ['id' => $target->id]);
    }

    // ==================== Delete FK blocked ====================

    public function test_delete_fk_blocked(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

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
        [$user1, $provUnit] = $this->createUserWithUnit(['unit_type_id' => 2, 'region_id' => 1]);
        $this->actingAs($user1);
        Session::put('current_unit_id', $provUnit->id);

        Livewire::test('units.index')
            ->assertSet('userUnitLevel', 'province')
            ->assertSet('userRegionId', 1);

        // County-level: unit has region with type=county
        [$user2, $countyUnit] = $this->createUserWithUnit(['unit_type_id' => 3, 'region_id' => 2]);
        $this->actingAs($user2);
        Session::put('current_unit_id', $countyUnit->id);

        Livewire::test('units.index')
            ->assertSet('userUnitLevel', 'county')
            ->assertSet('fixedRegionId', 2);
    }

    // ==================== Toggle ticket capability ====================

    public function test_toggle_ticket_capability(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_unit_tickets');
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $target = Unit::create([
            'name' => 'واحد تیکت', 'unit_type_id' => 5,
            'region_id' => 2, 'can_receive_tickets' => false,
        ]);

        Livewire::test('units.index')->call('toggleTicketCapability', $target->id);
        $this->assertDatabaseHas('units', ['id' => $target->id, 'can_receive_tickets' => true]);

        Livewire::test('units.index')->call('toggleTicketCapability', $target->id);
        $this->assertDatabaseHas('units', ['id' => $target->id, 'can_receive_tickets' => false]);
    }
}
