<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class MapsInteractiveLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ==================== Page load ====================

    public function test_interactive_map_renders_for_authorized_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps.interactive')
            ->assertStatus(200);
    }

    // ==================== Mount / units data ====================

    public function test_interactive_map_mounts_with_units_data(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $unit->update(['lat' => 35.6892, 'lng' => 51.3890]);

        $this->actingAs($user);
        Livewire::test('maps.interactive')
            ->assertSet('units', function ($units) use ($unit) {
                return count($units) === 1
                    && $units[0]['id'] === $unit->id
                    && $units[0]['lat'] == 35.6892
                    && $units[0]['lng'] == 51.3890;
            });
    }
}
