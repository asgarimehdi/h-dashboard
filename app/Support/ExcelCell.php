<?php

namespace App\Support;

/**
 * Excel formula-injection guard (issue #886, CWE-1236; #1766 for CSV).
 *
 * PhpSpreadsheet's xlsx binder types a string starting with '=' as
 * TYPE_FORMULA and every other trigger ('+', '-', '@') as TYPE_STRING — so
 * for .xlsx, escaping '=' at map() time is sufficient, and prefixing '+-@'
 * there would corrupt legitimate values like '-1+2' or '@mention'.
 *
 * A CSV file carries no cell-type metadata at all: Excel/LibreOffice re-parse
 * every field, so a leading '=', '+', '-', '@' (or a leading tab/CR that some
 * parsers strip) starts a formula. The CSV export path therefore additionally
 * runs {@see self::escapeForCsv()} at export time. Nothing is ever persisted
 * in that escaped form.
 *
 * Escaping happens in map(), not in a value binder: the binder runs for every
 * writer and never sees the chosen format.
 */
final class ExcelCell
{
    /**
     * Prefix a single quote to strings starting with '=' so Excel shows them
     * as text. Non-strings and '' pass through untouched (numeric columns
     * keep their types).
     */
    public static function escape(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || $value[0] !== '=') {
            return $value;
        }

        return "'".$value;
    }

    /**
     * First characters that start a formula once CSV drops cell types.
     *
     * '|' and '%' are deliberately absent: neither is a leading-formula
     * trigger in Excel/LibreOffice, and escaping them would rewrite
     * legitimate values such as '%CPU' or '|summary'.
     *
     * @var list<string>
     */
    private const CSV_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * CSV-only guard for the trigger characters that xlsx binds as strings.
     *
     * Export-time only: never persist this form. Call it after
     * {@see self::escape()} so an '='-leading value is prefixed exactly once.
     */
    public static function escapeForCsv(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::CSV_TRIGGERS, true) ? "'".$value : $value;
    }

    /**
     * Restore-side inverse of {@see self::escape()}.
     *
     * Only the exact shape escape() produces is reversed: a leading single
     * quote followed by '='. A value that merely starts with a quote (e.g.
     * "'abc") passes through untouched.
     *
     * LIMITATION: a genuine value that starts with "'=" is indistinguishable
     * from an escaped "=..." value and loses its apostrophe on restore —
     * escape() is not injective over that input (see #1748).
     *
     * Use ONLY when reading a stored audit value back for a restore write.
     * Export and display paths must keep the escaped form (#886).
     */
    public static function unescape(string $value): string
    {
        return str_starts_with($value, "'=") ? substr($value, 1) : $value;
    }
}
