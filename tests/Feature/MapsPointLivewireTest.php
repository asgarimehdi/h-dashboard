<?php

namespace Tests\Feature;

use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UnitTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
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

    public function test_add_point(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        // Remove coordinates to simulate a unit without location data
        $unit->update(['lat' => null, 'lng' => null]);
        Cache::flush();

        // Unit without coordinates should not appear in location
        $component = Livewire::test('maps/point');
        $location = $component->get('location');
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
        $location = $component->get('location');
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
        $location = $component->get('location');
        $this->assertEmpty(collect($location)->firstWhere('id', $orphan->id));
    }
}
