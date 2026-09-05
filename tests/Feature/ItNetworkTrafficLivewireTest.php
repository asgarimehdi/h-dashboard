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

class ItNetworkTrafficLivewireTest extends TestCase
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

        DB::select("SELECT setval('tahsils_id_seq', GREATEST((SELECT MAX(id) FROM tahsils), 1))");
        DB::select("SELECT setval('estekhdams_id_seq', GREATEST((SELECT MAX(id) FROM estekhdams), 1))");
        DB::select("SELECT setval('semats_id_seq', GREATEST((SELECT MAX(id) FROM semats), 1))");
        DB::select("SELECT setval('radifs_id_seq', GREATEST((SELECT MAX(id) FROM radifs), 1))");
    }

    protected function createUserWithUnit(string $perm = ''): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        if ($perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }

    // ==================== Smoke / auth ====================

    public function test_component_is_registered_and_mountable(): void
    {
        $user = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'out_1',
            'inItemId' => 'in_1',
        ])
            ->assertStatus(200);
    }

    // ==================== Renders / mount ====================

    public function test_renders(): void
    {
        $user = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'out_1',
            'inItemId' => 'in_1',
            'title' => 'Test Traffic',
            'initialDuration' => 3600,
        ])
            ->assertStatus(200)
            ->assertSee('Test Traffic')
            ->assertSee('out_1')
            ->assertSee('in_1');
    }

    public function test_mount_sets_default_values(): void
    {
        $user = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'item_out',
            'inItemId' => 'item_in',
        ])
            ->assertSet('outItemId', 'item_out')
            ->assertSet('inItemId', 'item_in')
            ->assertSet('title', 'Traffic')
            ->assertSet('initialDuration', 3600);
    }

    public function test_custom_title_and_duration(): void
    {
        $user = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'o1',
            'inItemId' => 'i1',
            'title' => 'WAN Traffic',
            'initialDuration' => 7200,
        ])
            ->assertSet('title', 'WAN Traffic')
            ->assertSet('initialDuration', 7200)
            ->assertSee('WAN Traffic');
    }

    // ==================== Edge cases ====================

    public function test_empty_data_placeholder(): void
    {
        $user = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'empty_out',
            'inItemId' => 'empty_in',
            'title' => 'Empty Chart',
        ])
            ->assertStatus(200)
            ->assertSee('Empty Chart');
    }

    public function test_single_data_point_renders(): void
    {
        $user = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'single_out',
            'inItemId' => 'single_in',
            'title' => 'Single Point',
            'initialDuration' => 1800,
        ])
            ->assertStatus(200)
            ->assertSet('initialDuration', 1800);
    }

    public function test_missing_series_safe_with_different_ids(): void
    {
        $user = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);

        Livewire::test('it.network-traffic-chart', [
            'outItemId' => 'nonexistent_out',
            'inItemId' => 'nonexistent_in',
        ])
            ->assertStatus(200)
            ->assertSet('outItemId', 'nonexistent_out')
            ->assertSet('inItemId', 'nonexistent_in');
    }
}
