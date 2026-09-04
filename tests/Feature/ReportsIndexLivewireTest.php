<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\TestCase;

class ReportsIndexLivewireTest extends TestCase
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

    protected function createUserWithMultipleUnits(): User
    {
        $unit1 = Unit::create(['name' => 'واحد اول']);
        $unit2 = Unit::create(['name' => 'واحد دوم']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'چندواحدی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit1->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit1->id, ['role' => 'staff', 'is_primary' => true]);
        $user->units()->attach($unit2->id, ['role' => 'staff', 'is_primary' => false]);

        return $user;
    }

    // ==================== Page load / auth ====================

    public function test_guest_302(): void
    {
        $this->get('/reports/tickets')->assertRedirect('/login');
    }

    public function test_no_context_redirect(): void
    {
        $user = $this->createUserWithMultipleUnits();
        $this->actingAs($user);

        // User with multiple units and no current_unit_id → redirect to select-context
        $this->get('/reports/tickets')->assertRedirect('/select-context');
    }

    public function test_renders_with_auth(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.index')
            ->assertStatus(200)
            ->assertSee('گزارش‌ها')
            ->assertSee('تیکت‌ها')
            ->assertSee('وظایف')
            ->assertSee('کاربران')
            ->assertSee('پرسنل')
            ->assertSee('کل');
    }

    // ==================== Mount / defaults ====================

    public function test_mount_sets_default_dates(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.index')
            ->assertSet('reportType', 'tickets')
            ->assertSet('dateFrom', Jalalian::fromCarbon(now()->subDays(30))->format('Y/m/d'))
            ->assertSet('dateTo', Jalalian::fromCarbon(now())->format('Y/m/d'))
            ->assertSet('selectedUnitId', null);
    }

    // ==================== Report type filter ====================

    public function test_report_type_changes_data(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $unit = Unit::first();
        Ticket::create(['unit_id' => $unit->id, 'subject' => 'تیکت تست', 'status' => 'open', 'ticket_code' => 'T-001', 'content' => 'test content']);

        Livewire::test('reports.index')
            ->assertSet('reportData.total', 1)
            ->set('reportType', 'persons')
            ->assertSet('reportData.total', 1); // User's Person record is in scope
    }

    // ==================== Scope filtering ====================

    public function test_scope_filtering(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $unit = Unit::first();
        $otherUnit = Unit::create(['name' => 'واحد دیگر']);

        Ticket::create(['unit_id' => $unit->id, 'subject' => 'تیکت ما', 'status' => 'open', 'ticket_code' => 'T-002', 'content' => 'test content']);
        Ticket::create(['unit_id' => $otherUnit->id, 'subject' => 'تیکت دیگران', 'status' => 'open', 'ticket_code' => 'T-003', 'content' => 'test content']);

        // User should only see their unit's tickets (1 total, not 2)
        Livewire::test('reports.index')
            ->assertSet('reportData.total', 1);
    }
}
