<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Person;
use App\Models\TaskActivity;
use App\Models\Ticket;
use App\Models\TicketCommentReaction;
use App\Models\TicketComment;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

#[CoversClass(\App\Models\Ticket::class)]

class OtherModelsTest extends TestCase
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

    protected function createTicket(): Ticket
    {
        $user = $this->createUserWithUnit();
        return Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => Unit::first()->id,
            'subject' => 'تیکت تست',
            'content' => 'متن تست',
            'priority' => 'normal',
            'status' => 'created',
        ]);
    }

    // ==================== TaskActivity ====================

    public function test_task_activity_belongs_to_user(): void
    {
        $user = $this->createUserWithUnit();
        $ticket = $this->createTicket();

        $activity = TaskActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'action' => 'created',
            'description' => 'تیکت ایجاد شد',
        ]);

        $this->assertNotNull($activity->user);
        $this->assertEquals($user->id, $activity->user->id);
    }

    public function test_task_activity_has_many_attachments(): void
    {
        $user = $this->createUserWithUnit();
        $ticket = $this->createTicket();

        $activity = TaskActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'action' => 'forward',
            'description' => 'ارجاع',
        ]);

        Attachment::create([
            'activity_id' => $activity->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => '/tmp/test.pdf',
            'file_name' => 'test.pdf',
            'file_size' => 1024,
        ]);

        $this->assertCount(1, $activity->attachments);
    }

    public function test_task_activity_fillable(): void
    {
        $user = $this->createUserWithUnit();
        $ticket = $this->createTicket();

        $activity = TaskActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'action' => 'accept',
            'description' => 'پذیرش',
            'is_internal' => true,
            'to_unit_id' => Unit::first()->id,
            'to_user_id' => $user->id,
        ]);

        $this->assertEquals('accept', $activity->action);
        $this->assertEquals('پذیرش', $activity->description);
        $this->assertTrue((bool) $activity->is_internal);
        $this->assertEquals(Unit::first()->id, $activity->to_unit_id);
        $this->assertEquals($user->id, $activity->to_user_id);
    }

    // ==================== Attachment ====================

    public function test_attachment_belongs_to_user(): void
    {
        $user = $this->createUserWithUnit();
        $ticket = $this->createTicket();

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => '/tmp/test.pdf',
            'file_name' => 'test.pdf',
            'file_size' => 1024,
        ]);

        $this->assertNotNull($attachment->user);
        $this->assertEquals($user->id, $attachment->user->id);
    }

    public function test_attachment_belongs_to_ticket(): void
    {
        $user = $this->createUserWithUnit();
        $ticket = $this->createTicket();

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => '/tmp/test.pdf',
            'file_name' => 'test.pdf',
            'file_size' => 1024,
        ]);

        $this->assertNotNull($attachment->ticket);
        $this->assertEquals($ticket->id, $attachment->ticket->id);
    }

    public function test_attachment_fillable(): void
    {
        $user = $this->createUserWithUnit();
        $ticket = $this->createTicket();

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'activity_id' => null,
            'file_path' => '/storage/uploads/file.xlsx',
            'file_name' => 'file.xlsx',
            'file_size' => 2048,
        ]);

        $this->assertEquals('/storage/uploads/file.xlsx', $attachment->file_path);
        $this->assertEquals('file.xlsx', $attachment->file_name);
        $this->assertEquals(2048, $attachment->file_size);
    }

    // ==================== TicketCommentReaction ====================

    public function test_reaction_belongs_to_comment(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $reaction = TicketCommentReaction::create([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'reaction' => '+1',
        ]);

        $this->assertNotNull($reaction->comment);
        $this->assertEquals($comment->id, $reaction->comment->id);
    }

    public function test_reaction_belongs_to_user(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر تست',
        ]);

        $reaction = TicketCommentReaction::create([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'reaction' => 'heart',
        ]);

        $this->assertNotNull($reaction->user);
        $this->assertEquals($user->id, $reaction->user->id);
    }

    public function test_reaction_fillable(): void
    {
        $ticket = $this->createTicket();
        $user = User::first();

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'نظر',
        ]);

        $reaction = TicketCommentReaction::create([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'reaction' => 'rocket',
        ]);

        $this->assertEquals('rocket', $reaction->reaction);
        $this->assertEquals($comment->id, $reaction->comment_id);
        $this->assertEquals($user->id, $reaction->user_id);
    }
}
