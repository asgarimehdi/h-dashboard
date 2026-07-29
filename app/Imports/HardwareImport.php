<?php

namespace App\Imports;

use App\Models\Hardware;
use App\Models\Person;
use App\Services\AccessService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class HardwareImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{

    private array $accessibleUnitIds = [];
    private array $existingRecords = [];
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
        if (!empty($this->selectedActions)) {
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

        // Apply organizational scope
        if (!empty($this->accessibleUnitIds)) {
            $query->whereHas('person', function ($q) {
                $q->whereIn('u_id', $this->accessibleUnitIds);
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
    }

    private function applySelectedAction($row, int $rowNumber): void
    {
        // Check if this row has a selected action
        $actionKey = "row_{$rowNumber}";
        if (!isset($this->selectedActions[$actionKey])) {
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
                $person = Person::where('n_code', $data['n_code'])->first();
                if (!$person) {
                    $this->importResults['preview'][] = [
                        'row' => $rowNumber,
                        'status' => 'error',
                        'message' => "پرسنل با کد ملی {$data['n_code']} یافت نشد",
                        'data' => $data,
                    ];
                    $this->importResults['skipped']++;
                    return;
                }

                if (!empty($this->accessibleUnitIds) && !in_array($person->u_id, $this->accessibleUnitIds)) {
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

                    if (!empty($changes)) {
                        $this->importResults['preview'][] = [
                            'row' => $rowNumber,
                            'status' => 'update',
                            'id' => $existing['record']->id,
                            'pc_name' => $existing['record']->pc_name,
                            'match_key' => $matchKey,
                            'changes' => $changes,
                            'person' => $person->f_name . ' ' . $person->l_name,
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
                            'person' => $person->f_name . ' ' . $person->l_name,
                            'data' => $data,
                        ];
                        $this->importResults['skipped']++;
                    }
                } else {
                    $this->importResults['preview'][] = [
                        'row' => $rowNumber,
                        'status' => 'create',
                        'pc_name' => $data['pc_name'],
                        'person' => $person->f_name . ' ' . $person->l_name,
                        'data' => $data,
                    ];
                    $this->importResults['created']++;
                }
            }

    private function findExistingRecord(array $data): ?array
    {
        $existing = null;
        $matchKey = null;

        if (in_array($this->compareKey, ['pc_name', 'both']) && !empty($data['pc_name']) && isset($this->existingRecords['pc_name'][$data['pc_name']])) {
            $existing = $this->existingRecords['pc_name'][$data['pc_name']];
            $matchKey = 'pc_name';
        } elseif (in_array($this->compareKey, ['mac', 'both']) && !empty($data['mac']) && isset($this->existingRecords['mac'][$data['mac']])) {
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
        $person = Person::where('n_code', $data['n_code'])->first();
        if (!$person) {
            $this->importResults['errors'][] = [
                'row' => $rowNumber,
                'error' => "Person with n_code {$data['n_code']} not found",
                'data' => $data,
            ];
            $this->importResults['skipped']++;
            return;
        }

        if (!empty($this->accessibleUnitIds) && !in_array($person->u_id, $this->accessibleUnitIds)) {
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

            if (!empty($changes)) {
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
            'motherboard', 'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at'
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

    private function normalizeForComparison($value): ?string
            {
                if ($value === null || $value === '' || $value === '\\\\\\\\\\\\\\\\N') {
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
                return trim((string)$value);
            }

    private function parseBoolean($value): ?bool
    {
        if ($value === null || $value === '' || $value === '\\N') {
            return null;
        }
        $val = strtolower(trim((string)$value));
        return in_array($val, ['1', 'true', 'yes', 'on', 'بله', 'تایید']);
    }

    private function parseDate($value): ?string
    {
        if ($value === null || $value === '' || $value === '\\N') {
            return null;
        }
        $value = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        return null;
    }

    private function clean($value): ?string
        {
            if ($value === null || $value === '' || $value === '\\N' || trim((string)$value) === '') {
                return null;
            }
            return trim((string)$value);
        }

    public function rules(): array
    {
        return [
            'n_code' => 'required',
            'pc_name' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'os' => 'nullable|string|max:100',
            'ip_valid' => 'nullable|string|max:45',
            'ip_local' => 'nullable|string|max:45',
            'mac' => 'nullable|string|max:17',
            'net_type' => 'nullable|string|max:50',
            'switch' => 'nullable|string|max:100',
            'port' => 'nullable|string|max:50',
            'shutdown' => 'nullable|boolean',
            'vlan' => 'nullable|string|max:50',
            'motherboard' => 'nullable|string|max:100',
            'cpu' => 'nullable|max:100',
            'ram' => 'nullable|max:50',
            'hdd' => 'nullable|max:100',
            'comments' => 'nullable|string',
            'mark' => 'nullable|boolean',
            'clean_at' => 'nullable|date_format:Y-m-d',
        ];
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