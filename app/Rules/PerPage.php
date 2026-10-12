<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the `?per_page=` (and `?initial_limit=`) window used by every
 * paginated API endpoint (issue #894).
 *
 * The cap these endpoints carried was one-sided: `min((int) $per_page, 100)`
 * bounds the value from above only. A negative value is truthy, so it survives
 * `Eloquent\Builder::paginate()`'s `?: $this->model->getPerPage()` fallback and
 * reaches `forPage()`, where `limit(-1)` is silently dropped by the query
 * builder — the SQL loses its LIMIT clause and returns the whole table.
 *
 * A missing or non-numeric value is left to the controller's own default, so a
 * sloppy `?per_page=abc` keeps rendering the default page size instead of
 * failing. Only a *numeric* value outside 1..MAX is rejected, so the client
 * learns why rather than silently receiving a different number of rows.
 *
 * The rule deliberately does not clamp: 422 is the contract the client sees,
 * the way `ReportDays` already handles the same shape of mistake for `?days=`.
 * The controllers keep their `min(...)` expression as defence in depth.
 */
class PerPage implements ValidationRule
{
    public function __construct(private readonly int $max = 100) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Junk input falls back to the controller's default, mirroring
        // `DailySeries::resolveDays()` for `?days=abc`.
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $perPage = (int) $value;

        if ($perPage < 1) {
            $fail("The {$attribute} field must be at least 1.");

            return;
        }

        if ($perPage > $this->max) {
            $fail("The {$attribute} field may not be greater than {$this->max}.");
        }
    }
}
