<?php

namespace App\Services\Disease;

use App\Models\Patient;
use App\Services\ActivityLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Minimal patient CRUD (gate decision "الف").
 *
 * Search, the national-code checksum and inline-create UI are #1243 — but that
 * issue must go through this service for writes, otherwise patient audit is
 * bypassed. No unit scope here: direct patient access is permission-gated
 * (spec §4.1); `Patient::visibleTo()` covers the case-derived path.
 */
final class PatientService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $actorUnitId): Patient
    {
        $validated = Validator::make($data, $this->rules())->validate();

        try {
            return DB::transaction(function () use ($validated, $actorUnitId): Patient {
                $patient = Patient::query()->create($validated);

                ActivityLogService::log('created', $patient, 'ثبت بیمار', null, [
                    'unit_id' => $actorUnitId,
                ]);

                return $patient;
            });
        } catch (QueryException $e) {
            $this->translateDuplicate($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $patientId, array $data, int $actorUnitId): Patient
    {
        $patient = Patient::query()->findOrFail($patientId);
        $validated = Validator::make($data, $this->rules($patient->id))->validate();
        $payload = ['unit_id' => $actorUnitId];

        try {
            DB::transaction(function () use ($patient, $validated, $payload): void {
                $patient->fill($validated)->save();

                ActivityLogService::log('updated', $patient, 'ویرایش بیمار', $payload, $payload);
            });
        } catch (QueryException $e) {
            $this->translateDuplicate($e);
        }

        return $patient;
    }

    public function delete(int $patientId, int $actorUnitId): void
    {
        $patient = Patient::query()->findOrFail($patientId);

        if ($patient->diseaseCases()->exists()) {
            throw ValidationException::withMessages([
                'patient' => 'این بیمار کیس ثبت‌شده دارد و قابل حذف نیست.',
            ]);
        }

        DB::transaction(function () use ($patient, $actorUnitId): void {
            $patient->delete();

            ActivityLogService::log('deleted', $patient, 'حذف بیمار', [
                'unit_id' => $actorUnitId,
            ], null);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignorePatientId = null): array
    {
        return [
            'kod_melli' => [
                'required',
                'digits:10',
                Rule::unique('patients', 'kod_melli')->ignore($ignorePatientId),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    private function translateDuplicate(QueryException $e): never
    {
        if (($e->errorInfo[0] ?? null) === '23505') {
            throw ValidationException::withMessages([
                'kod_melli' => 'این کد ملی قبلاً ثبت شده است.',
            ]);
        }

        throw $e;
    }
}
