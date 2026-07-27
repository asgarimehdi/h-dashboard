<?php

namespace App\Traits;

trait PersianNormalizer
{
    public static function normalize(string $text): string
    {
        $map = [
            'ي' => 'ی',
            'ك' => 'ک',
            '‌' => ' ', // ZWNJ -> space
            '‌' => ' ', // ZWNJ variants
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