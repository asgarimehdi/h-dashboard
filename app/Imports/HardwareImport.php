<?php

namespace App\Imports;

use App\Exports\Concerns\FormatsJalaliDates;
use App\Models\Hardware;
use App\Models\Person;
use App\Services\HardwareBulkAuditWriter;
use App\Support\HardwareAuditChange;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class HardwareImport extends AbstractImportPipeline implements ToCollection, WithCustomCsvSettings, WithHeadingRow
{
    use FormatsJalaliDates;

    /**
     * Fields the model's `saving` hook folds (Hardware::boot()).
     * Bulk writes bypass model events, so the import must apply the same
     * normalization itself — otherwise Persian variants land un-folded.
     */
    private const NORMALIZED_FIELDS = [
        'pc_name', 'type', 'os', 'cpu', 'ram', 'hdd', 'net_type', 'switch',
        'vlan', 'motherboard', 'comments',
    ];

    /**
     * #1758: the exact columns `updateRows()` upserts. `detectChanges()` must
     * compare THIS list — a column that is written but not compared is a
     * silent change the operator never approved in the preview.
     *
     * @var list<string>
     */
    private const WRITE_FIELDS = [
        'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
        'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
        'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
    ];

    private array $existingPersons = [];

    private string $compareKey = 'both'; // 'pc_name', 'mac', 'both'

    private ?int $auditUserId = null;

    private ?string $auditSource = null;

    private ?string $auditIp = null;

    private ?string $auditUserAgent = null;

    private bool $auditContextExplicit = false;

    public function setCompareKey(string $key): void
    {
        $this->compareKey = $key;
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

    /**
     * #1167: load only the rows this sheet can possibly match.
     *
     * The old version hydrated every in-scope hardware row (20 columns) in both
     * the preview and the confirm pass. Restricting the window to the compare
     * keys in the file keeps the scope predicate intact — the `whereIn` below
     * is unconditional and an empty scope compiles to `0 = 1` — while turning
     * a full-table hydration into an index-range lookup.
     *
     * @param  array<int, array{data: array<string, mixed>, invalid: array<string, string>}>  $dataByRow
     */
    protected function loadExistingRecords(array $dataByRow): void
    {
        $pcNames = [];
        $macs = [];
        $nCodes = [];

        foreach ($dataByRow as $mapped) {
            $data = $mapped['data'];
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

        // #1829: `hardwares` has no unique constraint on pc_name or mac, and
        // the query has no ORDER BY, so an overwriting assignment made a
        // duplicate-key lookup nondeterministic between loads. Order by id and
        // keep the first row per key (`??=`), i.e. the lowest id always wins.
        $query->orderBy('id');

        $hardwares = $query->get(['id', 'pc_name', 'mac', 'n_code', 'type', 'os', 'ip_valid', 'ip_local', 'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard', 'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at']);

        foreach ($hardwares as $hw) {
            // Index by pc_name and mac for quick lookup
            if ($hw->pc_name) {
                $this->existingRecords['pc_name'][$hw->pc_name] ??= $hw;
            }
            if ($hw->mac) {
                $this->existingRecords['mac'][$hw->mac] ??= $hw;
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
     * @param  array<string, string>  $invalid
     */
    protected function buildPreview(array $data, int $rowNumber, array $invalid = []): void
    {
        // Validate required fields
        if (empty($data['n_code']) || empty($data['pc_name'])) {
            $this->recordRejectedRow($rowNumber, 'فیلدهای اجباری n_code و pc_name خالی هستند', $data);

            return;
        }

        // Verify person exists and is in accessible units
        $person = $this->existingPersons[$data['n_code']] ?? null;
        if (! $person) {
            $this->recordRejectedRow($rowNumber, "پرسنل با کد ملی {$data['n_code']} یافت نشد", $data);

            return;
        }

        // UNCONDITIONAL membership test (issue #839): the `! empty(...)` conjunct
        // made this `! empty([]) && ...` = false, i.e. ACCEPT the row. Under an
        // empty scope `$this->existingPersons` is empty, so the "person not
        // found" check above already rejected the row — defence in depth.
        if (! in_array($person->u_id, $this->accessibleUnitIds)) {
            $this->recordRejectedRow($rowNumber, "پرسنل {$data['n_code']} در واحدهای قابل دسترس شما نیست", $data);

            return;
        }

        // Find existing record
        $existing = $this->findExistingRecord($data);
        $matchKey = $existing ? ($existing['key'] ?? 'unknown') : null;

        // #1829: pc_name and mac resolve to two different devices. Writing
        // either hit would give the two devices the same mac, so the row is
        // rejected — naming both devices so the operator can fix the sheet.
        if ($existing !== null && $existing['conflict'] !== null) {
            $this->recordRejectedRow(
                $rowNumber,
                "تعارض تطبیق: pc_name «{$data['pc_name']}» به دستگاه «{$existing['record']->pc_name}» و mac «{$data['mac']}» به دستگاه «{$existing['conflict']->pc_name}» می‌رسد؛ ردیف نوشته نشد",
                $data,
            );

            return;
        }

        if ($existing) {
            // #1704: the cell is non-empty but not a date the parser accepts.
            // Writing the parsed null over a stored date would silently destroy
            // it, so the row is rejected instead — the same row-rejection
            // contract as #1697. Create rows are untouched: there is no stored
            // value to lose there, so #890 semantics (store NULL) still apply.
            if (($invalidCleanAt = $invalid['clean_at'] ?? null) !== null) {
                $this->recordRejectedRow(
                    $rowNumber,
                    "تاریخ clean_at نامعتبر است: «{$invalidCleanAt}»؛ ردیف رد شد تا تاریخ ذخیره‌شده پاک نشود",
                    $data,
                );

                return;
            }

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

    /**
     * #1829: under `both`, BOTH keys are looked up and the resolved records are
     * compared. A row whose pc_name hits device A while its mac hits device B
     * is ambiguous — honouring either hit would copy one device's mac onto the
     * other — so the caller rejects it instead of guessing. Checking the keys
     * in priority order (`if` / `elseif`) hid exactly that case.
     *
     * @param  array<string, mixed>  $data
     * @return array{record: Hardware, key: string|null, conflict: Hardware|null}|null
     */
    protected function findExistingRecord(array $data): ?array
    {
        /** @var array<string, Hardware> $byPcName */
        $byPcName = $this->existingRecords['pc_name'] ?? [];
        /** @var array<string, Hardware> $byMac */
        $byMac = $this->existingRecords['mac'] ?? [];

        $pcHit = in_array($this->compareKey, ['pc_name', 'both'], true) && ! empty($data['pc_name'])
            ? ($byPcName[$data['pc_name']] ?? null)
            : null;

        $macHit = in_array($this->compareKey, ['mac', 'both'], true) && ! empty($data['mac'])
            ? ($byMac[$data['mac']] ?? null)
            : null;

        if ($pcHit !== null && $macHit !== null && $pcHit->id !== $macHit->id) {
            return ['record' => $pcHit, 'key' => 'pc_name', 'conflict' => $macHit];
        }

        if ($pcHit !== null) {
            return ['record' => $pcHit, 'key' => 'pc_name', 'conflict' => null];
        }

        if ($macHit !== null) {
            return ['record' => $macHit, 'key' => 'mac', 'conflict' => null];
        }

        return null;
    }

    /**
     * #1667: the keys a queued create occupies, per the active compare key.
     * A key is only registered for a non-empty value, so rows with no mac (or
     * no pc_name) never collapse onto each other — the same rule PersonImport
     * applies to an empty n_code.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function createDedupeKeys(array $data): array
    {
        $keys = [];

        if ($this->compareKey === 'pc_name' || $this->compareKey === 'both') {
            $pcName = $data['pc_name'] ?? null;
            if (is_string($pcName) && $pcName !== '') {
                $keys[] = 'pc_name:'.$pcName;
            }
        }

        if ($this->compareKey === 'mac' || $this->compareKey === 'both') {
            $mac = $data['mac'] ?? null;
            if (is_string($mac) && $mac !== '') {
                $keys[] = 'mac:'.$mac;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{data: array<string, mixed>, invalid: array<string, string>}
     */
    protected function mapRowToData(array $row): array
    {
        // #1704: the raw cell is still readable here; the date parser collapses
        // "blank" and "invalid" to null. Remember the text so `buildPreview()`
        // can refuse an update row instead of writing that null over the date
        // the record already stores. The raw text must never ride inside
        // `data`: those keys go straight into `Hardware::insert()`.
        $invalid = [];
        if (($invalidCleanAt = $this->invalidCleanAtCell($row['clean_at'] ?? null)) !== null) {
            $invalid['clean_at'] = $invalidCleanAt;
        }

        return [
            'data' => [
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
            ],
            'invalid' => $invalid,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function processRow(array $data, int $rowNumber, string $action = 'auto', bool $isConfirmation = false): void
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

        // #1829: buildPreview() already rejected this split match and the
        // #1209 guard in applySelectedAction() keeps the row from reaching
        // here. Should that ever change, the row must never fall through to
        // the create branch and leave the two devices sharing one mac.
        if ($existing !== null && $existing['conflict'] !== null) {
            $this->importResults['skipped']++;

            return;
        }

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

                $this->pendingCreates[] = [
                    'row' => $rowNumber,
                    'data' => $writeData,
                    'changes' => $this->createAuditChanges($writeData),
                ];

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
        return self::WRITE_FIELDS;
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
        return Hardware::normalizeForSearch($value);
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
     * #1667: a sheet can carry the same NEW device twice (copy-paste, a
     * re-exported range). `hardwares` has no unique constraint on the
     * compare key (see ImportHardwareJob), so both rows would be inserted
     * and a later import could match either one. Collapse queued creates
     * like PersonImport does for n_code (#1194) — last write wins — but
     * keyed on the compare key, because a match is what makes two rows the
     * same device.
     *
     * Under `both` a row collides when EITHER field matches the current
     * survivor. That is a CREATE-path rule only: the existing-record matcher
     * does the opposite for a row whose pc_name and mac point at two
     * different devices — findExistingRecord() reports the split match and
     * buildPreview() rejects it (#1829). Replacing a survivor
     * also RELEASES its old keys, because the surviving row has been
     * renamed by the later sheet row: A=(p1,m1), B=(p2,m1), C=(p1,m2)
     * yields TWO rows (B renames A to p2, so C matches nothing), while
     * A=(p1,m1), B=(p1,m2), C=(p2,m2) yields ONE.
     *
     * The replaced row's `changes` are dropped: the audit trail records one
     * `created` row with the surviving (last) values, exactly as the update
     * dedupe below and PersonImport already do. Every dropped row is also
     * reported in `errors`, naming its sheet row, the key it collided on
     * and the surviving row, so a swapped/mistyped pc_name or mac is
     * visible instead of vanishing silently.
     */
    protected function dedupePendingCreates(): void
    {
        /** @var list<array<string, mixed>> $kept */
        $kept = [];
        /** @var array<string, int> $keyIndex */
        $keyIndex = [];

        foreach ($this->pendingCreates as $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $item['data'];
            /** @var int $itemRow */
            $itemRow = $item['row'];

            $keys = $this->createDedupeKeys($itemData);

            // Collect every survivor this row collides with (pc_name may hit
            // one row while mac hits another), keeping the first key that
            // matched so the report names a concrete column and value.
            /** @var array<int, string> $victims */
            $victims = [];
            foreach ($keys as $key) {
                if (isset($keyIndex[$key])) {
                    $victims[$keyIndex[$key]] ??= $key;
                }
            }

            foreach ($victims as $index => $collidingKey) {
                $victim = $kept[$index];
                /** @var array<string, mixed> $victimData */
                $victimData = $victim['data'];

                foreach ($this->createDedupeKeys($victimData) as $victimKey) {
                    unset($keyIndex[$victimKey]);
                }

                unset($kept[$index]);

                [$field, $value] = explode(':', $collidingKey, 2);

                $this->importResults['errors'][] = [
                    'row' => $victim['row'],
                    'error' => "دستگاه تکراری؛ با ردیف {$itemRow} روی {$field} «{$value}» یکسان است و نادیده گرفته شد.",
                    'data' => $victimData,
                    'key' => $field,
                    'value' => $value,
                    'duplicate_of' => $itemRow,
                ];
            }

            $kept[] = $item;
            $index = array_key_last($kept);

            foreach ($keys as $key) {
                $keyIndex[$key] = $index;
            }
        }

        $droppedCreates = count($this->pendingCreates) - count($kept);

        if ($droppedCreates > 0) {
            $this->importResults['created'] = max(0, $this->importResults['created'] - $droppedCreates);
        }

        $this->pendingCreates = array_values($kept);
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    protected function insertCreateChunk(array $chunk): void
    {
        $now = now();
        $ids = $this->reserveHardwareIds(count($chunk));
        $rows = [];
        $auditChanges = [];

        foreach ($chunk as $index => $item) {
            /** @var array<string, mixed> $data */
            $data = $item['data'];
            /** @var array<string, mixed> $changes */
            $changes = $item['changes'];
            $id = $ids[$index];
            $rows[] = array_merge($data, [
                'id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $auditChanges[$id] = $changes;
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
            /** @var int $id */
            $id = $item['id'];
            $auditChanges[$id] = $item['changes'];
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
    protected function updateColumns(): array
    {
        return self::WRITE_FIELDS;
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

    /**
     * Hardware computes "wrote anything" AFTER dedupe on purpose (Person does
     * the opposite); see AbstractImportPipeline.
     */
    protected function shouldBumpCaches(bool $hadQueued, bool $hasAfterDedupe): bool
    {
        return $hasAfterDedupe;
    }

    protected function afterFlush(): void
    {
        // Bulk writes bypass `saved`, so the model's flush must be invoked
        // explicitly — inside the batch scope it lands as one increment per
        // namespace (#376 / #1167).
        Hardware::flushStatsCache();
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
    protected function normalizeForComparison($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        // #1034: the CSV null token is a single backslash + N (source `'\\N'`),
        // matching clean() and parseBoolean() below. The previous source literal
        // spelled ten backslashes (five at runtime, since single quotes halve
        // them) so it could never match the real token; a raw `\N` fell through
        // to trim() as the two characters `\` and `N`.
        // #1851: null / '' / '\N' are the "no value" sentinel. It must NOT
        // collapse into the literal string '0' — a text column (port, vlan,
        // switch, comments, …) may legitimately store "0". Booleans are the
        // only values that get the 0/1 collapse, and both sides of
        // shutdown/mark are real PHP bools (model casts + parseBoolean()).
        if ($value === null || $value === '' || $value === '\\N') {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        // Handle boolean 0 values that come from database
        if ($value === 0 || $value === '0') {
            return '0';
        }
        if ($value === 1 || $value === '1') {
            return '1';
        }

        return trim((string) $value);
    }

    /**
     * #890: the importer must understand the tokens the EXPORTER writes.
     *
     * `HardwareExport` renders `shutdown` as «خاموش»/«روشن» and `mark` as
     * «علامت‌دار»/«-», so re-importing an exported sheet fed those labels into a
     * parser that only knew latin tokens: every `true` came back `false`. The
     * measured round-trip in HardwareExportImportRoundTripTest is what proved it.
     *
     * #1637: `shutdown = true` means «خاموش شود» (the owner's off-hours power-off
     * program reads those rows), so «خاموش» is `true` and «روشن»/«فعال» are
     * `false`. The export's own `mark` column writes «-» for false, so that has
     * to read as false here too.
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
        // #1637: the exporter writes «خاموش» for shutdown=true.
        if (in_array($val, ['خاموش', 'علامت‌دار', 'علامت', 'دارد'], true)) {
            return true;
        }

        // Explicit false tokens, including the exporter's own dash.
        // #1637: the exporter writes «روشن» for shutdown=false.
        if (in_array($val, ['0', 'false', 'no', 'off', 'خیر', 'روشن', 'فعال', '-', 'بدون علامت'], true)) {
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
     * with Persian `۰-۹` or Arabic-Indic `٠-٩` digits as well (#1704), and
     * returns canonical Gregorian `Y-m-d` for storage. Returns null for
     * anything else, including a shape that matches but is not a real date.
     *
     * #1704: null means "blank cell OR unparseable cell" — the two are not
     * distinguishable here. A consumer must classify the raw cell with
     * `isBlankDateCell()` first when the difference matters: this importer
     * rejects an UPDATE row whose cell is non-empty but unparseable (so a
     * stored date is never silently erased) and stores NULL only for a blank
     * one; a CREATE row keeps the documented #890 behaviour (`NULL`).
     */
    private function parseDate($value): ?string
    {
        return $this->parseGregorianOrJalaliDate($value);
    }

    /**
     * #1704: the raw text of a `clean_at` cell that is neither blank nor
     * parseable, or null when the cell is fine.
     */
    private function invalidCleanAtCell(mixed $value): ?string
    {
        if ($this->isBlankDateCell($value) || $this->parseDate($value) !== null) {
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
}
