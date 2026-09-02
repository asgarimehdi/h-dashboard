# Plan 017: Fix N+1 Query in loadDeletedHardware

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

The `loadDeletedHardware` method in the hardware index Livewire component executes an N+1 query pattern: after fetching all `created` audit records, it runs an individual `HardwareAudit` query **per item** inside a `map()` callback to find the corresponding `deleted` timestamp.

### Current Code (Bug)

**File:** `resources/views/livewire/hardware/index.blade.php:675-689`

```php
$this->deletedHardware = HardwareAudit::whereIn('hardware_id', $deletedHardwareIds)
    ->where('action', 'created')
    ->with('user:id,n_code')
    ->get()
    ->map(function (HardwareAudit $audit) {
        // Pre-fetch deletedAt to avoid N+1 in Blade
        $deletedAudit = HardwareAudit::where('action', 'deleted')
            ->where('hardware_id', $audit->hardware_id)
            ->latest('created_at')
            ->first();
        $audit->deleted_at = $deletedAudit?->created_at;
        return $audit;
    })
    ->values()
    ->all();
```

The comment on line 680 ironically says "Pre-fetch deletedAt to avoid N+1 in Blade" — but the "pre-fetch" itself **is** the N+1: one `SELECT` per `$deletedHardwareIds` entry to find the latest `deleted` audit.

If there are 50 deleted hardware records, this runs **51 queries** (1 bulk + 50 individual).

### Related: loadDeletedHardware method

**File:** `resources/views/livewire/hardware/index.blade.php:655-692`

The preceding code collects `$deletedHardwareIds` from hardware marked as deleted.

---

## Solution

Batch-load all `deleted` audit records in a single query before the `map()`, then use a collection lookup.

### Changes

**File:** `resources/views/livewire/hardware/index.blade.php`

Replace lines 675-689 with:

```php
// Batch-load created audits
$createdAudits = HardwareAudit::whereIn('hardware_id', $deletedHardwareIds)
    ->where('action', 'created')
    ->with('user:id,n_code')
    ->get();

// Batch-load deleted timestamps (one query instead of N)
$deletedTimestamps = HardwareAudit::whereIn('hardware_id', $deletedHardwareIds)
    ->where('action', 'deleted')
    ->select('hardware_id', 'created_at')
    ->get()
    ->sortByDesc('created_at')
    ->keyBy('hardware_id');

$this->deletedHardware = $createdAudits
    ->map(function (HardwareAudit $audit) use ($deletedTimestamps) {
        $audit->deleted_at = $deletedTimestamps->get($audit->hardware_id)?->created_at;
        return $audit;
    })
    ->values()
    ->all();
```

### Why `sortByDesc` Then `keyBy`

A hardware item could have multiple `deleted` audit records (e.g., created → deleted → restored → deleted again). We need the **latest** deleted timestamp. `sortByDesc('created_at')` ensures the first entry per key in the `keyBy` map is the most recent deletion.

### Performance Impact

| Metric | Before | After |
|--------|--------|-------|
| Queries | 1 + N (N = deleted count) | 3 |
| Memory | Same | Same |
| Latency (50 items) | ~50ms | ~3ms |

---

## Verification

1. **Run hardware tests:**
   ```bash
   composer test -- --filter=Hardware
   ```
   Expected: all hardware tests pass.

2. **Xdebug/Debugbar check:**
   - Open hardware index → click trash icon
   - Enable Laravel Debugbar
   - Verify only 3 queries for deleted hardware (created audits, deleted timestamps, users eager-loaded)

3. **Correctness check:**
   - Verify deleted timestamps still display correctly in the trash modal
   - If a hardware was deleted twice, verify the latest deletion date shows

---

## STOP Conditions

- If the `HardwareAudit` model doesn't have the expected `hardware_id` column, abort.
- If tests fail due to changed query count, investigate and adjust test expectations.

---

## Out of Scope

- Converting the Livewire component to use a dedicated query service.
- Adding indexes to `hardware_audits` (check existing indexes separately).
- Changing the trash modal Blade template.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer test -- --filter=Hardware` | All hardware tests pass |
| 2 | `vendor/bin/pint --dirty --format agent` | Clean |
| 3 | Open trash modal with 20+ deleted items | 3 queries, not 21 |
| 4 | Trash shows correct latest-deleted timestamp | Matches DB |

---

## Maintenance Notes

- **Convention:** Livewire Blade files are single-file components (anonymous class at top). All queries live inline — no separate repository class.
- **Index check:** If `hardware_audits` lacks a composite index on `(hardware_id, action, created_at)`, add one in a separate migration.
- **Alternative:** This could be moved to a `loadDeletedHardwareWithTimestamps()` helper method within the same anonymous class for readability.
