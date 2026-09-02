<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HardwareController;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

covers(HardwareController::class);

class ReportsAdvancedLivewireTest extends TestCase
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
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    public function test_advanced_report_renders_for_authorized_user(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertStatus(200);
    }

    public function test_advanced_report_mounts_with_default_dates(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertSet('reportType', 'tickets')
            ->assertSet('statusFilter', 'all')
            ->assertSet('dateFrom', fn ($val) => ! empty($val) && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $val))
            ->assertSet('dateTo', fn ($val) => ! empty($val) && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $val));
    }

    public function test_advanced_report_has_units_loaded(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertSet('units', fn ($units) => count($units) >= 1);
    }
}
