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

class MapsRoute2LivewireTest extends TestCase
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

    protected function createUserWithUnit(string $permission = 'map'): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user->givePermissionTo($permission);

        return ['user' => $user, 'unit' => $unit];
    }

    // ==================== Guest / Auth gates ====================

    public function test_guest_302(): void
    {
        $this->get('/maps/route2')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);
        $this->get('/maps/route2')->assertStatus(403);
    }

    public function test_renders(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->assertStatus(200)
            ->assertSee('محاسبه فاصله جاده‌ای');
    }

    // ==================== Interaction tests ====================

    public function test_create_route(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->set('start_point', '35.6892,51.3890')
            ->set('end_point', '32.6546,51.6675')
            ->assertSet('start_point', '35.6892,51.3890')
            ->assertSet('end_point', '32.6546,51.6675');
    }

    public function test_edit_route(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->set('start_point', '35.6892,51.3890')
            ->set('end_point', '32.6546,51.6675')
            ->set('start_point', '36.3200,53.3200')
            ->assertSet('start_point', '36.3200,53.3200')
            ->assertSet('end_point', '32.6546,51.6675');
    }

    public function test_delete_route(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->set('start_point', '35.6892,51.3890')
            ->set('end_point', '32.6546,51.6675')
            ->set('start_point', '')
            ->set('end_point', '')
            ->assertSet('start_point', '')
            ->assertSet('end_point', '');
    }

    // ==================== Swap points ====================

    public function test_swap_points(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->set('start_point', '35.6892,51.3890')
            ->set('end_point', '32.6546,51.6675')
            ->call('swapPoints')
            ->assertSet('start_point', '32.6546,51.6675')
            ->assertSet('end_point', '35.6892,51.3890');
    }
}
