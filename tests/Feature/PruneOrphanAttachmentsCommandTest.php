<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #880 — `attachments:prune-orphans`.
 *
 * The delete-hook fix stops NEW orphans; everything already leaked before it
 * is still on disk with no row pointing at it. This command is the recovery
 * tool: it walks `storage/app/public/attachments/`, matches against
 * `attachments.file_path`, and reports — or, without `--dry-run`, deletes —
 * the files nothing references.
 *
 * Modelled on `data:archive` (`ArchiveOldRecords`): same `--dry-run` flag,
 * same chunked walk, same "report then act" output shape.
 */
#[CoversNothing]
class PruneOrphanAttachmentsCommandTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    private function referencedAttachment(string $name): Attachment
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        $path = "attachments/{$name}";
        Storage::disk('public')->put($path, 'bytes');

        $ticket = Ticket::create([
            'ticket_code' => 'T-880-'.$name,
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت ۸۸۰',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        // `attachments.ticket_id` is NOT NULL, so a surviving file always
        // hangs off a live ticket row.
        return Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => $path,
            'file_name' => $name,
            'file_size' => 5,
        ]);
    }

    private function orphanFile(string $name): string
    {
        $path = "attachments/{$name}";
        Storage::disk('public')->put($path, 'bytes');

        return $path;
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        Storage::fake('public');

        $this->referencedAttachment('kept.pdf');
        $orphan = $this->orphanFile('leaked.pdf');

        $this->artisan('attachments:prune-orphans', ['--dry-run' => true])
            ->expectsOutputToContain('leaked.pdf')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists($orphan);
    }

    public function test_dry_run_keeps_referenced_files(): void
    {
        Storage::fake('public');

        $this->referencedAttachment('kept.pdf');

        $this->artisan('attachments:prune-orphans', ['--dry-run' => true])
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('attachments/kept.pdf');
    }

    public function test_without_dry_run_unreferenced_files_are_deleted(): void
    {
        Storage::fake('public');

        $this->referencedAttachment('kept.pdf');
        $orphan = $this->orphanFile('leaked.pdf');

        $this->artisan('attachments:prune-orphans')
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing($orphan);
    }

    public function test_referenced_files_are_never_deleted(): void
    {
        Storage::fake('public');

        $this->referencedAttachment('kept.pdf');
        $this->referencedAttachment('also-kept.jpg');

        $this->artisan('attachments:prune-orphans')->assertExitCode(0);

        Storage::disk('public')->assertExists('attachments/kept.pdf');
        Storage::disk('public')->assertExists('attachments/also-kept.jpg');
    }

    public function test_the_command_never_deletes_attachment_rows(): void
    {
        // The command is a FILE cleanup tool, not a database one: a row whose
        // file is gone is a different (also real) problem and removing the row
        // here would hide it.
        Storage::fake('public');

        $attachment = $this->referencedAttachment('kept.pdf');
        Storage::disk('public')->delete($attachment->file_path);

        $this->artisan('attachments:prune-orphans')->assertExitCode(0);

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    public function test_nested_files_are_matched_by_their_stored_path(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('attachments/2026/01/nested.pdf', 'bytes');
        $attachment = $this->referencedAttachment('sibling.pdf');
        Attachment::whereKey($attachment->id)->update(['file_path' => 'attachments/2026/01/nested.pdf']);

        $this->artisan('attachments:prune-orphans')->assertExitCode(0);

        Storage::disk('public')->assertExists('attachments/2026/01/nested.pdf');
        Storage::disk('public')->assertMissing('attachments/sibling.pdf');
    }

    public function test_an_empty_attachments_directory_succeeds(): void
    {
        Storage::fake('public');

        $this->artisan('attachments:prune-orphans')->assertExitCode(0);

        $this->assertTrue(true);
    }
}
