<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Support\AttachmentPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;

/**
 * Issue #880 — recover attachment files that were leaked by the pre-#880
 * delete path (rows cascaded away, files left on the public disk forever).
 *
 * Issue #1276 — the files live on the private `local` disk now, so this is
 * also the only thing that can find the NEW orphans (a `store()` that fails
 * before the row is inserted, #1303). Scanning `public` would report a clean
 * run while every real orphan sat unseen on `local`.
 *
 * Modelled on `data:archive` (`ArchiveOldRecords`): the same `--dry-run`
 * convention, the same "report, then act" output shape, and the same chunked
 * walk so a directory with a very large number of files does not load them all
 * into memory at once.
 *
 * This only ever deletes FILES. A row whose file is already gone is a
 * different (also real) problem, and removing that row here would hide it
 * instead of reporting it.
 *
 * Issue #1878 — uploads write the file to disk BEFORE the row exists, so an
 * unreferenced file may be an upload still in flight. `--grace-minutes`
 * (default 60) keeps recently modified files out of the orphan set; `0`
 * disables the guard for an operator who has stopped uploads.
 */
class PruneOrphanAttachments extends Command
{
    protected $signature = 'attachments:prune-orphans
                            {--grace-minutes=60 : فایل‌های تغییرکرده در این تعداد دقیقهٔ اخیر حذف نمی‌شوند}
                            {--dry-run : فقط گزارش می‌دهد، بدون حذف فایل}';

    protected $description = 'Delete attachment files on the private local disk that no attachments row references';

    /**
     * Files per walk chunk. Mirrors `data:archive`.
     */
    private const CHUNK = 1000;

    public function handle(): int
    {
        $graceMinutes = filter_var(
            $this->option('grace-minutes'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        if ($graceMinutes === false) {
            $this->error('--grace-minutes باید عدد صحیح و دست‌کم ۰ باشد.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $dryRun = (bool) $this->option('dry-run');

        // `0` disables the guard entirely (the pre-#1878 behaviour). Any positive
        // value keeps files modified within that many minutes out of the orphan set.
        $cutoff = $graceMinutes > 0 ? now()->subMinutes($graceMinutes)->getTimestamp() : null;

        $this->info('Scanning storage/app/private/attachments for unreferenced files...');

        $orphans = [];
        $referenced = 0;
        $recent = 0;
        $unreadable = 0;

        // Issue #1178 — load the referenced set ONCE instead of asking the
        // database about each file separately. The old per-file `exists()`
        // made the scan O(files) queries, and because `attachments.file_path`
        // had no index each of them was a sequential scan of the table, so
        // the whole command cost O(files x rows). One `pluck` plus an
        // O(1) `isset()` per file makes it O(1) queries.
        //
        // `flip()` keys the set by path, so duplicate rows for one path
        // collapse into a single entry — the same answer the old query gave.
        // Both sides go through the shared normaliser (issue #1735): rows
        // written with a legacy `/storage/...` or `public/...` prefix must
        // still match their file, or the prune would delete it.
        $referencedPaths = Attachment::query()
            ->pluck('file_path')
            ->filter(fn (?string $path): bool => filled($path))
            ->map(fn (?string $path): string => AttachmentPath::normalize((string) $path))
            ->flip();

        foreach ($disk->allFiles(self::DIRECTORY) as $file) {
            $path = AttachmentPath::normalize($file);

            if (isset($referencedPaths[$path])) {
                $referenced++;

                continue;
            }

            // Issue #1878 — an upload writes its file BEFORE the `attachments`
            // row exists (`TicketsInboxFlow::submitAction()` and
            // `⚡create.blade.php` store first, insert later). A recently
            // modified unreferenced file may be an upload still in flight, not
            // an orphan, so the age guard keeps it.
            if ($cutoff !== null) {
                try {
                    $lastModified = $disk->lastModified($path);
                } catch (UnableToRetrieveMetadata) {
                    // It vanished (or cannot be stat'ed) between the walk and
                    // here. We cannot prove it is old, so we must not delete it.
                    $unreadable++;

                    continue;
                }

                // Strict `>=`, the same boundary as `imports:prune-sheets`:
                // a file exactly on the cutoff is still inside the window.
                if ($lastModified >= $cutoff) {
                    $recent++;

                    continue;
                }
            }

            $orphans[] = $path;
        }

        $unreferenced = count($orphans) + $recent + $unreadable;

        $this->line("  Found {$referenced} referenced file(s).");
        $this->line("  Found {$unreferenced} unreferenced file(s).");

        if ($recent > 0) {
            $this->line("  Skipped {$recent} file(s) modified within the last {$graceMinutes} minute(s).");
        }

        if ($unreadable > 0) {
            $this->warn("  Skipped {$unreadable} file(s) whose last-modified time could not be read.");
        }

        if ($orphans === []) {
            $this->info('  Nothing to prune.');
            $this->info('Orphan attachment prune complete.');

            return 0;
        }

        // Report every orphan by name — under `--dry-run` this output IS the
        // deliverable, and it is what lets an operator decide which of the
        // leaked files may go.
        foreach ($orphans as $path) {
            $this->line("    - {$path}");
        }

        if ($dryRun) {
            $this->warn('  Dry run — no files were deleted.');
            $this->info('Dry run complete.');

            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($orphans, self::CHUNK) as $chunk) {
            // The local disk sets `throw => false`, so a file removed between
            // the scan and here is skipped rather than aborting the run.
            $disk->delete($chunk);
            $deleted += count($chunk);
        }

        $this->line("  Deleted {$deleted} file(s).");
        $this->info('Orphan attachment prune complete.');

        return 0;
    }

    /**
     * The directory the upload paths store into, on the `local` disk.
     */
    private const DIRECTORY = 'attachments';
}
