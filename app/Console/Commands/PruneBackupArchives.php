<?php

namespace App\Console\Commands;

use App\Services\ActivityLogService;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

/**
 * Issue #1692: the download deletes its archive after sending, but a killed
 * worker (OOM, SIGKILL, a deploy restart) skips Symfony's `finally` block and
 * leaves a credential-bearing zip behind. This daily sweep is the safety net.
 */
class PruneBackupArchives extends Command
{
    protected $signature = 'backup:prune-archives
                            {--days=1 : فقط آرشیوهای قدیمی‌تر از این تعداد روز حذف می‌شوند}
                            {--dry-run : فقط گزارش می‌دهد، چیزی حذف نمی‌شود}';

    protected $description = 'Delete leftover backup archives older than the retention window';

    public function handle(BackupService $backups): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            $this->error('--days باید عدد صحیح و دست‌کم ۱ باشد.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $removed = $backups->pruneArchives($days, $dryRun);

        $verb = $dryRun ? 'قابل حذف' : 'حذف شد';
        $this->info(sprintf('%d آرشیو بک‌آپ %s (پنجره: %d روز).', count($removed), $verb, $days));

        if ($dryRun) {
            foreach ($removed as $name) {
                $this->line("  {$name}");
            }
        }

        // A dry run never writes an audit row, and an empty sweep is not an event.
        if (! $dryRun && $removed !== []) {
            ActivityLogService::logAs(
                null,
                'backup',
                null,
                sprintf('%d آرشیو بک‌آپ قدیمی‌تر از %d روز حذف شد.', count($removed), $days),
            );
        }

        return self::SUCCESS;
    }
}
