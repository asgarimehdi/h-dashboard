<?php

namespace App\Traits;

use App\Models\DiseaseFieldDef;
use App\Services\Disease\DiseaseFieldValidator;
use App\Support\JalaliDateRange;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Morilog\Jalali\Jalalian;
use Throwable;

/**
 * Shared form logic for the disease case screens (#1244).
 *
 * `disease.create` and `disease.index` render the same dynamic field form, so
 * the definition lookup, the immediate-feedback rules, the Jalali↔Gregorian
 * conversion and the `x-select` option mapping live here once.
 *
 * This trait does NOT validate the final `fields` payload — that is
 * `DiseaseFieldValidator` (#1241, consumed through `DiseaseCaseService`). The
 * rules here only exist so the user sees an error on the input they just
 * touched instead of waiting for the submit round-trip.
 */
trait BuildsDiseaseCaseForm
{
    /** @var array<string, mixed> */
    public array $fields = [];

    /** @var Collection<int, DiseaseFieldDef>|null */
    private ?Collection $fieldDefsMemo = null;

    private ?int $fieldDefsMemoDiseaseId = null;

    /**
     * Active definitions for a disease, ordered for display. Memoised per
     * request (private property → never dehydrated into the snapshot).
     *
     * @return Collection<int, DiseaseFieldDef>
     */
    protected function fieldDefsFor(?int $diseaseId): Collection
    {
        if ($diseaseId === null) {
            return new Collection;
        }

        if ($this->fieldDefsMemo === null || $this->fieldDefsMemoDiseaseId !== $diseaseId) {
            $this->fieldDefsMemo = DiseaseFieldDef::query()
                ->where('disease_id', $diseaseId)
                ->where('is_active', true)
                ->orderBy('sort')
                ->get();
            $this->fieldDefsMemoDiseaseId = $diseaseId;
        }

        return $this->fieldDefsMemo;
    }

    /**
     * A fresh `fields` state for a disease — every known key exists, so a
     * disease switch can discard the previous disease's keys wholesale.
     *
     * @return array<string, string>
     */
    protected function emptyFieldsFor(?int $diseaseId): array
    {
        $fields = [];

        foreach ($this->fieldDefsFor($diseaseId) as $def) {
            $fields[$def->field_key] = '';
        }

        return $fields;
    }

    /**
     * Immediate-feedback rules. Empty optional values are pruned before
     * validation, so non-implicit rules never run on an absent key.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function fieldRules(?int $diseaseId): array
    {
        $rules = [];

        foreach ($this->fieldDefsFor($diseaseId) as $def) {
            $key = "fields.{$def->field_key}";

            $rules[$key] = match ($def->type) {
                'number' => $def->required ? ['required', 'numeric'] : ['numeric'],
                'select' => $def->required
                    ? ['required', Rule::in($def->options ?? [])]
                    : [Rule::in($def->options ?? [])],
                'date' => array_values(array_filter([
                    $def->required ? 'required' : null,
                    static function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value === null || (is_string($value) && trim($value) === '')) {
                            return;
                        }

                        if (! is_string($value) || JalaliDateRange::parse($value) === null) {
                            $fail('تاریخ واردشده معتبر نیست.');
                        }
                    },
                ])),
                'text' => $def->required
                    ? ['required', 'string', 'max:'.DiseaseFieldValidator::MAX_TEXT_LENGTH]
                    : ['string', 'max:'.DiseaseFieldValidator::MAX_TEXT_LENGTH],
                default => [],
            };
        }

        return $rules;
    }

    /**
     * Drop empty values so an optional input left blank is "absent", not a
     * type error. `0` and `"0"` stay (they are values).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function nonEmptyFields(array $fields): array
    {
        return array_filter(
            $fields,
            static fn (mixed $value): bool => ! ($value === null || (is_string($value) && trim($value) === '')),
        );
    }

    /**
     * The payload `DiseaseCaseService` receives: date fields leave the form as
     * Jalali `Y/m/d` and are stored as Gregorian `Y-m-d` (decision «ه»).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function fieldsForService(array $fields, ?int $diseaseId): array
    {
        $dateKeys = $this->fieldDefsFor($diseaseId)
            ->where('type', 'date')
            ->pluck('field_key')
            ->all();

        $out = [];

        foreach ($fields as $key => $value) {
            if (in_array($key, $dateKeys, true) && is_string($value) && trim($value) !== '') {
                $out[$key] = JalaliDateRange::parse($value)?->toDateString() ?? $value;

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Stored Gregorian values back into the Jalali strings the pickers bind to.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    protected function fieldsFromStored(array $stored, ?int $diseaseId): array
    {
        $fields = $this->emptyFieldsFor($diseaseId);

        foreach ($this->fieldDefsFor($diseaseId) as $def) {
            if (! array_key_exists($def->field_key, $stored)) {
                continue;
            }

            $value = $stored[$def->field_key];

            if ($def->type === 'date' && is_string($value) && $value !== '') {
                $carbon = null;

                try {
                    $carbon = Carbon::createFromFormat('!Y-m-d', $value);
                } catch (Throwable) {
                    // A malformed stored date renders as-is instead of vanishing.
                }

                $fields[$def->field_key] = $carbon instanceof Carbon
                    ? Jalalian::fromCarbon($carbon)->format('Y/m/d')
                    : $value;

                continue;
            }

            $fields[$def->field_key] = $value;
        }

        return $fields;
    }

    /**
     * `x-select` options for every select def, keyed by field_key. MaryUI needs
     * value/label keys (#706); the component passes `option-value`/`option-label`.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    protected function selectOptionsMap(?int $diseaseId): array
    {
        $map = [];

        foreach ($this->fieldDefsFor($diseaseId) as $def) {
            if ($def->type !== 'select') {
                continue;
            }

            $map[$def->field_key] = array_map(
                static fn (string $option): array => ['value' => $option, 'label' => $option],
                $def->options ?? [],
            );
        }

        return $map;
    }

    /**
     * `DiseaseCaseService` throws `ValidationException` with snake_case keys
     * (and `duplicate` for the unique index). Map them onto the form properties
     * the Blade view actually renders.
     */
    protected function mapServiceValidationErrors(ValidationException $e): void
    {
        $rename = [
            'subject_unit_id' => 'subjectUnitId',
            'diagnosed_at' => 'diagnosedAt',
            'recorded_at' => 'recordedAt',
            'patient_id' => 'patientId',
            'disease_id' => 'diseaseId',
            'duplicate' => 'diagnosedAt',
        ];

        foreach ($e->errors() as $key => $messages) {
            $target = $rename[$key] ?? $key;

            $this->addError($target, $messages[0] ?? 'مقدار واردشده معتبر نیست.');
        }
    }

    protected function duplicateCaseMessage(): string
    {
        return 'برای این بیمار، این بیماری و این تاریخ قبلاً کیس ثبت شده است.';
    }
}
