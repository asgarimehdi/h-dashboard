<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Support\AttachmentPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Issue #1276 — move the attachment files that already exist on the public
 * disk onto the private `local` disk.
 *
 * `attachments.file_path` is a relative path and both disks root at
 * `storage/app/<public|private>`, so the SAME string is valid on both disks:
 * the move copies bytes and never rewrites the column. Only malformed legacy
 * values (`/storage/...`, `public/...`) are stripped — and even then only in
 * memory, to locate the file, never written back.
 *
 * Deployment order (also in the PR body):
 *   1. deploy the code
 *   2. `attachments:move-to-local --dry-run`
 *   3. `attachments:move-to-local`                  (copy + verify, no delete)
 *   4. verify a real download from inbox and monitoring
 *   5. `attachments:move-to-local --delete-public`  (public copy removed)
 *   6. remove the `public/storage` symlink, after reviewing its contents
 *
 * The command is idempotent and self-healing: a row already served from `local`
 * is skipped only while its size matches the public original. A size mismatch
 * proves an interrupted copy left a partial file behind, so the corrupt local
 * copy is discarded and recopied from public (never the other way round); the
 * public copy is still only deleted after the new copy passes the size gate.
 * A copy that fails is deleted instead of left in place, so the next run
 * retries from a clean state rather than failing forever. `--delete-public`
 * still removes the public copy once the sizes match.
 */
class MoveAttachmentsToLocal extends Command
{
    protected $signature = 'attachments:move-to-local {--dry-run : فقط گزارش می‌دهد، بدون کپی یا حذف} {--delete-public : پس از تأیید برابری اندازه، نسخهٔ عمومی را حذف می‌کند}';

    protected $description = 'Copy attachment files from the public disk to the private local disk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deletePublic = (bool) $this->option('delete-public');

        $local = Storage::disk('local');
        $public = Storage::disk('public');

        $migrated = 0;
        $alreadyLocal = 0;
        $deletedFromPublic = 0;
        $missing = 0;
        $failed = 0;

        $this->info('Moving attachment files from the public disk to the local disk...');

        if ($dryRun) {
            $this->warn('  Dry run — nothing will be copied or deleted.');
        }

        Attachment::query()
            ->select(['id', 'file_path'])
            ->chunkById(500, function ($attachments) use (
                $dryRun,
                $deletePublic,
                $local,
                $public,
                &$migrated,
                &$alreadyLocal,
                &$deletedFromPublic,
                &$missing,
                &$failed,
            ): void {
                foreach ($attachments as $attachment) {
                    $path = AttachmentPath::normalize((string) $attachment->file_path);

                    if ($path === '') {
                        $missing++;
                        $this->line("    missing: attachment #{$attachment->id} has an empty file_path");

                        continue;
                    }

                    if ($local->exists($path)) {
                        // #1928: a size mismatch proves the local copy is the
                        // failed half of an interrupted migration. Nothing
                        // writes to the public disk any more
                        // (NoPublicDiskStorageTest), so public is the source
                        // of truth. Repair it whether or not `--delete-public`
                        // is set — without the flag the run used to report a
                        // silent `alreadyLocal` while downloads served the
                        // corrupt bytes.
                        $needsRepair = $public->exists($path)
                            && $public->size($path) !== $local->size($path);

                        if ($needsRepair) {
                            if ($dryRun) {
                                $migrated++;
                                $this->warn("    would re-copy: {$path} differs in size between disks (attachment #{$attachment->id})");

                                continue;
                            }

                            // Drop the corrupt copy; the normal copy path below
                            // recreates it. The public original is only deleted
                            // after the new copy passes the same size gate.
                            $local->delete($path);
                        } else {
                            // Already migrated. `--delete-public` still has work
                            // to do here: the documented runbook copies first
                            // and deletes in a later invocation, so the second
                            // run must remove a public copy it did not just
                            // create. A missing public copy is not evidence of
                            // corruption — keep the only local copy.
                            if (! $deletePublic || ! $public->exists($path)) {
                                $alreadyLocal++;

                                continue;
                            }

                            if (! $dryRun) {
                                $public->delete($path);
                            }

                            $deletedFromPublic++;

                            continue;
                        }
                    }

                    if (! $public->exists($path)) {
                        $missing++;
                        $this->line("    missing: {$path} (attachment #{$attachment->id}) is on neither disk");

                        continue;
                    }

                    if ($dryRun) {
                        $migrated++;

                        continue;
                    }

                    $stream = $public->readStream($path);

                    if (! is_resource($stream)) {
                        $failed++;
                        $this->error("    failed: could not read {$path} (attachment #{$attachment->id})");

                        continue;
                    }

                    try {
                        $written = $local->writeStream($path, $stream);
                    } finally {
                        fclose($stream);
                    }

                    if ($written === false) {
                        // A failed write can leave a partial file behind; drop
                        // it so the next run retries from a clean state instead
                        // of treating the fragment as an already-migrated copy.
                        if ($local->exists($path)) {
                            $local->delete($path);
                        }

                        $failed++;
                        $this->error("    failed: could not write {$path} (attachment #{$attachment->id})");

                        continue;
                    }

                    // Never delete the only other copy before proving the copy
                    // is complete.
                    if ($public->size($path) !== $local->size($path)) {
                        if ($local->exists($path)) {
                            $local->delete($path);
                        }

                        $failed++;
                        $this->error("    failed: size mismatch after copying {$path} (attachment #{$attachment->id})");

                        continue;
                    }

                    $migrated++;

                    if ($deletePublic) {
                        $public->delete($path);
                        $deletedFromPublic++;
                    }
                }
            });

        $this->newLine();
        $this->line("  migrated: {$migrated}");
        $this->line("  already local: {$alreadyLocal}");
        $this->line("  deleted from public: {$deletedFromPublic}");
        $this->line("  missing: {$missing}");
        $this->line("  failed: {$failed}");

        // A row whose file is on neither disk means data loss the operator has
        // to look at, so it stops a deployment exactly like a failed copy does.
        if ($failed > 0 || $missing > 0) {
            $this->error('Migration finished with errors — nothing else was changed. Review the lines above.');

            return 1;
        }

        if ($dryRun) {
            $this->warn('  Dry run complete — no files were copied or deleted.');

            return 0;
        }

        $this->info('Attachment migration complete.');

        return 0;
    }
}
