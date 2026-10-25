<?php

namespace Tests\Unit;

use App\Support\JalaliDateRange;
use Tests\TestCase;

/**
 * Issue #1054 — the one Jalali `Y/m/d` -> Carbon parse, in one place.
 *
 * Five live copies of this parse shipped across the report and ticket filters.
 * These cases pin the exact behaviour those copies had, so the shared helper is
 * a pure refactor: valid input keeps its day bounds, and the deliberate
 * "invalid input is silently ignored" contract (return `null`, never throw) is
 * locked in — the filter inputs are `wire:model.live`, so a half-typed value
 * must not raise a validation error.
 */
covers(JalaliDateRange::class);

class JalaliDateRangeTest extends TestCase
{
    // ==================== parse() — valid input ====================

    public function test_a_valid_jalali_date_parses_to_the_start_of_that_day(): void
    {
        $result = JalaliDateRange::parse('1403/05/01');

        $this->assertNotNull($result);
        $this->assertSame('2024-07-22 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_end_of_day_returns_the_last_second_of_the_day(): void
    {
        $result = JalaliDateRange::parse('1403/05/01', endOfDay: true);

        $this->assertNotNull($result);
        $this->assertSame('2024-07-22 23:59:59', $result->format('Y-m-d H:i:s'));
    }

    // ==================== parse() — boundaries ====================

    public function test_the_first_day_of_a_jalali_year(): void
    {
        $result = JalaliDateRange::parse('1403/01/01');

        $this->assertNotNull($result);
        $this->assertSame('2024-03-20 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_the_last_day_of_a_thirty_day_esfand(): void
    {
        $result = JalaliDateRange::parse('1403/12/30');

        $this->assertNotNull($result);
        $this->assertSame('2025-03-20 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_the_last_day_of_a_twenty_nine_day_esfand(): void
    {
        $result = JalaliDateRange::parse('1403/12/29');

        $this->assertNotNull($result);
        $this->assertSame('2025-03-19 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    // ==================== parse() — invalid contract ====================

    public function test_empty_and_null_input_return_null_without_throwing(): void
    {
        $this->assertNull(JalaliDateRange::parse(''));
        $this->assertNull(JalaliDateRange::parse(null));
    }

    public function test_an_unparseable_string_returns_null(): void
    {
        $this->assertNull(JalaliDateRange::parse('abc'));
        $this->assertNull(JalaliDateRange::parse('not-a-date'));
    }

    public function test_a_single_digit_component_is_rejected(): void
    {
        // `Y/m/d` is strict: the library throws on `1403/5/1`, and the helper
        // swallows it to `null` — the documented contract.
        $this->assertNull(JalaliDateRange::parse('1403/5/1'));
    }

    // ==================== parseStrict() — API contract (#1245) ====================

    public function test_strict_parse_accepts_a_valid_jalali_date(): void
    {
        $result = JalaliDateRange::parseStrict('1405/01/01');

        $this->assertNotNull($result);
        $this->assertSame('2026-03-21 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_strict_parse_accepts_the_last_day_of_a_leap_esfand(): void
    {
        $this->assertNotNull(JalaliDateRange::parseStrict('1403/12/30'));
        $this->assertNotNull(JalaliDateRange::parseStrict('1405/12/29'));
    }

    public function test_strict_parse_rejects_esfand_thirty_in_a_non_leap_year(): void
    {
        // 1405 has 29 days in Esfand; a lenient parse would roll over to
        // 1406/01/01 and silently shift the report window.
        $this->assertNull(JalaliDateRange::parseStrict('1405/12/30'));
    }

    public function test_strict_parse_rejects_impossible_months_and_days(): void
    {
        $this->assertNull(JalaliDateRange::parseStrict('1405/13/01'));
        $this->assertNull(JalaliDateRange::parseStrict('1405/00/10'));
        $this->assertNull(JalaliDateRange::parseStrict('1405/07/32'));
    }

    public function test_strict_parse_rejects_empty_input(): void
    {
        $this->assertNull(JalaliDateRange::parseStrict(''));
        $this->assertNull(JalaliDateRange::parseStrict(null));
    }

    // ==================== range() ====================

    public function test_range_returns_ordered_bounds_and_ends_the_to_day(): void
    {
        $bounds = JalaliDateRange::range('1403/05/01', '1403/05/10');

        $this->assertNotNull($bounds);
        $this->assertSame('2024-07-22 00:00:00', $bounds[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2024-07-31 23:59:59', $bounds[1]->format('Y-m-d H:i:s'));
    }

    public function test_range_returns_null_when_the_range_is_inverted(): void
    {
        $this->assertNull(JalaliDateRange::range('1403/06/01', '1403/05/01'));
    }

    public function test_range_returns_null_when_an_end_is_missing_or_invalid(): void
    {
        $this->assertNull(JalaliDateRange::range(null, '1403/05/01'));
        $this->assertNull(JalaliDateRange::range('1403/05/01', null));
        $this->assertNull(JalaliDateRange::range('abc', '1403/05/01'));
        $this->assertNull(JalaliDateRange::range('1403/05/01', 'abc'));
    }
}
