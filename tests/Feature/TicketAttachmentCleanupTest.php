<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #880 — deleting a ticket cascaded its `attachments` ROWS but never
 * deleted the files they pointed at, so every deleted ticket left a permanent,
 * publicly-served orphan under `storage/app/public/attachments/`.
 *
 * The FK cascade happens in the database, AFTER Eloquent's model events have
 * run, so a hook on `Attachment` never fires on this path — the paths must be
 * collected in `Ticket::deleting`, while the rows are still readable.
 */
#[CoversNothing]
class TicketAttachmentCleanupTest extends TestCase
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
     * Store a real file on the `public` disk and return its `file_path`.
     */
    private function storeAttachmentFile(string $name): string
    {
        Storage::disk('public')->put($path = "attachments/{$name}", 'pdf-bytes');

        return $path;
    }

    private function createTicketWithAttachment(string $name = 'report.pdf'): array
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit([
            'create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets',
        ], 'admin');

        $ticket = Ticket::create([
            'ticket_code' => 'T-880-'.$name,
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت با پیوست ۸۸۰',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $path = $this->storeAttachmentFile($name);

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => $path,
            'file_name' => $name,
            'file_size' => 9,
        ]);

        return [$user, $ticket, $attachment, $path];
    }

    public function test_deleting_a_ticket_deletes_its_attachment_files(): void
    {
        Storage::fake('public');

        [, $ticket, $attachment, $path] = $this->createTicketWithAttachment();

        Storage::disk('public')->assertExists($path);

        // Delete through the real API surface (`DELETE /api/tickets/{ticket}`),
        // the only ticket-delete route in the codebase.
        $user = User::find($ticket->user_id);
        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);

        $response = $this->apiDelete("/api/tickets/{$ticket->id}", $token);

        $response->assertStatus(200);

        // The file must be gone, not just its row.
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_deleting_a_ticket_removes_every_attachment_not_only_the_first(): void
    {
        Storage::fake('public');

        [, $ticket] = $this->createTicketWithAttachment('one.pdf');

        $second = $this->storeAttachmentFile('two.pdf');
        $third = $this->storeAttachmentFile('three.pdf');

        Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $ticket->user_id,
            'file_path' => $second,
            'file_name' => 'two.pdf',
            'file_size' => 9,
        ]);
        Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $ticket->user_id,
            'file_path' => $third,
            'file_name' => 'three.pdf',
            'file_size' => 9,
        ]);

        $user = User::find($ticket->user_id);
        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);

        $this->apiDelete("/api/tickets/{$ticket->id}", $token)->assertStatus(200);

        Storage::disk('public')->assertMissing('attachments/one.pdf');
        Storage::disk('public')->assertMissing($second);
        Storage::disk('public')->assertMissing($third);
        $this->assertDatabaseMissing('attachments', ['ticket_id' => $ticket->id]);
    }

    public function test_deleting_a_ticket_without_attachments_still_succeeds(): void
    {
        Storage::fake('public');

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit([
            'create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets',
        ], 'admin');

        $ticket = Ticket::create([
            'ticket_code' => 'T-880-BARE',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'بدون پیوست',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);

        $this->apiDelete("/api/tickets/{$ticket->id}", $token)->assertStatus(200);

        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
    }

    public function test_a_missing_file_on_disk_does_not_block_the_delete(): void
    {
        // `config/filesystems.php` sets `throw => false` on the public disk, so
        // a row whose file was already removed by hand must still delete.
        Storage::fake('public');

        [, $ticket, $attachment] = $this->createTicketWithAttachment('vanished.pdf');
        Storage::disk('public')->delete('attachments/vanished.pdf');

        $user = User::find($ticket->user_id);
        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);

        $this->apiDelete("/api/tickets/{$ticket->id}", $token)->assertStatus(200);

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_deleting_one_ticket_leaves_another_tickets_file_alone(): void
    {
        Storage::fake('public');

        // One actor and ONE unit for both tickets: a second
        // `createUserWithUnit()` would move `current_unit_id` and the first
        // user's own ticket would fall out of their unit scope (403), which
        // tests the API gate instead of the file cleanup.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit([
            'create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'manage_unit_tickets',
        ], 'admin');

        $first = Ticket::create([
            'ticket_code' => 'T-880-FIRST',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت اول',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);
        $secondTicket = Ticket::create([
            'ticket_code' => 'T-880-SECOND',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت دوم',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $firstPath = $this->storeAttachmentFile('first.pdf');
        $kept = $this->storeAttachmentFile('kept.pdf');

        Attachment::create([
            'ticket_id' => $first->id,
            'user_id' => $user->id,
            'file_path' => $firstPath,
            'file_name' => 'first.pdf',
            'file_size' => 9,
        ]);
        Attachment::create([
            'ticket_id' => $secondTicket->id,
            'user_id' => $user->id,
            'file_path' => $kept,
            'file_name' => 'kept.pdf',
            'file_size' => 9,
        ]);

        $token = $this->createApiToken($user, ['tickets:read', 'tickets:write']);

        $this->apiDelete("/api/tickets/{$first->id}", $token)->assertStatus(200);

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($kept);
        $this->assertDatabaseHas('attachments', ['file_path' => $kept]);
    }
}
