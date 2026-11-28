<?php

namespace App\Imports;

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use App\Traits\PersianNormalizer;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PersonImport extends AbstractImportPipeline implements ToCollection, WithCustomCsvSettings, WithHeadingRow
{
    use PersianNormalizer;

    /**
     * Fields Person::boot() folds on save; bulk writes must reproduce it.
     */
    private const NORMALIZED_FIELDS = ['f_name', 'l_name'];

    /**
     * #1697: Persian label per numeric lookup column — the single source of the
     * row-rejection messages, in sheet-column order.
     */
    private const LOOKUP_LABELS = [
        't_id' => 'تحصیلات',
        'e_id' => 'نوع استخدام',
        'r_id' => 'ردیف سازمانی',
        's_id' => 'سمت',
        'u_id' => 'واحد',
    ];

    /**
     * #1697: `persons.t_id`/`e_id`/`r_id`/`s_id` are NOT NULL, so an empty cell
     * would abort the whole sheet with 23502 on confirm. `u_id` has its own
     * empty check in `buildPreview()`.
     */
    private const REQUIRED_LOOKUP_COLUMNS = ['t_id', 'e_id', 'r_id', 's_id'];

    /** Did any queued write create a person or move one between units? */
    private bool $touchesMapScope = false;

    /**
     * #1900: persons whose `u_id` this import moves, keyed by person id.
     *
     * Recorded at detection time because `AbstractImportPipeline::flushPendingWrites()`
     * empties `pendingUpdates` before it calls `afterFlush()`; nothing is left
     * to derive the transfers from at that point. `from` is the first-seen
     * (pre-write) value; `to` is the last queued value, which is exactly what
     * the upsert applies — the same last-write-wins order `flushPendingWrites()`
     * uses when it dedupes by id. A net no-op transfer (`from === to`) is
     * dropped before the pivot is touched.
     *
     * @var array<int, array{from: int, to: int}>
     */
    private array $unitTransfers = [];

    // Pre-loaded related models to avoid N+1 queries
    private array $units = [];

    private array $tahsils = [];

    private array $estekhdams = [];

    private array $radifs = [];

    private array $semats = [];

    /**
     * #1167: restrict the hydration to the national codes actually present in
     * the sheet. The scope predicate stays unconditional (issue #839).
     *
     * @param  array<int, array{data: array<string, mixed>, invalid: array<string, string>}>  $dataByRow
     */
    protected function loadExistingRecords(array $dataByRow): void
    {
        $nCodes = [];
        foreach ($dataByRow as $mapped) {
            $data = $mapped['data'];
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

        // Pre-load all related reference data to avoid N+1 queries
        $this->loadReferenceData();
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
     * @param  array<string, string>  $invalidLookups
     */
    protected function buildPreview(array $data, int $rowNumber, array $invalidLookups = []): void
    {
        // Validate required fields
        if (empty($data['n_code']) || empty($data['f_name']) || empty($data['l_name'])) {
            $this->recordRejectedRow($rowNumber, 'فیلدهای اجباری n_code، f_name و l_name خالی هستند', $data);

            return;
        }

        // #1697: a non-numeric (or missing required) lookup id must reject the
        // row here — the confirm pass reuses this rejection (#1209), so the
        // bulk insert never sees a fabricated `0` or a NOT NULL violation.
        $lookupError = $this->lookupRejectionMessage($data, $invalidLookups);
        if ($lookupError !== null) {
            $this->recordRejectedRow($rowNumber, $lookupError, $data);

            return;
        }

        // Verify unit exists and is in accessible units (using pre-loaded data)
        if (! empty($data['u_id'])) {
            $unit = $this->units[$data['u_id']] ?? null;
            if (! $unit) {
                $this->recordRejectedRow($rowNumber, "واحد با شناسه {$data['u_id']} یافت نشد", $data);

                return;
            }

            // UNCONDITIONAL membership test (issue #839): the `! empty(...)`
            // conjunct made this `! empty([]) && ...` = false, i.e. ACCEPT.
            // Under an empty scope `$this->units` is empty, so the check above
            // already rejected the row — this is a defence-in-depth backstop.
            if (! in_array($unit->id, $this->accessibleUnitIds)) {
                $this->recordRejectedRow($rowNumber, "واحد {$unit->name} در واحدهای قابل دسترس شما نیست", $data);

                return;
            }
        } else {
            $this->recordRejectedRow($rowNumber, 'شناسه واحد (u_id) خالی است', $data);

            return;
        }

        // Verify related models exist (using pre-loaded data)
        $validationError = $this->validateRelatedModels($data);
        if ($validationError) {
            $this->recordRejectedRow($rowNumber, $validationError, $data);

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

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: Person, key: string|null}|null
     */
    protected function findExistingRecord(array $data): ?array
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

    /**
     * #1697: build one merged rejection message per row.
     *
     * A row must produce exactly one `preview` entry — the operator UI derives
     * the sheet row number from the entry's position — so several bad columns
     * are joined with «؛ » in sheet-column order instead of being queued
     * separately.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $invalidLookups
     */
    private function lookupRejectionMessage(array $data, array $invalidLookups): ?string
    {
        $messages = [];

        foreach ($invalidLookups as $column => $cell) {
            $messages[] = 'شناسه '.self::LOOKUP_LABELS[$column]." ({$column}) باید یک عدد صحیح باشد؛ مقدار سلول: {$cell}";
        }

        foreach (self::REQUIRED_LOOKUP_COLUMNS as $column) {
            if (! array_key_exists($column, $invalidLookups) && ($data[$column] ?? null) === null) {
                $messages[] = 'شناسه '.self::LOOKUP_LABELS[$column]." ({$column}) خالی است";
            }
        }

        return $messages === [] ? null : implode('؛ ', $messages);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{data: array<string, mixed>, invalid: array<string, string>}
     */
    protected function mapRowToData(array $row): array
    {
        $ids = [];
        $invalid = [];

        foreach (self::LOOKUP_LABELS as $column => $_) {
            $parsed = $this->parseLookupId($row[$column] ?? null);
            $ids[$column] = $parsed['value'];

            if ($parsed['invalid'] !== null) {
                $invalid[$column] = $parsed['invalid'];
            }
        }

        // Expected CSV headers: n_code, f_name, l_name, t_id, e_id, r_id, s_id, u_id, phone
        return [
            'data' => [
                'n_code' => $this->clean($row['n_code'] ?? null),
                'f_name' => $this->clean($row['f_name'] ?? null),
                'l_name' => $this->clean($row['l_name'] ?? null),
                ...$ids,
                'phone' => $this->clean($row['phone'] ?? null),
            ],
            'invalid' => $invalid,
        ];
    }

    private function validateRelatedModels(array $data): ?string
    {
        if ($data['t_id'] !== null && ! isset($this->tahsils[$data['t_id']])) {
            return "تحصیلات با شناسه {$data['t_id']} یافت نشد";
        }
        if ($data['e_id'] !== null && ! isset($this->estekhdams[$data['e_id']])) {
            return "نوع استخدام با شناسه {$data['e_id']} یافت نشد";
        }
        if ($data['r_id'] !== null && ! isset($this->radifs[$data['r_id']])) {
            return "ردیف سازمانی با شناسه {$data['r_id']} یافت نشد";
        }
        if ($data['s_id'] !== null && ! isset($this->semats[$data['s_id']])) {
            return "سمت با شناسه {$data['s_id']} یافت نشد";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function processRow(array $data, int $rowNumber, string $action = 'auto', bool $isConfirmation = false): void
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

                        // #1900: `$existing['record']->u_id` is still the
                        // pre-row value here (`detectChanges()` ran before the
                        // `fill()` below). Keep the first-seen `from` and the
                        // last queued `to`, matching the upsert's last-write-wins.
                        $personId = (int) $existing['record']->id;
                        $this->unitTransfers[$personId] ??= ['from' => (int) $existing['record']->u_id, 'to' => 0];
                        $this->unitTransfers[$personId]['to'] = (int) $writeData['u_id'];
                    }

                    $this->pendingUpdates[] = [
                        'id' => $existing['record']->id,
                        'data' => $writeData,
                    ];

                    $existing['record']->fill($writeData);

                    $this->importResults['updated']++;
                } else {
                    // #1209: 'create' cannot write onto an existing record —
                    // such an action combo never writes anything, so the row
                    // must still land in a bucket.
                    $this->importResults['skipped']++;
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
            } else {
                // #1209: 'update' cannot write onto a record that does not
                // exist — the row must still land in a bucket.
                $this->importResults['skipped']++;
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function compareFields(): array
    {
        return ['f_name', 'l_name', 't_id', 'e_id', 'r_id', 's_id', 'u_id', 'phone'];
    }

    /**
     * @return list<string>
     */
    protected function normalizedFields(): array
    {
        return self::NORMALIZED_FIELDS;
    }

    protected function normalizeSearchValue(string $value): string
    {
        return Person::normalizeForSearch($value);
    }

    /**
     * #1194: two sheet rows can carry the same NEW n_code. `persons.n_code`
     * is unique, so a plain INSERT of both rows trips
     * `persons_n_code_unique` and aborts the whole chunk — the import then
     * rolls back with nothing written and, on the queued path, only the
     * worker sees the error. Dedupe by n_code (last write wins), exactly
     * like the update path.
     */
    protected function dedupePendingCreates(): void
    {
        $dedupedCreates = [];
        foreach ($this->pendingCreates as $item) {
            /** @var array<string, mixed> $data */
            $data = $item['data'];
            $nCode = $data['n_code'] ?? null;

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
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    protected function insertCreateChunk(array $chunk): void
    {
        $now = now();
        $rows = [];

        foreach ($chunk as $item) {
            /** @var array<string, mixed> $data */
            $data = $item['data'];
            $rows[] = array_merge($data, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Person::query()->insert($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    protected function updateChunk(array $chunk): void
    {
        $now = now();
        $rows = [];

        foreach ($chunk as $item) {
            /** @var array<string, mixed> $data */
            $data = $item['data'];
            $rows[] = array_merge($data, [
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

    /**
     * @return list<string>
     */
    protected function updateColumns(): array
    {
        return ['n_code', 'f_name', 'l_name', 't_id', 'e_id', 'r_id', 's_id', 'u_id', 'phone'];
    }

    /**
     * Person computes "wrote anything" BEFORE dedupe on purpose (the ordering
     * Hardware uses is the opposite); see AbstractImportPipeline.
     */
    protected function shouldBumpCaches(bool $hadQueued, bool $hasAfterDedupe): bool
    {
        return $hadQueued;
    }

    /**
     * Bulk writes bypass `saved`, so the two unconditional bumps and the two
     * u_id-dependent ones (maps/gis) are reproduced here; the surrounding
     * `batch()` collapses repeats into one increment per namespace.
     */
    protected function afterFlush(): void
    {
        // #1900: the bulk upsert fires no model events, so the linked user's
        // primary unit must be synced here — after every chunk has been written
        // and before the (batched, deferred) cache versions are bumped.
        $this->syncTransferredPersonsPrimaryUnits();

        $cache = app(CacheInvalidationServiceInterface::class);
        $cache->increment('hr_stats');
        $cache->increment('dashboard');
        // #1351: the bulk path fires no model events, so the manual block
        // must bump `report_persons` itself, or a bulk import leaves the
        // cached persons report stale until the TTL expires.
        $cache->increment('report_persons');

        if ($this->touchesMapScope) {
            $cache->increment('maps');
            $cache->increment('gis');
            // Issue #1347: same gate as maps/gis — the import writes persons
            // without model events, so the manual block must bump
            // `unit_hierarchy` too.
            $cache->increment('unit_hierarchy');
        }
    }

    /**
     * #1900: `persons.u_id` and the linked user's `user_units.is_primary` are
     * one invariant (#1803 / #1896). The bulk path writes with `upsert()` — no
     * model events, no hydrated model — so `PersonService::update()` cannot be
     * reused here; the sync runs once, after the writes, for the affected users.
     *
     * One query for all users (never one per person) and `setPrimaryUnit()` as
     * the only sanctioned writer of `is_primary` — the partial unique index
     * `user_units_one_primary_per_user` is preserved by its clear-then-set
     * order. Trashed users are deliberately skipped: `User` is soft-deleted, so
     * `$person->user` skips them too (#1896 behaviour); their pivots are
     * re-synced if the account is restored. A person without a linked user is
     * a no-op, and a created person cannot have one (the FK
     * `users.n_code → persons.n_code` makes that impossible).
     */
    private function syncTransferredPersonsPrimaryUnits(): void
    {
        // A net no-op transfer (two sheet rows cancelling out) must not touch
        // the pivot at all.
        $transfers = array_filter(
            $this->unitTransfers,
            static fn (array $transfer): bool => $transfer['from'] !== $transfer['to'],
        );

        if ($transfers === []) {
            return;
        }

        /** @var array<int, string|null> $nCodesByPersonId */
        $nCodesByPersonId = Person::query()
            ->whereIn('id', array_keys($transfers))
            ->pluck('n_code', 'id')
            ->all();

        /** @var array<string, User> $usersByNCode */
        $usersByNCode = User::query()
            ->whereIn('n_code', array_values(array_filter($nCodesByPersonId)))
            ->get(['id', 'n_code'])
            ->keyBy('n_code')
            ->all();

        foreach ($nCodesByPersonId as $personId => $nCode) {
            if ($nCode === null) {
                continue;
            }

            $user = $usersByNCode[$nCode] ?? null;
            $user?->setPrimaryUnit($transfers[$personId]['to']);
        }
    }

    protected function normalizeForComparison($value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * #1697: parse one numeric lookup cell without a silent cast.
     *
     * The cell may arrive as int/float (PhpSpreadsheet types CSV/XLSX cells)
     * or string. Accepted: integers >= 1 and whole floats; strings are
     * trimmed, Persian/Arabic-Indic digits are folded via `digitMap()`, and
     * only `[0-9]+` is accepted — never `\d` with `/u`, which also matches
     * Persian digits and casts them to 0. Fractions, `0`, negatives and
     * bigint overflow are invalid, not truncated.
     *
     * @return array{value: int|null, invalid: string|null}
     */
    private function parseLookupId(mixed $value): array
    {
        if ($value === null) {
            return ['value' => null, 'invalid' => null];
        }

        if (is_int($value)) {
            return $value >= 1
                ? ['value' => $value, 'invalid' => null]
                : ['value' => null, 'invalid' => (string) $value];
        }

        if (is_float($value)) {
            if (is_finite($value) && $value >= 1 && $value <= PHP_INT_MAX && floor($value) === $value) {
                return ['value' => (int) $value, 'invalid' => null];
            }

            return ['value' => null, 'invalid' => mb_substr((string) $value, 0, 30)];
        }

        $raw = mb_substr(trim((string) $value), 0, 30);
        $digits = trim(strtr((string) $value, self::digitMap()));

        if ($digits === '' || $digits === '\\N') {
            return ['value' => null, 'invalid' => null];
        }

        // Deliberately `[0-9]`, not `\d`: under `/u`, `\d` matches Persian
        // digits too and `(int)` then turns them into 0 — the original bug.
        if (! preg_match('/^[0-9]+$/', $digits)) {
            return ['value' => null, 'invalid' => $raw];
        }

        $number = (int) $digits;

        // Reject 0 and bigint overflow (`(string) $number` stops matching).
        if ($number <= 0 || (string) $number !== ltrim($digits, '0')) {
            return ['value' => null, 'invalid' => $raw];
        }

        return ['value' => $number, 'invalid' => null];
    }
}
