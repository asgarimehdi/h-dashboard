<?php

namespace App\Console\Commands;

use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Issue #1759: a failed import keeps its stored sheet, because that sheet is
 * the only artifact that shows which rows the run was given. This daily sweep
 * bounds how long it lives — the "keep, then sweep" pattern `#1692` already
 * uses for backup archives.
 *
 * Modelled on {@see PruneBackupArchives}: the same `--days`/`--dry-run`
 * convention, the same "report, then act" output shape, and the same chunked
 * delete. It deliberately has no extension filter: `imports/` only ever holds
 * queued sheets, so an unrecognised file in there is by definition an orphan.
 */
class PruneImportSheets extends Command
{
    protected $signature = 'imports:prune-sheets
                            {--days=7 : فقط شیت‌های قدیمی‌تر از این تعداد روز حذف می‌شوند}
                            {--dry-run : فقط گزارش می‌دهد، چیزی حذف نمی‌شود}';

    protected $description = 'Delete failed import sheets older than the retention window';

    /**
     * The directory the import uploads store into, on the default disk.
     */
    private const DIRECTORY = 'imports';

    /**
     * Files per delete chunk. Mirrors `attachments:prune-orphans`.
     */
    private const CHUNK = 1000;

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            $this->error('--days باید عدد صحیح و دست‌کم ۱ باشد.');

            return self::FAILURE;
        }

        // The jobs write to the default disk (`store('imports')`), so the sweep
        // must read the same one rather than pinning `local` (#1759).
        $disk = Storage::disk(config('filesystems.default'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days)->getTimestamp();

        $stale = [];
        foreach ($disk->allFiles(self::DIRECTORY) as $path) {
            // Strict `>=`: a sheet exactly on the cutoff is still inside the
            // window and is kept.
            if ($disk->lastModified($path) >= $cutoff) {
                continue;
            }

            $stale[] = $path;
        }

        $verb = $dryRun ? 'قابل حذف' : 'حذف شد';
        $this->info(sprintf('%d شیت ایمپورت %s (پنجره: %d روز).', count($stale), $verb, $days));

        if ($dryRun) {
            foreach ($stale as $path) {
                $this->line("  {$path}");
            }

            return self::SUCCESS;
        }

        // An empty sweep is not an event worth auditing.
        if ($stale === []) {
            return self::SUCCESS;
        }

        foreach (array_chunk($stale, self::CHUNK) as $chunk) {
            $disk->delete($chunk);
        }

        ActivityLogService::logAs(
            null,
            'import',
            null,
            sprintf('%d شیت ایمپورت قدیمی‌تر از %d روز حذف شد.', count($stale), $days),
        );

        return self::SUCCESS;
    }
}
