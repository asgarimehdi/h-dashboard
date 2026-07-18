# Plan 008: Reduce validate-unit-context middleware overhead

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat <planned-at SHA>..HEAD -- app/Http/Middleware/ValidateUnitContext.php`
> If ValidateUnitContext.php changed since this plan was written, compare the
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

`ValidateUnitContext` middleware loads `$user->units` on every authenticated request (line 31). This is a redundant eager load that fires a query even when `current_unit_id` is already set in the session. For a high-traffic app with many authenticated users, this adds unnecessary DB queries.

The fix: only load units when needed (when no `current_unit_id` is set or when validating access).

## Current state

**File:** `app/Http/Middleware/ValidateUnitContext.php` (lines 9-54)
```php
class ValidateUnitContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $currentUnitId = session('current_unit_id');

        if ($currentUnitId) {
            $hasAccess = $user->units()->where('units.id', $currentUnitId)->exists();

            if (! $hasAccess) {
                session()->forget('current_unit_id');
                session()->forget('current_unit_name');
                $currentUnitId = null;
            }
        }

        $userUnits = $user->units;  // <-- LOADS ALL UNITS HERE, ON EVERY REQUEST

        if ($userUnits->isEmpty() && ! $currentUnitId) {
            $person = $user->person;
            if ($person?->u_id) {
                session(['current_unit_id' => $person->u_id]);
                session(['current_unit_name' => $person->unit?->name ?? '-']);
            }

            return $next($request);
        }

        if ($userUnits->count() === 1 && ! $currentUnitId) {
            $unit = $userUnits->first();
            session(['current_unit_id' => $unit->id]);
            session(['current_unit_name' => $unit->name]);
        }

        if ($userUnits->count() > 1 && ! $currentUnitId) {
            return redirect('/select-context');
        }

        return $next($request);
    }
}
```

The line `$userUnits = $user->units;` loads the relationship eagerly on every request, even when `current_unit_id` is already set and valid.

**Repo conventions**: Middleware follows the `handle(Request $request, Closure $next): Response` signature. See `app/Http/Middleware/LastUserActivity.php` for pattern.

## Commands you will need

| Purpose   | Command                  | Expected on success |
|-----------|--------------------------|---------------------|
| Syntax    | `php -l app/Http/Middleware/ValidateUnitContext.php` | `No syntax errors detected` |
| Lint      | `vendor/bin/pint app/Http/Middleware/ValidateUnitContext.php --test` | `All files pass` |
| Test      | `php artisan test --compact` | all pass (or no NEW failures) |
| Build     | `npm run build`          | exit 0              |

## Scope

**In scope** (the only files you should modify):
- `app/Http/Middleware/ValidateUnitContext.php`

**Out of scope** (do NOT touch, even though they look related):
- `app/Http/Middleware/LastUserActivity.php` — separate concern, different plan
- `routes/web.php` — middleware registration
- Any model or controller code

## Git workflow

- Branch: `advisor/008-reduce-validateunitcontext-overhead` (or the repo's branch-naming convention if one is evident)
- Commit per step or per logical unit; message style: `perf: lazy-load units in ValidateUnitContext middleware`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Avoid eager-loading units when current_unit_id is already set

Replace the current implementation:
```php
$userUnits = $user->units;

if ($userUnits->isEmpty() && ! $currentUnitId) {
```
With:
```php
// Only load units when we need to determine unit context
if (! $currentUnitId) {
    $userUnits = $user->units;

    if ($userUnits->isEmpty()) {
        $person = $user->person;
        if ($person?->u_id) {
            session(['current_unit_id' => $person->u_id]);
            session(['current_unit_name' => $person->unit?->name ?? '-']);
        }

        return $next($request);
    }

    if ($userUnits->count() === 1) {
        $unit = $userUnits->first();
        session(['current_unit_id' => $unit->id]);
        session(['current_unit_name' => $unit->name]);
    }

    if ($userUnits->count() > 1) {
        return redirect('/select-context');
    }
}
```

**Verify**: `php -l app/Http/Middleware/ValidateUnitContext.php` → `No syntax errors detected`

### Step 2: Run lint and tests

`vendor/bin/pint app/Http/Middleware/ValidateUnitContext.php --test`

**Verify**: `All files pass`

`php artisan test --compact`

**Verify**: No NEW failures

## Test plan

- No new test files needed — the change is a performance optimization that doesn't change behavior.
- Validation: Verify the middleware still sets `current_unit_id` correctly (manual testing or existing tests).
- After changes, run `php artisan test --compact` to confirm no regressions.

## Done criteria

ALL must hold:

- [ ] `php -l app/Http/Middleware/ValidateUnitContext.php` exits 0
- [ ] `vendor/bin/pint app/Http/Middleware/ValidateUnitContext.php --test` exits 0
- [ ] `php artisan test --compact` exits with no NEW failures
- [ ] Middleware only loads `$user->units` when `current_unit_id` is not set
- [ ] No files outside `app/Http/Middleware/ValidateUnitContext.php` are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at the locations in "Current state" doesn't match the excerpts (the codebase has drifted since this plan was written).
- A step's verification fails twice after a reasonable fix attempt.
- The fix appears to require touching an out-of-scope file.
- You discover the assumption "current_unit_id set means no unit loading needed" is false (check if other code depends on `$user->units` being loaded).

## Maintenance notes

- The change reduces DB queries on every authenticated request where `current_unit_id` is already set in session.
- If a future feature needs `$user->units` loaded in all cases, reconsider this optimization.
- Tests should verify `current_unit_id` is set correctly in single-unit, multi-unit, and no-unit scenarios.