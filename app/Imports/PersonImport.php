<?php

namespace App\Imports;

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PersonImport implements ToCollection, WithCustomCsvSettings, WithHeadingRow
{
    private array $accessibleUnitIds = [];

    private array $existingRecords = [];

    private string $compareKey = 'n_code'; // always match by n_code for persons

    private array $selectedActions = [];

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
        // Pre-load existing person records by n_code for comparison
        $this->loadExistingRecords();

        // Pre-load all related reference data to avoid N+1 queries
        $this->loadReferenceData();

        // First pass: build preview data
        foreach ($rows as $index => $row) {
            $this->buildPreview($row->toArray(), $index + 2); // +2 for header row and 1-indexed
        }

        // Second pass: apply selected actions if provided
        if (! empty($this->selectedActions)) {
            // Reset counters since they were incremented during preview pass
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
        $query = Person::query();

        // Apply organizational scope
        if (! empty($this->accessibleUnitIds)) {
            $query->whereIn('u_id', $this->accessibleUnitIds);
        }

        // NOTE: 'id' MUST be selected — records are later updated via these
        // models; without the PK, update() issues WHERE id IS NULL and
        // silently persists nothing while counters still report success.
        $persons = $query->get(['id', 'n_code', 'f_name', 'l_name', 't_id', 'e_id', 'r_id', 's_id', 'u_id']);

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
        $unitQuery = Unit::query();
        if (! empty($this->accessibleUnitIds)) {
            $unitQuery->whereIn('id', $this->accessibleUnitIds);
        }
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
        // Map CSV columns to person fields
        $data = $this->mapRowToData($row);

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

            if (! empty($this->accessibleUnitIds) && ! in_array($unit->id, $this->accessibleUnitIds)) {
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
        // Expected CSV headers: n_code, f_name, l_name, t_id, e_id, r_id, s_id, u_id
        return [
            'n_code' => $this->clean($row['n_code'] ?? null),
            'f_name' => $this->clean($row['f_name'] ?? null),
            'l_name' => $this->clean($row['l_name'] ?? null),
            't_id' => $this->parseInt($row['t_id'] ?? null),
            'e_id' => $this->parseInt($row['e_id'] ?? null),
            'r_id' => $this->parseInt($row['r_id'] ?? null),
            's_id' => $this->parseInt($row['s_id'] ?? null),
            'u_id' => $this->parseInt($row['u_id'] ?? null),
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

    private function processRow(array $row, int $rowNumber, string $action = 'auto', bool $isConfirmation = false): void
    {
        $data = $this->mapRowToData($row);

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

        if (! empty($this->accessibleUnitIds) && ! in_array($unit->id, $this->accessibleUnitIds)) {
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
                Person::create($data);
                $this->importResults['created']++;
            }
        }
    }

    private function detectChanges(Person $existing, array $newData): array
    {
        $changes = [];
        $compareFields = ['f_name', 'l_name', 't_id', 'e_id', 'r_id', 's_id', 'u_id'];

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
