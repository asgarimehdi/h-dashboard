<?php

namespace App\Imports;

use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * #1813: the orchestration/bookkeeping half of the two sheet importers.
 *
 * HardwareImport and PersonImport differ in what a row means and how it is
 * validated, but the preview → confirm → chunked-write pipeline is identical.
 * This class owns that pipeline (and the shared state it needs); subclasses
 * supply the entity-specific hooks only. Every hook is `protected` on purpose:
 * two tests reflect into the importers (`normalizeForComparison`,
 * `updateColumns`, `detectChanges`), and a `private` move would break them.
 */
abstract class AbstractImportPipeline
{
    /**
     * Writes are grouped per chunk. 500 keeps each statement well inside
     * PostgreSQL's 65535 bind-parameter ceiling while collapsing a 5,000-row
     * sheet from 5,000 commits to 10 (#1167).
     */
    protected const CHUNK_SIZE = 500;

    protected array $accessibleUnitIds = [];

    protected array $existingRecords = [];

    protected array $selectedActions = [];

    /**
     * #1209: sheet rows the preview rejected, keyed by spreadsheet row number
     * with the preview's own error message. The confirm pass uses this to
     * carry every rejected row — with the same message — into the published
     * result instead of dropping it.
     *
     * @var array<int, string>
     */
    protected array $previewErrorMessages = [];

    /** @var list<array<string, mixed>> */
    protected array $pendingCreates = [];

    /** @var list<array<string, mixed>> */
    protected array $pendingUpdates = [];

    protected array $importResults = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
        'changes' => [],
        'preview' => [],
    ];

    public function __construct()
    {
        $this->accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();
    }

    public function setAccessibleUnitIds(array $ids): void
    {
        $this->accessibleUnitIds = $ids;
    }

    public function setSelectedActions(array $actions): void
    {
        $this->selectedActions = $actions;
    }

    /**
     * Map once, then run the preview pass and (when the operator supplied
     * actions) the confirm pass over the same mapped rows. #1167: every cache
     * bump is collected into one flush per namespace by the surrounding batch.
     *
     * @param  Collection<array-key, mixed>  $rows
     */
    public function collection(Collection $rows): void
    {
        $dataByRow = [];

        foreach ($rows as $index => $row) {
            $dataByRow[$index + 2] = $this->mapRowToData($row->toArray());
        }

        app(CacheInvalidationServiceInterface::class)->batch(function () use ($dataByRow) {
            $this->loadExistingRecords($dataByRow);

            // First pass: build preview data
            foreach ($dataByRow as $rowNumber => $mapped) {
                $this->buildPreview($mapped['data'], $rowNumber, $mapped['invalid']);
            }

            // Second pass: apply selected actions if provided
            if (! empty($this->selectedActions)) {
                // Reset counters since they were incremented during preview pass.
                // #1209: `errors` is reset too — the confirm pass re-derives
                // every preview-rejected row below (with the preview's own
                // message), instead of letting the buckets double up.
                $this->importResults['created'] = 0;
                $this->importResults['updated'] = 0;
                $this->importResults['skipped'] = 0;
                $this->importResults['changes'] = [];
                $this->importResults['errors'] = [];

                foreach ($dataByRow as $rowNumber => $mapped) {
                    $this->applySelectedAction($mapped['data'], $rowNumber);
                }

                $this->flushPendingWrites();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function applySelectedAction(array $data, int $rowNumber): void
    {
        // #1209: a row the preview rejected is never written, and the
        // operator's action map cannot resurrect it. Count it as skipped and
        // carry the preview's own error message into the confirm result, so
        // the published error list matches what the operator approved.
        $previewMessage = $this->previewErrorMessages[$rowNumber] ?? null;
        if (is_string($previewMessage)) {
            $this->importResults['skipped']++;
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => $previewMessage,
                'data' => $data,
            ];

            return;
        }

        // Check if this row has a selected action. #1209: a row the operator
        // skipped — or that never received an action — must still be counted,
        // not dropped silently.
        $actionKey = "row_{$rowNumber}";
        if (! isset($this->selectedActions[$actionKey]) || $this->selectedActions[$actionKey] === 'skip') {
            $this->importResults['skipped']++;

            return;
        }

        $this->processRow($data, $rowNumber, $this->selectedActions[$actionKey], true);
    }

    /**
     * #1209: record one sheet row the preview rejected.
     *
     * The row is written twice on purpose: the `preview` entry drives the
     * operator-facing preview table, the `errors` entry publishes the same
     * rejection in the result (both passes read the same counter buckets),
     * and the message is remembered so the confirm pass can re-publish it
     * verbatim.
     *
     * @param  array<string, mixed>  $data
     */
    protected function recordRejectedRow(int $rowNumber, string $message, array $data): void
    {
        $this->importResults['preview'][] = [
            'row' => $rowNumber,
            'status' => 'error',
            'message' => $message,
            'data' => $data,
        ];
        $this->importResults['errors'][] = [
            'row' => $rowNumber,
            'error' => $message,
            'data' => $data,
        ];
        $this->previewErrorMessages[$rowNumber] = $message;
    }

    protected function clean($value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N' || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Compare the columns the upsert actually writes (#1758), after applying
     * the same folding the model's `saving` hook would.
     *
     * @param  array<string, mixed>  $newData
     * @return array<string, mixed>
     */
    protected function detectChanges(Model $existing, array $newData): array
    {
        $newData = $this->normalizeWriteData($newData);

        $changes = [];

        foreach ($this->compareFields() as $field) {
            $oldValue = $existing->$field;
            $newValue = $newData[$field] ?? null;

            // Normalize for comparison
            $oldNormalized = $this->normalizeForComparison($oldValue);
            $newNormalized = $this->normalizeForComparison($newValue);

            if ($oldNormalized !== $newNormalized) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    /**
     * Apply the model's `saving` normalization to every written value.
     *
     * A bulk INSERT/UPDATE never dispatches `saving`, so skipping this would
     * persist Arabic Yeh/Kaf and Persian digits exactly as typed — a regression
     * against every other write path in the app (#1167).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeWriteData(array $data): array
    {
        foreach ($this->normalizedFields() as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $data[$field] = $this->normalizeSearchValue($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Dedupe the queued creates, write both queues in chunks, then let the
     * entity bump its cache namespaces.
     *
     * The `$hadQueued` / `$hasAfterDedupe` pair exists because the two
     * importers deliberately disagree: Person bumps when anything was queued
     * (before dedupe), Hardware only when a write survived dedupe. Subclasses
     * choose via `shouldBumpCaches()`; do not unify it.
     */
    protected function flushPendingWrites(): void
    {
        $hadQueued = ! empty($this->pendingCreates) || ! empty($this->pendingUpdates);

        $this->dedupePendingCreates();
        $hasAfterDedupe = ! empty($this->pendingCreates) || ! empty($this->pendingUpdates);

        foreach (array_chunk($this->pendingCreates, static::CHUNK_SIZE) as $chunk) {
            $this->insertCreateChunk($chunk);
        }

        // #1194: two sheet rows can resolve to the same existing id. PostgreSQL
        // rejects an upsert whose VALUES list contains the same conflict target
        // twice, so collapse duplicate ids here — last write wins, matching
        // row-by-row semantics.
        $dedupedUpdates = [];
        foreach ($this->pendingUpdates as $item) {
            $dedupedUpdates[$item['id']] = $item;
        }
        $this->pendingUpdates = array_values($dedupedUpdates);

        foreach (array_chunk($this->pendingUpdates, static::CHUNK_SIZE) as $chunk) {
            $this->updateChunk($chunk);
        }

        $this->pendingCreates = [];
        $this->pendingUpdates = [];

        if ($this->shouldBumpCaches($hadQueued, $hasAfterDedupe)) {
            $this->afterFlush();
        }
    }

    public function getImportResults(): array
    {
        return $this->importResults;
    }

    /**
     * @return array<string, string|bool>
     */
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => "\t",
            'enclosure' => '"',
            'escape_character' => '\\',
            'contiguous' => false,
            'input_encoding' => 'UTF-8',
        ];
    }

    /**
     * Map one sheet row to the data array plus any per-row invalidity the
     * preview needs (Person: bad lookup ids; Hardware: an unparseable date).
     *
     * @param  array<string, mixed>  $row
     * @return array{data: array<string, mixed>, invalid: array<string, string>}
     */
    abstract protected function mapRowToData(array $row): array;

    /**
     * @param  array<int, array{data: array<string, mixed>, invalid: array<string, string>}>  $dataByRow
     */
    abstract protected function loadExistingRecords(array $dataByRow): void;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $invalid
     */
    abstract protected function buildPreview(array $data, int $rowNumber, array $invalid = []): void;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function processRow(array $data, int $rowNumber, string $action = 'auto', bool $isConfirmation = false): void;

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: Model, key: string|null}|null
     */
    abstract protected function findExistingRecord(array $data): ?array;

    /**
     * @return list<string>
     */
    abstract protected function compareFields(): array;

    /**
     * @return list<string>
     */
    abstract protected function normalizedFields(): array;

    abstract protected function normalizeSearchValue(string $value): string;

    /**
     * @param  mixed  $value
     */
    abstract protected function normalizeForComparison($value): ?string;

    /**
     * @return list<string>
     */
    abstract protected function updateColumns(): array;

    abstract protected function dedupePendingCreates(): void;

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    abstract protected function insertCreateChunk(array $chunk): void;

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    abstract protected function updateChunk(array $chunk): void;

    abstract protected function shouldBumpCaches(bool $hadQueued, bool $hasAfterDedupe): bool;

    abstract protected function afterFlush(): void;
}
