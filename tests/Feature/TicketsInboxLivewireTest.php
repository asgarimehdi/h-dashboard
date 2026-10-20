<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Person;
use App\Models\TaskActivity;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class TicketsInboxLivewireTest extends TestCase
{
    use InteractsWithApiTokens;
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

    /**
     * A plain user attached to $unit — `createUserWithUnit()` creates its own
     * unit, so a recipient on a *specific* unit needs this.
     */
    protected function createUserOnUnit(Unit $unit): User
    {
        $person = Person::factory()->create(['u_id' => $unit->id]);
        $user = User::factory()->create(['n_code' => $person->n_code]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
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

    /**
     * #1165 pins the folded-expression indexes; this pins that the extra index
     * did not change what search finds. A subject stored with a ZWNJ must still
     * be reachable by a hyphen/space-separated query.
     */
    public function test_search_folds_zwnj_in_subject(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $stored = "حرفه\u{200C}ای شبکه";

        $this->createTicket(['subject' => $stored]);
        $this->createTicket(['subject' => 'مشکل چاپگر']);

        // The stored spelling carries a ZWNJ; the operator types a space.
        Livewire::test('tickets.inbox')
            ->set('search', 'حرفه ای')
            ->assertSee($stored)
            ->assertDontSee('مشکل چاپگر');
    }

    /**
     * Persian digits stored in `content` must be found by Latin digits. The
     * ticket code is digit-free so only the folded `content` column can match.
     */
    public function test_search_folds_persian_digits_in_content(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['ticket_code' => 'TKT-7777', 'subject' => 'موضوع الف', 'content' => 'کد پیگیری ۴۵ است']);
        $this->createTicket(['ticket_code' => 'TKT-8888', 'subject' => 'موضوع ب', 'content' => 'محتوا بدون عدد']);

        Livewire::test('tickets.inbox')
            ->set('search', '45')
            ->assertSee('TKT-7777')
            ->assertDontSee('TKT-8888');
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
    // #847 — the ticket was scoped, its `task` relation was not
    //
    // A ticket linked to an out-of-scope todo leaked that todo's title,
    // dates and status into this modal. Legacy rows predating #847's
    // validation still exist, so the read side is gated on its own.
    // =====================================================================

    public function test_show_ticket_hides_a_task_outside_the_viewers_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $victimUnit = Unit::create(['name' => 'واحد قربانی']);
        $secretTodo = Todo::create([
            'title' => 'عنوان محرمانه واحد دیگر',
            'start_at' => now(),
            'end_at' => now()->addWeek(),
            'is_completed' => false,
            'unit_id' => $victimUnit->id,
        ]);

        $ticket = $this->createTicket(['subject' => 'تیکت با وظیفه بیگانه', 'task_id' => $secretTodo->id]);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->assertSet('showModal', true)
            ->assertSet('showingTicket.task', null)
            ->assertSee('تیکت با وظیفه بیگانه')
            ->assertDontSee('عنوان محرمانه واحد دیگر')
            // The block's own label, not the bare phrase: «وظیفه مرتبط»
            // also appears in a PHP comment that ships inside the rendered
            // HTML, so a plain assertDontSee would fail for the wrong reason.
            ->assertDontSee('وظیفه مرتبط:');
    }

    public function test_show_ticket_still_renders_a_task_inside_the_viewers_scope(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $myUnit = $user->units()->first();
        $todo = Todo::create([
            'title' => 'وظیفه خودم در دسترس',
            'start_at' => now(),
            'end_at' => now()->addWeek(),
            'is_completed' => false,
            'unit_id' => $myUnit->id,
        ]);

        $ticket = $this->createTicket(['subject' => 'تیکت با وظیفه مجاز', 'task_id' => $todo->id]);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->assertSet('showModal', true)
            ->assertSet('showingTicket.task.id', $todo->id)
            ->assertSee('وظیفه خودم در دسترس')
            ->assertSee('وظیفه مرتبط:');
    }

    public function test_destination_viewer_sees_the_ticket_but_not_the_creators_task(): void
    {
        // The deliberate consequence of the #847 decision: the task block is
        // gated on the VIEWER's scope, so a recipient who cannot reach the
        // creator's todo sees the ticket with no task details. The ticket
        // itself is never hidden. Pinned so this stays a stated contract —
        // the alternative (gating on the ticket's own unit as well) was
        // considered and rejected, since it would re-expose the creator's
        // todo to the destination unit.
        ['user' => $creator, 'unit' => $creatorUnit] = $this->createUserWithUnit(['create_ticket']);

        $destination = Unit::create(['name' => 'واحد گیرنده', 'is_active' => true, 'can_receive_tickets' => true]);
        $creatorTodo = Todo::create([
            'title' => 'وظیفه سازنده',
            'start_at' => now(),
            'end_at' => now()->addWeek(),
            'is_completed' => false,
            'unit_id' => $creatorUnit->id,
        ]);

        $ticket = $this->createTicket([
            // `createTicket()` reads `unit`/`user` for its defaults but also
            // merges them into the create array, where they are NOT fillable
            // and are silently dropped. The real columns must be set, or the
            // ticket lands in `Unit::first()` (the creator's unit) and the
            // viewer cannot see it at all.
            'unit_id' => $destination->id,
            'user_id' => $creator->id,
            'subject' => 'تیکت بین دو واحد',
            'task_id' => $creatorTodo->id,
        ]);

        // The recipient needs `view_assigned_tickets` or the inbox page itself is
        // gated off and `showTicket` never runs — the assertion would then be
        // passing for the wrong reason.
        $viewer = $this->createUserOnUnit($destination);
        $viewer->givePermissionTo('view_assigned_tickets');

        $this->actingAs($viewer);

        // `accessibleUnitIds()` reads `current_unit_id` first and only falls
        // back to the pivot when the session has none. Without this the viewer
        // resolves an empty scope and `showTicket` returns early — the modal
        // would never open and the assertion below would fail for the wrong
        // reason (an unseeded context, not the task gate).
        Session::put('current_unit_id', $destination->id);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->assertSet('showModal', true)
            ->assertSet('showingTicket.id', $ticket->id)
            ->assertSee('تیکت بین دو واحد')
            ->assertDontSee('وظیفه سازنده');
    }

    // =====================================================================
    // S8 — acceptTicket
    // =====================================================================

    public function test_accept_ticket(): void
    {
        // Issue #1029: accept now needs create_ticket (like the API) and the
        // caller must be the ticket's current assignee (#529).
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'create_ticket']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created', 'current_assignee_id' => $user->id]);

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
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'create_ticket']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted', 'current_assignee_id' => $user->id]);

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
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'create_ticket']);
        $this->actingAs($user);

        $otherUnit = Unit::create(['name' => 'واحد دیگر']);
        $other = $this->createTicket(['unit' => $otherUnit, 'status' => 'created', 'current_assignee_id' => $user->id]);

        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $other->id)
            ->assertSet('showModal', false);

        $other->refresh();
        $this->assertSame('created', $other->status);
    }

    public function test_accept_ticket_without_create_ticket_is_forbidden(): void
    {
        // Issue #1029: accept is a create_ticket mutation, mirroring the API
        // (routes/api.php:153-154). The /inbox route only requires
        // view_assigned_tickets, so a read-only inbox member must be stopped
        // inside the component.
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created', 'current_assignee_id' => $user->id]);

        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $ticket->id)
            ->assertForbidden();

        $ticket->refresh();
        $this->assertSame('created', $ticket->status);
        $this->assertSame($user->id, $ticket->current_assignee_id);
        $this->assertNull($ticket->accepted_at);
        $this->assertDatabaseMissing('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'accepted',
        ]);
    }

    public function test_accept_ticket_referred_to_another_expert_is_rejected(): void
    {
        // Issue #1029 / #529: two experts in one unit must not be able to seize
        // each other's tickets. Before the fix, any unit member could accept a
        // ticket referred to a teammate and overwrite current_assignee_id.
        ['user' => $expert, 'unit' => $unit] = $this->createUserWithUnit(['view_assigned_tickets', 'create_ticket']);

        $teammate = $this->createUserOnUnit($unit);
        $teammate->givePermissionTo(['view_assigned_tickets', 'create_ticket']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $ticket = $this->createTicket([
            'unit' => $unit,
            'status' => 'forwarded',
            'current_assignee_id' => $teammate->id,
        ]);

        $this->actingAs($expert);

        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $ticket->id)
            ->assertDispatched('swal', function ($name, $params) {
                return ($params[0]['title'] ?? '') === 'فقط کارشناس ارجاع‌شده می‌تواند این تیکت را بپذیرد.';
            });

        $ticket->refresh();
        $this->assertSame('forwarded', $ticket->status);
        $this->assertSame($teammate->id, $ticket->current_assignee_id);
        $this->assertDatabaseMissing('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'accepted',
        ]);
    }

    public function test_accept_non_assignee_is_rejected_on_web_and_api(): void
    {
        // Issue #1029: web and API must reach the same verdict for a
        // non-assignee so the two surfaces cannot drift apart again.
        ['user' => $assignee, 'unit' => $unit] = $this->createUserWithUnit(['view_assigned_tickets', 'create_ticket']);

        $outsider = $this->createUserOnUnit($unit);
        $outsider->givePermissionTo(['view_assigned_tickets', 'create_ticket']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $ticket = $this->createTicket([
            'unit' => $unit,
            'status' => 'forwarded',
            'current_assignee_id' => $assignee->id,
        ]);

        $token = $this->createApiToken($outsider, ['tickets:read', 'tickets:write']);
        $this->apiPost("/api/tickets/{$ticket->id}/accept", [], $token)
            ->assertStatus(403)
            ->assertJson(['message' => 'Only the assigned user can accept this ticket.']);

        $this->actingAs($outsider);
        Livewire::test('tickets.inbox')->call('acceptTicket', $ticket->id);

        $ticket->refresh();
        $this->assertSame('forwarded', $ticket->status);
        $this->assertSame($assignee->id, $ticket->current_assignee_id);
    }

    // =====================================================================
    // S9 — rejectTicket
    // =====================================================================

    public function test_reject_ticket(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->call('forward')
            ->assertHasErrors(['targetUnitId' => 'required']);
    }

    public function test_forward_updates_unit_and_creates_activity(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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
    // Issue #818 — forward/submitAction gated on manage_unit_tickets
    // =====================================================================

    public function test_forward_without_permission_is_forbidden(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit();
        $ticket = $this->createTicket(['status' => 'created']);
        $originalUnitId = $ticket->unit_id;

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->call('forward')
            ->assertForbidden();

        $ticket->refresh();
        $this->assertSame($originalUnitId, $ticket->unit_id);
        $this->assertSame('created', $ticket->status);
        $this->assertDatabaseMissing('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'forwarded',
        ]);
    }

    public function test_submit_action_forward_without_permission_is_forbidden(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit();
        $ticket = $this->createTicket(['status' => 'accepted']);
        $originalUnitId = $ticket->unit_id;

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->set('completionNote', 'ارسال به مقصد')
            ->call('submitAction')
            ->assertForbidden();

        $ticket->refresh();
        $this->assertSame($originalUnitId, $ticket->unit_id);
        $this->assertSame('accepted', $ticket->status);
        $this->assertDatabaseMissing('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'forwarded',
        ]);
    }

    public function test_submit_action_completes_without_permission_is_forbidden(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('completionNote', 'گزارش نهایی تست')
            ->call('submitAction')
            ->assertForbidden();

        $ticket->refresh();
        $this->assertSame('accepted', $ticket->status);
        $this->assertNull($ticket->completed_at);
    }

    public function test_reject_ticket_without_permission_is_forbidden(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('rejectTicket', $ticket->id)
            ->assertForbidden();

        $ticket->refresh();
        $this->assertSame('created', $ticket->status);
    }

    public function test_bulk_complete_without_permission_is_forbidden(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);

        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$ticket->id])
            ->set('bulkAction', 'complete')
            ->call('executeBulkAction')
            ->assertForbidden();

        $ticket->refresh();
        $this->assertSame('accepted', $ticket->status);
    }

    public function test_forward_to_unit_that_cannot_receive_tickets_is_rejected(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $target = Unit::create([
            'name' => 'واحد غیرقابل دریافت',
            'is_active' => true,
            'can_receive_tickets' => false,
        ]);
        $ticket = $this->createTicket(['status' => 'created']);
        $originalUnitId = $ticket->unit_id;

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->call('forward')
            ->assertHasErrors(['targetUnitId']);

        $ticket->refresh();
        $this->assertSame($originalUnitId, $ticket->unit_id);
    }

    public function test_submit_action_forward_to_unit_that_cannot_receive_tickets_is_rejected(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $target = Unit::create([
            'name' => 'واحد غیرقابل دریافت دو',
            'is_active' => true,
            'can_receive_tickets' => false,
        ]);
        $ticket = $this->createTicket(['status' => 'accepted']);
        $originalUnitId = $ticket->unit_id;

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->set('completionNote', 'ارسال به مقصد')
            ->call('submitAction')
            ->assertHasErrors(['targetUnitId']);

        $ticket->refresh();
        $this->assertSame($originalUnitId, $ticket->unit_id);
        $this->assertSame('accepted', $ticket->status);
    }

    public function test_forward_to_inactive_unit_is_rejected(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $target = Unit::create([
            'name' => 'واحد غیرفعال',
            'is_active' => false,
            'can_receive_tickets' => true,
        ]);
        $ticket = $this->createTicket(['status' => 'created']);
        $originalUnitId = $ticket->unit_id;

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->call('forward')
            ->assertHasErrors(['targetUnitId']);

        $ticket->refresh();
        $this->assertSame($originalUnitId, $ticket->unit_id);
    }

    public function test_forward_records_target_unit_in_activity(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit('واحد ثبت فعالیت');
        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->set('forwardNote', 'لطفا بررسی شود')
            ->call('forward');

        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'forwarded',
            'to_unit_id' => $target->id,
        ]);
    }

    public function test_forward_ignores_client_supplied_target_unit_name(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit('واحد واقعی');
        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->set('targetUnitName', 'واحد جعلی مهاجم')
            ->call('forward');

        $activity = TaskActivity::where('ticket_id', $ticket->id)
            ->where('action', 'forwarded')
            ->firstOrFail();

        $this->assertStringContainsString($target->name, $activity->description);
        $this->assertStringNotContainsString('جعلی', $activity->description);
    }

    public function test_select_target_unit_resolves_name_server_side(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit('واحد معتبر مقصد');

        Livewire::test('tickets.inbox')
            ->call('selectTargetUnit', $target->id, 'نام جعلی که کلاینت فرستاد')
            ->assertSet('targetUnitId', $target->id)
            ->assertSet('targetUnitName', 'واحد معتبر مقصد');
    }

    /**
     * #818 (correction 4) / #1055 step 2: the destination unit is display state
     * the picker writes through `selectTargetUnit()`, never URL state. A crafted
     * `?targetUnitId=` link once pre-selected a unit the caller never picked, and
     * `targetUnitName` travelled into the audit trail verbatim. Those properties
     * now live in `TicketsInboxFlow`, so this pins that they did not carry a
     * `#[Url]` binding with them.
     */
    public function test_target_unit_is_not_settable_from_the_query_string(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit();

        Livewire::withQueryParams([
            'targetUnitId' => $target->id,
            'targetUnitName' => 'واحد جعلی',
        ])->test('tickets.inbox')
            ->assertSet('targetUnitId', null)
            ->assertSet('targetUnitName', '');

        // Control: the `#[Url]` filter state *does* read the query string, so the
        // assertions above are about the flow properties, not about
        // withQueryParams() being a no-op.
        Livewire::withQueryParams(['statusFilter' => 'completed'])
            ->test('tickets.inbox')
            ->assertSet('statusFilter', 'completed');
    }

    public function test_submit_action_target_unit_id_is_validated(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);
        $originalUnitId = $ticket->unit_id;

        Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticket->id)
            ->set('targetUnitId', 99999)
            ->set('completionNote', 'ارسال به واحد نامعتبر')
            ->call('submitAction')
            ->assertHasErrors(['targetUnitId']);

        $ticket->refresh();
        $this->assertSame($originalUnitId, $ticket->unit_id);
        $this->assertSame('accepted', $ticket->status);
    }

    public function test_accept_ticket_still_allowed_for_expert_without_manage_unit_tickets(): void
    {
        // #1029: the expert role holds create_ticket (not manage_unit_tickets)
        // and may accept a ticket referred to it — the gate must stay
        // create_ticket, or the role loses accept entirely.
        ['user' => $user] = $this->createUserWithUnit(['create_ticket', 'view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'forwarded', 'current_assignee_id' => $user->id]);

        Livewire::test('tickets.inbox')
            ->call('acceptTicket', $ticket->id)
            ->assertDispatched('swal');

        $ticket->refresh();
        $this->assertSame('accepted', $ticket->status);
        $this->assertSame($user->id, $ticket->current_assignee_id);
    }

    public function test_forward_notifies_target_unit_like_submit_action_does(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $target = $this->createTargetUnit('واحد اعلان تست');
        $recipient = $this->createUserOnUnit($target);

        $ticket = $this->createTicket(['status' => 'created']);

        Livewire::test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->set('targetUnitId', $target->id)
            ->set('forwardNote', 'لطفا بررسی شود')
            ->call('forward');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $recipient->id,
            'type' => 'ticket_forwarded',
            'title' => 'تیکت ارجاع شد',
        ]);
    }

    public function test_forward_controls_hidden_without_manage_unit_tickets(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);

        // The completion modal itself is always in the DOM (x-modal hides it with
        // CSS), so the contract to assert is the bulk bar and the row button that
        // OPEN it — submitAction() is what actually refuses, server-side.
        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$ticket->id])
            ->assertDontSee('تکمیل دسته‌ای')
            ->assertDontSee('ارجاع دسته‌ای')
            ->assertSee('لغو انتخاب');

        Livewire::test('tickets.inbox')
            ->assertDontSee('تکمیل دسته‌ای');

        $ticket->refresh();
        $this->assertSame('accepted', $ticket->status);
    }

    public function test_forward_controls_visible_with_manage_unit_tickets(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $ticket = $this->createTicket(['status' => 'accepted']);

        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$ticket->id])
            ->assertSee('تکمیل دسته‌ای')
            ->assertSee('ارجاع دسته‌ای');

        $ticket->refresh();
        $this->assertSame('accepted', $ticket->status);
    }

    // =====================================================================
    // S11 — bulk actions
    // =====================================================================

    public function test_bulk_actions(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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

        // toggleSelectAll (#1040: the flag is the whole state — the ledger
        // `selectedTickets` must stay empty, the old code loaded EVERY open
        // ticket id into the public array and shipped it each roundtrip).
        Livewire::test('tickets.inbox')
            ->call('toggleSelectAll')
            ->assertSet('selectAll', true)
            ->assertSet('selectedTickets', [])
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

    // =====================================================================
    // #1040 — «انتخاب همه» is server-expanded against the page's ACTIVE
    // filter/search, and the snapshot ledger stays empty the whole time
    // =====================================================================

    /**
     * The core of the #1040 approved semantics: after `toggleSelectAll()` the
     * ledger is empty, `executeBulkAction` expands «همه» ON THE SERVER against
     * the active tab (pending = created|forwarded) and the active search, and
     * the effect is asserted on the DATABASE (rows changed, task activity
     * written) — not on component state (#936). A ticket hidden by the search
     * and a ticket outside the active tab must both stay untouched; the
     * pre-#1040 behaviour selected every reachable created/forwarded/accepted
     * ticket regardless of what the operator had filtered.
     */
    public function test_select_all_expands_on_the_server_against_the_active_filter(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        // In the active search AND in the pending tab.
        $inScope = $this->createTicket(['status' => 'created', 'subject' => 'دارای مارکر']);
        // Pending but hidden by the active search — «انتخاب همه» must respect it.
        $hiddenBySearch = $this->createTicket(['status' => 'created', 'subject' => 'بدون عبارت خاص']);
        // Found by the search but outside the active tab (pending).
        $outsideTab = $this->createTicket(['status' => 'accepted', 'subject' => 'دارای مارکر']);

        Livewire::test('tickets.inbox')
            ->set('search', 'دارای مارکر')
            ->call('toggleSelectAll')
            ->assertSet('selectAll', true)
            ->assertSet('selectedTickets', [])
            // The server-side count respects the SAME filter/search.
            ->assertSee('1 تیکت انتخاب شده')
            ->set('bulkAction', 'complete')
            ->call('executeBulkAction');

        $this->assertSame('completed', $inScope->fresh()->status);
        $this->assertSame('created', $hiddenBySearch->fresh()->status);
        $this->assertSame('accepted', $outsideTab->fresh()->status);

        $this->assertDatabaseHas('task_activities', ['ticket_id' => $inScope->id, 'action' => 'completed']);
        $this->assertDatabaseMissing('task_activities', ['ticket_id' => $hiddenBySearch->id, 'action' => 'completed']);
        $this->assertDatabaseMissing('task_activities', ['ticket_id' => $outsideTab->id, 'action' => 'completed']);
    }

    /**
     * The conflict order the expert plan pinned: a MANUAL pick → «انتخاب همه»
     * → un-picking one row. Select-all clears the manual ledger (the manual
     * picks are subsumed by «همه»), the subsequent un-pick becomes an
     * EXCLUSION, and the bulk action completes everything except that
     * excluded row — asserted on the database, not on component state (#936).
     */
    public function test_select_all_then_manually_unpicked_row_is_excluded(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $t1 = $this->createTicket(['status' => 'created', 'subject' => 'تیکت یک']);
        $t2 = $this->createTicket(['status' => 'created', 'subject' => 'تیکت دو']);
        $t3 = $this->createTicket(['status' => 'forwarded', 'subject' => 'تیکت سه']);

        Livewire::test('tickets.inbox')
            // ۱. a manual pick (ledger = inclusions)
            ->call('toggleTicketSelection', $t1->id)
            ->assertSet('selectedTickets', [$t1->id])
            // ۲. «انتخاب همه»: ledger cleared, the flag carries the rest
            ->call('toggleSelectAll')
            ->assertSet('selectAll', true)
            ->assertSet('selectedTickets', [])
            // ۳. un-pick ONE row on the checked table: the property is again
            // non-empty, but it now holds exceptions, not picks
            ->call('toggleTicketSelection', $t2->id)
            ->assertSet('selectedTickets', [$t2->id])
            ->set('bulkAction', 'complete')
            ->call('executeBulkAction')
            // the response closes the flow on a reset selection
            ->assertSet('selectedTickets', []);

        $this->assertSame('completed', $t1->fresh()->status);
        $this->assertSame('created', $t2->fresh()->status);
        $this->assertSame('completed', $t3->fresh()->status);

        $this->assertDatabaseHas('task_activities', ['ticket_id' => $t1->id, 'action' => 'completed']);
        $this->assertDatabaseMissing('task_activities', ['ticket_id' => $t2->id, 'action' => 'completed']);
        $this->assertDatabaseHas('task_activities', ['ticket_id' => $t3->id, 'action' => 'completed']);
    }

    /**
     * #1040: the «لغو انتخاب» button was a client-only `$set()` that left a
     * `selectAll=true` flag standing behind an empty ledger. The method must
     * reset BOTH, and the bulk bar (driven by the server-side count) must go
     * away with it.
     */
    public function test_clear_selection_resets_both_the_ledger_and_select_all(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['status' => 'created', 'subject' => 'تیکت بماند']);
        $this->createTicket(['status' => 'forwarded', 'subject' => 'تیکت بماند دوم']);

        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [99999])
            ->call('toggleSelectAll')
            ->assertSet('selectAll', true)
            ->assertSet('selectedTickets', [])
            ->assertSee('تیکت انتخاب شده')
            ->call('clearSelection')
            ->assertSet('selectAll', false)
            ->assertSet('selectedTickets', [])
            ->assertDontSee('تیکت انتخاب شده');

        // After the reset a stray executeBulkAction() call must be a no-op on
        // phantom «selection» — assert on the DATABASE, not on state (#936).
        Livewire::test('tickets.inbox')
            ->set('bulkAction', 'complete')
            ->call('executeBulkAction');

        $this->assertSame('created', Ticket::where('subject', 'تیکت بماند')->firstOrFail()->status);
        $this->assertSame('forwarded', Ticket::where('subject', 'تیکت بماند دوم')->firstOrFail()->status);
        $this->assertDatabaseMissing('task_activities', ['action' => 'completed']);
    }

    public function test_execute_bulk_action_only_completed_warning(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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

    public function test_bulk_complete_toast_reports_processed_not_selected_count(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $created = $this->createTicket(['status' => 'created']);
        $forwarded = $this->createTicket(['status' => 'forwarded']);
        $alreadyDone = $this->createTicket(['status' => 'completed']);
        $outOfScope = $this->createTicket(['status' => 'created', 'unit' => Unit::factory()->create()]);

        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$created->id, $forwarded->id, $alreadyDone->id, $outOfScope->id])
            ->set('bulkAction', 'complete')
            ->call('executeBulkAction')
            ->assertDispatched('swal', function ($name, $params) {
                $title = (string) ($params[0]['title'] ?? '');

                return str_contains($title, '2 تیکت') && ! str_contains($title, '4 تیکت');
            });

        // Only the two in-scope, not-already-completed tickets were processed.
        $this->assertSame('completed', $created->fresh()->status);
        $this->assertSame('completed', $forwarded->fresh()->status);
        $this->assertSame('completed', $alreadyDone->fresh()->status);
        $this->assertSame('created', $outOfScope->fresh()->status);

        $this->assertDatabaseHas('task_activities', ['ticket_id' => $created->id, 'action' => 'completed']);
        $this->assertDatabaseHas('task_activities', ['ticket_id' => $forwarded->id, 'action' => 'completed']);
        // Skipped selections must not be counted as processed.
        $this->assertDatabaseMissing('task_activities', ['ticket_id' => $alreadyDone->id, 'action' => 'completed']);
        $this->assertDatabaseMissing('task_activities', ['ticket_id' => $outOfScope->id, 'action' => 'completed']);
    }

    public function test_bulk_forward_toast_reports_processed_not_selected_count(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $forwardable = $this->createTicket(['status' => 'created']);
        $outOfScope = $this->createTicket(['status' => 'created', 'unit' => Unit::factory()->create()]);

        Livewire::test('tickets.inbox')
            ->set('selectedTickets', [$forwardable->id, $outOfScope->id])
            ->set('bulkAction', 'forward')
            ->call('executeBulkAction')
            ->assertDispatched('swal', function ($name, $params) {
                $title = (string) ($params[0]['title'] ?? '');

                return str_contains($title, '1 تیکت') && ! str_contains($title, '2 تیکت');
            });

        $this->assertSame('forwarded', $forwardable->fresh()->status);
        $this->assertSame('created', $outOfScope->fresh()->status);

        $this->assertDatabaseHas('task_activities', ['ticket_id' => $forwardable->id, 'action' => 'forwarded']);
        $this->assertDatabaseMissing('task_activities', ['ticket_id' => $outOfScope->id, 'action' => 'forwarded']);
    }

    // =====================================================================
    // S12 — submitAction
    // =====================================================================

    public function test_submit_action_completes_ticket(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
        $this->actingAs($user);

        $todo = Todo::create([
            'title' => 'وظیفه تست',
            'start_at' => now(),
            'is_completed' => false,
            // #847: the detail modal now gates `task` on the viewer's scope,
            // so this todo needs a unit the viewer can actually reach. It was
            // built with no `unit_id` at all, which put it in the ownerless
            // bucket — readable only under the blanket null-unit rule this
            // issue removes.
            'unit_id' => $user->units()->first()->id,
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

        // Issue #1054: invalid date strings are silently ignored — the
        // deliberate contract — so the component renders and no date bound is
        // applied (the ticket stays visible).
        Livewire::test('tickets.inbox')
            ->set('dateFrom', 'not-a-date')
            ->set('dateTo', 'also-not-a-date')
            ->assertStatus(200)
            ->assertSee('تیکت معمولی');
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
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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

        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets', 'manage_unit_tickets']);
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

    // =====================================================================
    // #906 — creator column is null-safe
    //
    // `maintenance:generate-due` writes a ticket with `user_id = null`, and a
    // soft-deleted creator resolves `$ticket->user` to null too. Both reached
    // `$ticket->user->person?->f_name`, and Laravel promotes that warning to an
    // exception, so one such row 500s the whole list.
    //
    // The poison ticket must live in the VIEWER'S OWN unit: parked in
    // `Unit::first()` while the viewer sits elsewhere, the row is out of scope
    // and the test passes without proving anything.
    //
    // Labels are asserted against the exact span the cell renders, with
    // `escape: false`. A bare `assertSee('سیستم')` is vacuous — the help modal on
    // every page already contains that word 18 times, and `کاربر غیرفعال` once.
    // =====================================================================

    /**
     * A ticket exactly as `maintenance:generate-due` writes it: no `user_id`
     * at all. The shared `createTicket()` helper cannot express this — it falls
     * back to `User::first()` whenever the `user` override is null.
     */
    protected function makeOwnerlessTicket(Unit $unit, string $code, string $subject): Ticket
    {
        return Ticket::create([
            'ticket_code' => $code,
            'user_id' => null,
            'unit_id' => $unit->id,
            'subject' => $subject,
            'content' => 'محتوای تست',
            'priority' => 'normal',
            'status' => 'created',
        ]);
    }

    public function test_ownerless_ticket_renders_the_system_label(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->makeOwnerlessTicket($unit, 'T906-NULL', 'تیکت بدون سازنده');

        Livewire::test('tickets.inbox')
            ->assertStatus(200)
            ->assertSee('#T906-NULL')
            ->assertSee('<span class="font-bold text-sm">سیستم</span>', escape: false);
    }

    public function test_ticket_of_a_soft_deleted_creator_renders_the_inactive_user_label(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $creator = Person::factory()->create(['u_id' => $unit->id]);
        $creatorUser = User::factory()->create(['n_code' => $creator->n_code]);
        $creatorUser->delete();

        $this->createTicket([
            'unit' => $unit,
            'user' => $creatorUser,
            'ticket_code' => 'T906-SOFT',
            'subject' => 'تیکت با سازنده غیرفعال',
        ]);

        Livewire::test('tickets.inbox')
            ->assertStatus(200)
            ->assertSee('#T906-SOFT')
            ->assertSee('<span class="font-bold text-sm">کاربر غیرفعال</span>', escape: false);
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

    // =====================================================================
    // #1056 — inbox search must fold Persian characters like every other
    // list (hardware/users/kargozini). The raw `LIKE` compared the stored
    // spelling byte-for-byte against the typed one, so a user whose keyboard
    // emits the ARABIC kaf/yeh (U+0643/U+064A) could not find a ticket stored
    // with the PERSIAN kaf/yeh (U+06A9/U+06CC) — the same input worked in the
    // hardware list, which folds both the term and the column.
    // =====================================================================

    public function test_search_folds_arabic_variants_in_subject(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        // «کد یکتا» with PERSIAN letters — the shape the app stores.
        $persian = "\u{06A9}\u{062F} \u{06CC}\u{06A9}\u{062A}\u{0627}";
        // The same phrase typed with ARABIC letters — the shape a foreign
        // keyboard produces and the shape folding must reconcile.
        $arabic = "\u{0643}\u{062F} \u{064A}\u{0643}\u{062A}\u{0627}";

        $this->createTicket(['subject' => $persian]);
        $this->createTicket(['subject' => 'تیکت کنترل']);

        Livewire::test('tickets.inbox')
            ->set('search', $arabic)
            ->assertSee($persian)
            ->assertDontSee('تیکت کنترل');
    }

    public function test_search_folds_arabic_variants_in_content(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $persian = "\u{06A9}\u{062F} \u{06CC}\u{06A9}\u{062A}\u{0627}";
        $arabic = "\u{0643}\u{062F} \u{064A}\u{0643}\u{062A}\u{0627}";

        // The match lives only in `content`, so the row is found iff the
        // content column is folded too — the subject here is unrelated.
        $this->createTicket(['ticket_code' => 'T-FLD-01', 'subject' => 'موضوع بی‌ربط', 'content' => $persian]);
        $this->createTicket(['ticket_code' => 'T-FLD-02', 'subject' => 'موضوع دیگر', 'content' => 'محتوای دیگر']);

        Livewire::test('tickets.inbox')
            ->set('search', $arabic)
            ->assertSee('T-FLD-01')
            ->assertDontSee('T-FLD-02');
    }

    public function test_search_folds_persian_digits_in_ticket_code(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);

        $this->createTicket(['ticket_code' => 'T-4500', 'subject' => 'کد لاتین']);
        $this->createTicket(['ticket_code' => 'T-9999', 'subject' => 'کد دیگر']);

        // Persian digits ۴۵۰۰ fold to Latin 4500 on the term side and on the
        // folded `ticket_code` column, so a Persian-keyboard entry matches a
        // Latin code.
        Livewire::test('tickets.inbox')
            ->set('search', "T-\u{06F4}\u{06F5}\u{06F0}\u{06F0}")
            ->assertSee('T-4500')
            ->assertDontSee('T-9999');
    }
}
