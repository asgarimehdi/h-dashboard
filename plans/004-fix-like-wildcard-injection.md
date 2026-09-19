# Plan 004: Centralize LIKE wildcard escaping in PersianNormalizer

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19 (v2, verified with CodeGraph)

## Why this matters
LIKE wildcard injection allows users to craft search queries matching unintended rows. `normalizeForSearch()` (in `app/Traits/PersianNormalizer.php`) is called by 13+ search endpoints but does NOT escape `%` or `_`. `HardwareExportController` escapes correctly — the inconsistency is an oversight.

## Current state (verified)
- **Function**: `PersianNormalizer::normalizeForSearch()` at `app/Traits/PersianNormalizer.php:29-47`
- Currently does: Persian normalization, digit conversion, whitespace cleanup
- **Missing**: LIKE wildcard escaping for `%` and `_`
- **13 callers**: PersonController, HrStatsController, Hardware model scopes, hardware index, kargozini views, etc.
- **Existing escape**: `HardwareExportController` has its own `str_replace(['%', '_'], ...)` — this becomes redundant after the fix

## Scope
**In scope**: `app/Traits/PersianNormalizer.php` (normalizeForSearch method)
**Out of scope**: Remove redundant escaping in HardwareExportController (separate cleanup)

## Steps

### Step 1: Add LIKE escaping to normalizeForSearch()
In `app/Traits/PersianNormalizer.php`, after line 44 (whitespace cleanup), add:

```php
// Escape LIKE wildcards to prevent injection
$text = str_replace(['%', '_'], ['\\%', '\\_'], $text);
```

Full method after change:
```php
public static function normalizeForSearch(string $text): string
{
    $text = self::normalize($text);

    $text = strtr($text, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);

    $text = preg_replace('/\s+/', ' ', trim($text));

    // Escape LIKE wildcards to prevent injection
    $text = str_replace(['%', '_'], ['\\%', '\\_'], $text);

    return $text;
}
```

### Step 2: Verify all callers still work
```bash
grep -rn "normalizeForSearch" app/ --include="*.php"
# → list all 13+ callers
grep -rn "escapeLike\|str_replace.*wildcard" app/Http/Controllers/Api/HardwareExportController.php
# → note the existing escape (to be removed in separate cleanup)
```

### Step 3: Test review
Check existing tests:
```bash
grep -rn "normalizeForSearch" tests/ --include="*.php"
```
Add test case in `tests/Unit/PersianNormalizerTest.php`:
```php
it('escapes LIKE wildcards in search terms', function () {
    expect(PersianNormalizer::normalizeForSearch('test%name'))->toBe('test\\%name');
    expect(PersianNormalizer::normalizeForSearch('test_name'))->toBe('test\\_name');
    expect(PersianNormalizer::normalizeForSearch('100%_test'))->toBe('100\\%\\_test');
});
```

## Done criteria
- [ ] normalizeForSearch() escapes % and _ characters
- [ ] Existing tests pass
- [ ] New test case added
- [ ] `vendor/bin/pint --dirty --format agent` passes
