<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the `?months=` window used by the HR monthly trend aggregates
 * (`/api/hr/analytics/headcount-trend`, `/api/hr/analytics/vacancy-trend`,
 * issue #952).
 *
 * The cap these endpoints carried was one-sided: `min((int) $months, 24)`
 * bounds the value from above only. `months=0` silently collapsed to a
 * single bucket and `months=-6` to an empty array — two window parameters on
 * the same product with two different contracts.
 *
 * The contract is the one `ReportDays` already sets for `?days=`: a missing
 * or non-numeric value falls back to the controller's default instead of
 * failing, so a sloppy `?months=abc` still renders a chart, while a numeric
 * value outside 1..MAX is rejected with 422 so the client learns why.
 * `months=12` yields exactly 12 buckets (inclusive at both ends would be 13).
 */
class ReportMonths implements ValidationRule
{
    public const DEFAULT_MONTHS = 12;

    public const MAX_MONTHS = 24;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Junk input falls back to the controller's default, mirroring
        // `DailySeries::resolveDays()` for `?days=abc`.
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $months = (int) $value;

        if ($months < 1) {
            $fail("The {$attribute} field must be at least 1 month.");

            return;
        }

        if ($months > self::MAX_MONTHS) {
            $fail("The {$attribute} field may not be greater than ".self::MAX_MONTHS.' months.');
        }
    }

    /**
     * Resolve a raw `?months=` value to the window the endpoints aggregate.
     *
     * Mirrors `DailySeries::resolveDays()`: a missing or non-numeric value
     * yields the default rather than erroring. Numeric values pass through
     * untouched — out-of-range ones are rejected by `validate()`, so the
     * client is told why instead of silently getting a different window.
     */
    public static function resolveMonths(mixed $value): int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_MONTHS;
        }

        return (int) $value;
    }
}
