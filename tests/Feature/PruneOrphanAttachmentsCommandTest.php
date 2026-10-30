<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
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

    /**
     * Issue #1178 — the bulk lookup must issue a bounded number of queries.
     *
     * A per-file lookup would scale with the number of files on the disk, so
     * this is the ceiling that keeps the command's cost flat.
     */
    private const MAX_LOOKUP_QUERIES = 5;

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
        Storage::disk('local')->put($path, 'bytes');

        $ticket = $this->ticketFor($user, $unit, $name);

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

    /**
     * A ticket to hang attachments off, without creating a new user/unit for
     * every single file.
     */
    private function ticketFor(User $user, Unit $unit, string $suffix): Ticket
    {
        return Ticket::create([
            'ticket_code' => 'T-880-'.$suffix,
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت ۸۸۰',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);
    }

    /**
     * An attachment row pointing at an existing ticket, for fixtures that
     * need many rows without many tickets.
     */
    private function attachmentRow(string $path, Ticket $ticket, User $user): Attachment
    {
        return Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => $path,
            'file_name' => basename($path),
            'file_size' => 5,
        ]);
    }

    private function orphanFile(string $name): string
    {
        $path = "attachments/{$name}";
        Storage::disk('local')->put($path, 'bytes');

        return $path;
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->referencedAttachment('kept.pdf');
        $orphan = $this->orphanFile('leaked.pdf');

        $this->artisan('attachments:prune-orphans', ['--dry-run' => true])
            ->expectsOutputToContain('leaked.pdf')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($orphan);
    }

    public function test_dry_run_keeps_referenced_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->referencedAttachment('kept.pdf');

        $this->artisan('attachments:prune-orphans', ['--dry-run' => true])
            ->assertExitCode(0);

        Storage::disk('local')->assertExists('attachments/kept.pdf');
    }

    public function test_without_dry_run_unreferenced_files_are_deleted(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->referencedAttachment('kept.pdf');
        $orphan = $this->orphanFile('leaked.pdf');

        $this->artisan('attachments:prune-orphans')
            ->assertExitCode(0);

        Storage::disk('local')->assertMissing($orphan);
        // Issue #1276 — the command scans the private disk only; nothing on
        // the public disk is touched (or created).
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    public function test_referenced_files_are_never_deleted(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->referencedAttachment('kept.pdf');
        $this->referencedAttachment('also-kept.jpg');

        $this->artisan('attachments:prune-orphans')->assertExitCode(0);

        Storage::disk('local')->assertExists('attachments/kept.pdf');
        Storage::disk('local')->assertExists('attachments/also-kept.jpg');
    }

    public function test_the_command_never_deletes_attachment_rows(): void
    {
        // The command is a FILE cleanup tool, not a database one: a row whose
        // file is gone is a different (also real) problem and removing the row
        // here would hide it.
        Storage::fake('local');
        Storage::fake('public');

        $attachment = $this->referencedAttachment('kept.pdf');
        Storage::disk('local')->delete($attachment->file_path);

        $this->artisan('attachments:prune-orphans')->assertExitCode(0);

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    public function test_nested_files_are_matched_by_their_stored_path(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        Storage::disk('local')->put('attachments/2026/01/nested.pdf', 'bytes');
        $attachment = $this->referencedAttachment('sibling.pdf');
        Attachment::whereKey($attachment->id)->update(['file_path' => 'attachments/2026/01/nested.pdf']);

        $this->artisan('attachments:prune-orphans')->assertExitCode(0);

        Storage::disk('local')->assertExists('attachments/2026/01/nested.pdf');
        Storage::disk('local')->assertMissing('attachments/sibling.pdf');
    }

    public function test_an_empty_attachments_directory_succeeds(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->artisan('attachments:prune-orphans')->assertExitCode(0);

        $this->assertTrue(true);
    }

    /**
     * Issue #1178 — the reference lookup must not scale with the number of
     * files on the disk.
     *
     * The old implementation asked the database about every file separately,
     * so 20 files meant 20 queries (each one a sequential scan, because
     * `attachments.file_path` had no index). The bulk lookup issues a
     * constant number of queries however many files are on the disk.
     */
    public function test_the_reference_lookup_does_not_scale_with_the_number_of_files_on_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        $ticket = $this->ticketFor($user, $unit, 'scale');

        // 20 files on the fake disk, 5 of them referenced by a row.
        foreach (range(1, 20) as $index) {
            Storage::disk('local')->put("attachments/file-{$index}.pdf", 'bytes');
        }

        foreach (range(1, 5) as $index) {
            $this->attachmentRow("attachments/file-{$index}.pdf", $ticket, $user);
        }

        $this->assertQueryCount(self::MAX_LOOKUP_QUERIES, function (): void {
            $this->artisan('attachments:prune-orphans', ['--dry-run' => true])
                ->assertExitCode(0);
        });

        // 40 files now — a per-file lookup would double the query count,
        // the bulk lookup keeps it flat.
        foreach (range(21, 40) as $index) {
            Storage::disk('local')->put("attachments/file-{$index}.pdf", 'bytes');
        }

        $this->assertQueryCount(self::MAX_LOOKUP_QUERIES, function (): void {
            $this->artisan('attachments:prune-orphans', ['--dry-run' => true])
                ->assertExitCode(0);
        });
    }

    /**
     * Issue #1178 — the bulk lookup must reach the same verdict as the old
     * per-file query: `--dry-run` reports exactly the orphans by name, the
     * real run deletes exactly those files, and no attachment row is touched
     * either way.
     */
    public function test_the_bulk_lookup_reports_and_deletes_the_same_orphans_as_the_per_file_lookup(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $referenced = ['kept-a.pdf', 'kept-b.pdf', 'kept-c.pdf'];
        $orphans = ['leaked-a.pdf', 'leaked-b.pdf'];

        foreach ($referenced as $name) {
            $this->referencedAttachment($name);
        }

        foreach ($orphans as $name) {
            $this->orphanFile($name);
        }

        $this->artisan('attachments:prune-orphans', ['--dry-run' => true])
            ->expectsOutputToContain('Found 3 referenced file(s).')
            ->expectsOutputToContain('Found 2 unreferenced file(s).')
            ->expectsOutputToContain('attachments/leaked-a.pdf')
            ->expectsOutputToContain('attachments/leaked-b.pdf')
            ->doesntExpectOutputToContain('kept-a.pdf')
            ->doesntExpectOutputToContain('kept-b.pdf')
            ->doesntExpectOutputToContain('kept-c.pdf')
            ->assertExitCode(0);

        // `--dry-run` reports but never deletes.
        foreach ($orphans as $name) {
            Storage::disk('local')->assertExists("attachments/{$name}");
        }

        $this->artisan('attachments:prune-orphans')
            ->expectsOutputToContain('Found 3 referenced file(s).')
            ->expectsOutputToContain('Found 2 unreferenced file(s).')
            ->expectsOutputToContain('attachments/leaked-a.pdf')
            ->expectsOutputToContain('attachments/leaked-b.pdf')
            ->assertExitCode(0);

        foreach ($referenced as $name) {
            Storage::disk('local')->assertExists("attachments/{$name}");
        }

        foreach ($orphans as $name) {
            Storage::disk('local')->assertMissing("attachments/{$name}");
        }

        // The command only ever deletes FILES — the rows survive both runs.
        $this->assertSame(3, Attachment::count());

        foreach ($referenced as $name) {
            $this->assertDatabaseHas('attachments', ['file_path' => "attachments/{$name}"]);
        }
    }
}
