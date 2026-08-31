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
use Tests\TestCase;

#[CoversClass(\App\Models\Ticket::class)]

class TicketModelTest extends TestCase
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

    protected function createTicketWithRelations(array $ticketAttrs = []): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        $ticket = Ticket::create(array_merge([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت تست',
            'content' => 'متن تست',
            'priority' => 'normal',
            'status' => 'created',
        ], $ticketAttrs));

        return ['ticket' => $ticket, 'user' => $user, 'unit' => $unit];
    }

    // --- canBeCompleted ---

    public function test_can_be_completed_when_status_is_accepted(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'accepted']);

        $this->assertTrue($ticket->canBeCompleted());
    }

    public function test_cannot_be_completed_when_status_is_created(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'created']);

        $this->assertFalse($ticket->canBeCompleted());
    }

    public function test_cannot_be_completed_when_status_is_forwarded(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'forwarded']);

        $this->assertFalse($ticket->canBeCompleted());
    }

    public function test_cannot_be_completed_when_status_is_completed(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'completed']);

        $this->assertFalse($ticket->canBeCompleted());
    }

    // --- statusName ---

    public function test_status_name_returns_persian_for_created(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'created']);

        $this->assertEquals('جدید ', $ticket->status_name);
    }

    public function test_status_name_returns_persian_for_forwarded(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'forwarded']);

        $this->assertEquals('ارجاع شده', $ticket->status_name);
    }

    public function test_status_name_returns_persian_for_accepted(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'accepted']);

        $this->assertEquals('در حال پیگیری', $ticket->status_name);
    }

    public function test_status_name_returns_persian_for_completed(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'completed']);

        $this->assertEquals('پایان یافته', $ticket->status_name);
    }

    public function test_status_name_returns_persian_for_rejected(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'rejected']);

        $this->assertEquals('رد شده', $ticket->status_name);
    }

    public function test_status_name_returns_unknown_for_unknown_status(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations(['status' => 'unknown']);

        $this->assertEquals('نامشخص', $ticket->status_name);
    }

    // --- waitingDuration ---

    public function test_waiting_duration_less_than_one_hour(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations();
        // Ticket created just now
        $duration = $ticket->waiting_duration;

        $this->assertEquals('کمتر از ۱ ساعت', $duration['text']);
        $this->assertStringContainsString('emerald', $duration['class']);
    }

    public function test_waiting_duration_between_1_and_24_hours(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations();
        $ticket->update(['created_at' => now()->subHours(5)]);

        $duration = $ticket->waiting_duration;

        $this->assertStringContainsString('ساعت', $duration['text']);
        $this->assertStringContainsString('emerald', $duration['class']);
    }

    public function test_waiting_duration_between_24_and_48_hours(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations();
        \Illuminate\Support\Facades\DB::table('tickets')
            ->where('id', $ticket->id)
            ->update(['created_at' => now()->subHours(30)]);
        $ticket->refresh();

        $duration = $ticket->waiting_duration;

        $this->assertStringContainsString('روز', $duration['text']);
        $this->assertStringContainsString('orange', $duration['class']);
    }

    public function test_waiting_duration_more_than_48_hours(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations();
        \Illuminate\Support\Facades\DB::table('tickets')
            ->where('id', $ticket->id)
            ->update(['created_at' => now()->subHours(72)]);
        $ticket->refresh();

        $duration = $ticket->waiting_duration;

        $this->assertStringContainsString('روز', $duration['text']);
        $this->assertStringContainsString('red', $duration['class']);
        $this->assertStringContainsString('animate-pulse', $duration['class']);
    }

    // --- Relationships ---

    public function test_ticket_belongs_to_unit(): void
    {
        ['ticket' => $ticket, 'unit' => $unit] = $this->createTicketWithRelations();

        $this->assertNotNull($ticket->unit);
        $this->assertEquals($unit->id, $ticket->unit->id);
    }

    public function test_ticket_belongs_to_user(): void
    {
        ['ticket' => $ticket, 'user' => $user] = $this->createTicketWithRelations();

        $this->assertNotNull($ticket->user);
        $this->assertEquals($user->id, $ticket->user->id);
    }

    public function test_ticket_has_many_activities(): void
    {
        ['ticket' => $ticket, 'user' => $user] = $this->createTicketWithRelations();

        \App\Models\TaskActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'action' => 'created',
            'description' => 'تیکت ایجاد شد',
        ]);

        $this->assertCount(1, $ticket->activities);
    }

    public function test_ticket_has_many_comments(): void
    {
        ['ticket' => $ticket, 'user' => $user] = $this->createTicketWithRelations();

        \App\Models\TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $this->assertCount(1, $ticket->comments);
    }

    public function test_ticket_has_many_attachments(): void
    {
        ['ticket' => $ticket] = $this->createTicketWithRelations();

        $this->assertEmpty($ticket->attachments);
    }

    public function test_ticket_assignee_is_settable(): void
    {
        ['ticket' => $ticket, 'unit' => $unit] = $this->createTicketWithRelations();
        $nCode2 = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'واگذار', 'l_name' => 'شده',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $assignee = User::create(['n_code' => $nCode2, 'password' => Hash::make('password')]);

        $ticket->update(['current_assignee_id' => $assignee->id]);
        $ticket->refresh();

        $this->assertEquals($assignee->id, $ticket->assignee->id);
    }
}
