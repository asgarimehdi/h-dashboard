<?php

namespace App\Jobs;

use App\Imports\PersonImport;
use App\Support\ImportStatus;
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
 * Confirmed personnel imports (#1167) — the PersonImport counterpart of
 * {@see ImportHardwareJob}, without the audit half (persons have no audit
 * observer).
 */
#[Connection('redis-reports')]
class ImportPersonsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /**
     * One attempt, for the same reason as ImportHardwareJob: a partially
     * applied import must not be replayed.
     */
    public int $tries = 1;

    /**
     * #1194: one import per user at a time — a double click would otherwise
     * queue the same sheet twice, and both jobs would compare against the
     * pre-import state. The dispatcher takes an atomic claim (see
     * {@see ImportStatus::claim()}) before queueing, so the second click is
     * rejected with a message instead of silently duplicating rows.
     *
     * @param  array<string, string>  $selectedActions  `row_{n}` => create|update|skip
     * @param  array<int, int>  $accessibleUnitIds  Scope captured from the dispatcher.
     * @param  string|null  $statusToken  Identifies this dispatch to the browser
     *                                    (see {@see ImportStatus}).
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
        public ?string $statusToken = null,
    ) {}

    public function handle(): void
    {
        $import = new PersonImport;
        $import->setCompareKey($this->compareKey);
        $import->setAccessibleUnitIds($this->accessibleUnitIds);
        $import->setSelectedActions($this->selectedActions);

        Excel::import($import, Storage::path($this->path));

        // Delete only after a successful run; a failed import keeps the stored
        // sheet for inspection/diagnosis.
        Storage::delete($this->path);

        $results = $import->getImportResults();

        // #1194: publish the outcome so the browser learns the rows exist.
        if ($this->userId !== null) {
            if ($this->statusToken !== null) {
                ImportStatus::publish($this->userId, $this->statusToken, $results);
            }

            ImportStatus::release($this->userId);
        }

        Log::info('ImportPersonsJob: completed', [
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

        // The browser was told the import is running, so it has to learn that
        // it failed.
        if ($this->userId !== null) {
            if ($this->statusToken !== null) {
                ImportStatus::publishFailure($this->userId, $this->statusToken, $exception->getMessage());
            }

            ImportStatus::release($this->userId);
        }

        Log::error('ImportPersonsJob failed', [
            'path' => $this->path,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
