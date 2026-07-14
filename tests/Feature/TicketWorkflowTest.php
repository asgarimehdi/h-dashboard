<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function ticket_has_created_status_by_default(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create();

        $this->assertEquals('created', $ticket->status);
    }

    public function ticket_can_be_forwarded(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create(['status' => 'created']);
        $ticket->update(['status' => 'forwarded']);

        $this->assertEquals('forwarded', $ticket->fresh()->status);
    }

    public function ticket_accepted_sets_accepted_at(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'status' => 'forwarded',
            'accepted_at' => null,
        ]);

        $ticket->update(['status' => 'accepted', 'accepted_at' => now()]);
        $ticket->refresh();

        $this->assertEquals('accepted', $ticket->status);
        $this->assertNotNull($ticket->accepted_at);
        $this->assertInstanceOf(Carbon::class, $ticket->accepted_at);
    }

    public function ticket_completed_sets_completed_at(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'status' => 'accepted',
            'accepted_at' => now()->subDay(),
            'completed_at' => null,
        ]);

        $ticket->update(['status' => 'completed', 'completed_at' => now()]);
        $ticket->refresh();

        $this->assertEquals('completed', $ticket->status);
        $this->assertNotNull($ticket->completed_at);
        $this->assertInstanceOf(Carbon::class, $ticket->completed_at);
    }

    public function ticket_rejected_status_works(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create(['status' => 'created']);
        $ticket->update(['status' => 'rejected']);

        $this->assertEquals('rejected', $ticket->fresh()->status);
    }

    public function ticket_timestamps_are_cast(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'accepted_at' => now(),
            'completed_at' => now(),
        ]);

        $this->assertInstanceOf(Carbon::class, $ticket->created_at);
        $this->assertInstanceOf(Carbon::class, $ticket->updated_at);
        $this->assertInstanceOf(Carbon::class, $ticket->accepted_at);
        $this->assertInstanceOf(Carbon::class, $ticket->completed_at);
    }

    public function ticket_factory_produces_valid_data(): void
    {
        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        $ticket = Ticket::factory()->for($user)->for($unit)->create([
            'subject' => 'Test subject',
            'content' => 'Test content',
        ]);

        $this->assertNotEmpty($ticket->subject);
        $this->assertNotEmpty($ticket->content);
        $this->assertNotNull($ticket->ticket_code);
    }
}
