# Plan 021: Split HrController (596 lines) into 3 Controllers

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

`HrController` at 596 lines with 12 methods mixes three distinct responsibilities:
1. **Org-chart tree rendering** (3 methods)
2. **Aggregate HR stats** (4 methods)
3. **Time-series analytics** (3 methods)

This violates Single Responsibility Principle and makes the controller hard to navigate and test independently.

### Current Code

**File:** `app/Http/Controllers/Api/HrController.php` (596 lines)

| Method | Lines | Responsibility |
|--------|-------|---------------|
| `orgChart` | 27-72 | Org-chart tree |
| `orgChartExpandable` | 74-113 | Org-chart tree |
| `loadSubtree` | 114-166 | Org-chart tree |
| `stats` | 167-259 | Aggregate stats |
| `vacancies` | 260-292 | Aggregate stats |
| `personnel` | 293-363 | Aggregate stats |
| `personDetail` | 364-381 | Aggregate stats |
| `headcountTrend` | 382-434 | Time-series analytics |
| `vacancyTrend` | 435-517 | Time-series analytics |
| `staffingRatio` | 518-596 | Time-series analytics |

### Current Route Registration

**File:** `routes/api.php` (routes registered under HR prefix — need to verify exact registration).

---

## Solution

Extract 3 controllers from `HrController`, each in its own file. Move raw SQL to service/repository classes for the analytics methods.

### New Files

**1. `app/Http/Controllers/Api/OrgChartController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgChartController extends Controller
{
    public function orgChart(Request $request): JsonResponse
    {
        // Move from HrController::orgChart (lines 27-72)
    }

    public function orgChartExpandable(Request $request): JsonResponse
    {
        // Move from HrController::orgChartExpandable (lines 74-113)
    }

    public function loadSubtree(Request $request, int $unitId): JsonResponse
    {
        // Move from HrController::loadSubtree (lines 114-166)
    }
}
```

**2. `app/Http/Controllers/Api/HrStatsController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrStatsController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        // Move from HrController::stats (lines 167-259)
    }

    public function vacancies(Request $request): JsonResponse
    {
        // Move from HrController::vacancies (lines 260-292)
    }

    public function personnel(Request $request): JsonResponse
    {
        // Move from HrController::personnel (lines 293-363)
    }

    public function personDetail(Request $request, string $nCode): JsonResponse
    {
        // Move from HrController::personDetail (lines 364-381)
    }
}
```

**3. `app/Http/Controllers/Api/HrAnalyticsController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrAnalyticsController extends Controller
{
    use PersianNormalizer;

    public function headcountTrend(Request $request): JsonResponse
    {
        // Move from HrController::headcountTrend (lines 382-434)
    }

    public function vacancyTrend(Request $request): JsonResponse
    {
        // Move from HrController::vacancyTrend (lines 435-517)
    }

    public function staffingRatio(Request $request): JsonResponse
    {
        // Move from HrController::staffingRatio (lines 518-596)
    }
}
```

### Route Changes

**File:** `routes/api.php`

```php
// Before:
Route::prefix('hr')->group(function () {
    Route::get('/org-chart', [HrController::class, 'orgChart']);
    Route::get('/org-chart-expandable', [HrController::class, 'orgChartExpandable']);
    Route::get('/org-chart/{unitId}/subtree', [HrController::class, 'loadSubtree']);
    Route::get('/stats', [HrController::class, 'stats']);
    Route::get('/vacancies', [HrController::class, 'vacancies']);
    Route::get('/personnel', [HrController::class, 'personnel']);
    Route::get('/person-detail/{nCode}', [HrController::class, 'personDetail']);
    Route::get('/headcount-trend', [HrController::class, 'headcountTrend']);
    Route::get('/vacancy-trend', [HrController::class, 'vacancyTrend']);
    Route::get('/staffing-ratio', [HrController::class, 'staffingRatio']);
});

// After:
Route::prefix('hr')->group(function () {
    // Org chart
    Route::get('/org-chart', [OrgChartController::class, 'orgChart']);
    Route::get('/org-chart-expandable', [OrgChartController::class, 'orgChartExpandable']);
    Route::get('/org-chart/{unitId}/subtree', [OrgChartController::class, 'loadSubtree']);

    // Stats
    Route::get('/stats', [HrStatsController::class, 'stats']);
    Route::get('/vacancies', [HrStatsController::class, 'vacancies']);
    Route::get('/personnel', [HrStatsController::class, 'personnel']);
    Route::get('/person-detail/{nCode}', [HrStatsController::class, 'personDetail']);

    // Analytics
    Route::get('/headcount-trend', [HrAnalyticsController::class, 'headcountTrend']);
    Route::get('/vacancy-trend', [HrAnalyticsController::class, 'vacancyTrend']);
    Route::get('/staffing-ratio', [HrAnalyticsController::class, 'staffingRatio']);
});
```

### Delete After Migration

**File:** `app/Http/Controllers/Api/HrController.php` — delete after all methods are moved.

---

## Verification

1. **Run all HR tests:**
   ```bash
   composer test -- --filter=Hr
   ```
   Expected: all HR tests pass (no behavioral change).

2. **Check routes:**
   ```bash
   php artisan route:list --path=api/hr
   ```
   Expected: all 10 HR routes still present, pointing to new controllers.

3. **Verify no remaining references to HrController:**
   ```bash
   grep -rn 'HrController' app/ routes/ tests/
   ```
   Expected: 0 matches (except the deleted file itself).

---

## STOP Conditions

- If the `HrController` has static methods called from other places, identify and migrate them first.
- If any test imports `HrController::class`, update the import to the new controller.
- If route parameters differ (e.g., `{unitId}` vs `{unit_id}`), match the original signature exactly.

---

## Out of Scope

- Moving raw SQL to service/repository classes (mentioned as future work, not this plan).
- Adding new HR endpoints.
- Changing the HR dashboard Livewire component to use the new controller structure.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer test -- --filter=Hr` | All HR tests pass |
| 2 | `php artisan route:list --path=api/hr` | 10 routes, new controllers |
| 3 | `grep -rn 'HrController' app/ routes/ tests/` | 0 matches |
| 4 | `wc -l app/Http/Controllers/Api/OrgChartController.php` | ~120 lines |
| 5 | `wc -l app/Http/Controllers/Api/HrStatsController.php` | ~250 lines |
| 6 | `wc -l app/Http/Controllers/Api/HrAnalyticsController.php` | ~240 lines |
| 7 | `vendor/bin/pint --dirty --format agent` | Clean |

---

## Maintenance Notes

- **Naming convention:** Controllers use PascalCase in `app/Http/Controllers/Api/`. The new files follow the same convention.
- **Test files:** Check `tests/Feature/` for HR-related tests that reference `HrController::class` in assertions or route definitions.
- **Flutter app:** The Flutter app calls these API endpoints by URL path. Route paths are unchanged — only the PHP controller class changes.
- **Future:** Extract raw SQL (especially analytics queries) into `HrAnalyticsService` for testability.
