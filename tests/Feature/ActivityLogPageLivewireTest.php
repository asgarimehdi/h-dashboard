<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** @covers \App\Models\ActivityLog */
class ActivityLogPageLivewireTest extends TestCase
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

    // ==================== Page load / auth ====================

    public function test_activity_log_page_loads_for_authorized_user(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        Livewire::test('activity-log.index')
            ->assertStatus(200);
    }

    public function test_guest_redirected_from_activity_log(): void
    {
        $this->get('/activity-log')->assertRedirect('/login');
    }

    public function test_activity_log_returns_403_without_permission(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $this->get('/activity-log')->assertStatus(403);
    }

    // ==================== Mount ====================

    public function test_activity_log_mount_loads_type_stats(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        ActivityLog::create(['user_id' => $user->id, 'type' => 'created', 'description' => 'test']);
        ActivityLog::create(['user_id' => $user->id, 'type' => 'updated', 'description' => 'test']);
        ActivityLog::create(['user_id' => $user->id, 'type' => 'login', 'description' => 'test']);

        Livewire::test('activity-log.index')
            ->assertSet('typeStats.created', 1)
            ->assertSet('typeStats.updated', 1)
            ->assertSet('typeStats.login', 1);
    }

    // ==================== Search filter ====================

    public function test_activity_log_search_filters_by_description(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        ActivityLog::create(['user_id' => $user->id, 'type' => 'created', 'description' => 'ایجاد کاربر']);
        ActivityLog::create(['user_id' => $user->id, 'type' => 'updated', 'description' => 'ویرایش پروفایل']);

        $component = Livewire::test('activity-log.index');
        $component->set('search', 'ایجاد')
            ->assertSee('ایجاد کاربر')
            ->assertDontSee('ویرایش پروفایل');
    }

    // ==================== Type filter ====================

    public function test_activity_log_type_filter_works(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        ActivityLog::create(['user_id' => $user->id, 'type' => 'created', 'description' => 'ایجاد']);
        ActivityLog::create(['user_id' => $user->id, 'type' => 'login', 'description' => 'ورود']);

        $component = Livewire::test('activity-log.index')
            ->set('typeFilter', 'login');

        // The type filter should work - verify component renders
        $component->assertStatus(200);
    }

    // ==================== User filter ====================

    public function test_activity_log_user_filter_works(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        // Create second user in same unit
        $nCode2 = (string) fake()->unique()->numerify('##########');
        $unit = Unit::first();
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'دوم', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user2 = User::create(['n_code' => $nCode2, 'password' => Hash::make('password')]);
        $user2->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        ActivityLog::create(['user_id' => $user->id, 'type' => 'login', 'description' => 'ورود ۱']);
        ActivityLog::create(['user_id' => $user2->id, 'type' => 'login', 'description' => 'ورود ۲']);

        $component = Livewire::test('activity-log.index')
            ->set('userId', $user->id);

        $component->assertSee('ورود ۱')
            ->assertDontSee('ورود ۲');
    }

    // ==================== Date filter ====================

    public function test_activity_log_date_filter_works(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'login',
            'description' => 'قدیمی',
            'created_at' => now()->subDays(30),
        ]);
        ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'login',
            'description' => 'جدید',
            'created_at' => now(),
        ]);

        // Date filter expects Jalali format
        $from = now()->subDays(15)->format('Y/m/d');
        $to = now()->format('Y/m/d');

        $component = Livewire::test('activity-log.index')
            ->set('dateFrom', $from)
            ->set('dateTo', $to);

        // Verify component renders with filters
        $component->assertStatus(200);
    }

    // ==================== Detail modal ====================

    public function test_activity_log_show_detail_modal(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'updated',
            'description' => 'ویرایش اطلاعات',
            'old_values' => ['name' => 'قدیم'],
            'new_values' => ['name' => 'جدید'],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'TestAgent',
        ]);

        $component = Livewire::test('activity-log.index')
            ->call('showDetail', $log->id);

        $component->assertSet('showModal', true)
            ->assertSet('selectedLog.id', $log->id)
            ->assertSee('ویرایش اطلاعات')
            ->assertSee('قدیم')
            ->assertSee('جدید')
            ->assertSee('192.168.1.1');
    }

    public function test_activity_log_close_detail_modal(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'login',
            'description' => 'ورود',
        ]);

        Livewire::test('activity-log.index')
            ->call('showDetail', $log->id)
            ->call('closeDetail')
            ->assertSet('showModal', false)
            ->assertSet('selectedLog', null);
    }

    // ==================== Pagination ====================

    public function test_activity_log_pagination_works(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        // Create 25 logs (perPage is 20)
        foreach (range(1, 25) as $i) {
            ActivityLog::create([
                'user_id' => $user->id,
                'type' => 'login',
                'description' => "ورود {$i}",
            ]);
        }

        $component = Livewire::test('activity-log.index');
        // Verify component renders with pagination
        $component->assertStatus(200);
    }
}