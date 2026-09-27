<?php

namespace App\Traits;

trait PersianNormalizer
{
    /**
     * Arabic/Persian character variants mapped to their Persian form.
     *
     * Single source of truth: `normalize()` applies it in PHP and
     * `foldSeparatorsSql()` builds the matching `regexp_replace` chain from it.
     * Adding a character here automatically covers both sides — that is the
     * only way the two cannot drift apart and silently break search (#705).
     *
     * ZWNJ/ZWJ map to a SPACE, not to nothing. Deleting them makes the SQL regex
     * consume the following letter as a modifier and eat it.
     */
    public static function normalize(string $text): string
    {
        return strtr($text, self::charMap());
    }

    /**
     * The Arabic/Persian variant map. A method rather than a constant because a
     * trait constant cannot be read from outside the trait, and the tests call
     * these helpers on the trait directly.
     *
     * @return array<string, string>
     */
    public static function charMap(): array
    {
        return [
            "\u{064A}" => "\u{06CC}",  // Arabic Yeh -> Persian Yeh
            "\u{0643}" => "\u{06A9}",  // Arabic Kaf -> Persian Kaf
            "\u{0623}" => "\u{0627}",  // Arabic Alef with Hamza above -> Persian Alef
            "\u{0625}" => "\u{0627}",  // Arabic Alef with Hamza below -> Persian Alef
            "\u{0622}" => "\u{0627}",  // Arabic Alef with Madda above -> Persian Alef
            "\u{200C}" => ' ',          // ZWNJ -> space
            "\u{200D}" => ' ',          // ZWJ -> space
        ];
    }

    public static function normalizeForSearch(string $text): string
    {
        $text = self::normalize($text);

        // Convert Persian (۰-۹) and Arabic-Indic (٠-٩) digits to Latin so a
        // national code typed on a Persian keyboard matches the Latin digits
        // stored in the database.
        $text = strtr($text, self::digitMap());

        // Remove extra spaces
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }

    /**
     * Persian (۰-۹) and Arabic-Indic (٠-٩) digits mapped to Latin.
     *
     * Second half of the single source of truth: `normalizeForSearch()` applies
     * it in PHP, `foldSeparatorsSql()` emits the matching `translate()` call, so
     * the term side and the column side fold digits identically.
     *
     * @return array<string, string>
     */
    public static function digitMap(): array
    {
        return [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
    }

    /**
     * Escape LIKE wildcards (% and _) for safe use in LIKE queries.
     * Call this ONLY in query builders, NOT in data normalization.
     */
    public static function escapeLikeWildcards(string $text): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $text);
    }

    /**
     * SQL that folds a text column to the same shape `normalizeForSearch()`
     * produces, so a `LIKE` comparison can succeed against a stored value that
     * still carries characters the normalizer rewrites.
     *
     * Issue #705: `normalizeForQuery()` rewrites user input, but the stored text
     * keeps the original code points, so the term stops matching. Two shapes
     * break it, both common in Persian:
     *
     *   - ZWNJ (U+200C) becomes a space, so "حرفه‌ای" is stored with the ZWNJ
     *     and searched as "حرفه ای".
     *   - Arabic Alef variants (U+0622 آ, U+0623 أ, U+0625 إ) become U+0627 ا,
     *     so "آموزش" is stored with آ and searched with ا.
     *
     * The same map `normalize()` uses is applied to the column, then the
     * separator runs are collapsed, so both sides land on one spelling. Digits
     * are folded too: `normalizeForSearch()` turns Persian (۰-۹) and
     * Arabic-Indic (٠-٩) digits into Latin ones, so the column gets the same
     * `digitMap()` treatment via `translate()` — otherwise a stored "۴۵" could
     * never match the "45" the term side produces. The two sides therefore fold
     * exactly the same sets of characters: `charMap()` plus `digitMap()`, one
     * shared source each.
     *
     * The only difference left is whitespace: PHP collapses any `\s+` run and
     * trims, SQL collapses runs of spaces. Stored person/unit names carry
     * neither tabs nor newlines, so the two agree where it matters.
     *
     * Why fold the column and not the pattern: PostgreSQL `LIKE` supports only
     * `%` and `_` as wildcards and has NO character-class syntax, so `[ ... ]`
     * is matched literally and a "either spelling" pattern cannot be expressed.
     * `regexp_replace` is the documented way to change the text being matched
     * (PostgreSQL 9.7 "Pattern Matching"). Consequence: not index-friendly —
     * though the term is always a leading `%`, so the LIKE was never
     * index-seekable anyway.
     */
    public static function foldSeparatorsSql(string $column): string
    {
        // Built from the same CHAR_MAP that normalize() uses, so the SQL and the
        // PHP side can never disagree about which spellings are equivalent.
        //
        // ZWNJ and ZWJ both map to a space, so they are folded in ONE pass —
        // replacing each separately with a class that also matches spaces would
        // collapse unrelated runs and break single-space names.
        $zwnj = "\u{200C}";
        $zwj = "\u{200D}";

        $expr = "regexp_replace({$column}, '[{$zwnj}{$zwj}]', ' ', 'g')";

        foreach (self::charMap() as $from => $to) {
            if ($to === ' ') {
                continue; // already handled above
            }
            $expr = "regexp_replace({$expr}, '{$from}', '{$to}', 'g')";
        }

        // Same digit map normalizeForSearch() applies to the term, in a single
        // translate() pass (1:1 character mapping, which is all a digit fold is).
        $digits = self::digitMap();
        $expr = "translate({$expr}, '".implode('', array_keys($digits))."', '".implode('', array_values($digits))."')";

        // Collapse the run a folded separator may have left, like PHP does.
        return "regexp_replace({$expr}, ' +', ' ', 'g')";
    }

    /**
     * Normalized, wildcard-escaped term to compare against `foldSeparatorsSql()`.
     */
    public static function foldedTerm(string $text): string
    {
        return self::escapeLikeWildcards(self::normalizeForSearch($text));
    }

    /**
     * Normalize text AND escape LIKE wildcards — use in search query builders.
     * Combines normalizeForSearch() + escapeLikeWildcards().
     */
    public static function normalizeForQuery(string $text): string
    {
        return self::escapeLikeWildcards(self::normalizeForSearch($text));
    }
}
