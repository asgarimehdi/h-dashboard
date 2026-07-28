<?php

namespace App\Traits;

trait PersianNormalizer
{
    public static function normalize(string $text): string
    {
        // Normalize Arabic/Persian character variants
        // ي (Arabic Yeh, U+064A) -> ی (Persian Yeh, U+06CC)
        // ك (Arabic Kaf, U+0643) -> ک (Persian Kaf, U+06A9)
        // ZWNJ (U+200C) and ZWJ (U+200D) -> space
        $map = [
            "\u{064A}" => "\u{06CC}",  // Arabic Yeh -> Persian Yeh
            "\u{0643}" => "\u{06A9}",  // Arabic Kaf -> Persian Kaf
            "\u{200C}" => ' ',          // ZWNJ -> space
            "\u{200D}" => ' ',          // ZWJ -> space
        ];

        return strtr($text, $map);
    }

    public static function normalizeForSearch(string $text): string
    {
        $text = self::normalize($text);
        // Remove extra spaces
        $text = preg_replace('/\s+/', ' ', trim($text));
        return $text;
    }
}
