# Plan 008: Fix static $suppressAudit leaking across Octane requests

- **Status:** Not started
- **Category:** bug
- **Effort:** M
- **Risk:** MED
- **Priority:** P2
- **Depends on:** none
- **Base SHA:** 5f9c24e

## Why this matters

`Hardware::$suppressAudit` is a `public static bool` property (line 21 of `app/Models/Hardware.php`). Under Laravel Octane, PHP processes persist across requests. If a bulk operation crashes after setting `Hardware::$suppressAudit = true` but before the `finally` block can reset it, the static flag remains `true` for all subsequent requests. This silently suppresses ALL hardware audit logging — every create, update, and delete goes unrecorded until the Octane worker is restarted.

The API controller (`HardwareController.php`) already uses the request-scoped `request()->attributes->set('suppress_audit', true)` pattern (lines 296, 342). The observer already checks both (line 19-20). But the **static property is never set to true** by any code — it's dead code that exists as a latent risk. However, it IS checked first in `shouldSuppress()`, and any future code or extension that sets it would trigger the cross-request leak.

The fix removes the static property entirely, making the request-attribute approach the only mechanism.

## Current state (with file:line excerpts)

### Static property — `app/Models/Hardware.php:21`

```php
public static bool $suppressAudit = false;
```

This is the only declaration. No code in the codebase currently sets it to `true` — the API controller uses `request()->attributes->set('suppress_audit', true)` instead. But the static property is checked first in the observer, and removing it eliminates the class of bugs.

### Observer check — `app/Observers/HardwareAuditObserver.php:17-21`

```php
protected function shouldSuppress(): bool
{
    return Hardware::$suppressAudit
        || request()->attributes->get('suppress_audit', false);
}
```

Two mechanisms: static flag (dangerous under Octane) and request attribute (safe). Both branches checked via OR.

### API controller uses request attribute — `app/Http/Controllers/Api/HardwareController.php:296,302,342,346`

```php
request()->attributes->set('suppress_audit', true);
try {
    // ... bulk operation ...
} finally {
    request()->attributes->remove('suppress_audit');
}
```

This is the correct, request-scoped pattern. The `finally` block ensures cleanup even on exception.

## Scope

### In scope
- `app/Models/Hardware.php` — remove `$suppressAudit` static property (line 21)
- `app/Observers/HardwareAuditObserver.php` — remove the `Hardware::$suppressAudit` check from `shouldSuppress()` (line 19)

### Out of scope
- `app/Http/Controllers/Api/HardwareController.php` — already uses correct pattern, no changes needed
- Livewire bulk operations — they don't suppress audits (they rely on the observer)
- Adding request-attribute suppress to other controllers

## Commands

```bash
cd /home/runner/h-dashboard
grep -rn "suppressAudit" app/
php artisan test --filter Hardware
git diff --stat
```

## Steps

### Step 1: Remove static property from Hardware model

**File:** `app/Models/Hardware.php`

Remove lines 18-21:

```php
// BEFORE:
    /**
     * Flag to suppress audit logging during bulk operations.
     */
    public static bool $suppressAudit = false;

// AFTER:
// (delete these 4 lines)
```

**Verify:** Read the file. Confirm the static property is gone.

### Step 2: Simplify shouldSuppress() in observer

**File:** `app/Observers/HardwareAuditObserver.php`

Replace lines 11-21:

```php
// BEFORE:
    /**
     * Check if audit logging should be suppressed.
     *
     * Supports both static flag (standard PHP-FPM) and request attributes
     * (Laravel Octane / long-running workers).
     */
    protected function shouldSuppress(): bool
    {
        return Hardware::$suppressAudit
            || request()->attributes->get('suppress_audit', false);
    }

// AFTER:
    /**
     * Check if audit logging should be suppressed.
     *
     * Uses request attributes only (safe for Octane / long-running workers).
     * The static Hardware::$suppressAudit flag was removed because under Octane
     * it could leak across requests if a bulk operation crashed mid-way.
     */
    protected function shouldSuppress(): bool
    {
        return request()->attributes->get('suppress_audit', false);
    }
```

**Verify:** Read the method. Confirm only `request()->attributes->get()` is used.

### Step 3: Verify no other references

```bash
grep -rn "suppressAudit" app/
```

Expected: **Zero results.** Both the static property declaration and the observer reference are gone.

Also check for any direct `$suppressAudit` references:

```bash
grep -rn '\$suppressAudit' app/ resources/ routes/
```

Expected: Zero results.

### Step 4: Verify request attribute pattern is still intact

```bash
grep -rn "suppress_audit" app/
```

Expected: 4 matches — `HardwareAuditObserver.php` (1 check) + `HardwareController.php` (2 sets + 2 removes).

### Step 5: Run tests

```bash
php artisan test --filter Hardware
```

Expected: All tests pass. The static flag was never set to `true` by any production code, so removing it should not change behavior.

## Test plan

- Existing `Hardware` tests should pass unchanged.
- **Gap:** There are likely no tests for the Octane-specific static-leak scenario. Note but do not write new tests.
- Manual verification: `grep -rn "suppressAudit" app/` returns zero results. `grep -rn "suppress_audit" app/` returns 4+ results (observer + controller).

## Done criteria

- [ ] `Hardware::$suppressAudit` static property is removed from `app/Models/Hardware.php`
- [ ] `Hardware::$suppressAudit` reference is removed from `app/Observers/HardwareAuditObserver.php`
- [ ] `grep -rn "suppressAudit" app/` returns zero results
- [ ] `grep -rn "suppress_audit" app/` returns only the request-attribute pattern
- [ ] `php artisan test --filter Hardware` passes

## STOP conditions

- If `grep -rn "suppressAudit" app/` reveals references in files not listed in this plan, STOP and investigate those files before proceeding.
- If any test explicitly sets `Hardware::$suppressAudit = true`, STOP — those tests need to be migrated to use `request()->attributes->set('suppress_audit', true)` instead.
- If removing the static property breaks a Livewire bulk operation that depends on it (unlikely but possible if a middleware or trait sets it), STOP and investigate.

## Maintenance notes

- The `request()->attributes->get('suppress_audit', false)` pattern is safe for Octane because request attributes are scoped to a single HTTP request lifecycle.
- The `try/finally` pattern in `HardwareController.php` (lines 297-303, 343-347) is the correct way to use this: set before bulk, remove after — even on exception.
- If future code needs to suppress audits from non-request contexts (e.g., queue jobs, Artisan commands), it should use a separate mechanism (like a config flag or dedicated scope) rather than a static property.
- Consider adding a `@phpstan-ignore` or `@phpstan-static` annotation if static analysis flags the removed property in child classes (unlikely for a static bool).
