<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UnitTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(Unit::class);

class MapsPointLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(UnitTypeSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        // Resync sequences for tables with explicit IDs
        DB::statement("SELECT setval('tahsils_id_seq', (SELECT COALESCE(MAX(id), 1) FROM tahsils))");
        DB::statement("SELECT setval('estekhdams_id_seq', (SELECT COALESCE(MAX(id), 1) FROM estekhdams))");
        DB::statement("SELECT setval('semats_id_seq', (SELECT COALESCE(MAX(id), 1) FROM semats))");
        DB::statement("SELECT setval('radifs_id_seq', (SELECT COALESCE(MAX(id), 1) FROM radifs))");

        Cache::flush();
    }

    protected function createUserWithUnit(): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return ['user' => $user, 'unit' => $unit];
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('map');
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        Livewire::test('maps/point')
            ->assertStatus(200)
            ->assertSee('نقاط لوکیشن');
    }

    // ==================== Interaction tests ====================

    public function test_add_point(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('map');
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Add lat/lng to the existing unit (simulating point creation)
        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        $component = Livewire::test('maps/point')
            ->assertStatus(200);

        $location = $component->get('location');
        $ids = array_column($location, 'id');
        $this->assertContains($unit->id, $ids);
    }

    public function test_edit_point(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('map');
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Set initial coordinates
        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        // Verify it's in the results
        $component1 = Livewire::test('maps/point');
        $location1 = $component1->get('location');
        $found = collect($location1)->firstWhere('id', $unit->id);
        $this->assertEquals(35.6892, (float) $found['lat']);
        $this->assertEquals(51.3890, (float) $found['lng']);

        // Update coordinates
        $unit->update(['lat' => 36.0, 'lng' => 52.0]);
        Cache::flush();

        $component2 = Livewire::test('maps/point');
        $location2 = $component2->get('location');
        $found = collect($location2)->firstWhere('id', $unit->id);
        $this->assertEquals(36.0, (float) $found['lat']);
        $this->assertEquals(52.0, (float) $found['lng']);
    }

    public function test_delete_point(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('map');
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Set coordinates
        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        // Confirm it's present
        $component1 = Livewire::test('maps/point');
        $location1 = $component1->get('location');
        $this->assertNotEmpty(collect($location1)->firstWhere('id', $unit->id));

        // Remove coordinates (delete the point)
        $unit->update(['lat' => null, 'lng' => null]);
        Cache::flush();

        $component2 = Livewire::test('maps/point');
        $location2 = $component2->get('location');
        $this->assertEmpty(collect($location2)->firstWhere('id', $unit->id));
    }

    // ==================== Edge case tests ====================

    public function test_invalid_coords(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('map');
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Unit without coordinates should not appear in location
        $component = Livewire::test('maps/point');
        $location = $component->get('location');
        $this->assertEmpty(collect($location)->firstWhere('id', $unit->id));
    }

    public function test_duplicate_points_handled(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('map');
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
        $location = $component->get('location');
        $ids = array_column($location, 'id');
        $this->assertContains($unit->id, $ids);
        $this->assertContains($child->id, $ids);
    }

    public function test_missing_unit_rejected(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('map');
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
        $location = $component->get('location');
        $this->assertEmpty(collect($location)->firstWhere('id', $orphan->id));
    }
}
