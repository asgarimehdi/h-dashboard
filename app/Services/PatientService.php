<?php

namespace App\Services;

use App\Models\Patient;
use App\Rules\IranianNationalCode;
use App\Support\JalaliDateRange;
use App\Traits\PersianNormalizer;
use Closure;
use Morilog\Jalali\Jalalian;

/**
 * The patient registry's only write path (#1243) — shared with the case form's
 * inline-create (#1244).
 *
 * Deliberately scope-free: direct patient access is permission-gated (spec
 * §4.1) and the `patients` table has no unit column. The unit predicate belongs
 * to cases only (`Patient::visibleTo()` is the case-derived read path).
 *
 * The national code is canonicalized here and nowhere else: Persian/Arabic
 * digits become Latin and the value keeps its leading zeros (the column is
 * `string(10)`). Names are stored as typed — the search folds the COLUMN at
 * query time (`foldSeparatorsSql()`), so no `saving` hook rewrites them.
 *
 * Audit carries no PII: only the action and the subject. Never add a name or a
 * national code to the description (decisions 18/22).
 */
final class PatientService
{
    use PersianNormalizer;

    /**
     * The rules shared by both write surfaces. Keys match the Livewire
     * properties the partial `disease.partials.patient-form` binds to.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'kod_melli' => ['required', 'string', 'digits:10', new IranianNationalCode],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date_jalali' => [
                'nullable',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    // `nullable` only skips null; an empty string from a cleared
                    // picker is "no birth date", not a malformed one.
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    $date = JalaliDateRange::parse($value);

                    // Closed decision «ب»: a future birth date is rejected.
                    if ($date === null || $date->isFuture()) {
                        $fail('تاریخ تولد معتبر نیست.');
                    }
                },
            ],
            'gender' => ['required', 'in:male,female'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Patient
    {
        $patient = Patient::query()->create([
            'kod_melli' => trim(strtr((string) ($data['kod_melli'] ?? ''), self::digitMap())),
            'first_name' => $this->nullableString($data['first_name'] ?? null),
            'last_name' => $this->nullableString($data['last_name'] ?? null),
            'birth_date' => $this->birthDate($data['birth_date_jalali'] ?? null),
            'gender' => (string) ($data['gender'] ?? ''),
        ]);

        ActivityLogService::created($patient, 'ثبت بیمار جدید');

        return $patient;
    }

    /**
     * Exact national-code lookup; Persian/Arabic digits are folded first.
     */
    public function findByKodMelli(string $raw): ?Patient
    {
        $code = trim(strtr($raw, self::digitMap()));

        if ($code === '') {
            return null;
        }

        return Patient::query()->where('kod_melli', $code)->first();
    }

    /**
     * One uniqueness message for both write surfaces, naming the existing
     * patient and pointing at the action that opens their record.
     */
    public static function duplicateMessage(Patient $existing): string
    {
        return "این کد ملی قبلاً برای «{$existing->fullName()}» ثبت شده است؛ از دکمهٔ «مشاهدهٔ پرونده» استفاده کنید.";
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function birthDate(mixed $value): ?string
    {
        $jalali = is_string($value) ? trim($value) : '';

        if ($jalali === '') {
            return null;
        }

        return Jalalian::fromFormat('Y/m/d', $jalali)->toCarbon()->toDateString();
    }
}
