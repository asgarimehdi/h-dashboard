<?php

namespace App\Jobs;

use App\Imports\HardwareImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Confirmed hardware imports (#1167).
 *
 * The write path used to run inside the Livewire request: a 5,000-row sheet
 * meant ~5,000 commits, ~5,000 audit inserts and ~20,000 cache increments while
 * the user waited. Preview stays synchronous (it only parses and diffs); the
 * confirmed write is queued.
 *
 * The job carries everything it needs: the stored upload path (the Livewire
 * temp file is gone by the time a worker picks this up), the compare key, the
 * per-row actions, the scope captured at dispatch time (a worker has no
 * session, so `accessibleUnitIds()` would resolve to `[]`) and the actor
 * context for the audit trail.
 */
#[Connection('redis-reports')]
class ImportHardwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A large sheet is CPU + DB bound. The default 60s worker timeout is not
     * enough for tens of thousands of rows.
     */
    public int $timeout = 600;

    /**
     * Exactly one attempt. A failed run may have written an earlier chunk;
     * replaying it would duplicate the rows that had no pre-existing match
     * (and `hardwares` has no unique constraint on the compare key).
     */
    public int $tries = 1;

    /**
     * @param  array<string, string>  $selectedActions  `row_{n}` => create|update|skip
     * @param  array<int, int>  $accessibleUnitIds  Scope captured from the dispatcher.
     */
    public function __construct(
        public string $path,
        public string $compareKey,
        public array $selectedActions,
        public array $accessibleUnitIds,
        public ?int $userId = null,
        public ?string $source = 'import',
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    public function handle(): void
    {
        $import = new HardwareImport;
        $import->setCompareKey($this->compareKey);
        $import->setAccessibleUnitIds($this->accessibleUnitIds);
        $import->setSelectedActions($this->selectedActions);
        $import->setAuditContext($this->userId, $this->source ?? 'import', $this->ip, $this->userAgent);

        Excel::import($import, Storage::path($this->path));

        // Delete only after a successful run; a failed import keeps the stored
        // sheet for inspection/diagnosis.
        Storage::delete($this->path);

        $results = $import->getImportResults();

        Log::info('ImportHardwareJob: completed', [
            'path' => $this->path,
            'created' => $results['created'],
            'updated' => $results['updated'],
            'skipped' => $results['skipped'],
            'errors' => count($results['errors'] ?? []),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        // #1194: drop the stored upload on failure so a failed run does not
        // leave an orphaned file behind; the job never retries ($tries = 1).
        Storage::delete($this->path);

        Log::error('ImportHardwareJob failed', [
            'path' => $this->path,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
