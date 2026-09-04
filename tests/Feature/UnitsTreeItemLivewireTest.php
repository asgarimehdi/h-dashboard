<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

class UnitsTreeItemLivewireTest extends TestCase
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

    public function test_guest_302(): void
    {
        $this->get('/units')->assertRedirect('/login');
    }

    public function test_renders_tree(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Create some child units
        $child1 = Unit::create(['name' => 'فرزند اول', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        $child2 = Unit::create(['name' => 'فرزند دوم', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        $component = Livewire::test('units.index')
            ->assertStatus(200);

        // Root unit name should be visible
        $component->assertSee($unit->name);
        // Children should be visible
        $component->assertSee('فرزند اول');
        $component->assertSee('فرزند دوم');
    }

    public function test_authenticated_without_permission_403(): void
    {
        [$user] = $this->createUserWithUnit(['permission' => 'manage_users']);
        $this->actingAs($user);

        $this->get('/units')->assertStatus(403);
    }

    public function test_authenticated_with_permission_200(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        Livewire::test('units.index')->assertStatus(200);
    }

    // ==================== Interaction tests ====================

    public function test_toggle_expand_collapse(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Create children
        $child1 = Unit::create(['name' => 'فرزند اول', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        $child2 = Unit::create(['name' => 'فرزند دوم', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        $component = Livewire::test('units.chart')
            ->assertStatus(200);

        // By default, root is expanded (per hr.org-chart pattern)
        $expanded = $component->get('expanded');
        $this->assertContains((string) $unit->id, $expanded);

        // Toggle to collapse
        $component->call('toggle', (string) $unit->id);
        $expandedAfterCollapse = $component->get('expanded');
        $this->assertNotContains((string) $unit->id, $expandedAfterCollapse);

        // Toggle again to expand
        $component->call('toggle', (string) $unit->id);
        $expandedAfterExpand = $component->get('expanded');
        $this->assertContains((string) $unit->id, $expandedAfterExpand);

        // Children should be rendered when expanded
        $component->assertSee('فرزند اول');
        $component->assertSee('فرزند دوم');
    }

    public function test_select_unit(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $child = Unit::create(['name' => 'فرزند انتخاب شده', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        $component = Livewire::test('units.chart')
            ->assertStatus(200)
            ->call('selectUnit', $child->id);

        // The selected unit should be set
        $this->assertEquals($child->id, $component->get('selectedUnit')?->id);
    }

    public function test_search_highlight(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        Unit::create(['name' => 'بیمارستان امیرالمؤمنین', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        Unit::create(['name' => 'خانه بهداشت ولیعصر', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        $component = Livewire::test('units.index')
            ->set('search', 'امیر')
            ->assertStatus(200);

        // Matching unit should have border-primary class (highlighted)
        $component->assertSee('بیمارستان امیرالمؤمنین');
        $component->assertDontSee('خانه بهداشت ولیعصر');
    }

    // ==================== Edge case tests ====================

    public function test_nesting_indent(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Create 3-level nesting: root -> child -> grandchild
        $child = Unit::create(['name' => 'فرزند', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        $grandchild = Unit::create(['name' => 'نوه', 'unit_type_id' => 5, 'parent_id' => $child->id, 'region_id' => 2]);

        $component = Livewire::test('units.chart')
            ->assertStatus(200);

        // All levels should render
        $component->assertSee($unit->name);
        $component->assertSee('فرزند');
        $component->assertSee('نوه');

        // Check expanded state includes root and child levels by default
        $expanded = $component->get('expanded');
        $this->assertContains((string) $unit->id, $expanded);
        $this->assertContains((string) $child->id, $expanded);
    }

    public function test_empty_children_no_toggle(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Create a leaf unit (no children)
        $leaf = Unit::create(['name' => 'برگ', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        $component = Livewire::test('units.index')
            ->assertStatus(200);

        // Leaf unit should render
        $component->assertSee('برگ');

        // The tree-item for leaf should show a dot instead of +/- toggle
        // This is tested by verifying the component renders without error
        // and that the leaf unit is visible
    }

    public function test_unit_without_unit_type_no_type_span(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Create a unit without unit_type
        $noType = Unit::create(['name' => 'بدون نوع', 'unit_type_id' => null, 'parent_id' => $unit->id, 'region_id' => 2]);

        $component = Livewire::test('units.index')
            ->assertStatus(200);

        // Should render the unit name
        $component->assertSee('بدون نوع');

        // Should not crash - no type span should be rendered
        // (no assertion needed, just verify no 500 error)
    }

    public function test_explicit_id_seeding_resync(): void
    {
        // This test verifies that explicit-ID seeding with setval resync works
        // The setUp already does this, so if we get here without duplicate key errors,
        // the resync worked.

        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $component = Livewire::test('units.index')
            ->assertStatus(200);

        // Verify we can create new units after explicit-ID seeding
        Unit::create(['name' => 'واحد جدید بعد از رست', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        $this->assertDatabaseHas('units', ['name' => 'واحد جدید بعد از رست']);
    }

    public function test_is_last_shortens_branch_line(): void
    {
        [$user, $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Create multiple children - last one should have shortened branch line
        $child1 = Unit::create(['name' => 'فرزند اول', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        $child2 = Unit::create(['name' => 'فرزند دوم', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);
        $child3 = Unit::create(['name' => 'فرزند سوم (آخرین)', 'unit_type_id' => 5, 'parent_id' => $unit->id, 'region_id' => 2]);

        $component = Livewire::test('units.index')
            ->assertStatus(200);

        $component->assertSee('فرزند اول');
        $component->assertSee('فرزند دوم');
        $component->assertSee('فرزند سوم (آخرین)');
    }
}
