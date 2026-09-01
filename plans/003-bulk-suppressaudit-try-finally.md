# Plan 003: Restore bulk audit suppression flag with try/finally

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareController.php app/Models/Hardware.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `70e35c2`, 2026-09-01

## Why this matters

`HardwareController::bulkMark` and `bulkDelete` suppress per-row audit events via a global static `Hardware::$suppressAudit = true`, do a bulk `update`/`delete`, then set it `false`. If the bulk query throws (deadlock, FK, observer), the `false` line is skipped and the flag stays `true` for the rest of the request — and under long-lived runtimes (Octane/RoadRunner) for subsequent requests — silently dropping all future hardware audits. Wrapping in `try/finally` guarantees restoration.

## Current state

Files:

- `app/Http/Controllers/Api/HardwareController.php` — `bulkMark` ~335-378, `bulkDelete` ~380-418, `batchInsertAudits` ~420-447
- `app/Models/Hardware.php:22` — `public static bool $suppressAudit = false;`
- `app/Observers/HardwareAuditObserver.php:17,48,64,77` — `if (Hardware::$suppressAudit) return;`

Current code at `app/Http/Controllers/Api/HardwareController.php:359-376` (bulkMark):

```php
// Suppress individual audit entries during bulk operations
Hardware::$suppressAudit = true;

// Single update query on the verified IDs
$count = Hardware::whereIn('id', $accessibleHardwareIds)
    ->update(['mark' => $request->mark]);

// Restore audit logging
Hardware::$suppressAudit = false;

// Batch insert audit entries
$this->batchInsertAudits($hardwares, 'bulk_mark', [...]);
```

And at `407-412` (bulkDelete):

```php
// Suppress individual audit entries during bulk operations
Hardware::$suppressAudit = true;

$count = Hardware::whereIn('id', $accessibleHardwareIds)->delete();

// Restore audit logging
Hardware::$suppressAudit = false;
```

Repo conventions (AGENTS.md):

- No Octane yet, but static persists per FPM request; still a bug within one request if later code in same request writes hardware (e.g., `batchInsertAudits` calls `HardwareAudit::insert` which is fine, but subsequent hardware saves in same request lose audit). This violates **Scheduler & Console** expectations — long-lived `queue:listen` (in `composer.json dev` script) would propagate the stale flag.
- Error handling in controllers returns JSON with `success` and uses `abort(403)` for scope (see `HardwareController:353-354`) — keep **AccessService + spatie `manage_hardware`** gating.
- Format with `vendor/bin/pint --dirty --format agent` (AGENTS.md **Conventions > Formatting**).
- Cache: `Hardware::flushStatsCache()` must stay outside `try` and still bump `CacheInvalidationService` namespaces `hardware_stats/gis/maps/dashboard`.
- Code intelligence: `codegraph explore "HardwareController bulkMark bulkDelete"` before editing.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareController.php` | empty |
| Lint | `vendor/bin/pint --dirty --format agent` (AGENTS.md **Formatting**) | exit 0 |
| Tests | `composer test` — `config:clear + route:clear + XDEBUG_MODE=off php artisan test` (AGENTS.md **Running Tests**, hermetic `CACHE_STORE=array`) | 884 baseline pass |
| Single suite | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareBulkOperationsTest.php` | pass |
| CodeGraph | `codegraph explore "HardwareController bulkMark"` | symbols |

## Scope

**In scope**:

- `app/Http/Controllers/Api/HardwareController.php`
- `tests/Feature/HardwareBulkAuditSuppressionTest.php` (create, or extend `HardwareBulkOperationsTest.php` if preferred — but keep in-scope by creating new file)

**Out of scope**:

- `app/Models/Hardware.php` — don't change flag type/semantics
- `app/Observers/HardwareAuditObserver.php` — observer stays early-return
- Any queue / Octane config

## Git workflow

- Branch: `advisor/003-bulk-suppressaudit-finally` (from `kimya`/`main`, AGENTS.md **New Features** flow)
- Commit: `fix(api): restore suppressAudit flag via try/finally in bulk ops`
- Do NOT push — plans only, no execution per user request
- Frontend: API-only, no `npm run build` needed

## Steps

### Step 1: Wrap `bulkMark` suppression in try/finally

In `app/Http/Controllers/Api/HardwareController.php` method `bulkMark` (around 359-377), change to:

```php
Hardware::$suppressAudit = true;
try {
    $count = Hardware::whereIn('id', $accessibleHardwareIds)
        ->update(['mark' => $request->mark]);
} finally {
    Hardware::$suppressAudit = false;
}

// Batch insert audit entries
$this->batchInsertAudits($hardwares, 'bulk_mark', [
    ['field' => 'mark', 'old' => ! $request->mark, 'new' => $request->mark],
]);

app(GisController::class)::invalidateCache();
Hardware::flushStatsCache();
```

Keep the batch insert *outside* the try so it runs after flag is restored (it uses `HardwareAudit::insert` directly, not observer, so flag irrelevant but ordering is clearer). Keep `accessibleHardwareIds` validation above the try.

**Verify**: `vendor/bin/pint --dirty --format agent` → 0.

### Step 2: Wrap `bulkDelete` suppression in try/finally

In `bulkDelete` (around 400-416), change to:

```php
// Batch insert audit entries before deletion
$this->batchInsertAudits($hardwares, 'bulk_delete', null, fn ($hw) => $hw->getAttributes());

$accessibleHardwareIds = $hardwares->pluck('id')->toArray();

Hardware::$suppressAudit = true;
try {
    $count = Hardware::whereIn('id', $accessibleHardwareIds)->delete();
} finally {
    Hardware::$suppressAudit = false;
}

app(GisController::class)::invalidateCache();
Hardware::flushStatsCache();
```

Note: `batchInsertAudits` stays *before* the delete (so it can read `getAttributes`), and suppression only wraps the `delete()` call that would fire `deleting` observer. Ensure the `pluck` stays before the try.

**Verify**: `vendor/bin/pint --dirty --format agent` → 0. `XDEBUG_MODE=off php artisan test tests/Feature/HardwareBulkOperationsTest.php` → pass.

### Step 3: Add regression test that flag is restored after exception

Create `tests/Feature/HardwareBulkAuditSuppressionTest.php` (Pest), modeled on `tests/Feature/HardwareBulkOperationsTest.php` and `HardwareAuditObserverTest.php`:

- Cases:
  1. `bulkMark restores suppressAudit even when update throws` — mock or force an exception inside the bulk update path. Simplest: use `DB::shouldReceive` or temporarily break by mocking `Hardware::whereIn`? Instead, test indirectly: after a failed `bulkMark` (e.g., send invalid ids that pass validation but trigger 403? Not throw. Better: use reflection to assert flag after exception). Recommended approach: in test, set `Hardware::$suppressAudit = false`, then call `bulkMark` with a DB failure injected via `DB::spy` or by passing ids that cause a deliberate exception via a mocked `Hardware` model. Simpler deterministic test: verify the code contains `try`/`finally` via a source assertion, plus a behavioral test: do a failing bulk operation (force exception by violating a DB constraint) and then assert a subsequent `Hardware::create` still creates an audit row.
  
  Implementation sketch (choose one that works without heavy mocking):
  ```php
  test('suppressAudit is false after bulkMark even on failure', function () {
      // Arrange: create user with manage_hardware, accessible unit, two hardwares
      // Act: call bulkMark with ids that include a non-existent id? That returns 403 before flag set — not good.
      // Instead, test the happy path leaves flag false and the failure path via manual flag check:
      expect(Hardware::$suppressAudit)->toBeFalse();
      // Perform a successful bulkMark
      $this->actingAs($user, 'sanctum')->postJson('/api/hardware/bulk-mark', ['ids'=>[$hw1->id],'mark'=>true])->assertOk();
      expect(Hardware::$suppressAudit)->toBeFalse();
      // Verify audit still works for next write
      $hw = Hardware::create([...]);
      expect(HardwareAudit::where('hardware_id', $hw->id)->where('action','created')->exists())->toBeTrue();
  });
  ```
  To cover the exception path, assert the source contains `try` and `finally`:
  ```php
  test('bulk methods use try finally for suppressAudit', function () {
      $src = file_get_contents(app_path('Http/Controllers/Api/HardwareController.php'));
      expect($src)->toContain('Hardware::$suppressAudit = true;');
      expect($src)->toContain('} finally {');
      expect(substr_count($src, 'Hardware::$suppressAudit = false;'))->toBe(2);
  });
  ```

Keep the test hermetic — no real DB exception needed if source assertion is present.

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/HardwareBulkAuditSuppressionTest.php` → 2-3 passed. `composer test` → full suite passes.

## Test plan

- New file `tests/Feature/HardwareBulkAuditSuppressionTest.php` with at least 2 tests: source-contains-try-finally and behavioral flag-restored after bulkMark.
- Pattern: `tests/Feature/HardwareBulkOperationsTest.php:1-40` for Pest structure, `tests/Feature/HardwareAuditObserverTest.php` for audit assertions.
- If mocking is too invasive, the source assertion alone is acceptable as a regression gate (it will catch future removal of try/finally).

## Done criteria

- [ ] `vendor/bin/pint --dirty --format agent` exits 0
- [ ] `XDEBUG_MODE=off php artisan test tests/Feature/HardwareBulkAuditSuppressionTest.php` exits 0
- [ ] `composer test` exits 0
- [ ] `grep -A3 "suppressAudit = true" app/Http/Controllers/Api/HardwareController.php` shows a `try {` line within 2 lines and a `} finally {` block restoring to false
- [ ] `Hardware::$suppressAudit` is still `false` after a successful bulkMark/bulkDelete (behavioral test)
- [ ] No out-of-scope files modified
- [ ] `plans/README.md` row for 003 updated to DONE

## STOP conditions

- `bulkMark`/`bulkDelete` methods at `HardwareController.php:335-418` don't match excerpt (drift — maybe bulk routes were removed or renamed).
- `Hardware::$suppressAudit` type changed from `bool` to something else, or observer no longer checks it.
- `Hardware::flushStatsCache()` or `GisController::invalidateCache()` moved inside the try — if so, move them outside as shown; if unclear, stop and report.
- Test harness cannot read `app_path` file (permission) — adjust to `base_path('app/...')`.

## Maintenance notes

- If Octane/RoadRunner is ever adopted, `static $suppressAudit` becomes cross-request state — this try/finally is even more critical, but consider replacing the static with a request-scoped context (e.g., `Context::` or a service) to avoid leakage entirely. Track as follow-up.
- Reviewer: confirm that `batchInsertAudits` remains outside the try (it should not be suppressed). Check that `finally` does not swallow the original exception (it doesn't — `finally` rethrows after restoring flag).
- Follow-up deferred: extracting a helper `withSuppressedAudit(fn()=>...)` to DRY the two methods — left out to keep diff minimal; can be done later.
