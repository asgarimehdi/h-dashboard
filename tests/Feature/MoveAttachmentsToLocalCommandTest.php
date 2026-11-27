<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1276 — the one-shot migration of existing attachment files from the
 * public disk to the private `local` disk.
 *
 * Deployment order: code → `--dry-run` → copy (no delete) → verify a real
 * download → `--delete-public`. These tests pin every step of it.
 */
#[CoversNothing]
class MoveAttachmentsToLocalCommandTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function attachmentRow(string $path, int $size = 9): Attachment
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);

        $ticket = Ticket::create([
            'ticket_code' => 'T-MOVE-'.md5($path),
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت مهاجرت پیوست',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        return Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => $path,
            'file_name' => basename($path),
            'file_size' => $size,
        ]);
    }

    public function test_it_copies_a_file_from_public_to_local(): void
    {
        $path = 'attachments/report.pdf';
        Storage::disk('public')->put($path, 'pdf-bytes');
        $attachment = $this->attachmentRow($path);

        $this->artisan('attachments:move-to-local')
            ->expectsOutputToContain('migrated')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($path);
        $this->assertSame('pdf-bytes', Storage::disk('local')->get($path));

        // Without `--delete-public` the public copy is deliberately kept so a
        // code revert is still safe.
        Storage::disk('public')->assertExists($path);

        // The path is identical on both disks — `file_path` must not change.
        $this->assertDatabaseHas('attachments', [
            'id' => $attachment->id,
            'file_path' => $path,
        ]);
    }

    public function test_it_is_idempotent(): void
    {
        $path = 'attachments/report.pdf';
        Storage::disk('public')->put($path, 'pdf-bytes');
        $this->attachmentRow($path);

        $this->artisan('attachments:move-to-local')->assertExitCode(0);

        // The second run sees the file on local and does nothing.
        $this->artisan('attachments:move-to-local')
            ->expectsOutputToContain('already')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(1, Attachment::count());
    }

    public function test_dry_run_touches_nothing(): void
    {
        $path = 'attachments/report.pdf';
        Storage::disk('public')->put($path, 'pdf-bytes');
        $this->attachmentRow($path);

        $this->artisan('attachments:move-to-local', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        Storage::disk('local')->assertMissing($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_delete_public_removes_the_public_copy_after_the_sizes_match(): void
    {
        $path = 'attachments/report.pdf';
        Storage::disk('public')->put($path, 'pdf-bytes');
        $this->attachmentRow($path);

        $this->artisan('attachments:move-to-local', ['--delete-public' => true])
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    /**
     * The documented deployment runs the copy first and `--delete-public` in a
     * later invocation, so the delete step must also work for a file that is
     * already on local — otherwise step 5 of the runbook would be a no-op.
     */
    public function test_delete_public_also_removes_a_copy_that_was_migrated_earlier(): void
    {
        $path = 'attachments/report.pdf';
        Storage::disk('public')->put($path, 'pdf-bytes');
        $this->attachmentRow($path);

        $this->artisan('attachments:move-to-local')->assertExitCode(0);
        Storage::disk('local')->assertExists($path);

        $this->artisan('attachments:move-to-local', ['--delete-public' => true])
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_row_whose_file_is_on_neither_disk_fails_the_run(): void
    {
        $path = 'attachments/vanished.pdf';
        $this->attachmentRow($path);

        $this->artisan('attachments:move-to-local')
            ->expectsOutputToContain($path)
            ->assertExitCode(1);

        Storage::disk('local')->assertMissing($path);
    }

    /**
     * `--delete-public` is only ever allowed after the copied file's size is
     * verified. A disk that lies about the destination size must leave the
     * public copy alone and fail the run.
     */
    public function test_a_size_mismatch_keeps_the_public_copy_and_fails(): void
    {
        $path = 'attachments/report.pdf';
        Storage::disk('public')->put($path, 'pdf-bytes');
        $this->attachmentRow($path);

        $realLocal = Storage::disk('local');
        $lyingLocal = Mockery::mock($realLocal);
        $lyingLocal->shouldReceive('size')->andReturn(1);
        Storage::set('local', $lyingLocal);

        $this->artisan('attachments:move-to-local', ['--delete-public' => true])
            ->assertExitCode(1);

        Storage::disk('public')->assertExists($path);
        // #1928: the partial copy must not survive the failure, or the next
        // run would mistake it for a completed migration.
        $this->assertFalse($realLocal->exists($path));
    }

    /**
     * #1928 — a size mismatch on an already-local row means an interrupted
     * migration left a partial file on `local`. The public copy is the source
     * of truth, so the corrupt file is replaced byte-for-byte even without
     * `--delete-public`, and a second run reports the row as already local
     * instead of failing forever.
     */
    public function test_it_repairs_a_corrupt_local_copy_from_the_public_original(): void
    {
        $path = 'attachments/report.pdf';
        $full = 'pdf-bytes-complete';
        Storage::disk('public')->put($path, $full);
        Storage::disk('local')->put($path, substr($full, 0, 4));
        $this->attachmentRow($path, strlen($full));

        $this->artisan('attachments:move-to-local')
            ->expectsOutputToContain('migrated: 1')
            ->assertExitCode(0);

        $this->assertSame($full, Storage::disk('local')->get($path));
        Storage::disk('public')->assertExists($path);

        $this->artisan('attachments:move-to-local')
            ->expectsOutputToContain('already local: 1')
            ->expectsOutputToContain('failed: 0')
            ->assertExitCode(0);
    }

    /**
     * #1928 — with `--delete-public`, the repair must run before the public
     * copy is removed; otherwise the row is stuck in `failed` forever.
     */
    public function test_it_repairs_a_corrupt_local_copy_then_deletes_public_when_asked(): void
    {
        $path = 'attachments/report.pdf';
        $full = 'pdf-bytes-complete';
        Storage::disk('public')->put($path, $full);
        Storage::disk('local')->put($path, substr($full, 0, 4));
        $this->attachmentRow($path, strlen($full));

        $this->artisan('attachments:move-to-local', ['--delete-public' => true])
            ->expectsOutputToContain('migrated: 1')
            ->assertExitCode(0);

        $this->assertSame($full, Storage::disk('local')->get($path));
        Storage::disk('public')->assertMissing($path);

        $this->artisan('attachments:move-to-local', ['--delete-public' => true])
            ->assertExitCode(0);
    }

    /**
     * #1928 — `writeStream` returning false can leave a half-written file. It
     * must be removed, and the next run must recover from the intact public
     * copy.
     */
    public function test_a_failed_write_removes_the_partial_local_file_and_the_next_run_recovers(): void
    {
        $path = 'attachments/report.pdf';
        $full = 'pdf-bytes-complete';
        Storage::disk('public')->put($path, $full);
        $this->attachmentRow($path, strlen($full));

        $realLocal = Storage::disk('local');
        $failingLocal = Mockery::mock($realLocal);
        $failingLocal->shouldReceive('writeStream')
            ->once()
            ->andReturnUsing(function (string $target, $stream) use ($realLocal): bool {
                $realLocal->put($target, fread($stream, 4));

                return false;
            });
        Storage::set('local', $failingLocal);

        $this->artisan('attachments:move-to-local')->assertExitCode(1);

        $this->assertFalse($realLocal->exists($path));
        Storage::disk('public')->assertExists($path);

        Storage::set('local', $realLocal);

        $this->artisan('attachments:move-to-local')->assertExitCode(0);

        $this->assertSame($full, $realLocal->get($path));
    }

    /**
     * #1928 — a dry run reports the repair it would perform but changes
     * nothing: no delete, no copy.
     */
    public function test_dry_run_reports_a_corrupt_local_copy_without_repairing_it(): void
    {
        $path = 'attachments/report.pdf';
        $full = 'pdf-bytes-complete';
        $partial = substr($full, 0, 4);
        Storage::disk('public')->put($path, $full);
        Storage::disk('local')->put($path, $partial);
        $this->attachmentRow($path, strlen($full));

        $this->artisan('attachments:move-to-local', ['--dry-run' => true])
            ->expectsOutputToContain('would re-copy')
            ->expectsOutputToContain('migrated: 1')
            ->assertExitCode(0);

        $this->assertSame($partial, Storage::disk('local')->get($path));
        Storage::disk('public')->assertExists($path);
    }

    /**
     * Rows written before `file_path` was normalised can carry a `/`, `storage/`
     * or `public/` prefix. The command strips it to find the file but never
     * rewrites `file_path`.
     */
    public function test_it_strips_legacy_prefixes_without_rewriting_the_row(): void
    {
        $path = 'attachments/report.pdf';
        Storage::disk('public')->put($path, 'pdf-bytes');

        $legacyPath = '/storage/'.$path;
        $attachment = $this->attachmentRow($legacyPath);

        $this->artisan('attachments:move-to-local')->assertExitCode(0);

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('attachments', [
            'id' => $attachment->id,
            'file_path' => $legacyPath,
        ]);
    }
}
