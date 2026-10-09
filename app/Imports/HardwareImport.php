<?php

namespace App\Imports;

use App\Exports\Concerns\FormatsJalaliDates;
use App\Models\Hardware;
use App\Models\Person;
use App\Services\AccessService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class HardwareImport implements ToCollection, WithCustomCsvSettings, WithHeadingRow
{
    use FormatsJalaliDates;

    private array $accessibleUnitIds = [];

    private array $existingRecords = [];

    private array $existingPersons = [];

    private string $compareKey = 'both'; // 'pc_name', 'mac', 'both'

    private array $selectedActions = [];

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
        // Pre-load existing hardware records by pc_name and mac for comparison
        $this->loadExistingRecords();

        // First pass: build preview data
        foreach ($rows as $index => $row) {
            $this->buildPreview($row->toArray(), $index + 2); // +2 for header row and 1-indexed
        }

        // Second pass: apply selected actions if provided
        if (! empty($this->selectedActions)) {
            // Reset counters since they were incremented during preview pass
            // The actual import will increment them correctly
            $this->importResults['created'] = 0;
            $this->importResults['updated'] = 0;
            $this->importResults['skipped'] = 0;
            $this->importResults['changes'] = [];

            foreach ($rows as $index => $row) {
                $this->applySelectedAction($row->toArray(), $index + 2);
            }
        }
    }

    private function loadExistingRecords(): void
    {
        $query = Hardware::query();

        // Apply organizational scope — UNCONDITIONAL (issue #839 / Plan 50).
        // `[]` is a legitimate `AccessService` output, so an emptiness guard
        // here would index EVERY hardware row org-wide by pc_name / mac and a
        // matching CSV row would rewrite a record the actor cannot see.
        // An empty array compiles to `0 = 1` (fail-closed by construction).
        $query->whereHas('person', function ($q) {
            $q->whereIn('u_id', $this->accessibleUnitIds);
        });

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
        $this->loadExistingPersons();
    }

    private function loadExistingPersons(): void
    {
        $query = Person::query();

        // Apply organizational scope — UNCONDITIONAL (issue #839). `[]` must not
        // fall through to "every person in the org".
        $query->whereIn('u_id', $this->accessibleUnitIds);

        $persons = $query->get(['n_code', 'u_id', 'f_name', 'l_name']);

        foreach ($persons as $person) {
            $this->existingPersons[$person->n_code] = $person;
        }
    }

    private function applySelectedAction($row, int $rowNumber): void
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

        $this->processRow($row, $rowNumber, $action, true);
    }

    private function buildPreview($row, int $rowNumber): void
    {
        // Map CSV columns to hardware fields
        $data = $this->mapRowToData($row);

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

    private function processRow(array $row, int $rowNumber, string $action = 'auto', bool $isConfirmation = false): void
    {
        $data = $this->mapRowToData($row);

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

                // Update the record
                if ($action === 'update' || $action === 'auto') {
                    $existing['record']->update($data);
                    $this->importResults['updated']++;
                }
            } else {
                $this->importResults['skipped']++;
            }
        } else {
            // Create new record
            if ($action === 'create' || $action === 'auto') {
                Hardware::create($data);
                $this->importResults['created']++;
            }
        }
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
        if ($value === null || $value === '' || $value === '\\\\\\\\\\N') {
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
