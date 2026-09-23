<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ['user' => $user] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        Livewire::test('units.chart')
            ->assertStatus(200)
            ->assertSee('ساختار درختی واحدها')
            ->assertSee('واحد تست');
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
        $this->assertEquals('واحد تست', $rootUnits[0]->name);
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
