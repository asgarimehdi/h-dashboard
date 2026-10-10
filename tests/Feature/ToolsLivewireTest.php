<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DailyReport;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

#[CoversNothing]

class ToolsLivewireTest extends TestCase
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

    public function test_tools_page_loads_for_authorized_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->assertStatus(200);
    }

    public function test_tools_page_requires_auth(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
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
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->assertSet('stats.old_tickets', 0)
            ->assertSet('stats.total_tickets', 0)
            ->assertSet('stats.total_activities', 0)
            ->assertSet('stats.total_notifications', 0);
    }

    public function test_tools_mount_reflects_old_tickets(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
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

    // ==================== Archive tickets (issue #929) ====================
    //
    // Archived == completed + `archived_at`. The writer never invents a new
    // `status` value, so every status reader keeps its meaning: the label
    // stays «پایان یافته», `?status=completed` still lists the row, the
    // daily-report open count excludes it, and the inbox row guards (which
    // hide actions for `completed`) hide them for archived rows too.
    // Archiving is one-way by design — there is no restore path.

    public function test_archive_tickets_sets_archived_at_and_keeps_completed_status(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
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

        $ticket = Ticket::where('ticket_code', 'TKT-001')->first();
        $this->assertSame('completed', $ticket->status);
        $this->assertNotNull($ticket->archived_at);
    }

    public function test_archive_tickets_is_idempotent(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
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

        $first = Ticket::where('ticket_code', 'TKT-001')->first()->archived_at;

        $this->travel(10)->minutes();

        Livewire::test('tools.tools')
            ->set('archiveDays', 30)
            ->call('archiveTickets');

        // A second run must not re-touch the row: same timestamp, still completed.
        $ticket = Ticket::where('ticket_code', 'TKT-001')->first();
        $this->assertSame('completed', $ticket->status);
        $this->assertTrue($ticket->archived_at->equalTo($first));
    }

    public function test_archive_tickets_skips_recent_completed(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
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
        $this->assertNull(Ticket::where('ticket_code', 'TKT-001')->first()->archived_at);
    }

    public function test_archive_tickets_validates_days_range(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->set('archiveDays', 3)
            ->call('archiveTickets')
            ->assertHasErrors(['archiveDays']);
    }

    public function test_tools_mount_excludes_archived_from_old_tickets(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $unit = $user->units()->first();

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'آرشیوشده',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
            'archived_at' => now()->subDay(),
        ]);

        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->assertSet('stats.old_tickets', 0)
            ->assertSet('stats.total_tickets', 1);
    }

    public function test_archived_ticket_keeps_completed_status_name(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $unit = $user->units()->first();

        $ticket = Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'آرشیوشده',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
            'archived_at' => now()->subDay(),
        ]);

        // The #929 regression: the label read «نامشخص» for archived rows.
        $this->assertSame('پایان یافته', $ticket->fresh()->status_name);
    }

    public function test_archived_ticket_listed_under_completed_api_filter(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $unit = $user->units()->first();

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'آرشیوشده',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
            'archived_at' => now()->subDay(),
        ]);

        $token = $user->createToken('test-token', ['tickets:read'])->plainTextToken;
        $user->givePermissionTo('view_all_tickets');

        // Still reachable as a completed ticket through the mobile API…
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/tickets?status=completed')
            ->assertOk()
            ->assertJsonFragment(['ticket_code' => 'TKT-001']);

        // …and `archived` is not a status value, so the filter refuses it.
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/tickets?status=archived')
            ->assertStatus(422);
    }

    public function test_archived_ticket_show_by_id_reports_completed_label(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $unit = $user->units()->first();

        $ticket = Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'آرشیوشده',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
            'archived_at' => now()->subDay(),
        ]);

        $token = $user->createToken('test-token', ['tickets:read'])->plainTextToken;
        $user->givePermissionTo('view_all_tickets');

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonFragment(['status' => 'completed', 'status_name' => 'پایان یافته']);
    }

    public function test_archived_ticket_excluded_from_daily_open_count(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $unit = $user->units()->first();

        Ticket::create([
            'ticket_code' => 'TKT-ARCHIVED',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'آرشیوشده',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
            'archived_at' => now()->subDay(),
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-OPEN',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'باز',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $this->artisan('reports:generate-daily')->assertSuccessful();

        $report = DailyReport::where('unit_id', $unit->id)->first();
        $this->assertNotNull($report);
        $this->assertSame(1, $report->payload['open_tickets']);
    }

    public function test_archived_ticket_hides_inbox_row_actions(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users', 'view_assigned_tickets', 'manage_unit_tickets']);
        $unit = $user->units()->first();

        $archived = Ticket::create([
            'ticket_code' => 'TKT-ARCHIVED',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'آرشیوشده',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
            'archived_at' => now()->subDay(),
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-OPEN',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'باز',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $this->actingAs($user);

        // The live row still offers accept… (proves the row renders actions at all)
        // …while the archived row — still `completed` — offers none of the
        // dead accept / reject / complete actions (the #929 regression).
        Livewire::test('tickets.inbox')
            ->set('statusFilter', 'all')
            ->assertSee('acceptTicket(', false)
            ->assertDontSee("acceptTicket({$archived->id})", false)
            ->assertDontSee("rejectTicket({$archived->id})", false)
            ->assertDontSee("openCompletionModal({$archived->id})", false);
    }

    // ==================== Clean activities ====================

    public function test_clean_activities_deletes_old_logs(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
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
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
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
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->set('activityDays', 5)
            ->call('cleanActivities')
            ->assertHasErrors(['activityDays']);
    }

    public function test_clean_notifications_validates_days_range(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->set('notificationDays', 100)
            ->call('cleanNotifications')
            ->assertHasErrors(['notificationDays']);
    }
}
