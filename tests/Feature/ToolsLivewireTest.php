<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

covers(Ticket::class);

class ToolsLivewireTest extends TestCase
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

    // ==================== Page load ====================

    public function test_tools_page_loads_for_authorized_user(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->assertStatus(200);
    }

    public function test_tools_page_requires_auth(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        // /tools is protected by unit_context middleware (requires session unit)
        $this->get('/tools')->assertStatus(200);
    }

    public function test_guest_redirected_from_tools(): void
    {
        $this->get('/tools')->assertRedirect('/login');
    }

    // ==================== Mount / stats ====================

    public function test_tools_mount_populates_stats(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->assertSet('stats.old_tickets', 0)
            ->assertSet('stats.total_tickets', 0)
            ->assertSet('stats.total_activities', 0)
            ->assertSet('stats.total_notifications', 0);
    }

    public function test_tools_mount_reflects_old_tickets(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();

        // Create an old completed ticket (> 30 days)
        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'قدیمی',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
        ]);

        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->assertSet('stats.old_tickets', 1)
            ->assertSet('stats.total_tickets', 1);
    }

    // ==================== Archive tickets ====================

    public function test_archive_tickets_marks_old_completed_as_archived(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'قدیمی',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
        ]);

        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->set('archiveDays', 30)
            ->call('archiveTickets');

        $this->assertDatabaseHas('tickets', ['ticket_code' => 'TKT-001', 'status' => 'archived']);
    }

    public function test_archive_tickets_skips_recent_completed(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'جدید',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(5),
        ]);

        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->set('archiveDays', 30)
            ->call('archiveTickets');

        $this->assertDatabaseHas('tickets', ['ticket_code' => 'TKT-001', 'status' => 'completed']);
    }

    public function test_archive_tickets_validates_days_range(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->set('archiveDays', 3)
            ->call('archiveTickets')
            ->assertHasErrors(['archiveDays']);
    }

    // ==================== Clean activities ====================

    public function test_clean_activities_deletes_old_logs(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'test',
            'description' => 'قدیمی',
        ]);
        // Manually backdate because ActivityLogService sets now()
        DB::table('activity_logs')->where('description', 'قدیمی')->update(['created_at' => now()->subDays(120)]);

        Livewire::test('tools.tools')
            ->set('activityDays', 90)
            ->call('cleanActivities');

        $this->assertDatabaseMissing('activity_logs', ['description' => 'قدیمی']);
    }

    // ==================== Clean notifications ====================

    public function test_clean_notifications_deletes_old_notifications(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'قدیمی',
        ]);
        DB::table('notifications')->where('title', 'قدیمی')->update(['created_at' => now()->subDays(30)]);

        Livewire::test('tools.tools')
            ->set('notificationDays', 7)
            ->call('cleanNotifications');

        $this->assertDatabaseMissing('notifications', ['title' => 'قدیمی']);
    }

    // ==================== Validation ====================

    public function test_clean_activities_validates_days_range(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->set('activityDays', 5)
            ->call('cleanActivities')
            ->assertHasErrors(['activityDays']);
    }

    public function test_clean_notifications_validates_days_range(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->set('notificationDays', 100)
            ->call('cleanNotifications')
            ->assertHasErrors(['notificationDays']);
    }
}
