<?php

namespace App\Rules;

use App\Traits\PersianNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Iranian national code (کد ملی) — the checksum the spec §3.4 asks for.
 *
 * One implementation for both write paths (#1243 patient registry, #1244 case
 * form). `isValid()` is the lookup/CTA primitive; `validate()` is the form
 * rule. Persian/Arabic-Indic digits are folded first so a code typed on a
 * Persian keyboard is the same value as the Latin one stored in `patients`.
 *
 * The stored value keeps its leading zeros: the column is `string(10)`, and
 * `0013542419` is a real code, so nothing here casts to int.
 *
 * Warning about the spec sample: `0012345678` is NOT checksum-valid (its first
 * nine digits sum to 112, `112 % 11 = 2`, so the last digit must be `9`). The
 * valid fixtures in the tests are `0499370899` and `0013542419`.
 */
final class IranianNationalCode implements ValidationRule
{
    use PersianNormalizer;

    public const MESSAGE = 'کد ملی معتبر نیست.';

    public static function isValid(string $code): bool
    {
        $code = trim(strtr($code, self::digitMap()));

        // Exactly ten digits after folding. `\d` is ASCII-only here, which is
        // safe because Persian/Arabic-Indic digits were already folded above.
        if (preg_match('/^\d{10}$/', $code) !== 1) {
            return false;
        }

        // 0000000000, 1111111111, … pass the checksum arithmetically but are
        // never real national codes.
        if (preg_match('/^(\d)\1{9}$/', $code) === 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }

        $remainder = $sum % 11;
        $checkDigit = $remainder < 2 ? $remainder : 11 - $remainder;

        return (int) $code[9] === $checkDigit;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(self::MESSAGE);
        }
    }
}
