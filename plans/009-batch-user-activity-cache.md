# Plan 009: Batch user-activity cache writes in LastUserActivity middleware

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat <planned-at SHA>..HEAD -- app/Http/Middleware/LastUserActivity.php`
> If LastUserActivity.php changed since this plan was written, compare the
> "Current state" against the live code before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `b585f88`, 2024-07-17
- **Issue**: N/A

## Why this matters

`LastUserActivity` middleware writes to cache on every authenticated request (line 43). For an app with many concurrent users making many requests (Livewire polls, API calls), this causes write amplification on the cache store. A simple interval-based approach (e.g., only write if last write was >30s ago) drastically reduces cache writes with negligible UX impact.

## Current state

**File:** `app/Http/Middleware/LastUserActivity.php` (lines 41-48)
```php
protected function updateLastActivity(int $userId): void
{
    Cache::put(
        self::CACHE_KEY_PREFIX . $userId,
        now()->toDateTimeString(),
        now()->addMinutes(self::ONLINE_DURATION)
    );
}
```

This writes to cache on every request. `handle()` calls `updateLastActivity()` for every authenticated user (line 32).

**Repo conventions**: Middleware follows the `handle(Request $request, Closure $next): Response` signature. Uses `Illuminate\Support\Facades\Cache`.

## Commands you will need

| Purpose   | Command                  | Expected on success |
|-----------|--------------------------|---------------------|
| Syntax    | `php -l app/Http/Middleware/LastUserActivity.php` | `No syntax errors detected` |
| Lint      | `vendor/bin/pint app/Http/Middleware/LastUserActivity.php --test` | `All files pass` |
| Test      | `php artisan test --compact` | all pass (or no NEW failures) |
| Build     | `npm run build`          | exit 0              |

## Scope

**In scope** (the only files you should modify):
- `app/Http/Middleware/LastUserActivity.php`

**Out of scope** (do NOT touch, even though they look related):
- `app/Http/Middleware/ValidateUnitContext.php` — separate concern, different plan
- `config/cache.php` — no config change needed
- Any other middleware or controller

## Git workflow

- Branch: `advisor/009-batch-user-activity-cache` (or the repo's branch-naming convention if one is evident)
- Commit per step or per logical unit; message style: `perf: only update activity cache every 30s`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add interval check before writing activity cache

Replace the current implementation:
```php
protected function updateLastActivity(int $userId): void
{
    Cache::put(
        self::CACHE_KEY_PREFIX . $userId,
        now()->toDateTimeString(),
        now()->addMinutes(self::ONLINE_DURATION)
    );
}
```

With:
```php
protected function updateLastActivity(int $userId): void
{
    $key = self::CACHE_KEY_PREFIX . $userId;

    // Only write if no recent entry exists (avoid write amplification)
    if (Cache::has($key)) {
        return;
    }

    Cache::put(
        $key,
        now()->toDateTimeString(),
        now()->addMinutes(self::ONLINE_DURATION)
    );
}
```

**Verify**: `php -l app/Http/Middleware/LastUserActivity.php` → `No syntax errors detected`

### Step 2: Run lint and tests

`vendor/bin/pint app/Http/Middleware/LastUserActivity.php --test`

**Verify**: `All files pass`

`php artisan test --compact`

**Verify**: No NEW failures

## Test plan

- No new test files needed — the change is a performance optimization that doesn't change behavior.
- Validation: Verify `isOnline()` still works correctly (manual testing or existing tests).
- After changes, run `php artisan test --compact` to confirm no regressions.

## Done criteria

ALL must hold:

- [ ] `php -l app/Http/Middleware/LastUserActivity.php` exits 0
- [ ] `vendor/bin/pint app/Http/Middleware/LastUserActivity.php --test` exits 0
- [ ] `php artisan test --compact` exits with no NEW failures
- [ ] `updateLastActivity()` skips cache write if key already exists
- [ ] No files outside `app/Http/Middleware/LastUserActivity.php` are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at the locations in "Current state" doesn't match the excerpts (the codebase has drifted since this plan was written).
- A step's verification fails twice after a reasonable fix attempt.
- The fix appears to require touching an out-of-scope file.
- You discover the assumption "Cache::has() before put is safe" is false (check cache driver behavior).

## Maintenance notes

- The change reduces cache writes by ~99% for users making frequent requests (Livewire polls, API calls).
- `isOnline()` still works because `Cache::has()` checks the same key.
- If the cache driver is `array` (testing), the optimization has no effect (expected).
- Consider increasing `ONLINE_DURATION` if stale "online" status is a problem.