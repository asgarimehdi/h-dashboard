# Plan 002: LIKE Wildcard Injection in Hardware Filter Scopes

> Written against commit: `7e0052e` (beta/sydney)
> Category: Security | Effort: S | Impact: medium

## Problem

Multiple Hardware filter scopes and the Livewire component's inline query builder accept user input and embed it directly in `LIKE` patterns without escaping SQL LIKE wildcards (`%` and `_`). This allows a user to type `%` or `_` in any filter field to alter query matching behavior — expanding results beyond intended matches or causing unexpected query patterns.

**Two root causes:**

1. **Missing `normalizeForQuery()`**: Six model scopes (`filterType`, `filterOs`, `filterCpu`, `filterRam`, `filterHdd`, `filterNetType`) and the Livewire component's inline filter queries pass user input directly into `LIKE "%{$value}%"` without escaping.

2. **Wrong normalizer method**: Two model scopes (`filterUnit`, `filterSemat`) use `normalizeForSearch()` instead of `normalizeForQuery()`. This normalizes Persian characters but does NOT escape LIKE wildcards.

**Compare** with the correct pattern already used in `scopeFilterSearch` (line 114), `scopeFilterPerson` (line 222), and `HardwareExportController` (all filters):
```php
$s = self::normalizeForQuery($term);  // normalizes + escapes wildcards
$query->where('column', 'LIKE', "%{$s}%");
```

### Evidence

**Location 1 — `app/Models/Hardware.php` (8 scopes):**

| Scope | Line | Method Used | Vulnerable? |
|---|---|---|---|
| `scopeFilterSearch` | 114 | `normalizeForQuery()` | ✅ Safe |
| `scopeFilterType` | 140 | None — raw `$type` | ❌ Vulnerable |
| `scopeFilterOs` | 149 | None — raw `$os` | ❌ Vulnerable |
| `scopeFilterCpu` | 159 | None — raw `$cpu` | ❌ Vulnerable |
| `scopeFilterRam` | 169 | None — raw `$ram` | ❌ Vulnerable |
| `scopeFilterHdd` | 179 | None — raw `$hdd` | ❌ Vulnerable |
| `scopeFilterNetType` | 199 | None — raw `$netType` | ❌ Vulnerable |
| `scopeFilterPerson` | 222 | `normalizeForQuery()` | ✅ Safe |
| `scopeFilterUnit` | 241 | `normalizeForSearch()` | ❌ Partial — no wildcard escaping |
| `scopeFilterSemat` | 260 | `normalizeForSearch()` | ❌ Partial — no wildcard escaping |

**Location 2 — `resources/views/livewire/hardware/index.blade.php` (Livewire component, lines 525-548):**

The `hardwares()` method builds queries inline (does NOT call model scopes) and uses raw user input:
```php
// line 530
$query->where('type', 'LIKE', "%{$type}%");
// line 533
$query->where('os', 'LIKE', "%{$this->filterOs}%");
// lines 536, 539, 542, 548 — same pattern for cpu, ram, hdd, netType
```

Note: `filterPerson` (line 556) and `filterUnit` (line 565) correctly use `normalizeForQuery()`. Only the 6 scalar filters are vulnerable.

**Location 3 — `app/Http/Controllers/Api/GisController.php` (line 191):**
```php
$query->where('hardwares.type', 'LIKE', "%{$request->type}%");
```

**Correct pattern (HardwareExportController — all 9 filters properly escaped):**
```php
$type = self::normalizeForQuery($type);  // line 56
$query->where('type', 'LIKE', "%{$type}%");
```

### Callers of vulnerable scopes

- `HardwareController::index()` (line 121-132) calls `filterType()`, `filterOs()`, etc. with raw `$request->input()` — vulnerable via the API.
- Livewire component builds queries inline — vulnerable via the web UI.
- `GisController` builds inline — vulnerable via the GIS API.

## Solution

Add `normalizeForQuery()` to all 6 unprotected filter scopes in `Hardware.php`, change `normalizeForSearch()` to `normalizeForQuery()` in `filterUnit` and `filterSemat`, and apply the same fix to the Livewire component's inline queries and `GisController`.

### Before (Hardware.php:138-140)

```php
$scopeFilterType($query, ?string $type): void
{
    if (! $type) {
        return;
    }

    $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
    $type = $typeAliases[$type] ?? $type;

    $query->where('hardwares.type', 'LIKE', "%{$type}%");
}
```

### After

```php
public function scopeFilterType($query, ?string $type): void
{
    if (! $type) {
        return;
    }

    $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
    $type = $typeAliases[$type] ?? $type;

    $type = self::normalizeForQuery($type);
    $query->where('hardwares.type', 'LIKE', "%{$type}%");
}
```

### Before (Hardware.php:241, scopeFilterUnit)

```php
$normalized = self::normalizeForSearch($term);
```

### After

```php
$normalized = self::normalizeForQuery($term);
```

Same change for `scopeFilterSemat` (line 260).

### Livewire component (index.blade.php:525-548)

Add `normalizeForQuery()` before each LIKE:
```php
if ($this->filterType) {
    $type = self::normalizeForQuery($this->filterType);
    $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
    $type = $typeAliases[$type] ?? $type;
    $query->where('type', 'LIKE', "%{$type}%");
}
if ($this->filterOs) {
    $os = self::normalizeForQuery($this->filterOs);
    $query->where('os', 'LIKE', "%{$os}%");
}
// ... same for cpu, ram, hdd, netType
```

### GisController (line 191)

```php
// Before
$query->where('hardwares.type', 'LIKE', "%{$request->type}%");
// After
$type = self::normalizeForQuery($request->type);
$query->where('hardwares.type', 'LIKE', "%{$type}%");
```

`GisController` must `use App\Traits\PersianNormalizer;` and `use PersianNormalizer;` in the class.

## Files in Scope

- `app/Models/Hardware.php` — 8 scope fixes (6 add `normalizeForQuery`, 2 change `normalizeForSearch` → `normalizeForQuery`)
- `resources/views/livewire/hardware/index.blade.php` — 6 inline LIKE fixes
- `app/Http/Controllers/Api/GisController.php` — 1 LIKE fix + add trait

## Files Out of Scope

- `app/Http/Controllers/Api/HardwareExportController.php` — already safe (uses `normalizeForQuery()` on all 9 filters)
- `app/Http/Controllers/Api/PersonController.php` — already safe
- `app/Http/Controllers/Api/HrStatsController.php` — already safe
- `app/Http/Controllers/Api/HardwareController.php` — delegates to model scopes (fixed at scope level)

## Steps

### Step 1: Fix model scopes in Hardware.php

1. Add `$type = self::normalizeForQuery($type);` before the LIKE in `scopeFilterType` (after alias mapping, before the query)
2. Add `$os = self::normalizeForQuery($os);` in `scopeFilterOs`
3. Add `$cpu = self::normalizeForQuery($cpu);` in `scopeFilterCpu`
4. Add `$ram = self::normalizeForQuery($ram);` in `scopeFilterRam`
5. Add `$hdd = self::normalizeForQuery($hdd);` in `scopeFilterHdd`
6. Add `$netType = self::normalizeForQuery($netType);` in `scopeFilterNetType`
7. Change `normalizeForSearch($term)` → `normalizeForQuery($term)` in `scopeFilterUnit` (line 241)
8. Change `normalizeForSearch($term)` → `normalizeForQuery($term)` in `scopeFilterSemat` (line 260)
9. Verify: `grep -n 'normalizeForSearch' app/Models/Hardware.php` — should only show line 61 (the boot saving handler, which is correct for data normalization)

### Step 2: Fix Livewire component inline queries

1. In `resources/views/livewire/hardware/index.blade.php`, add normalization for `filterType` (line 525-530), `filterOs` (532-533), `filterCpu` (535-536), `filterRam` (538-539), `filterHdd` (541-542), `filterNetType` (547-548)
2. Each block should call `self::normalizeForQuery()` on the value before the LIKE

### Step 3: Fix GisController

1. Add `use App\Traits\PersianNormalizer;` import to `GisController.php`
2. Add `use PersianNormalizer;` inside the class
3. Normalize `$request->type` at line 191 before the LIKE

### Step 4: Verify

```bash
composer phpstan    # Ensure no new errors
composer pint       # Format
composer test       # All 1352 tests pass
```

## Test Plan

1. **Unit test**: Add tests to `tests/Feature/HardwareScopeTest.php` (or a new file `tests/Feature/HardwareFilterWildcardTest.php`) that verify:
   - Searching for `type` with value `laptop%` returns only records where type contains literal `laptop%`, not all records (because `%` would match everything)
   - Searching for `type` with value `pc_` returns only records where type contains literal `pc_`, not `pc1`/`pc2` (because `_` is a single-char wildcard)
   - Same pattern for os, cpu, ram, hdd, net_type filters

2. **Existing tests**: All 1352 tests in `composer test` must pass unchanged — the normalization is transparent for normal input

3. **Regression check**: Verify that normal filter values (e.g., `type=pc`, `os=Windows`) still match correctly after escaping

## Maintenance Note

All future LIKE queries involving user input MUST use `normalizeForQuery()` — never raw interpolation. The `scopeFilterSearch` and `scopeFilterPerson` scopes already follow this pattern and serve as the reference. The `HardwareExportController` is another good reference (9 filters, all properly escaped).

Do NOT use `normalizeForSearch()` in query builders — it normalizes Persian characters but does NOT escape LIKE wildcards. Use `normalizeForQuery()` which combines both.

## Done Criteria

- [ ] All 6 unprotected filter scopes in `Hardware.php` use `normalizeForQuery()`
- [ ] `scopeFilterUnit` and `scopeFilterSemat` use `normalizeForQuery()` instead of `normalizeForSearch()`
- [ ] Livewire component's 6 inline filter LIKE queries use `normalizeForQuery()`
- [ ] `GisController` type filter uses `normalizeForQuery()`
- [ ] `grep -n 'normalizeForSearch' app/Models/Hardware.php` returns only line 61 (boot saving handler)
- [ ] All existing tests pass (`composer test`)
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
- [ ] New wildcard-escape tests added and passing
