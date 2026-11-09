<?php

namespace App\Services;

use App\Models\Patient;
use App\Rules\IranianNationalCode;
use App\Support\JalaliDateRange;
use App\Traits\PersianNormalizer;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
 * The input is Jalali (`birth_date_jalali`); `create()` validates the
 * NORMALIZED copy of the payload with `validationRules()` (#1300) — rules are
 * never restated. Only the five validated keys reach the insert; a Gregorian
 * `birth_date` key in the input is ignored.
 *
 * Insert + audit are one transaction: the audit row is written after the
 * insert (it needs `id`) inside it, so a failed audit rolls the patient back.
 * A PostgreSQL `23505` becomes a `ValidationException` under the `duplicate`
 * key with a PII-free message; every other DB error propagates untranslated.
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
        // Normalize FIRST, then validate the normalized copy against the same
        // shared rules — a Persian-digits code would fail `digits:10` raw.
        $normalized = [
            'kod_melli' => trim(strtr((string) ($data['kod_melli'] ?? ''), self::digitMap())),
            'first_name' => $this->nullableString($data['first_name'] ?? null),
            'last_name' => $this->nullableString($data['last_name'] ?? null),
            'birth_date_jalali' => is_string($data['birth_date_jalali'] ?? null)
                ? trim($data['birth_date_jalali'])
                : '',
            'gender' => (string) ($data['gender'] ?? ''),
        ];

        $validated = Validator::make($normalized, self::validationRules())->validate();

        try {
            return DB::transaction(function () use ($validated): Patient {
                // Only the validated keys are written; the Gregorian date is
                // derived from the validated Jalali string, never from $data.
                $patient = Patient::query()->create([
                    'kod_melli' => $validated['kod_melli'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'birth_date' => $this->birthDate($validated['birth_date_jalali'] ?? null),
                    'gender' => $validated['gender'],
                ]);

                // After the insert (the audit needs `id`), inside the transaction.
                ActivityLogService::created($patient, 'ثبت بیمار جدید');

                return $patient;
            });
        } catch (UniqueConstraintViolationException) {
            // Outside the transaction: it has already rolled back. Generic,
            // PII-free — callers name the existing patient via their lookup.
            throw ValidationException::withMessages([
                'duplicate' => 'این کد ملی قبلاً ثبت شده است.',
            ]);
        }
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
