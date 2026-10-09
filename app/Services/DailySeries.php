<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Daily counts over a date window, with the days that have no rows present as
 * zero (issue #736).
 *
 * A `GROUP BY date(...)` aggregate only returns days that exist in the table, so
 * a day with no tickets becomes a missing column — which reads as "no data"
 * rather than "zero that day". Here the window is materialised with
 * `generate_series` and left-joined against the aggregate, so the shape of the
 * output depends only on the window, never on how the data happens to be
 * distributed.
 *
 * Deliberately not read from `daily_reports`: that table is written by
 * `reports:generate-daily` at 06:00, so it is always a day behind, and a failed
 * schedule silently becomes a hole in the chart.
 */
class DailySeries
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 365;

    public function __construct(
        private readonly int $days = self::DEFAULT_DAYS,
        private readonly ?Carbon $from = null,
        private readonly ?Carbon $to = null,
    ) {}

    /**
     * The last N days, ending today (inclusive). `days = 1` is today alone.
     *
     * This is the `?days=` path (`/api/reports/*`, the dashboard trend): there is
     * no picked range, so the window is derived from `now()`.
     */
    public static function lastDays(int $days): self
    {
        // `now()` is a CarbonInterface; `Carbon::instance()` is the documented
        // narrowing (Carbon ≥ 3), so the stored bounds are plain Carbon.
        $to = Carbon::instance(now()->startOfDay());

        return new self($days, $to->copy()->subDays($days - 1), $to);
    }

    /**
     * An explicit inclusive range — for pages that already offer a
     * date-from/date-to picker and therefore have no single "last N days".
     *
     * The range itself is kept, because it *is* the contract: previously only the
     * day count survived and `window()` rebuilt "N days ending today", so every
     * past window charted the wrong axis and `counts()`' LEFT JOIN dropped the
     * rows that sat inside the range the user actually picked (issue #866).
     *
     * A range wider than MAX_DAYS is clamped back to MAX_DAYS ending on the
     * picked to-day. `?days=` is already rejected above the cap by `ReportDays`,
     * but a picker page takes free-text Jalali dates straight through, and an
     * unclamped 40-year range materialises 14610 rows — one Highcharts point each.
     */
    public static function between(Carbon $from, Carbon $to): self
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $days = (int) $start->diffInDays($end) + 1;

        if ($days > self::MAX_DAYS) {
            $start = $end->copy()->subDays(self::MAX_DAYS - 1);
            $days = self::MAX_DAYS;
        }

        return new self($days, $start, $end);
    }

    /**
     * Resolve a raw `?days=` value.
     *
     * A missing or non-numeric value falls back to the default rather than
     * erroring, so a sloppy `?days=abc` still renders a chart. Numeric values
     * outside 1..MAX are returned as-is and rejected by the validator, so the
     * client is told why instead of silently getting a different window.
     */
    public static function resolveDays(mixed $value): int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_DAYS;
        }

        return (int) $value;
    }

    /**
     * Counts per day over the window, ascending, one entry per day.
     *
     * `$query` is the caller's own already-filtered builder — unit scope, status
     * filter, whatever the page needs — so this fills gaps in an existing
     * aggregate instead of re-deriving its filters. A query that matches nothing
     * yields a full-length window of zeros, never a shorter one.
     *
     * Accepts either builder flavour; the helpers below narrow down what each
     * path needs, so neither case is handled twice.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @return array<int, array{day: string, count: int}> Gregorian `Y-m-d` days.
     */
    public function counts(EloquentBuilder|QueryBuilder $query, string $dateColumn): array
    {
        [$from, $to] = $this->window();

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedDate = $grammar->wrap($this->qualifiedColumn($query, $dateColumn));

        // The aggregate is a subquery built from the caller's builder, so all of
        // its filters and bindings are preserved. Identifiers are code
        // constants, never request input; the window is bound as two dates.
        $perDay = $this->toBaseQuery($query)
            ->selectRaw("date({$wrappedDate}) as day, COUNT(*) as count")
            ->groupByRaw("date({$wrappedDate})");

        $rows = DB::select(
            'WITH days AS (
                SELECT generate_series(?::date, ?::date, interval \'1 day\')::date AS day
             )
             SELECT days.day::text AS day, COALESCE(per_day.count, 0) AS count
             FROM days
             LEFT JOIN ('.$perDay->toSql().') AS per_day ON per_day.day = days.day
             ORDER BY days.day',
            array_merge([$from, $to], $perDay->getBindings())
        );

        return array_map(fn ($row) => [
            'day' => (string) $row->day,
            'count' => (int) $row->count,
        ], $rows);
    }

    /**
     * The window as Gregorian `Y-m-d` bounds, oldest first — the range itself,
     * not a recomputed one.
     *
     * @return array{0: string, 1: string}
     */
    public function window(): array
    {
        $to = $this->to ?? now()->startOfDay();
        $from = $this->from ?? $to->copy()->subDays($this->days - 1);

        return [$from->copy()->startOfDay()->toDateString(), $to->copy()->startOfDay()->toDateString()];
    }

    /**
     * Table-qualify the date column when the builder knows its model, so a
     * joined aggregate cannot resolve the bare name to the wrong table.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    private function qualifiedColumn(EloquentBuilder|QueryBuilder $query, string $dateColumn): string
    {
        if (! $query instanceof EloquentBuilder) {
            return $dateColumn;
        }

        return $query->getModel()->getTable().'.'.$dateColumn;
    }

    /**
     * The underlying base builder — a clone, so adding the group-by never
     * mutates the caller's query.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    private function toBaseQuery(EloquentBuilder|QueryBuilder $query): QueryBuilder
    {
        return clone ($query instanceof EloquentBuilder ? $query->toBase() : $query);
    }
}
