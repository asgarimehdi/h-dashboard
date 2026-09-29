<?php

namespace App\Rules;

use App\Services\DailySeries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the `?days=` window used by every `by_day` report aggregate
 * (issue #736). Without a cap a client could ask for the whole history back
 * and the query would grow linearly with the table.
 *
 * A missing or non-numeric value resolves to the default instead of failing —
 * a sloppy `?days=abc` still renders a chart — while a numeric value outside
 * 1..MAX is rejected so the client learns why.
 */
class ReportDays implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $days = DailySeries::resolveDays($value);

        if ($days < 1) {
            $fail("The {$attribute} field must be at least 1 day.");

            return;
        }

        if ($days > DailySeries::MAX_DAYS) {
            $fail("The {$attribute} field may not be greater than ".DailySeries::MAX_DAYS.' days.');
        }
    }
}
