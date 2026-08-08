<?php

use App\Traits\PersianNormalizer;

class NormalizerHelper {
    use PersianNormalizer;
}

test('normalize converts Arabic Yeh and Kaf to Persian', function () {
    $input = "ي ك"; // Arabic Yeh (U+064A), Arabic Kaf (U+0643)
    $expected = "ی ک"; // Persian Yeh (U+06CC), Persian Kaf (U+06A9)
    expect(NormalizerHelper::normalize($input))->toBe($expected);
});

test('normalize converts ZWNJ and ZWJ to spaces', function () {
    $input = "سلام\u{200C}دنیا\u{200D}تست";
    $expected = "سلام دنیا تست";
    expect(NormalizerHelper::normalize($input))->toBe($expected);
});

test('normalize handles mixed strings correctly', function () {
    $input = "تست ي ك ZWNJ\u{200C} ZWJ\u{200D}";
    // ZWNJ and ZWJ characters become spaces, but literal "ZWNJ" and "ZWJ" text remains
    $expected = "تست ی ک ZWNJ  ZWJ ";
    expect(NormalizerHelper::normalize($input))->toBe($expected);
});

test('normalize is idempotent', function () {
    $input = "تست ي ك";
    $first = NormalizerHelper::normalize($input);
    expect(NormalizerHelper::normalize($first))->toBe($first);
});

test('normalizeForSearch trims and collapses whitespace', function () {
    $input = "  سلام    دنیا  \u{064A}  ";
    $expected = "سلام دنیا ی";
    expect(NormalizerHelper::normalizeForSearch($input))->toBe($expected);
});
