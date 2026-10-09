<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Issue #880 — recover attachment files that were leaked by the pre-#880
 * delete path (rows cascaded away, files left on the public disk forever).
 *
 * Modelled on `data:archive` (`ArchiveOldRecords`): the same `--dry-run`
 * convention, the same "report, then act" output shape, and the same chunked
 * walk so a directory with a very large number of files does not load them all
 * into memory at once.
 *
 * This only ever deletes FILES. A row whose file is already gone is a
 * different (also real) problem, and removing that row here would hide it
 * instead of reporting it.
 */
class PruneOrphanAttachments extends Command
{
    protected $signature = 'attachments:prune-orphans {--dry-run : فقط گزارش می‌دهد، بدون حذف فایل}';

    protected $description = 'Delete attachment files on the public disk that no attachments row references';

    /**
     * Files per walk chunk. Mirrors `data:archive`.
     */
    private const CHUNK = 1000;

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Scanning storage/app/public/attachments for unreferenced files...');

        $orphans = [];
        $referenced = 0;

        foreach ($disk->allFiles(self::DIRECTORY) as $file) {
            $path = self::path($file);

            if (Attachment::query()->where('file_path', $path)->exists()) {
                $referenced++;

                continue;
            }

            $orphans[] = $path;
        }

        $this->line("  Found {$referenced} referenced file(s).");
        $this->line('  Found '.count($orphans).' unreferenced file(s).');

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
            // The public disk sets `throw => false`, so a file removed between
            // the scan and here is skipped rather than aborting the run.
            $disk->delete($chunk);
            $deleted += count($chunk);
        }

        $this->line("  Deleted {$deleted} file(s).");
        $this->info('Orphan attachment prune complete.');

        return 0;
    }

    /**
     * The directory the upload paths store into, on the `public` disk.
     */
    private const DIRECTORY = 'attachments';

    /**
     * Normalise a disk entry to the slash-separated relative path that
     * `attachments.file_path` stores (e.g. `attachments/2026/01/report.pdf`).
     */
    private static function path(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }
}
