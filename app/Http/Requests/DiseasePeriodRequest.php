<?php

namespace App\Http\Requests;

use App\Support\JalaliDateRange;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Morilog\Jalali\Jalalian;

/**
 * Shared validation for the two aggregate disease endpoints (issue #1245).
 *
 * `disease` is required but "active" is not a read condition: the catalogue
 * endpoint hides inactive rows, while `stats`/`map` must still show the
 * historical cases of a disease that was switched off (decision 16/§3.2).
 *
 * `from`/`to` are Jalali `Y/m/d` only (decision د) and parsed strictly, so an
 * impossible date is a 422 instead of a silent normalisation.
 */
class DiseasePeriodRequest extends UnitScopedRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'disease' => ['required', 'integer', Rule::exists('diseases', 'id')],
            'from' => ['nullable', 'string', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'to' => ['nullable', 'string', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
        ];
    }

    /**
     * Strict Jalali parsing and the `from <= to` ordering, after the shape
     * rules have run.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (['from', 'to'] as $field) {
                    $value = $this->boundInput($field);

                    if ($value !== null && JalaliDateRange::parseStrict($value) === null) {
                        $validator->errors()->add($field, 'تاریخ وارد شده معتبر نیست.');
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = JalaliDateRange::parseStrict($this->boundInput('from'));
                $to = JalaliDateRange::parseStrict($this->boundInput('to'));

                if ($from !== null && $to !== null && $from->gt($to)) {
                    $validator->errors()->add('to', 'تاریخ پایان نباید قبل از تاریخ شروع باشد.');
                }
            },
        ];
    }

    /**
     * The inclusive day bounds of the request.
     *
     * Defaults to the current Persian year — computed, never hard-coded — and
     * Esfand's length comes from the calendar, so 29/30 is right in both leap
     * and non-leap years.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public function periodBounds(): array
    {
        $year = Jalalian::fromCarbon(now())->getYear();

        $from = JalaliDateRange::parseStrict(
            $this->boundInput('from') ?? sprintf('%04d/01/01', $year)
        );
        $to = JalaliDateRange::parseStrict(
            $this->boundInput('to') ?? sprintf('%04d/12/%02d', $year, $this->esfandLength($year))
        );

        if ($from === null || $to === null) {
            throw new InvalidArgumentException('The disease period is not a valid Jalali range.');
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * A validated bound, or null when the caller omitted it.
     */
    private function boundInput(string $field): ?string
    {
        $value = $this->input($field);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Days in Esfand of the given year (29 or 30).
     */
    private function esfandLength(int $year): int
    {
        return (new Jalalian($year, 12, 1))->getMonthDays();
    }
}
