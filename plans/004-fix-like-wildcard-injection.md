# Plan 004: Centralize LIKE wildcard escaping

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19

## Why this matters
LIKE wildcard injection allows users to craft search queries matching unintended rows. `normalizeForSearch()` is called by most search endpoints but does NOT escape `%` or `_`. `HardwareExportController` escapes correctly — the inconsistency is an oversight.

## Current state
- `app/Support/Helpers.php` (or wherever `normalizeForSearch` lives): does NOT escape LIKE wildcards
- `app/Http/Controllers/Api/HardwareExportController.php` line 37: correctly escapes with `str_replace(['%', '_'], ['\\\\%', '\\\\_'], ...)`
- Affected callers: PersonController::index, Hardware::scopeFilterSearch, HrStatsController::personnel, all Hardware filter scopes

## Scope
**In scope**: The `normalizeForSearch()` function definition
**Out of scope**: Remove the redundant escaping in HardwareExportController (separate cleanup)

## Steps

### Step 1: Add LIKE escaping to normalizeForSearch()
Find the function definition and add escaping:
```php
function normalizeForSearch(string $term): string
{
    $term = preg_replace('/\s+/', ' ', trim($term));
    $term = str_replace(['%', '_'], ['\\\\%', '\\\\_'], $term);
    return $term;
}
```

### Step 2: Verify all callers
**Verify**: `cd /home/runner/h-dashboard && grep -rn "normalizeForSearch" app/ --include="*.php"` → list all callers
**Verify**: `cd /home/runner/h-dashboard && grep -rn "escapeLike\|str_replace.*wildcard" app/Http/Controllers/Api/HardwareExportController.php` → note the existing escape (to be removed later)

## Done criteria
- [ ] normalizeForSearch() escapes % and _ characters
- [ ] pint passes

## STOP conditions
- If normalizeForSearch is defined in a vendor package (unlikely)
