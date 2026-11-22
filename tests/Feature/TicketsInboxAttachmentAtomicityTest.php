<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1615 — `submitAction()` wrote the completion/refer attachments INSIDE
 * its DB transaction: the file hit the `local` disk at
 * `app/Traits/TicketsInboxFlow.php:732` and the row was inserted right after
 * it, so a failure in between (or on the next file) left a rolled-back row and
 * a live file — an orphan that only the manual `attachments:prune-orphans`
 * command cleans up.
 *
 * The fix mirrors `saveTicket()` (#1303, `⚡create.blade.php`): files are
 * stored BEFORE the transaction, off the lock, and every exit path that rolls
 * the transaction back deletes them. That second half is not optional here —
 * `submitAction()` has five early `return`s that roll back before the old
 * upload loop ever ran, so "move `store()` up" without cleanup would orphan
 * files on all five (`test_a_forward_rejected_by_the_locked_guard_leaves_no_file`
 * is the guard that catches exactly that).
 *
 * Failure is injected with a PHP exception from a model event, never with a raw
 * SQL error: `RefreshDatabase` wraps each test in a transaction, so the
 * component's transaction compiles to a SAVEPOINT and a poisoned connection
 * would break the outer one.
 *
 * @see TicketsCreateAtomicityTest for the same contract on the create path.
 * @see TicketsInboxLivewireTest::test_file_uploads() for the happy path this
 *      file deliberately does not touch (`test_file_uploads` belongs to #1319).
 */
covers(Ticket::class);

class TicketsInboxAttachmentAtomicityTest extends TestCase
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
     * Fail the attachment INSERT after `$succeeded` rows went through.
     *
     * `$succeeded = 0` throws while storing the first file's row (one file on
     * disk, no rows); `$succeeded = 1` throws while processing the second file
     * (two files on disk, one row already written inside the transaction).
     */
    private function failAttachmentInsertAfter(int $succeeded): void
    {
        $seen = 0;

        Attachment::creating(function () use (&$seen, $succeeded): void {
            $seen++;

            if ($seen > $succeeded) {
                throw new \RuntimeException('boom');
            }
        });
    }

    /**
     * An inbox operator on their own unit, plus a ticket assigned to them —
     * `test_file_uploads`' fixture, made explicit here so this file owns it.
     *
     * @return array{user: User, unit: Unit, ticket: Ticket}
     */
    private function prepareInbox(array $ticketOverrides = []): array
    {
        Storage::fake('local');
        Storage::fake('public');

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit([
            'view_assigned_tickets',
            'manage_unit_tickets',
        ]);
        $this->actingAs($user);

        $ticket = Ticket::create(array_merge([
            'ticket_code' => 'T1615-'.fake()->unique()->numerify('######'),
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت اتمیک پیوست',
            'content' => 'محتوای تست',
            'priority' => 'normal',
            'status' => 'accepted',
            'current_assignee_id' => $user->id,
        ], $ticketOverrides));

        return ['user' => $user, 'unit' => $unit, 'ticket' => $ticket];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function complete(int $ticketId, array $files, ?int $targetUnitId = null): Testable
    {
        $component = Livewire::test('tickets.inbox')
            ->call('openCompletionModal', $ticketId)
            ->set('completionNote', 'گزارش تستی اتمیک')
            ->set('completionFiles', $files);

        if ($targetUnitId !== null) {
            $component->set('targetUnitId', $targetUnitId);
        }

        // submitAction() catches its own failures and turns them into an error
        // on `completionNote`; the catch below only keeps a stray re-throw from
        // masking the database/disk assertion, which is the real contract.
        try {
            return $component->call('submitAction');
        } catch (\Throwable $e) {
            return $component;
        }
    }

    private function assertNoAttachmentLeftBehind(): void
    {
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    /**
     * Failure while the first file's row is inserted: one file was already
     * stored when the exception fired, and it must not survive the rollback.
     */
    public function test_a_failure_on_the_first_file_leaves_no_file_on_disk(): void
    {
        ['ticket' => $ticket] = $this->prepareInbox();

        $files = [
            UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
        ];

        $this->failAttachmentInsertAfter(0);

        $this->complete($ticket->id, $files)->assertHasErrors('completionNote');

        $this->assertNoAttachmentLeftBehind();
        $this->assertSame('accepted', $ticket->refresh()->status);
    }

    /**
     * Failure while the SECOND file is processed: two files are on disk and the
     * first attachment row is already inside the transaction. Both the row and
     * both files must go — cleanup has to cover every file of the call, not
     * just the last one.
     */
    public function test_a_failure_on_the_second_file_cleans_up_both_files(): void
    {
        ['ticket' => $ticket] = $this->prepareInbox();

        $files = [
            UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
        ];

        $this->failAttachmentInsertAfter(1);

        $this->complete($ticket->id, $files)->assertHasErrors('completionNote');

        $this->assertNoAttachmentLeftBehind();
        $this->assertSame('accepted', $ticket->refresh()->status);
    }

    /**
     * The trap: `submitAction()`'s locked guard rolls back and returns BEFORE
     * the old upload loop, so this path never wrote a file. Storing the files up
     * front is only safe because the same cleanup covers these early returns —
     * without it, this test is the one that goes red.
     */
    public function test_a_forward_rejected_by_the_locked_guard_leaves_no_file(): void
    {
        ['ticket' => $ticket] = $this->prepareInbox(['status' => 'completed']);

        $target = Unit::create([
            'name' => 'واحد مقصد اتمیک',
            'is_active' => true,
            'can_receive_tickets' => true,
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        $this->complete($ticket->id, [$file], $target->id)->assertHasErrors('completionNote');

        $this->assertNoAttachmentLeftBehind();
        $this->assertSame('completed', $ticket->refresh()->status);
        $this->assertNotSame($target->id, $ticket->unit_id);
    }

    /**
     * The happy path with two files: `$committed` must be set before the
     * cleanup runs, or a successful submit would delete the attachments it just
     * recorded. `test_file_uploads` stays untouched (§ gate decision 3).
     */
    public function test_a_successful_completion_keeps_both_files(): void
    {
        ['ticket' => $ticket] = $this->prepareInbox();

        $files = [
            UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
        ];

        $this->complete($ticket->id, $files)->assertHasNoErrors();

        $this->assertSame('completed', $ticket->refresh()->status);
        $this->assertDatabaseCount('attachments', 2);
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));

        $paths = Attachment::where('ticket_id', $ticket->id)->pluck('file_path');

        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }
}
