<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class TicketsInboxLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Create a destination unit capable of receiving tickets.
     */
    protected function createTargetUnit(string $name = 'واحد مقصد'): Unit
    {
        return Unit::create([
            'name' => $name,
            'is_active' => true,
            'can_receive_tickets' => true,
        ]);
    }

    protected function createTicket(array $overrides = []): Ticket
    {
        $unit = $overrides['unit'] ?? Unit::first();
        $user = $overrides['user'] ?? User::first();

        return Ticket::create(array_merge([
            'ticket_code' => 'T'.fake()->unique()->numerify('######'),
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت تست',
            'content' => 'محتوای تست',
            'priority' => 'normal',
            'status' => 'created',
        ], $overrides));
    }

    // =====================================================================
    // Auth / page load
    // =====================================================================

    public function test_guest_302(): void
    {
        $this->get('/tickets/inbox')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $this->get('/tickets/inbox')->assertStatus(403);
    }

    public function test_authorized_loads_200(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        Livewire::test('tickets.inbox')
            ->assertStatus(200)
            ->assertSee('صندوق تیکت‌های پشتیبانی');
    }

    // =====================================================================
    // S1 — empty / header / view modes
    // =====================================================================

    public function test_view_modes(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        // Empty state when no tickets
        Livewire::test('tickets.inbox')
            ->assertStatus(200)
            ->assertSee('صندوق تیکت‌های پشتیبانی');

        // Sent mode via updateFilter
        Livewire::test('tickets.inbox')
            ->call('updateFilter', 'sent', 'pending')
            ->assertSet('viewMode', 'sent')
            ->assertSet('statusFilter', 'pending');

        // Received mode via updateFilter
        Livewire::test('tickets.inbox')
            ->call('updateFilter', 'received', 'pending')
            ->assertSet('viewMode', 'received')
            ->assertSet('statusFilter', 'pending');

        // switchView resets statusFilter to pending
        Livewire::test('tickets.inbox')
            ->set('statusFilter', 'completed')
            ->call('switchView', 'sent')
            ->assertSet('viewMode', 'sent')
            ->assertSet('statusFilter', 'pending');
    }

    // =====================================================================
    // S2 — received vs sent scope
    // =====================================================================

    public function test_received_scope_filters_by_accessible_units(): void
    {
        $result = $this->createUserWithUnit(['view_assigned_tickets']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        // Accessible ticket in user's unit
        $inScope = $this->createTicket([
            'unit' => $unit,
            'user' => $user,
            'subject' => 'تیکت در دسترس',
        ]);

        // Out-of-scope ticket in a brand new unit
        $otherUnit = Unit::create(['name' => 'واحد دیگر']);
        $otherTicket = $this->createTicket([
            'unit' => $otherUnit,
            'user' => $user,
            'subject' => 'تیکت خارج از دسترس',
        ]);

        Livewire::test('tickets.inbox')
            ->assertSee('تیکت در دسترس')
            ->assertDontSee('تیکت خارج از دسترس');
    }

    public function test_sent_scope_shows_user_own_tickets(): void
    {
        $result = $this->createUserWithUnit(['view_assigned_tickets']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        $ownTicket = $this->createTicket([
            'unit' => $unit,
            'user' => $user,
            'subject' => 'تیکت خودم',
        ]);

        // Another user creating a ticket in the same unit
        ['user' => $user2] = $this->createUserWithUnit(['view_assigned_tickets']);
        $otherTicket = $this->createTicket([
            'unit' => $unit,
            'user' => $user2,
            'subject' => 'تیکت دیگران',
        ]);

        Livewire::test('tickets.inbox')
            ->call('updateFilter', 'sent', 'all')
            ->assertSee('تیکت خودم')
            ->assertDontSee('تیکت دیگران');
    }

    // =====================================================================
    // S3 — status filters
    // =====================================================================

    public function test_status_filters(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $created = $this->createTicket(['subject' => 'تیکت ایجاد شده', 'status' => 'created']);
        $accepted = $this->createTicket(['subject' => 'تیکت پذیرفته', 'status' => 'accepted']);
        $completed = $this->createTicket(['subject' => 'تیکت تکمیل', 'status' => 'completed']);
        $forwarded = $this->createTicket(['subject' => 'تیکت ارجاع', 'status' => 'forwarded']);
        $rejected = $this->createTicket(['subject' => 'تیکت رد', 'status' => 'rejected']);

        // pending = created + forwarded
        Livewire::test('tickets.inbox')
            ->set('statusFilter', 'pending')
            ->assertSee('تیکت ایجاد شده')
            ->assertSee('تیکت ارجاع')
            ->assertDontSee('تیکت پذیرفته')
            ->assertDontSee('تیکت تکمیل')
            ->assertDontSee('تیکت رد');

        // all
        Livewire::test('tickets.inbox')
            ->set('statusFilter', 'all')
            ->assertSee('تیکت ایجاد شده')
            ->assertSee('تیکت پذیرفته')
            ->assertSee('تیکت تکمیل')
            ->assertSee('تیکت ارجاع')
            ->assertSee('تیکت رد');

        // completed
        Livewire::test('tickets.inbox')
            ->set('statusFilter', 'completed')
            ->assertSee('تیکت تکمیل')
            ->assertDontSee('تیکت ایجاد شده');
    }

    // =====================================================================
    // S4 — search filter
    // =====================================================================

    public function test_search(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['subject' => 'مشکل شبکه', 'content' => 'توضیح شبکه']);
        $this->createTicket(['subject' => 'مشکل چاپگر', 'content' => 'پرینتر خراب']);
        $this->createTicket(['ticket_code' => 'T-ABC-001', 'subject' => 'کد خاص', 'content' => 'body']);

        Livewire::test('tickets.inbox')
            ->set('search', 'شبکه')
            ->assertSee('مشکل شبکه')
            ->assertDontSee('مشکل چاپگر');

        Livewire::test('tickets.inbox')
            ->set('search', 'T-ABC')
            ->assertSee('کد خاص')
            ->assertDontSee('مشکل شبکه');
    }

    // =====================================================================
    // S5 — Jalali date filter
    // =====================================================================

    public function test_jalali_dates(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        // created_at in past
        $old = $this->createTicket(['subject' => 'تیکت قدیمی تست داتا']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $new = $this->createTicket(['subject' => 'تیکت جدید تست داتا']);
        $new->forceFill(['created_at' => now()])->save();

        $from = Jalalian::fromCarbon(now()->subDays(15))->format('Y/m/d');
        $to = Jalalian::fromCarbon(now()->addDay())->format('Y/m/d');

        Livewire::test('tickets.inbox')
            ->set('dateFrom', $from)
            ->set('dateTo', $to)
            ->assertSee('تیکت جدید تست داتا')
            ->assertDontSee('تیکت قدیمی تست داتا');
    }

    // =====================================================================
    // S6 — updateFilter / switchView reset page
    // =====================================================================

    public function test_update_filter_resets_state(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        Livewire::test('tickets.inbox')
            ->set('statusFilter', 'completed')
            ->set('viewMode', 'sent')
            ->call('updateFilter', 'received', 'pending')
            ->assertSet('viewMode', 'received')
            ->assertSet('statusFilter', 'pending');

        Livewire::test('tickets.inbox')
            ->set('viewMode', 'sent')
            ->call('switchView', 'received')
            ->assertSet('viewMode', 'received')
            ->assertSet('statusFilter', 'pending');
    }

    // =====================================================================
    // S7 — showTicket scope
    // =====================================================================

    public function test_show_ticket_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['subject' => 'تیکت من']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->assertSet('showModal', true)
            ->assertSet('showingTicket.id', $ticket->id)
            ->assertSee('تیکت من')
            ->call('closeDetail')
            ->assertSet('showModal', false)
            ->assertSet('showingTicket', null);
    }

    public function test_show_ticket_out_of_scope_404(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $otherUnit = Unit::create(['name' => 'واحد دیگر']);
        $other = $this->createTicket(['unit' => $otherUnit, 'subject' => 'خارج از دسترس']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $other->id)
            ->assertSet('showModal', false);
    }

    // =====================================================================
    // S8 — acceptTicket
    // =====================================================================

    public function test_accept_ticket(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $ticket->id)
            ->assertDispatched('swal');

        $ticket->refresh();
        $this->assertSame('accepted', $ticket->status);
        $this->assertSame($user->id, $ticket->current_assignee_id);
        $this->assertNotNull($ticket->accepted_at);
        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'accepted',
        ]);
    }

    public function test_accept_ticket_already_accepted_warning(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);

        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $ticket->id)
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'تیکت قبلاً پذیرفته شده است';
            });

        $ticket->refresh();
        $this->assertSame('accepted', $ticket->status);
    }

    public function test_accept_ticket_out_of_scope_404(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $otherUnit = Unit::create(['name' => 'واحد دیگر']);
        $other = $this->createTicket(['unit' => $otherUnit, 'status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $other->id)
            ->assertSet('showModal', false);
    }

    // =====================================================================
    // S9 — rejectTicket
    // =====================================================================

    public function test_reject_ticket(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('rejectTicket', $ticket->id)
            ->assertDispatched('swal');

        $ticket->refresh();
        $this->assertSame('rejected', $ticket->status);
        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'rejected',
        ]);
    }

    // =====================================================================
    // S10 — forward validates targetUnitId
    // =====================================================================

    public function test_forward_validates_target_unit_id(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->call('forward')
            ->assertHasErrors(['targetUnitId' => 'required']);
    }

    public function test_forward_updates_unit_and_creates_activity(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit();
        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->set('targetUnitName', $target->name)
            ->set('forwardNote', 'لطفا بررسی شود')
            ->call('forward')
            ->assertDispatched('swal');

        $ticket->refresh();
        $this->assertSame('forwarded', $ticket->status);
        $this->assertSame($target->id, $ticket->unit_id);
        $this->assertNull($ticket->current_assignee_id);
        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'forwarded',
        ]);
    }

    // =====================================================================
    // S11 — bulk actions
    // =====================================================================

    public function test_bulk_actions(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $t1 = $this->createTicket(['status' => 'created', 'subject' => 'تیکت یک']);
        $t2 = $this->createTicket(['status' => 'forwarded', 'subject' => 'تیکت دو']);
        $t3 = $this->createTicket(['status' => 'accepted', 'subject' => 'تیکت سه']);

        // toggleTicketSelection
        Livewire::test('tickets.inbox')
            ->call('toggleTicketSelection', $t1->id)
            ->call('toggleTicketSelection', $t2->id)
            ->assertSet('selectedTickets', [$t1->id, $t2->id])
            ->call('toggleTicketSelection', $t1->id)
            ->assertSet('selectedTickets', [$t2->id]);

        // openBulkModal with no selection => warning
        Livewire::test('tickets.inbox')
            ->call('openBulkModal', 'complete')
            ->assertSet('showBulkModal', false)
            ->assertDispatched('swal');

        // executeBulkAction: complete
        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$t1->id, $t2->id])
            ->set('bulkAction', 'complete')
            ->set('bulkNote', 'پایان دسته‌ای')
            ->call('executeBulkAction')
            ->assertDispatched('swal');

        $t1->refresh();
        $t2->refresh();
        $this->assertSame('completed', $t1->status);
        $this->assertSame('completed', $t2->status);
        $this->assertNotNull($t1->completed_at);
        $this->assertNotNull($t2->completed_at);

        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $t1->id,
            'action' => 'completed',
        ]);
        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $t2->id,
            'action' => 'completed',
        ]);

        // toggleSelectAll
        Livewire::test('tickets.inbox')
            ->call('toggleSelectAll')
            ->assertSet('selectAll', true)
            ->call('toggleSelectAll')
            ->assertSet('selectAll', false)
            ->assertSet('selectedTickets', []);

        // bulk forward
        $t4 = $this->createTicket(['status' => 'created', 'subject' => 'تیکت چهار']);

        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$t4->id])
            ->set('bulkAction', 'forward')
            ->call('executeBulkAction')
            ->assertDispatched('swal');

        $t4->refresh();
        $this->assertSame('forwarded', $t4->status);
        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $t4->id,
            'action' => 'forwarded',
        ]);
    }

    public function test_execute_bulk_action_only_completed_warning(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $t = $this->createTicket(['status' => 'completed']);
        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$t->id])
            ->set('bulkAction', 'complete')
            ->call('executeBulkAction')
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'هیچ تیکت قابل پردازشی یافت نشد';
            });

        $t->refresh();
        $this->assertSame('completed', $t->status);
    }

    // =====================================================================
    // S12 — submitAction
    // =====================================================================

    public function test_submit_action_completes_ticket(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->assertSet('isCompletionModalOpen', true)
            ->set('completionNote', 'گزارش نهایی تست')
            ->call('submitAction')
            ->assertDispatched('swal');

        $ticket->refresh();
        $this->assertSame('completed', $ticket->status);
        $this->assertNotNull($ticket->completed_at);
        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'completed',
        ]);
    }

    public function test_submit_action_validates_completion_note(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('completionNote', 'a') // too short
            ->call('submitAction')
            ->assertHasErrors(['completionNote']);

        $ticket->refresh();
        $this->assertSame('accepted', $ticket->status);
    }

    public function test_submit_action_forward_with_target_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit();
        $ticket = $this->createTicket(['status' => 'accepted']);

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->set('targetUnitName', $target->name)
            ->set('completionNote', 'ارسال به مقصد')
            ->call('submitAction')
            ->assertDispatched('swal');

        $ticket->refresh();
        $this->assertSame('forwarded', $ticket->status);
        $this->assertSame($target->id, $ticket->unit_id);
        $this->assertNull($ticket->current_assignee_id);
        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'forwarded',
        ]);
    }

    public function test_submit_action_rejects_non_accepted_without_target(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('completionNote', 'توضیح کافی برای رد شدن')
            ->call('submitAction')
            ->assertHasErrors(['completionNote']);

        $ticket->refresh();
        $this->assertSame('created', $ticket->status);
    }

    public function test_submit_action_completes_todo_when_all_tickets_done(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $todo = Todo::create([
            'title' => 'وظیفه تست',
            'start_at' => now(),
            'is_completed' => false,
        ]);

        $t1 = $this->createTicket(['status' => 'accepted', 'task_id' => $todo->id]);
        $t2 = $this->createTicket(['status' => 'completed', 'task_id' => $todo->id]);

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $t1->id)
            ->set('completionNote', 'پایان کار')
            ->call('submitAction');

        $todo->refresh();
        $this->assertTrue($todo->is_completed);
    }

    // =====================================================================
    // E1 — invalid Jalali -> error
    // =====================================================================

    public function test_invalid_jalali_date_filter_handled(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['subject' => 'تیکت معمولی']);

        // Invalid date string => component should not fatal and just render
        Livewire::test('tickets.inbox')
            ->set('dateFrom', 'not-a-date')
            ->set('dateTo', 'also-not-a-date')
            ->assertStatus(200);
    }

    // =====================================================================
    // E3 — executeBulkAction unknown action ignored
    // =====================================================================

    public function test_execute_bulk_action_unknown_action(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $t = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$t->id])
            ->set('bulkAction', 'unknown_action')
            ->call('executeBulkAction')
            ->assertDispatched('swal', function ($name, $params) {
                return str_contains((string) ($params[0]['title'] ?? ''), 'تیکت');
            });

        $t->refresh();
        $this->assertSame('created', $t->status);
    }

    // =====================================================================
    // E4 — forward without targetUnitId -> validation error
    // =====================================================================

    public function test_forward_without_target_unit_id_validation_error(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('forwardNote', 'یادداشت')
            ->call('forward')
            ->assertHasErrors(['targetUnitId']);
    }

    // =====================================================================
    // E5 — updated* lifecycle hooks reset pagination
    // =====================================================================

    public function test_updated_search_resets_state(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['subject' => 'تیکت الف']);
        $this->createTicket(['subject' => 'تیکت ب']);

        Livewire::test('tickets.inbox')
            ->set('search', 'الف')
            ->assertSee('تیکت الف')
            ->assertDontSee('تیکت ب');
    }

    public function test_updated_status_filter_resets_page(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['status' => 'completed', 'subject' => 'تکمیل شده']);
        $this->createTicket(['status' => 'created', 'subject' => 'ایجاد شده']);

        Livewire::test('tickets.inbox')
            ->set('statusFilter', 'completed')
            ->assertSee('تکمیل شده')
            ->assertDontSee('ایجاد شده');
    }

    public function test_updated_view_mode_resets_state(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['status' => 'created', 'subject' => 'معمولی']);

        Livewire::test('tickets.inbox')
            ->set('viewMode', 'sent')
            ->assertSet('viewMode', 'sent');
    }

    public function test_updated_unit_search_loads_units(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        Livewire::test('tickets.inbox')
            ->set('unitSearch', 'مقصد')
            ->assertSet('unitSearch', 'مقصد');
    }

    public function test_updated_date_filters_resets_state(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['subject' => 'تیکت تاریخ']);

        Livewire::test('tickets.inbox')
            ->set('dateFrom', Jalalian::fromCarbon(now()->subDays(7))->format('Y/m/d'))
            ->set('dateTo', Jalalian::fromCarbon(now()->addDay())->format('Y/m/d'))
            ->assertSee('تیکت تاریخ');
    }

    public function test_set_tab_resets_page(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        Livewire::test('tickets.inbox')
            ->call('setTab', 'completed')
            ->assertSet('currentTab', 'completed');
    }

    public function test_select_target_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit();

        Livewire::test('tickets.inbox')
            ->call('selectTargetUnit', $target->id, $target->name)
            ->assertSet('targetUnitId', $target->id)
            ->assertSet('targetUnitName', $target->name)
            ->assertSet('unitSearch', '');
    }

    public function test_close_all_modals(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        Livewire::test('tickets.inbox')
            ->set('isCompletionModalOpen', true)
            ->set('completionNote', 'something')
            ->set('selectedTickets', [1, 2])
            ->call('closeAllModals')
            ->assertSet('isCompletionModalOpen', false)
            ->assertSet('completionNote', '')
            ->assertSet('selectedTickets', []);
    }

    public function test_open_comments_for_dispatches(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $t = $this->createTicket();

        Livewire::test('tickets.inbox')
            ->call('openCommentsFor', $t->id)
            ->assertDispatched('openComments', ticketId: (int) $t->id);
    }

    // =====================================================================
    // E6 — file upload via submitAction with Storage::fake
    // =====================================================================

    public function test_file_uploads(): void
    {
        Storage::fake('public');

        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('completionNote', 'گزارش تستی')
            ->set('completionFiles', [$file])
            ->call('submitAction')
            ->assertDispatched('swal');

        $ticket->refresh();
        $this->assertSame('completed', $ticket->status);
        $this->assertGreaterThan(0, Attachment::where('ticket_id', $ticket->id)->count());
    }

    public function test_remove_file(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $file1 = UploadedFile::fake()->create('a.pdf', 50);
        $file2 = UploadedFile::fake()->create('b.pdf', 50);

        Livewire::test('tickets.inbox')
            ->set('completionFiles', [$file1, $file2])
            ->assertCount('completionFiles', 2)
            ->call('removeFile', 0)
            ->assertCount('completionFiles', 1);
    }
}
