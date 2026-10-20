<?php

namespace App\Imports;

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PersonImport implements ToCollection, WithCustomCsvSettings, WithHeadingRow
{
    /**
     * Same chunking contract as HardwareImport (#1167): each statement stays
     * under PostgreSQL's bind-parameter ceiling and a 5,000-row sheet becomes
     * ten writes instead of 5,000.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Fields Person::boot() folds on save; bulk writes must reproduce it.
     */
    private const NORMALIZED_FIELDS = ['f_name', 'l_name'];

    private array $accessibleUnitIds = [];

    private array $existingRecords = [];

    private string $compareKey = 'n_code'; // always match by n_code for persons

    private array $selectedActions = [];

    /** @var list<array{data: array<string, mixed>}> */
    private array $pendingCreates = [];

    /** @var list<array{id: int, data: array<string, mixed>}> */
    private array $pendingUpdates = [];

    /** Did any queued write create a person or move one between units? */
    private bool $touchesMapScope = false;

    // Pre-loaded related models to avoid N+1 queries
    private array $units = [];

    private array $tahsils = [];

    private array $estekhdams = [];

    private array $radifs = [];

    private array $semats = [];

    private array $importResults = [
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

    public function setCompareKey(string $key): void
    {
        $this->compareKey = $key;
    }

    public function setAccessibleUnitIds(array $ids): void
    {
        $this->accessibleUnitIds = $ids;
    }

    public function setSelectedActions(array $actions): void
    {
        $this->selectedActions = $actions;
    }

    public function collection(Collection $rows): void
    {
        // Map once: the mapped rows drive the lookup window and both passes.
        $dataByRow = [];

        foreach ($rows as $index => $row) {
            $dataByRow[$index + 2] = $this->mapRowToData($row->toArray());
        }

        // #1167: collect every cache bump into one flush per namespace.
        app(CacheInvalidationServiceInterface::class)->batch(function () use ($dataByRow) {
            // Pre-load existing person records by n_code for comparison
            $this->loadExistingRecords($dataByRow);

            // Pre-load all related reference data to avoid N+1 queries
            $this->loadReferenceData();

            // First pass: build preview data
            foreach ($dataByRow as $rowNumber => $data) {
                $this->buildPreview($data, $rowNumber);
            }

            // Second pass: apply selected actions if provided
            if (! empty($this->selectedActions)) {
                // Reset counters since they were incremented during preview pass
                $this->importResults['created'] = 0;
                $this->importResults['updated'] = 0;
                $this->importResults['skipped'] = 0;
                $this->importResults['changes'] = [];

                foreach ($dataByRow as $rowNumber => $data) {
                    $this->applySelectedAction($data, $rowNumber);
                }

                $this->flushPendingWrites();
            }
        });
    }

    /**
     * #1167: restrict the hydration to the national codes actually present in
     * the sheet. The scope predicate stays unconditional (issue #839).
     *
     * @param  array<int, array<string, mixed>>  $rowsData
     */
    private function loadExistingRecords(array $rowsData): void
    {
        $nCodes = [];
        foreach ($rowsData as $data) {
            if (! empty($data['n_code'])) {
                $nCodes[$data['n_code']] = true;
            }
        }

        $query = Person::query();

        // Apply organizational scope — UNCONDITIONAL (issue #839 / Plan 50).
        // `[]` is a legitimate `AccessService` output, so an emptiness guard
        // here would drop the predicate and put the whole org in scope.
        // An empty array compiles to `0 = 1`, which is fail-closed by
        // construction. Never re-add `if (! empty($this->accessibleUnitIds))`.
        $query->whereIn('u_id', $this->accessibleUnitIds)
            ->whereIn('n_code', array_keys($nCodes));

        // NOTE: 'id' MUST be selected — records are later updated via these
        // models; without the PK, update() issues WHERE id IS NULL and
        // silently persists nothing while counters still report success.
        $persons = $query->get(['id', 'n_code', 'f_name', 'l_name', 't_id', 'e_id', 'r_id', 's_id', 'u_id', 'phone']);

        foreach ($persons as $person) {
            if ($person->n_code) {
                $this->existingRecords['n_code'][$person->n_code] = $person;
            }
        }
    }

    /**
     * Pre-load all reference data (units, tahsils, estekhdams, radifs, semats)
     * to avoid N+1 queries during import
     */
    private function loadReferenceData(): void
    {
        // Load units
        // UNCONDITIONAL scope (issue #839): an empty scope loads NO unit, so the
        // "unit not found" check downstream fires for every row instead of
        // accepting an org-wide import.
        $unitQuery = Unit::query()->whereIn('id', $this->accessibleUnitIds);
        foreach ($unitQuery->get(['id', 'name']) as $unit) {
            $this->units[$unit->id] = $unit;
        }

        // Load tahsils (education levels)
        foreach (Tahsil::get(['id', 'name']) as $tahsil) {
            $this->tahsils[$tahsil->id] = $tahsil;
        }

        // Load estekhdams (employment types)
        foreach (Estekhdam::get(['id', 'name']) as $estekhdam) {
            $this->estekhdams[$estekhdam->id] = $estekhdam;
        }

        // Load radifs (ranks)
        foreach (Radif::get(['id', 'name']) as $radif) {
            $this->radifs[$radif->id] = $radif;
        }

        // Load semats (job titles)
        foreach (Semat::get(['id', 'name']) as $semat) {
            $this->semats[$semat->id] = $semat;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applySelectedAction(array $data, int $rowNumber): void
    {
        // Check if this row has a selected action
        $actionKey = "row_{$rowNumber}";
        if (! isset($this->selectedActions[$actionKey])) {
            return;
        }

        $action = $this->selectedActions[$actionKey];
        if ($action === 'skip') {
            return;
        }

        $this->processRow($data, $rowNumber, $action, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildPreview(array $data, int $rowNumber): void
    {
        // Validate required fields
        if (empty($data['n_code']) || empty($data['f_name']) || empty($data['l_name'])) {
            $this->importResults['preview'][] = [
                'row' => $rowNumber,
                'status' => 'error',
                'message' => 'فیلدهای اجباری n_code، f_name و l_name خالی هستند',
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Verify unit exists and is in accessible units (using pre-loaded data)
        if (! empty($data['u_id'])) {
            $unit = $this->units[$data['u_id']] ?? null;
            if (! $unit) {
                $this->importResults['preview'][] = [
                    'row' => $rowNumber,
                    'status' => 'error',
                    'message' => "واحد با شناسه {$data['u_id']} یافت نشد",
                    'data' => $data,
                ];
                $this->importResults['skipped']++;

                return;
            }

            // UNCONDITIONAL membership test (issue #839): the `! empty(...)`
            // conjunct made this `! empty([]) && ...` = false, i.e. ACCEPT.
            // Under an empty scope `$this->units` is empty, so the check above
            // already rejected the row — this is a defence-in-depth backstop.
            if (! in_array($unit->id, $this->accessibleUnitIds)) {
                $this->importResults['preview'][] = [
                    'row' => $rowNumber,
                    'status' => 'error',
                    'message' => "واحد {$unit->name} در واحدهای قابل دسترس شما نیست",
                    'data' => $data,
                ];
                $this->importResults['skipped']++;

                return;
            }
        } else {
            $this->importResults['preview'][] = [
                'row' => $rowNumber,
                'status' => 'error',
                'message' => 'شناسه واحد (u_id) خالی است',
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Verify related models exist (using pre-loaded data)
        $validationError = $this->validateRelatedModels($data);
        if ($validationError) {
            $this->importResults['preview'][] = [
                'row' => $rowNumber,
                'status' => 'error',
                'message' => $validationError,
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Find existing record by n_code
        $existing = $this->findExistingRecord($data);
        $matchKey = $existing ? ($existing['key'] ?? 'unknown') : null;

        if ($existing) {
            $changes = $this->detectChanges($existing['record'], $data);

            if (! empty($changes)) {
                $this->importResults['preview'][] = [
                    'row' => $rowNumber,
                    'status' => 'update',
                    'n_code' => $existing['record']->n_code,
                    'match_key' => $matchKey,
                    'changes' => $changes,
                    'person' => $existing['record']->f_name.' '.$existing['record']->l_name,
                    'data' => $data,
                ];
                $this->importResults['updated']++;
            } else {
                $this->importResults['preview'][] = [
                    'row' => $rowNumber,
                    'status' => 'unchanged',
                    'n_code' => $existing['record']->n_code,
                    'match_key' => $matchKey,
                    'message' => 'بدون تغییر',
                    'person' => $existing['record']->f_name.' '.$existing['record']->l_name,
                    'data' => $data,
                ];
                $this->importResults['skipped']++;
            }
        } else {
            $this->importResults['preview'][] = [
                'row' => $rowNumber,
                'status' => 'create',
                'n_code' => $data['n_code'],
                'person' => $data['f_name'].' '.$data['l_name'],
                'data' => $data,
            ];
            $this->importResults['created']++;
        }
    }

    private function findExistingRecord(array $data): ?array
    {
        $existing = null;
        $matchKey = null;

        if (! empty($data['n_code']) && isset($this->existingRecords['n_code'][$data['n_code']])) {
            $existing = $this->existingRecords['n_code'][$data['n_code']];
            $matchKey = 'n_code';
        }

        if ($existing) {
            return ['record' => $existing, 'key' => $matchKey];
        }

        return null;
    }

    private function mapRowToData(array $row): array
    {
        // Expected CSV headers: n_code, f_name, l_name, t_id, e_id, r_id, s_id, u_id, phone
        return [
            'n_code' => $this->clean($row['n_code'] ?? null),
            'f_name' => $this->clean($row['f_name'] ?? null),
            'l_name' => $this->clean($row['l_name'] ?? null),
            't_id' => $this->parseInt($row['t_id'] ?? null),
            'e_id' => $this->parseInt($row['e_id'] ?? null),
            'r_id' => $this->parseInt($row['r_id'] ?? null),
            's_id' => $this->parseInt($row['s_id'] ?? null),
            'u_id' => $this->parseInt($row['u_id'] ?? null),
            'phone' => $this->clean($row['phone'] ?? null),
        ];
    }

    private function validateRelatedModels(array $data): ?string
    {
        if (! empty($data['t_id']) && ! isset($this->tahsils[$data['t_id']])) {
            return "تحصیلات با شناسه {$data['t_id']} یافت نشد";
        }
        if (! empty($data['e_id']) && ! isset($this->estekhdams[$data['e_id']])) {
            return "نوع استخدام با شناسه {$data['e_id']} یافت نشد";
        }
        if (! empty($data['r_id']) && ! isset($this->radifs[$data['r_id']])) {
            return "ردیف سازمانی با شناسه {$data['r_id']} یافت نشد";
        }
        if (! empty($data['s_id']) && ! isset($this->semats[$data['s_id']])) {
            return "سمت با شناسه {$data['s_id']} یافت نشد";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function processRow(array $data, int $rowNumber, string $action = 'auto', bool $isConfirmation = false): void
    {
        // Validate required fields
        if (empty($data['n_code']) || empty($data['f_name']) || empty($data['l_name'])) {
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => 'Missing required fields: n_code, f_name, l_name are required',
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Verify unit exists and is in accessible units (using pre-loaded data)
        $unit = $this->units[$data['u_id']] ?? null;
        if (! $unit) {
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => "Unit with id {$data['u_id']} not found",
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // UNCONDITIONAL membership test (issue #839): the `! empty(...)` conjunct
        // made this `! empty([]) && ...` = false, i.e. ACCEPT the row. Under an
        // empty scope `$this->units` is empty, so the "not found" check above
        // already rejected it — this is a defence-in-depth backstop.
        if (! in_array($unit->id, $this->accessibleUnitIds)) {
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => "Unit {$data['u_id']} is not in your accessible units",
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Verify related models exist (using pre-loaded data)
        $validationError = $this->validateRelatedModels($data);
        if ($validationError) {
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => $validationError,
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Find existing record by n_code
        $existing = $this->findExistingRecord($data);
        $matchKey = $existing ? ($existing['key'] ?? 'unknown') : null;

        if ($existing) {
            // Check for changes
            $changes = $this->detectChanges($existing['record'], $data);

            if (! empty($changes)) {
                $this->importResults['changes'][] = [
                    'row' => $rowNumber,
                    'n_code' => $existing['record']->n_code,
                    'match_key' => $matchKey,
                    'changes' => $changes,
                ];

                // Queue the write and mirror it in memory, so a second sheet
                // row matching the same person sees the new values the way the
                // old row-by-row `update()` did.
                if ($action === 'update' || $action === 'auto') {
                    $writeData = $this->normalizeWriteData($data);

                    if ((int) $existing['record']->u_id !== (int) $writeData['u_id']) {
                        $this->touchesMapScope = true;
                    }

                    $this->pendingUpdates[] = [
                        'id' => $existing['record']->id,
                        'data' => $writeData,
                    ];

                    $existing['record']->fill($writeData);

                    $this->importResults['updated']++;
                }
            } else {
                $this->importResults['skipped']++;
            }
        } else {
            // Create new record
            if ($action === 'create' || $action === 'auto') {
                $writeData = $this->normalizeWriteData($data);

                $this->pendingCreates[] = ['data' => $writeData];
                $this->touchesMapScope = true;

                $this->importResults['created']++;
            }
        }
    }

    /**
     * Reproduce Person::boot()'s `saving` normalization on the bulk path.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeWriteData(array $data): array
    {
        foreach (self::NORMALIZED_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $data[$field] = Person::normalizeForSearch($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Write the queued rows in chunks, then bump the cache namespaces once.
     *
     * Bulk writes bypass `saved`, so the two unconditional bumps and the two
     * u_id-dependent ones (maps/gis) are reproduced here; the surrounding
     * `batch()` collapses repeats into one increment per namespace.
     */
    private function flushPendingWrites(): void
    {
        $wrote = ! empty($this->pendingCreates) || ! empty($this->pendingUpdates);

        // #1194: two sheet rows can carry the same NEW n_code. `persons.n_code`
        // is unique, so a plain INSERT of both rows trips
        // `persons_n_code_unique` and aborts the whole chunk — the import then
        // rolls back with nothing written and, on the queued path, only the
        // worker sees the error. Dedupe by n_code (last write wins), exactly
        // like the update path below.
        $dedupedCreates = [];
        foreach ($this->pendingCreates as $item) {
            $nCode = $item['data']['n_code'] ?? null;

            if (! is_string($nCode) || $nCode === '') {
                // A row without a national code is not a create candidate
                // anyway (processRow rejects it), so keep it verbatim rather
                // than collapsing unrelated rows onto one key.
                $dedupedCreates[] = $item;

                continue;
            }

            $dedupedCreates['n_code:'.$nCode] = $item;
        }

        $droppedCreates = count($this->pendingCreates) - count($dedupedCreates);

        if ($droppedCreates > 0) {
            $this->importResults['created'] = max(0, $this->importResults['created'] - $droppedCreates);
        }

        $this->pendingCreates = array_values($dedupedCreates);

        foreach (array_chunk($this->pendingCreates, self::CHUNK_SIZE) as $chunk) {
            $now = now();
            $rows = [];

            foreach ($chunk as $item) {
                $rows[] = array_merge($item['data'], [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Person::query()->insert($rows);
        }

        // #1194: two sheet rows can resolve to the same person (n_code is the
        // compare key, but a sheet may repeat it). PostgreSQL rejects an upsert
        // whose VALUES list contains the same conflict target twice.
        $dedupedUpdates = [];
        foreach ($this->pendingUpdates as $item) {
            $dedupedUpdates[$item['id']] = $item;
        }
        $this->pendingUpdates = array_values($dedupedUpdates);

        foreach (array_chunk($this->pendingUpdates, self::CHUNK_SIZE) as $chunk) {
            $now = now();
            $rows = [];

            foreach ($chunk as $item) {
                $rows[] = array_merge($item['data'], [
                    'id' => $item['id'],
                    'updated_at' => $now,
                ]);
            }

            // `id` is the primary key — a legal ON CONFLICT target.
            Person::query()->upsert(
                $rows,
                ['id'],
                array_merge($this->updateColumns(), ['updated_at'])
            );
        }

        $this->pendingCreates = [];
        $this->pendingUpdates = [];

        if (! $wrote) {
            return;
        }

        $cache = app(CacheInvalidationServiceInterface::class);
        $cache->increment('hr_stats');
        $cache->increment('dashboard');

        if ($this->touchesMapScope) {
            $cache->increment('maps');
            $cache->increment('gis');
        }
    }

    /**
     * @return list<string>
     */
    private function updateColumns(): array
    {
        return ['n_code', 'f_name', 'l_name', 't_id', 'e_id', 'r_id', 's_id', 'u_id', 'phone'];
    }

    private function detectChanges(Person $existing, array $newData): array
    {
        $changes = [];
        $compareFields = ['f_name', 'l_name', 't_id', 'e_id', 'r_id', 's_id', 'u_id', 'phone'];

        foreach ($compareFields as $field) {
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

    private function normalizeForComparison($value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N') {
            return null;
        }

        return trim((string) $value);
    }

    private function parseInt($value): ?int
    {
        if ($value === null || $value === '' || $value === '\\N') {
            return null;
        }
        $val = trim((string) $value);
        if ($val === '' || $val === '\\N') {
            return null;
        }

        return (int) $val;
    }

    private function clean($value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N' || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    public function getImportResults(): array
    {
        return $this->importResults;
    }

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
}
