<?php

namespace Tests\Unit;

use App\Exports\Concerns\FormatsJalaliDates;
use Tests\TestCase;

/**
 * Issue #1704 — the shared date parser's input contract.
 *
 * `parseGregorianOrJalaliDate()` is the one parser behind `HardwareImport`
 * (#890) and it never had direct coverage: the importer tests only reach it
 * through a CSV. The hole #1704 found is an *input* the ASCII `\d` regex never
 * matched — a date typed on a Persian keyboard — so the cases below pin the
 * accepted shapes (Persian/Arabic-Indic digits, mixed scripts, ISO), the
 * rejected ones (impossible dates, unknown shapes) and the blank tokens.
 */
covers(FormatsJalaliDates::class);

class FormatsJalaliDatesTest extends TestCase
{
    private function parser(): object
    {
        return new class
        {
            use FormatsJalaliDates;

            public function parse(mixed $value): ?string
            {
                return $this->parseGregorianOrJalaliDate($value);
            }

            public function blank(mixed $value): bool
            {
                return $this->isBlankDateCell($value);
            }
        };
    }

    // ==================== Persian / Arabic-Indic digits (#1704) ====================

    public function test_persian_digit_jalali_slashed_parses_to_gregorian(): void
    {
        $this->assertSame('2026-02-08', $this->parser()->parse('۱۴۰۴/۱۱/۱۹'));
    }

    public function test_persian_digit_jalali_dashed_parses_to_gregorian(): void
    {
        $this->assertSame('2026-02-08', $this->parser()->parse('۱۴۰۴-۱۱-۱۹'));
    }

    public function test_arabic_indic_digit_jalali_parses_to_gregorian(): void
    {
        $this->assertSame('2026-02-08', $this->parser()->parse('١٤٠٤/١١/١٩'));
    }

    public function test_mixed_persian_and_latin_digits_parse(): void
    {
        // strtr is per character, so a half-converted cell must still work.
        $this->assertSame('2026-02-08', $this->parser()->parse('۱۴۰۴/۱۱/19'));
        $this->assertSame('2026-02-08', $this->parser()->parse('1404/۱۱/۱۹'));
    }

    public function test_persian_digit_iso_date_is_stored_as_ascii(): void
    {
        // The ISO branch returns the raw string, so the fold must happen before
        // the regex — otherwise Persian digits would land in a `date` column.
        $this->assertSame('2026-02-08', $this->parser()->parse('۲۰۲۶-۰۲-۰۸'));
    }

    public function test_folding_digits_does_not_bypass_the_calendar_check(): void
    {
        $this->assertNull($this->parser()->parse('۱۴۰۴/۱۳/۰۱'));
        $this->assertNull($this->parser()->parse('۱۴۰۴/۰۱/۳۲'));
    }

    // ==================== ASCII shapes are unchanged ====================

    public function test_ascii_jalali_and_iso_shapes_still_parse(): void
    {
        $this->assertSame('2026-02-08', $this->parser()->parse('1404/11/19'));
        $this->assertSame('2026-02-08', $this->parser()->parse('1404-11-19'));
        $this->assertSame('2026-02-08', $this->parser()->parse('2026-02-08'));
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame('2026-02-08', $this->parser()->parse("  ۱۴۰۴/۱۱/۱۹\t"));
    }

    // ==================== Rejected shapes ====================

    public function test_impossible_ascii_dates_are_rejected(): void
    {
        $this->assertNull($this->parser()->parse('1404/13/01'));
        $this->assertNull($this->parser()->parse('1404/01/32'));
        $this->assertNull($this->parser()->parse('2026-02-30'));
    }

    public function test_unknown_shapes_are_rejected(): void
    {
        // Day-first and slashed Gregorian stay rejected on purpose: nothing in
        // this repo emits them.
        $this->assertNull($this->parser()->parse('05/01/2026'));
        $this->assertNull($this->parser()->parse('2026/02/08'));
        $this->assertNull($this->parser()->parse('not a date'));
    }

    // ==================== Blank cells ====================

    public function test_blank_tokens_return_null(): void
    {
        $parser = $this->parser();

        $this->assertNull($parser->parse(''));
        $this->assertNull($parser->parse('\N'));
        $this->assertNull($parser->parse('-'));
        $this->assertNull($parser->parse('   '));
        $this->assertNull($parser->parse(null));
    }

    public function test_blank_classifier_matches_the_parser_blank_tokens(): void
    {
        // #1704: the importer classifies a cell with isBlankDateCell() to tell
        // "no date" from "unparseable", so the two lists must agree.
        $parser = $this->parser();

        foreach (['', '\N', '-', '   ', null] as $blank) {
            $this->assertTrue($parser->blank($blank), 'expected blank: '.var_export($blank, true));
            $this->assertNull($parser->parse($blank));
        }

        $this->assertFalse($parser->blank('05/01/2026'));
        $this->assertFalse($parser->blank('۱۴۰۴/۱۱/۱۹'));
    }
}
