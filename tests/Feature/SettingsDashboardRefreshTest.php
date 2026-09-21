<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

covers(User::class);

class SettingsDashboardRefreshTest extends TestCase
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
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    public function test_dashboard_refresh_setting_persists(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('dashboardRefresh', 30)
            ->call('save');

        $user->refresh();
        $this->assertEquals(30, $user->settings['dashboard_refresh']);
    }

    public function test_dashboard_reads_refresh_setting(): void
    {
        $user = $this->createUserWithUnit();
        $user->settings = ['dashboard_refresh' => 60];
        $user->save();
        $this->actingAs($user);

        Livewire::test('dashboard')
            ->assertSet('refreshInterval', 60);
    }
}
