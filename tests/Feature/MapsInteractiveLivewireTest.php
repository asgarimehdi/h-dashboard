<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MapsInteractiveLivewireTest extends TestCase
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

    protected function createUserWithUnit(): User
    {
        $unit = Unit::create([
            'name' => 'واحد تست',
            'lat' => 35.6892,
            'lng' => 51.3890,
        ]);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        session(['current_unit_id' => $unit->id]);

        return $user;
    }

    // ==================== Page load ====================

    public function test_interactive_map_renders_for_authorized_user(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('maps.interactive')
            ->assertStatus(200);
    }

    // ==================== Mount / units data ====================

    public function test_interactive_map_mounts_with_units_data(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();

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
