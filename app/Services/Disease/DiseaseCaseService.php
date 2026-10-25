<?php

namespace App\Services\Disease;

use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Patient;
use App\Services\ActivityLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Create/update/delete disease cases (spec §3.4, §4).
 *
 * No `auth()` / `session()` here: the recorder unit and the accessible unit
 * ids are arguments, so the same service is usable from a queue worker
 * (#836's warning). Every write is audited with non-clinical metadata only —
 * never `fields`, the patient's name or the national code.
 */
final class DiseaseCaseService
{
    public function __construct(private readonly DiseaseFieldValidator $fieldValidator) {}

    /**
     * @param  array{patient_id?: int, disease_id?: int, subject_unit_id?: int, diagnosed_at?: string, recorded_at?: string|null, fields?: mixed}  $data
     * @param  array<int>  $accessibleUnitIds
     */
    public function create(array $data, int $recorderUnitId, array $accessibleUnitIds): DiseaseCase
    {
        $subjectUnitId = $this->accessibleSubjectUnit($data['subject_unit_id'] ?? null, $accessibleUnitIds);
        $patientId = $this->existingPatientId($data['patient_id'] ?? null);
        $disease = $this->activeDisease($data['disease_id'] ?? null);

        $diagnosedAt = $this->dateValue($data['diagnosed_at'] ?? null, 'diagnosed_at', true);
        $recordedAt = $this->dateValue($data['recorded_at'] ?? null, 'recorded_at', false)
            ?? now()->format('Y-m-d');

        $fields = $this->validatedFields($disease, $data['fields'] ?? []);

        $case = new DiseaseCase;
        $case->fill([
            'patient_id' => $patientId,
            'disease_id' => $disease->id,
            'unit_id' => $recorderUnitId,
            'subject_unit_id' => $subjectUnitId,
            'diagnosed_at' => $diagnosedAt,
            'recorded_at' => $recordedAt,
            'fields' => $fields,
        ]);

        $payload = [
            'patient_id' => $patientId,
            'disease_id' => $disease->id,
            'unit_id' => $recorderUnitId,
            'subject_unit_id' => $subjectUnitId,
            'diagnosed_at' => $diagnosedAt,
            'recorded_at' => $recordedAt,
        ];

        try {
            DB::transaction(function () use ($case, $payload): void {
                $case->save();
                ActivityLogService::log('created', $case, 'ثبت کیس بیماری', null, $payload);
            });
        } catch (QueryException $e) {
            $this->translateDuplicate($e);
        }

        return $case;
    }

    /**
     * @param  array{patient_id?: int, disease_id?: int, subject_unit_id?: int, diagnosed_at?: string, recorded_at?: string|null, fields?: mixed}  $data
     * @param  array<int>  $accessibleUnitIds
     */
    public function update(int $caseId, array $data, array $accessibleUnitIds): DiseaseCase
    {
        $case = DiseaseCase::visibleTo($accessibleUnitIds)->findOrFail($caseId);

        $subjectUnitId = $this->accessibleSubjectUnit($data['subject_unit_id'] ?? null, $accessibleUnitIds);
        $patientId = $this->existingPatientId($data['patient_id'] ?? null);

        $newDiseaseId = is_numeric($data['disease_id'] ?? null) ? (int) $data['disease_id'] : 0;
        $diseaseIsChanging = $newDiseaseId !== $case->disease_id;
        $disease = $this->activeDisease($newDiseaseId, $diseaseIsChanging);

        $diagnosedAt = $this->dateValue($data['diagnosed_at'] ?? null, 'diagnosed_at', true);
        $recordedAt = $this->dateValue($data['recorded_at'] ?? null, 'recorded_at', false)
            ?? $case->recorded_at->format('Y-m-d');

        $fields = $this->validatedFields($disease, $data['fields'] ?? []);

        $oldPayload = $this->auditPayload($case);

        // `unit_id` (the recorder) is deliberately not filled: it is server state.
        $case->fill([
            'patient_id' => $patientId,
            'disease_id' => $disease->id,
            'subject_unit_id' => $subjectUnitId,
            'diagnosed_at' => $diagnosedAt,
            'recorded_at' => $recordedAt,
            'fields' => $fields,
        ]);

        $newPayload = $this->auditPayload($case);

        try {
            DB::transaction(function () use ($case, $oldPayload, $newPayload): void {
                $case->save();
                ActivityLogService::log('updated', $case, 'ویرایش کیس بیماری', $oldPayload, $newPayload);
            });
        } catch (QueryException $e) {
            $this->translateDuplicate($e);
        }

        return $case;
    }

    /** @param array<int> $accessibleUnitIds */
    public function delete(int $caseId, array $accessibleUnitIds): void
    {
        $case = DiseaseCase::visibleTo($accessibleUnitIds)->findOrFail($caseId);
        $payload = $this->auditPayload($case);

        DB::transaction(function () use ($case, $payload): void {
            $case->delete();
            ActivityLogService::log('deleted', $case, 'حذف کیس بیماری', $payload, null);
        });
    }

    /**
     * @param  array<int>  $accessibleUnitIds
     */
    private function accessibleSubjectUnit(mixed $unitId, array $accessibleUnitIds): int
    {
        $subjectUnitId = is_numeric($unitId) ? (int) $unitId : 0;
        $accessible = array_map(intval(...), $accessibleUnitIds);

        if (! in_array($subjectUnitId, $accessible, true)) {
            throw ValidationException::withMessages([
                'subject_unit_id' => 'واحد بیمار باید در محدودهٔ دسترسی شما باشد.',
            ]);
        }

        return $subjectUnitId;
    }

    private function existingPatientId(mixed $patientId): int
    {
        $id = is_numeric($patientId) ? (int) $patientId : 0;

        if ($id === 0 || ! Patient::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'patient_id' => 'بیمار انتخاب‌شده یافت نشد.',
            ]);
        }

        return $id;
    }

    private function activeDisease(mixed $diseaseId, bool $mustBeActive = true): Disease
    {
        $id = is_numeric($diseaseId) ? (int) $diseaseId : 0;
        $disease = $id > 0 ? Disease::query()->with('fieldDefs')->find($id) : null;

        if (! $disease instanceof Disease) {
            throw ValidationException::withMessages([
                'disease_id' => 'بیماری انتخاب‌شده یافت نشد.',
            ]);
        }

        if ($mustBeActive && ! $disease->is_active) {
            throw ValidationException::withMessages([
                'disease_id' => 'ثبت کیس جدید برای بیماری غیرفعال مجاز نیست.',
            ]);
        }

        return $disease;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFields(Disease $disease, mixed $fields): array
    {
        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                'fields' => 'ساختار فیلدهای کیس معتبر نیست.',
            ]);
        }

        return $this->fieldValidator->validate($disease->fieldDefs, $fields);
    }

    private function dateValue(mixed $value, string $key, bool $required): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw ValidationException::withMessages([$key => 'تاریخ الزامی است.']);
            }

            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ValidationException::withMessages([$key => 'تاریخ واردشده معتبر نیست.']);
        }

        $date = null;

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            // Falls through to the shared error below.
        }

        if (! $date instanceof Carbon || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$key => 'تاریخ واردشده معتبر نیست.']);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(DiseaseCase $case): array
    {
        return [
            'patient_id' => (int) $case->patient_id,
            'disease_id' => (int) $case->disease_id,
            'unit_id' => (int) $case->unit_id,
            'subject_unit_id' => (int) $case->subject_unit_id,
            'diagnosed_at' => $case->diagnosed_at->format('Y-m-d'),
            'recorded_at' => $case->recorded_at->format('Y-m-d'),
        ];
    }

    private function translateDuplicate(QueryException $e): never
    {
        if (($e->errorInfo[0] ?? null) === '23505') {
            throw ValidationException::withMessages([
                'duplicate' => 'برای این بیمار در این بیماری و تاریخ تشخیص قبلاً کیس ثبت شده است.',
            ]);
        }

        throw $e;
    }
}
