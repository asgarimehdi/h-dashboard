<?php

namespace App\Support;

/**
 * Excel formula-injection guard (issue #886, CWE-1236).
 *
 * PhpSpreadsheet binds any string starting with '=' as TYPE_FORMULA, and
 * the audits CSV route (?format=csv) carries no cell-type metadata at all —
 * so escaping must happen in map(), not in a binder. Scope is '=' only:
 * '+-@' bind as strings on this stack, and escaping them would corrupt
 * legitimate values like '-1+2' or '@mention'.
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
}
