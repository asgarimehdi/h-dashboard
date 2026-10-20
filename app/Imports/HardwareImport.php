<?php

namespace App\Imports;

use App\Exports\Concerns\FormatsJalaliDates;
use App\Models\Hardware;
use App\Models\Person;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use App\Services\HardwareBulkAuditWriter;
use App\Support\HardwareAuditChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class HardwareImport implements ToCollection, WithCustomCsvSettings, WithHeadingRow
{
    use FormatsJalaliDates;

    /**
     * Writes are grouped per chunk. 500 keeps each statement well inside
     * PostgreSQL's 65535 bind-parameter ceiling (19 columns × 500 = 9500)
     * while collapsing a 5,000-row sheet from 5,000 commits to 10.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Fields the model's `saving` hook folds (Hardware::boot()).
     * Bulk writes bypass model events, so the import must apply the same
     * normalization itself — otherwise Persian variants land un-folded.
     */
    private const NORMALIZED_FIELDS = [
        'pc_name', 'type', 'os', 'cpu', 'ram', 'hdd', 'net_type', 'switch',
        'vlan', 'motherboard', 'comments',
    ];

    private array $accessibleUnitIds = [];

    private array $existingRecords = [];

    private array $existingPersons = [];

    private string $compareKey = 'both'; // 'pc_name', 'mac', 'both'

    private array $selectedActions = [];

    /** @var list<array{data: array<string, mixed>, changes: array<string, mixed>}> */
    private array $pendingCreates = [];

    /** @var list<array{id: int, data: array<string, mixed>, changes: array<string, mixed>}> */
    private array $pendingUpdates = [];

    private ?int $auditUserId = null;

    private ?string $auditSource = null;

    private ?string $auditIp = null;

    private ?string $auditUserAgent = null;

    private bool $auditContextExplicit = false;

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

    /**
     * #1167: the queued import runs with no authenticated user and no import
     * route, but every audit row must still name the actor and the source it
     * came from. The dispatcher captures this context and the job replays it
     * here. The explicit flag matters: `$userId = null` is a legitimate
     * "no actor" answer and must not silently fall back to `Auth::id()`
     * (which, inside a worker, is also null — but in a test process it is not).
     */
    public function setAuditContext(?int $userId, ?string $source, ?string $ip, ?string $userAgent): void
    {
        $this->auditUserId = $userId;
        $this->auditSource = $source;
        $this->auditIp = $ip;
        $this->auditUserAgent = $userAgent;
        $this->auditContextExplicit = true;
    }

    public function collection(Collection $rows): void
    {
        // Map once, then use the mapped rows for both the lookup-key window and
        // both passes. `rowNumber` is the spreadsheet line (header + 1-indexed),
        // which is also the `row_{n}` key the preview sends back.
        $dataByRow = [];

        foreach ($rows as $index => $row) {
            $dataByRow[$index + 2] = $this->mapRowToData($row->toArray());
        }

        // #1167: the four hardware cache namespaces must bump at most once per
        // import, not four times per row. The batch scope collects every
        // increment and flushes one per namespace at the end.
        app(CacheInvalidationServiceInterface::class)->batch(function () use ($dataByRow) {
            $this->loadExistingRecords(array_values($dataByRow));

            // First pass: build preview data
            foreach ($dataByRow as $rowNumber => $data) {
                $this->buildPreview($data, $rowNumber);
            }

            // Second pass: apply selected actions if provided
            if (! empty($this->selectedActions)) {
                // Reset counters since they were incremented during preview pass
                // The actual import will increment them correctly
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
     * #1167: load only the rows this sheet can possibly match.
     *
     * The old version hydrated every in-scope hardware row (20 columns) in both
     * the preview and the confirm pass. Restricting the window to the compare
     * keys in the file keeps the scope predicate intact — the `whereIn` below
     * is unconditional and an empty scope compiles to `0 = 1` — while turning
     * a full-table hydration into an index-range lookup.
     *
     * @param  array<int, array<string, mixed>>  $rowsData
     */
    private function loadExistingRecords(array $rowsData): void
    {
        $pcNames = [];
        $macs = [];
        $nCodes = [];

        foreach ($rowsData as $data) {
            if (! empty($data['pc_name'])) {
                $pcNames[$data['pc_name']] = true;
            }
            if (! empty($data['mac'])) {
                $macs[$data['mac']] = true;
            }
            if (! empty($data['n_code'])) {
                $nCodes[$data['n_code']] = true;
            }
        }

        $query = Hardware::query();

        // Apply organizational scope — UNCONDITIONAL (issue #839 / Plan 50).
        // `[]` is a legitimate `AccessService` output, so an emptiness guard
        // here would index EVERY hardware row org-wide by pc_name / mac and a
        // matching CSV row would rewrite a record the actor cannot see.
        // An empty array compiles to `0 = 1` (fail-closed by construction).
        $query->whereHas('person', function ($q) {
            $q->whereIn('u_id', $this->accessibleUnitIds);
        });

        // Only the compare keys the caller asked for can produce a match; a
        // `whereIn` on an unused key would widen the window for nothing.
        if ($this->compareKey === 'pc_name') {
            $query->whereIn('pc_name', array_keys($pcNames));
        } elseif ($this->compareKey === 'mac') {
            $query->whereIn('mac', array_keys($macs));
        } else {
            $query->where(function ($q) use ($pcNames, $macs) {
                $q->whereIn('pc_name', array_keys($pcNames))
                    ->orWhereIn('mac', array_keys($macs));
            });
        }

        $hardwares = $query->get(['id', 'pc_name', 'mac', 'n_code', 'type', 'os', 'ip_valid', 'ip_local', 'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard', 'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at']);

        foreach ($hardwares as $hw) {
            // Index by pc_name and mac for quick lookup
            if ($hw->pc_name) {
                $this->existingRecords['pc_name'][$hw->pc_name] = $hw;
            }
            if ($hw->mac) {
                $this->existingRecords['mac'][$hw->mac] = $hw;
            }
        }

        // Pre-load all persons in accessible units to avoid N+1 queries
        $this->loadExistingPersons(array_keys($nCodes));
    }

    /**
     * @param  array<int, string>  $nCodes  National codes referenced by the sheet.
     */
    private function loadExistingPersons(array $nCodes): void
    {
        $query = Person::query();

        // Apply organizational scope — UNCONDITIONAL (issue #839). `[]` must not
        // fall through to "every person in the org".
        $query->whereIn('u_id', $this->accessibleUnitIds)
            ->whereIn('n_code', $nCodes);

        $persons = $query->get(['n_code', 'u_id', 'f_name', 'l_name']);

        foreach ($persons as $person) {
            $this->existingPersons[$person->n_code] = $person;
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
        if (empty($data['n_code']) || empty($data['pc_name'])) {
            $this->importResults['preview'][] = [
                'row' => $rowNumber,
                'status' => 'error',
                'message' => 'فیلدهای اجباری n_code و pc_name خالی هستند',
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Verify person exists and is in accessible units
        $person = $this->existingPersons[$data['n_code']] ?? null;
        if (! $person) {
            $this->importResults['preview'][] = [
                'row' => $rowNumber,
                'status' => 'error',
                'message' => "پرسنل با کد ملی {$data['n_code']} یافت نشد",
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // UNCONDITIONAL membership test (issue #839): the `! empty(...)` conjunct
        // made this `! empty([]) && ...` = false, i.e. ACCEPT the row. Under an
        // empty scope `$this->existingPersons` is empty, so the "person not
        // found" check above already rejected it — defence in depth.
        if (! in_array($person->u_id, $this->accessibleUnitIds)) {
            $this->importResults['preview'][] = [
                'row' => $rowNumber,
                'status' => 'error',
                'message' => "پرسنل {$data['n_code']} در واحدهای قابل دسترس شما نیست",
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Find existing record
        $existing = $this->findExistingRecord($data);
        $matchKey = $existing ? ($existing['key'] ?? 'unknown') : null;

        if ($existing) {
            $changes = $this->detectChanges($existing['record'], $data);

            if (! empty($changes)) {
                $this->importResults['preview'][] = [
                    'row' => $rowNumber,
                    'status' => 'update',
                    'id' => $existing['record']->id,
                    'pc_name' => $existing['record']->pc_name,
                    'match_key' => $matchKey,
                    'changes' => $changes,
                    'person' => $person->f_name.' '.$person->l_name,
                    'data' => $data,
                ];
                $this->importResults['updated']++;
            } else {
                $this->importResults['preview'][] = [
                    'row' => $rowNumber,
                    'status' => 'unchanged',
                    'id' => $existing['record']->id,
                    'pc_name' => $existing['record']->pc_name,
                    'match_key' => $matchKey,
                    'message' => 'بدون تغییر',
                    'person' => $person->f_name.' '.$person->l_name,
                    'data' => $data,
                ];
                $this->importResults['skipped']++;
            }
        } else {
            $this->importResults['preview'][] = [
                'row' => $rowNumber,
                'status' => 'create',
                'pc_name' => $data['pc_name'],
                'person' => $person->f_name.' '.$person->l_name,
                'data' => $data,
            ];
            $this->importResults['created']++;
        }
    }

    private function findExistingRecord(array $data): ?array
    {
        $existing = null;
        $matchKey = null;

        if (in_array($this->compareKey, ['pc_name', 'both']) && ! empty($data['pc_name']) && isset($this->existingRecords['pc_name'][$data['pc_name']])) {
            $existing = $this->existingRecords['pc_name'][$data['pc_name']];
            $matchKey = 'pc_name';
        } elseif (in_array($this->compareKey, ['mac', 'both']) && ! empty($data['mac']) && isset($this->existingRecords['mac'][$data['mac']])) {
            $existing = $this->existingRecords['mac'][$data['mac']];
            $matchKey = 'mac';
        }

        if ($existing) {
            return ['record' => $existing, 'key' => $matchKey];
        }

        return null;
    }

    private function mapRowToData(array $row): array
    {
        return [
            'n_code' => $this->clean($row['n_code'] ?? null),
            'pc_name' => $this->clean($row['pc_name'] ?? null),
            'type' => $this->clean($row['type'] ?? null),
            'os' => $this->clean($row['os'] ?? null),
            'ip_valid' => $this->clean($row['ip_valid'] ?? null),
            'ip_local' => $this->clean($row['ip_local'] ?? null),
            'mac' => $this->clean($row['mac'] ?? null),
            'net_type' => $this->clean($row['net_type'] ?? null),
            'switch' => $this->clean($row['switch'] ?? null),
            'port' => $this->clean($row['port'] ?? null),
            'shutdown' => $this->parseBoolean($row['shutdown'] ?? null) ?? false,
            'vlan' => $this->clean($row['vlan'] ?? null),
            'motherboard' => $this->clean($row['motherboard'] ?? null),
            'cpu' => $this->clean($row['cpu'] ?? null),
            'ram' => $this->clean($row['ram'] ?? null),
            'hdd' => $this->clean($row['hdd'] ?? null),
            'comments' => $this->clean($row['comments'] ?? null),
            'mark' => $this->parseBoolean($row['mark'] ?? null) ?? false,
            'clean_at' => $this->parseDate($row['clean_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function processRow(array $data, int $rowNumber, string $action = 'auto', bool $isConfirmation = false): void
    {
        // Validate required fields
        if (empty($data['n_code']) || empty($data['pc_name'])) {
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => 'Missing required fields: n_code and pc_name are required',
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Verify person exists and is in accessible units
        $person = $this->existingPersons[$data['n_code']] ?? null;
        if (! $person) {
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => "Person with n_code {$data['n_code']} not found",
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // UNCONDITIONAL membership test (issue #839): the `! empty(...)` conjunct
        // made this `! empty([]) && ...` = false, i.e. ACCEPT the row.
        if (! in_array($person->u_id, $this->accessibleUnitIds)) {
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => "Person {$data['n_code']} is not in your accessible units",
                'data' => $data,
            ];
            $this->importResults['skipped']++;

            return;
        }

        // Find existing record by pc_name or mac
        $existing = $this->findExistingRecord($data);
        $matchKey = $existing ? ($existing['key'] ?? 'unknown') : null;

        if ($existing) {
            // Check for changes
            $changes = $this->detectChanges($existing['record'], $data);

            if (! empty($changes)) {
                $this->importResults['changes'][] = [
                    'row' => $rowNumber,
                    'id' => $existing['record']->id,
                    'pc_name' => $existing['record']->pc_name,
                    'match_key' => $matchKey,
                    'changes' => $changes,
                ];

                // Queue the write. The record is updated in memory too, so a
                // second sheet row matching the same device sees the new values
                // exactly as it did when the old code wrote row-by-row.
                if ($action === 'update' || $action === 'auto') {
                    $writeData = $this->normalizeWriteData($data);
                    $auditChanges = $this->updateAuditChanges($existing['record'], $writeData);

                    $this->pendingUpdates[] = [
                        'id' => $existing['record']->id,
                        'data' => $writeData,
                        'changes' => $auditChanges,
                    ];

                    // Mirror the per-row `update($data)` in memory without
                    // touching the database or firing model events.
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

                $this->pendingCreates[] = [
                    'data' => $writeData,
                    'changes' => $this->createAuditChanges($writeData),
                ];

                $this->importResults['created']++;
            }
        }
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
    private function normalizeWriteData(array $data): array
    {
        foreach (self::NORMALIZED_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $data[$field] = Hardware::normalizeForSearch($data[$field]);
            }
        }

        return $data;
    }

    /**
     * The `hardware_audits` row the observer would have written for a create.
     *
     * The observer reads casted attributes, so a fresh unsaved model applies the
     * same `mark` / `shutdown` / `clean_at` casts before formatting.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{field: string, old: string, new: string}>
     */
    private function createAuditChanges(array $data): array
    {
        $model = new Hardware($data);
        $changes = [];

        foreach (HardwareAuditChange::AUDITED_FIELDS as $field) {
            $value = $model->getAttribute($field);
            if ($value !== null && $value !== '') {
                $changes[] = [
                    'field' => $field,
                    'old' => HardwareAuditChange::NO_VALUE,
                    'new' => HardwareAuditChange::formatValueForDisplay($value),
                ];
            }
        }

        return $changes;
    }

    /**
     * The `updated` diff the observer would have built from `getDirty()`.
     *
     * The probe model is filled (casts applied) but never saved; comparing it
     * to its loaded original reproduces Eloquent's dirty semantics — notably
     * that a date equal to the stored value is not dirty even when the CSV
     * spells it `Y-m-d` and the column holds a timestamp (#890).
     *
     * @param  array<string, mixed>  $data
     * @return list<array{field: string, old: string, new: string}>
     */
    private function updateAuditChanges(Hardware $existing, array $data): array
    {
        $probe = clone $existing;
        $probe->fill($data);

        $original = $probe->getOriginal();
        $changes = [];

        foreach ($probe->getDirty() as $field => $newValue) {
            if (in_array($field, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $oldValue = array_key_exists($field, $original) ? $original[$field] : null;

            if ($this->normalizeAuditValue($oldValue) === $this->normalizeAuditValue($newValue)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'old' => HardwareAuditChange::formatValueForDisplay($oldValue),
                'new' => HardwareAuditChange::formatValueForDisplay($newValue),
            ];
        }

        return $changes;
    }

    private function normalizeAuditValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Write the queued rows, then their audit trail, in chunks.
     *
     * `insert()` and `upsert()` go through the query builder: no model events,
     * no per-row audit INSERT and no per-row cache increment. The audit rows
     * the observer would have produced are built per row above and inserted by
     * the shared {@see HardwareBulkAuditWriter}; the four cache namespaces are
     * bumped once by the surrounding `CacheInvalidationServiceInterface::batch()`.
     */
    private function flushPendingWrites(): void
    {
        $wrote = ! empty($this->pendingCreates) || ! empty($this->pendingUpdates);

        foreach (array_chunk($this->pendingCreates, self::CHUNK_SIZE) as $chunk) {
            $this->insertCreates($chunk);
        }

        // #1194: two sheet rows can resolve to the same existing hardware id
        // (pc_name / mac are not unique). PostgreSQL rejects an upsert whose
        // VALUES list contains the same conflict target twice, so collapse
        // duplicate ids here — last write wins, matching row-by-row semantics.
        $dedupedUpdates = [];
        foreach ($this->pendingUpdates as $item) {
            $dedupedUpdates[$item['id']] = $item;
        }
        $this->pendingUpdates = array_values($dedupedUpdates);

        foreach (array_chunk($this->pendingUpdates, self::CHUNK_SIZE) as $chunk) {
            $this->updateRows($chunk);
        }

        $this->pendingCreates = [];
        $this->pendingUpdates = [];

        if ($wrote) {
            // Bulk writes bypass `saved`, so the model's flush must be invoked
            // explicitly — inside the batch scope it lands as one increment per
            // namespace (#376 / #1167).
            Hardware::flushStatsCache();
        }
    }

    /**
     * @param  list<array{data: array<string, mixed>, changes: array<string, mixed>}>  $chunk
     */
    private function insertCreates(array $chunk): void
    {
        $now = now();
        $ids = $this->reserveHardwareIds(count($chunk));
        $rows = [];
        $auditChanges = [];

        foreach ($chunk as $index => $item) {
            $id = $ids[$index];
            $rows[] = array_merge($item['data'], [
                'id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $auditChanges[$id] = $item['changes'];
        }

        Hardware::query()->insert($rows);

        // Build the model stubs the writer expects (it only reads `->id`).
        $items = array_map(fn (int $id) => (object) ['id' => $id], $ids);

        [$userId, $source, $ip, $userAgent] = $this->auditContext();

        app(HardwareBulkAuditWriter::class)->batchInsertAudits(
            collect($items),
            'created',
            null,
            fn ($hardware) => $auditChanges[$hardware->id] ?? [],
            $userId,
            $source,
            $ip,
            $userAgent,
        );
    }

    /**
     * @param  list<array{id: int, data: array<string, mixed>, changes: array<string, mixed>}>  $chunk
     */
    private function updateRows(array $chunk): void
    {
        $now = now();
        $rows = [];

        foreach ($chunk as $item) {
            $rows[] = array_merge($item['data'], [
                'id' => $item['id'],
                'updated_at' => $now,
            ]);
        }

        // `id` is the primary key, so this is a legal ON CONFLICT target without
        // a schema change. The compare key (`pc_name` / `mac`) is not unique in
        // `hardwares`, so it could never be used as the conflict target.
        Hardware::query()->upsert(
            $rows,
            ['id'],
            array_merge($this->updateColumns(), ['updated_at'])
        );

        // An update whose values normalize to the stored ones produces no dirty
        // fields, and the observer writes no audit row for it. Filter those out
        // rather than inserting an empty audit entry.
        $auditable = array_values(array_filter($chunk, fn (array $item) => $item['changes'] !== []));

        if (empty($auditable)) {
            return;
        }

        $auditChanges = [];
        foreach ($auditable as $item) {
            $auditChanges[$item['id']] = $item['changes'];
        }

        $items = array_map(fn (array $item) => (object) ['id' => $item['id']], $auditable);

        [$userId, $source, $ip, $userAgent] = $this->auditContext();

        app(HardwareBulkAuditWriter::class)->batchInsertAudits(
            collect($items),
            'updated',
            null,
            fn ($hardware) => $auditChanges[$hardware->id] ?? [],
            $userId,
            $source,
            $ip,
            $userAgent,
        );
    }

    /**
     * @return list<string>
     */
    private function updateColumns(): array
    {
        return [
            'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
        ];
    }

    /**
     * Reserve ids before the bulk INSERT so each created row's audit entry can
     * name its `hardware_id` in the same batch. PostgreSQL's `nextval()` is
     * atomic and returns unique values even under concurrent inserts; gaps are
     * normal and harmless.
     *
     * @return list<int>
     */
    private function reserveHardwareIds(int $count): array
    {
        $rows = DB::select("SELECT nextval('hardwares_id_seq') AS id FROM generate_series(1, ?::int)", [$count]);

        return array_map(fn ($row) => (int) $row->id, $rows);
    }

    /**
     * @return array{0: ?int, 1: string, 2: ?string, 3: ?string}
     */
    private function auditContext(): array
    {
        if ($this->auditContextExplicit) {
            return [$this->auditUserId, $this->auditSource ?? 'import', $this->auditIp, $this->auditUserAgent];
        }

        $userId = Auth::id();

        return [
            $userId === null ? null : (int) $userId,
            'import',
            request()->ip(),
            request()->userAgent(),
        ];
    }

    private function detectChanges(Hardware $existing, array $newData): array
    {
        $changes = [];
        $compareFields = [
            'n_code', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'shutdown', 'vlan',
            'motherboard', 'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
        ];

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

    /**
     * #890: this is a TYPE-AWARE comparison, not a string comparison.
     *
     * The old version ended in `trim((string) $value)`, so a `date`-cast Carbon
     * from the database became `'2026-02-08 00:00:00'` while the freshly parsed
     * CSV value was `'2026-02-08'`. The two never matched, so every preview
     * reported a change that was not one and every re-import wrote a bogus
     * diff. Normalising both sides to the same shape is what removes it.
     */
    private function normalizeForComparison($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        // #1034: the CSV null token is a single backslash + N (source `'\\N'`),
        // matching clean() and parseBoolean() below. The previous source literal
        // spelled ten backslashes (five at runtime, since single quotes halve
        // them) so it could never match the real token; a raw `\N` fell through
        // to trim() as the two characters `\` and `N`.
        if ($value === null || $value === '' || $value === '\\N') {
            return '0'; // Treat null/empty as false for boolean comparison
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        // Handle boolean false/0 values that come from database
        if ($value === false || $value === 0 || $value === '0') {
            return '0';
        }
        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }

        return trim((string) $value);
    }

    /**
     * #890: the importer must understand the tokens the EXPORTER writes.
     *
     * `HardwareExport` renders `shutdown` as «روشن»/«خاموش» and `mark` as
     * «علامت‌دار»/«-», so re-importing an exported sheet fed those labels into a
     * parser that only knew latin tokens: every `true` came back `false`. The
     * measured round-trip in HardwareExportImportRoundTripTest is what proved it.
     *
     * «روشن» is the on state; «خاموش» and «-» are off. The export's own `mark`
     * column writes «-» for false, so that has to read as false here too.
     */
    private function parseBoolean($value): ?bool
    {
        if ($value === null || $value === '' || $value === '\\N') {
            return null;
        }
        $val = strtolower(trim((string) $value));

        if (in_array($val, ['1', 'true', 'yes', 'on', 'بله', 'تایید'], true)) {
            return true;
        }

        // The Persian labels this repo's own exports emit (#890).
        if (in_array($val, ['روشن', 'فعال', 'علامت‌دار', 'علامت', 'دارد'], true)) {
            return true;
        }

        // Explicit false tokens, including the exporter's own dash.
        if (in_array($val, ['0', 'false', 'no', 'off', 'خیر', 'خاموش', '-', 'بدون علامت'], true)) {
            return false;
        }

        // Unknown token: previously anything not in the true-list became false,
        // which is how an unrecognised value silently flipped a flag. Anything
        // else is "not stated", and `?? false` at the call site keeps the
        // caller's default rather than inventing a change.
        return null;
    }

    /**
     * #890: one date contract shared with the exporters.
     *
     * The sheet is operator-facing and every export in this repo emits Jalali
     * `Y/m/d`, so a `clean_at` cell can legitimately hold a Jalali date. The
     * old implementation accepted only ISO Gregorian, so re-importing an
     * exported sheet silently set `clean_at` to NULL on every row that had one
     * — the operator's own file, read back, destroyed the field.
     *
     * Accepts ISO `2026-02-08`, Jalali `1404/11/19` and Jalali `1404-11-19`,
     * and returns canonical Gregorian `Y-m-d` for storage. Returns null for
     * anything else, including a shape that matches but is not a real date.
     */
    private function parseDate($value): ?string
    {
        return $this->parseGregorianOrJalaliDate($value);
    }

    private function clean($value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N' || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    // #890: `rules()` was REMOVED, not activated.
    //
    // This class declares ToCollection, WithCustomCsvSettings and
    // WithHeadingRow — never WithValidation — so every rule in the old method
    // was dead code: Maatwebsite\Excel guards its own validation on
    // `$import instanceof WithValidation`, so `'clean_at' =>
    // 'nullable|date_format:Y-m-d'` was never evaluated while reading as an
    // enforced contract.
    //
    // Activating it instead was rejected: PersonImport carries the same three
    // traits and no rules() either, and the manual per-row validation in
    // processRow() is the designed preview/confirm path. Turning on framework
    // validation here would change import behaviour well beyond this bug and
    // deserves its own issue if it is ever wanted.

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
