# Plan 020: Deduplicate accessibleIds Auth Pattern — Create UnitScopedRequest

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

The same authorization pattern is repeated **50+ times** across all 8+ API controllers:

```php
$accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
```

This is followed by an `in_array()` check to verify the requested resource belongs to an accessible unit. The pattern appears in every controller method that scopes data by unit.

### Current Code (Repeated Pattern)

**Files affected (with occurrence counts):**

| Controller | Occurrences |
|-----------|-------------|
| `TicketCommentController.php` | 8 (lines 23, 60, 121, 137, 167, 187, 219, 243) |
| `HrController.php` | 10 (lines 29, 76, 116, 169, 262, 295, 366, 384, 437, 520) |
| `PersonController.php` | 5 (lines 18, 70, 94, 110, 143) |
| `TicketController.php` | 5 (lines 50, 77, 98, 121, 134) |
| `TodoController.php` | 5 (lines 17, 77, 91, 114, 130) |
| `HardwareController.php` | 2 (lines 181, 217) |
| `HardwareAuditController.php` | 1 (line 129) |
| `UnitController.php` | 6 (lines 15, 34, 58, 79, 96, 131) |
| `GisController.php` | 5 (lines 107, 154, 247, 309, 367) |
| `ReportController.php` | 3 (lines 20, 55, 104) |
| **Total** | **50** |

### Typical Pattern (Example)

**File:** `app/Http/Controllers/Api/TicketController.php:50`
```php
$accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
if (! in_array($ticket->unit_id, $accessibleIds)) {
    return response()->json(['message' => 'Unauthorized'], 403);
}
```

The `AccessService` call is identical everywhere. The `in_array` check varies slightly (checking different FK: `unit_id`, `u_id`, `person->u_id`, etc.).

---

## Solution

Create a `UnitScopedRequest` FormRequest that automatically resolves `$accessibleIds` and provides a helper for the `in_array` authorization check.

### New Files

**File:** `app/Http/Requests/UnitScopedRequest.php`

```php
<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;

class UnitScopedRequest extends FormRequest
{
    protected ?array $accessibleIds = null;

    public function authorize(): bool
    {
        return true; // Authorization logic delegated to methods
    }

    public function accessibleIds(): array
    {
        return $this->accessibleIds ??= app(AccessService::class)
            ->accessibleUnitIds($this->user());
    }

    /**
     * Assert the given unit ID is within the caller's accessible scope.
     * Returns 403 JSON response if not authorized.
     */
    public function assertAccessibleUnit(int $unitId): \Illuminate\Http\JsonResponse|true
    {
        if (! in_array($unitId, $this->accessibleIds())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return true;
    }
}
```

### Migration Strategy

**Phase 1 (this plan):** Create `UnitScopedRequest` and adopt it in 2-3 controllers as proof of concept. Keep the existing `Request $request` signatures working (don't break tests).

**Phase 2 (future):** Migrate all controllers to use `UnitScopedRequest` and remove all `app(AccessService::class)->accessibleUnitIds()` calls.

### Changes for Phase 1

**File:** `app/Http/Requests/UnitScopedRequest.php` — create (as above).

**File:** `app/Http/Controllers/Api/TodoController.php` — migrate first (smallest, 5 occurrences):
```php
public function index(UnitScopedRequest $request): JsonResponse
{
    $accessibleIds = $request->accessibleIds();
    // ... rest unchanged
}
```

Repeat for `HardwareController` (2 occurrences — minimal risk).

### Existing Middleware Alternative Considered

A middleware that adds `$request->accessibleIds` was considered but rejected:
- Middleware runs before route parameters are available (can't do per-model checks).
- The `in_array` check varies per route (different FKs).
- FormRequest gives per-method control with shared logic.

---

## Verification

1. **Run all API tests:**
   ```bash
   composer test -- --filter=Api
   ```
   Expected: all API tests pass (no behavioral change).

2. **Verify no old pattern in migrated controllers:**
   ```bash
   grep -n 'app(AccessService::class)->accessibleUnitIds' app/Http/Controllers/Api/TodoController.php
   ```
   Expected: 0 matches.

3. **Verify pattern still exists in non-migrated controllers:**
   ```bash
   grep -c 'app(AccessService::class)->accessibleUnitIds' app/Http/Controllers/Api/*.php
   ```
   Expected: counts match before for non-migrated controllers.

---

## STOP Conditions

- If `UnitScopedRequest` causes DI conflicts with existing middleware, abort.
- If any existing test that uses `UnitScopedRequest` fails, revert and investigate.
- If `authorize()` returning `true` bypasses existing auth middleware, check route definitions.

---

## Out of Scope

- Migrating all 50 occurrences in this plan (Phase 2).
- Changing the `AccessService` API or caching behavior.
- Adding role-based authorization to the FormRequest.
- Changing route definitions or middleware stacks.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer test -- --filter=Todo` | All todo tests pass |
| 2 | `composer test -- --filter=Hardware` | All hardware tests pass |
| 3 | `grep -rn 'app(AccessService::class)->accessibleUnitIds' app/Http/Controllers/Api/TodoController.php` | 0 matches |
| 4 | `vendor/bin/pint --dirty --format agent` | Clean |
| 5 | Manual: create todo via API with token | Works as before |

---

## Maintenance Notes

- **Convention:** No existing `FormRequest` classes in this project (app/Http/Requests/ doesn't exist). This creates the first one — establish the pattern.
- **Phase 2 scope:** 50 occurrences across 10 controllers. Consider doing it controller-by-controller in separate PRs to limit blast radius.
- **Performance:** `accessibleUnitIds()` result is cached by `AccessService`. The FormRequest's memoization (`$this->accessibleIds ??=`) prevents double-calling within a single request.
- **Encoding:** No `in_array` encoding issues — unit IDs are always integers.
