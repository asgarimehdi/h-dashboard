<?php

namespace App\Traits;

trait PersianNormalizer
{
    public static function normalize(string $text): string
    {
        // Normalize Arabic/Persian character variants
        // ي (Arabic Yeh, U+064A) -> ی (Persian Yeh, U+06CC)
        // ك (Arabic Kaf, U+0643) -> ک (Persian Kaf, U+06A9)
        // أ (Arabic Alef with Hamza above, U+0623) -> ا (Persian Alef, U+0627)
        // إ (Arabic Alef with Hamza below, U+0625) -> ا (Persian Alef, U+0627)
        // آ (Arabic Alef with Madda above, U+0622) -> ا (Persian Alef, U+0627)
        // ZWNJ (U+200C) and ZWJ (U+200D) -> space
        $map = [
            "\u{064A}" => "\u{06CC}",  // Arabic Yeh -> Persian Yeh
            "\u{0643}" => "\u{06A9}",  // Arabic Kaf -> Persian Kaf
            "\u{0623}" => "\u{0627}",  // Arabic Alef with Hamza above -> Persian Alef
            "\u{0625}" => "\u{0627}",  // Arabic Alef with Hamza below -> Persian Alef
            "\u{0622}" => "\u{0627}",  // Arabic Alef with Madda above -> Persian Alef
            "\u{200C}" => ' ',          // ZWNJ -> space
            "\u{200D}" => ' ',          // ZWJ -> space
        ];

        return strtr($text, $map);
    }

    public static function normalizeForSearch(string $text): string
    {
        $text = self::normalize($text);

        // Convert Persian (۰-۹) and Arabic-Indic (٠-٩) digits to Latin so a
        // national code typed on a Persian keyboard matches the Latin digits
        // stored in the database.
        $text = strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        // Remove extra spaces
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }
}
