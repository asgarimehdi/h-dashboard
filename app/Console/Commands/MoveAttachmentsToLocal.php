<?php

namespace App\Console\Commands;

use App\Models\Attachment;
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
 * The command is idempotent: a row already served from `local` is skipped, and
 * `--delete-public` still removes its public copy once the sizes match.
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
                    $path = self::normalize((string) $attachment->file_path);

                    if ($path === '') {
                        $missing++;
                        $this->line("    missing: attachment #{$attachment->id} has an empty file_path");

                        continue;
                    }

                    if ($local->exists($path)) {
                        // Already migrated. `--delete-public` still has work to
                        // do here: the documented runbook copies first and
                        // deletes in a later invocation, so the second run must
                        // remove a public copy it did not just create.
                        if (! $deletePublic || ! $public->exists($path)) {
                            $alreadyLocal++;

                            continue;
                        }

                        if ($public->size($path) !== $local->size($path)) {
                            $failed++;
                            $this->error("    failed: {$path} differs in size between disks; public copy kept (attachment #{$attachment->id})");

                            continue;
                        }

                        if (! $dryRun) {
                            $public->delete($path);
                        }

                        $deletedFromPublic++;

                        continue;
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
                        $failed++;
                        $this->error("    failed: could not write {$path} (attachment #{$attachment->id})");

                        continue;
                    }

                    // Never delete the only other copy before proving the copy
                    // is complete.
                    if ($public->size($path) !== $local->size($path)) {
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

    /**
     * Strip the legacy prefixes that make a stored path unusable on either
     * disk (`/storage/attachments/x.pdf`, `public/attachments/x.pdf`, ...).
     */
    private static function normalize(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        foreach (['storage/', 'public/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }

        return ltrim($path, '/');
    }
}
