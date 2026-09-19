<?php

use Tests\Unit\NormalizerHelper;

test('normalize converts Arabic Yeh and Kaf to Persian', function () {
    $input = 'ي ك'; // Arabic Yeh (U+064A), Arabic Kaf (U+0643)
    $expected = 'ی ک'; // Persian Yeh (U+06CC), Persian Kaf (U+06A9)
    expect(NormalizerHelper::normalize($input))->toBe($expected);
});

test('normalize converts ZWNJ and ZWJ to spaces', function () {
    $input = "سلام\u{200C}دنیا\u{200D}تست";
    $expected = 'سلام دنیا تست';
    expect(NormalizerHelper::normalize($input))->toBe($expected);
});

test('normalize handles mixed strings correctly', function () {
    $input = "تست ي ك ZWNJ\u{200C} ZWJ\u{200D}";
    // ZWNJ and ZWJ characters become spaces, but literal "ZWNJ" and "ZWJ" text remains
    $expected = 'تست ی ک ZWNJ  ZWJ ';
    expect(NormalizerHelper::normalize($input))->toBe($expected);
});

test('normalize is idempotent', function () {
    $input = 'تست ي ك';
    $first = NormalizerHelper::normalize($input);
    expect(NormalizerHelper::normalize($first))->toBe($first);
});

test('normalizeForSearch trims and collapses whitespace', function () {
    $input = "  سلام    دنیا  \u{064A}  ";
    $expected = 'سلام دنیا ی';
    expect(NormalizerHelper::normalizeForSearch($input))->toBe($expected);
});

test('escapeLikeWildcards escapes percent signs', function () {
    expect(NormalizerHelper::escapeLikeWildcards('hello%world'))->toBe('hello\\%world');
});

test('escapeLikeWildcards escapes underscores', function () {
    expect(NormalizerHelper::escapeLikeWildcards('hello_world'))->toBe('hello\\_world');
});

test('escapeLikeWildcards escapes both wildcards in mixed text', function () {
    expect(NormalizerHelper::escapeLikeWildcards('%test_case%'))->toBe('\\%test\\_case\\%');
});

test('escapeLikeWildcards returns text unchanged when no wildcards present', function () {
    expect(NormalizerHelper::escapeLikeWildcards('سلام دنیا'))->toBe('سلام دنیا');
});

test('normalizeForQuery combines normalize and escape for Persian text with wildcards', function () {
    $input = "  ي ك %تست  \u{200C} ";
    $expected = "ی ک \\%تست";
    expect(NormalizerHelper::normalizeForQuery($input))->toBe($expected);
});

test('normalizeForQuery escapes wildcards and converts Persian digits', function () {
    $input = '۱۲۳_%test%';
    $expected = '123\\_\\%test\\%';
    expect(NormalizerHelper::normalizeForQuery($input))->toBe($expected);
});
