<?php

namespace App\Services\Disease;

use App\Models\DiseaseFieldDef;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validates a case's dynamic `fields` payload against its disease's
 * `disease_field_defs` (spec §3.4).
 *
 * The client is never trusted: required/empty, type shape, select membership,
 * calendar-valid dates, the text limit and unknown keys are all decided here.
 * `0` and `"0"` are values, not "empty". The returned array holds only
 * known, non-empty fields, normalised for storage.
 */
final class DiseaseFieldValidator
{
    public const MAX_TEXT_LENGTH = 1000;

    /**
     * @param  Collection<int, DiseaseFieldDef>  $defs
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(Collection $defs, array $fields): array
    {
        $errors = [];
        $normalized = [];
        $known = [];

        foreach ($defs->sortBy('sort') as $def) {
            $key = $def->field_key;
            $known[$key] = true;
            $value = $fields[$key] ?? null;

            if ($this->isEmpty($value)) {
                if ($def->required) {
                    $errors["fields.{$key}"][] = "فیلد «{$def->label_fa}» الزامی است.";
                }

                continue;
            }

            [$ok, $normalizedValue, $message] = $this->normalize($def, $value);

            if ($ok) {
                $normalized[$key] = $normalizedValue;
            } else {
                $errors["fields.{$key}"][] = $message;
            }
        }

        foreach (array_keys($fields) as $key) {
            if (! isset($known[$key])) {
                $errors["fields.{$key}"][] = 'این فیلد برای بیماری انتخاب‌شده تعریف نشده است.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null
            || $value === []
            || (is_string($value) && trim($value) === '');
    }

    /**
     * @return array{0: bool, 1: mixed, 2: string}
     */
    private function normalize(DiseaseFieldDef $def, mixed $value): array
    {
        return match ($def->type) {
            'number' => $this->normalizeNumber($def, $value),
            'select' => $this->normalizeSelect($def, $value),
            'date' => $this->normalizeDate($def, $value),
            'text' => $this->normalizeText($def, $value),
            default => [false, null, "نوع فیلد «{$def->label_fa}» پشتیبانی نمی‌شود."],
        };
    }

    /** @return array{0: bool, 1: mixed, 2: string} */
    private function normalizeNumber(DiseaseFieldDef $def, mixed $value): array
    {
        if (is_array($value) || is_bool($value) || ! is_numeric($value)) {
            return [false, null, "فیلد «{$def->label_fa}» باید عدد باشد."];
        }

        return [true, $value + 0, ''];
    }

    /** @return array{0: bool, 1: mixed, 2: string} */
    private function normalizeSelect(DiseaseFieldDef $def, mixed $value): array
    {
        if (! is_scalar($value)) {
            return [false, null, "مقدار فیلد «{$def->label_fa}» معتبر نیست."];
        }

        $needle = (string) $value;

        foreach ($def->options ?? [] as $option) {
            if ((string) $option === $needle) {
                return [true, $option, ''];
            }
        }

        return [false, null, "مقدار فیلد «{$def->label_fa}» معتبر نیست."];
    }

    /** @return array{0: bool, 1: mixed, 2: string} */
    private function normalizeDate(DiseaseFieldDef $def, mixed $value): array
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return [false, null, "تاریخ فیلد «{$def->label_fa}» معتبر نیست."];
        }

        $date = null;

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            // InvalidFormatException from Carbon — falls through to the error.
        }

        if (! $date instanceof Carbon || $date->format('Y-m-d') !== $value) {
            return [false, null, "تاریخ فیلد «{$def->label_fa}» معتبر نیست."];
        }

        return [true, $value, ''];
    }

    /** @return array{0: bool, 1: mixed, 2: string} */
    private function normalizeText(DiseaseFieldDef $def, mixed $value): array
    {
        if (! is_string($value)) {
            return [false, null, "فیلد «{$def->label_fa}» باید متن باشد."];
        }

        $trimmed = trim($value);

        if (mb_strlen($trimmed) > self::MAX_TEXT_LENGTH) {
            return [false, null, "فیلد «{$def->label_fa}» حداکثر ".self::MAX_TEXT_LENGTH.' کاراکتر است.'];
        }

        return [true, $trimmed, ''];
    }
}
