<?php

namespace App\Support;

use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

/**
 * Turns the Jalali `Y/m/d` strings the report and ticket date filters carry
 * into Carbon day bounds.
 *
 * Issue #1054 — this parse was re-implemented in five live places
 * (`reports/advanced`, `reports/todos`, `activity-log/index`, and the inline
 * filter blocks in `tickets/inbox` and `tickets/monitoring`). It lives here now.
 * `reports/persons` also carries a copy but never calls it; that dead copy is
 * deliberately left alone (#1054's separate follow-up).
 *
 * Deliberate contract — an empty or unparseable value yields `null`, which the
 * callers read as "no date filter". The filter inputs are `wire:model.live`, so
 * a half-typed value must not raise a validation error mid-keystroke; silently
 * dropping the bound is the behaviour every copy shipped with. Do not turn this
 * into an exception or a validation error without a product decision.
 */
final class JalaliDateRange
{
    /**
     * Parse one Jalali `Y/m/d` string into a Carbon bound.
     *
     * @param  bool  $endOfDay  return the last second of the day instead of the first
     */
    public static function parse(?string $date, bool $endOfDay = false): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        try {
            $carbon = Jalalian::fromFormat('Y/m/d', $date)->toCarbon();
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $carbon->endOfDay() : $carbon->startOfDay();
    }

    /**
     * A from..to pair as Carbon bounds, or `null` when either end is
     * missing/invalid or the range is inverted.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function range(?string $from, ?string $to): ?array
    {
        $start = self::parse($from);
        $end = self::parse($to, endOfDay: true);

        if (! $start || ! $end || $start->gt($end)) {
            return null;
        }

        return [$start, $end];
    }
}
