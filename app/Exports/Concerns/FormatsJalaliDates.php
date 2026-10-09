<?php

namespace App\Exports\Concerns;

use Carbon\Carbon;
use Morilog\Jalali\Jalalian;
use Throwable;

/**
 * Shared Jalali date handling for the Excel exports (#890).
 *
 * Every export in this repo emits dates as Jalali `Y/m/d` — the sheet is
 * operator-facing and Jalali is what a Persian-speaking admin reads — so the
 * importer must accept Jalali back. The display side and the parse side are two
 * halves of ONE contract and belong next to each other so they cannot drift.
 *
 * `formatJalaliOrDash()` is deliberately total: a stored value that is not a
 * real calendar date (a Jalali year written verbatim into a `date` column by an
 * older importer, for instance) must degrade to `-` instead of throwing out of
 * the row mapper. Before this guard one bad row 500'd the whole download for
 * every other row in the operator's scope.
 */
trait FormatsJalaliDates
{
    /**
     * The exporter-facing contract: Jalali `Y/m/d`, or `-` when there is
     * nothing to show or the value cannot be rendered.
     *
     * `Jalalian::fromCarbon()` is typed against a concrete `Carbon\Carbon`, so
     * an immutable cast (`CarbonImmutable`) or a plain `DateTimeInterface` is
     * materialised through `Carbon::instance()` first. The signature stays
     * `mixed` because the value arrives straight off an Eloquent cast on a
     * row that may itself hold a malformed value — which is the case this
     * method exists to survive.
     */
    protected function formatJalaliOrDash(mixed $date): string
    {
        if ($date === null) {
            return '-';
        }

        if ($date instanceof \DateTimeInterface && ! $date instanceof Carbon) {
            $date = Carbon::instance($date);
        }

        try {
            return Jalalian::fromCarbon($date)->format('Y/m/d');
        } catch (Throwable) {
            // A single malformed row must not break the operator's export.
            return '-';
        }
    }

    /**
     * The importer-facing half: accept the three shapes a `clean_at` cell can
     * legitimately hold and return canonical Gregorian `Y-m-d`.
     *
     * Accepted:
     *  - ISO dashed Gregorian `2026-02-08`
     *  - Jalali slashed `1404/11/19` (what this repo's exports emit)
     *  - Jalali dashed `1404-11-19`
     *
     * Rejected (returns null, and the caller stores NULL rather than a
     * half-parsed value): any other shape, and any date that fails the
     * calendar check — `1404/13/01` is not a date.
     *
     * Jalali dashed has to be disambiguated from ISO by year range: the Jalali
     * year is always well below the Gregorian one, so `1404-11-19` cannot be
     * mistaken for a Gregorian date. Without that check it would be stored
     * verbatim in a `date` column and later break the export.
     */
    protected function parseGregorianOrJalaliDate(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '\N' || $raw === '-') {
            return null;
        }

        // Jalali, slashed or dashed. Checked BEFORE the ISO branch, because
        // `1404-11-19` also matches the ISO shape and would otherwise be stored
        // verbatim in a `date` column — which is exactly what broke the export.
        if (preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$#', $raw, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];

            // A Gregorian year is never in the Jalali range, and vice versa:
            // Iranian records sit at 1178–1499, Gregorian use sits at
            // 1900–2100. Anything outside both is not a date we can trust.
            if ($year < self::JALALI_MIN_YEAR || $year > self::JALALI_MAX_YEAR) {
                return $this->parseIsoGregorian($year, $month, $day, $raw);
            }

            try {
                $jalalian = Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/%02d', $year, $month, $day));
            } catch (Throwable) {
                return null;
            }

            // fromFormat() NORMALISES rather than rejects — 1404/13/01 comes
            // back as 1405/01/02 and 1404/01/32 as 1404/02/01 — so comparing
            // the round-trip is what actually proves the date existed.
            if (! $this->isRealJalaliDate($year, $month, $day, $jalalian)) {
                return null;
            }

            return $jalalian->toCarbon()->format('Y-m-d');
        }

        return null;
    }

    /**
     * The ISO branch, reached only for years outside the Jalali range.
     */
    private function parseIsoGregorian(int $year, int $month, int $day, string $raw): ?string
    {
        // Dashed only: a slashed value in Gregorian shape is rejected, since
        // nothing in this repo emits that.
        if (! str_contains($raw, '-')) {
            return null;
        }

        return $this->isRealGregorianDate($year, $month, $day) ? $raw : null;
    }

    /**
     * Jalali years accepted by `parseGregorianOrJalaliDate()`. 1178–1499
     * brackets every plausible Iranian record; the Gregorian years in use
     * (1900–2100) sit entirely outside it, so the disambiguation is unambiguous.
     */
    private const JALALI_MIN_YEAR = 1178;

    private const JALALI_MAX_YEAR = 1499;

    private function isRealGregorianDate(int $year, int $month, int $day): bool
    {
        return checkdate($month, $day, $year);
    }

    private function isRealJalaliDate(int $year, int $month, int $day, Jalalian $jalalian): bool
    {
        // JalaliFromFormat normalises out-of-range values instead of rejecting
        // them (1404/13/01 becomes 1405/01/01), so compare the round-trip.
        return $jalalian->format('Y/m/d') === sprintf('%04d/%02d/%02d', $year, $month, $day);
    }
}
