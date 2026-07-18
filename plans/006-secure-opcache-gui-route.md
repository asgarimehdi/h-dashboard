# Plan 006: Secure OPcache GUI route

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat <planned-at SHA>..HEAD -- routes/web.php`
> If routes/web.php changed since this plan was written, compare the
> "Current state" against the live code before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `b585f88`, 2024-07-17
- **Issue**: N/A

## Why this matters

The `/op` route at `routes/web.php:106` serves the OPcache GUI by directly `include`-ing a file from `resources/views/op/index.php`. While this file is a well-known open-source tool (opcache-gui v3.6.0), serving it via `include` in a production Laravel application is fragile: it bypasses Laravel's view rendering, CSRF protection, and any middleware that might apply to the rest of the app. If the file is modified by an attacker or a future contributor adds unsafe code, it becomes an LFI/RCE surface.

Moving it to a proper Livewire component or moving it to a separate admin subdomain removes this risk. For this plan, we'll just add a comment documenting the file's provenance and restrict access to local/dev environments only.

## Current state

**File:** `routes/web.php` (lines 104-113)
```php
Route::middleware('role_or_permission:op-cache')->group(function () {
    Route::get('/op', function () {
        $path = resource_path('views/op/index.php');
        if (! is_file($path)) {
            abort(404, 'OPcache GUI not found.');
        }
        include $path;
    })->name('op');
});
```

**File:** `resources/views/op/index.php` (first 20 lines)
```php
<?php
/**
 * OPcache GUI
 *
 * A simple but effective single-file GUI for the OPcache PHP extension.
 *
 * @author Andrew Collington, andy@amnuts.com
 * @version 3.6.0
 * @link https://github.com/amnuts/opcache-gui
 * @license MIT, https://acollington.mit-license.org/
 */
$options = [
    'allow_filelist'   => true,
    ...
```

The file is a well-known open-source tool, but its `include` pattern is fragile.

**Repo conventions**: Routes follow the pattern of middleware groups with Livewire routes. See `routes/web.php:28` for auth middleware grouping.

## Commands you will need

| Purpose   | Command                  | Expected on success |
|-----------|--------------------------|---------------------|
| Syntax    | `php -l routes/web.php` | `No syntax errors detected` |
| Lint      | `vendor/bin/pint routes/web.php --test` | `All files pass` |
| Test      | `php artisan test --compact` | all pass (or no NEW failures) |
| Build     | `npm run build`          | exit 0              |

## Scope

**In scope** (the only files you should modify):
- `routes/web.php`

**Out of scope** (do NOT touch, even though they look related):
- `resources/views/op/index.php` — we are not modifying this file in this plan
- Any middleware or config files
- Any Livewire components

## Git workflow

- Branch: `advisor/006-secure-opcache-gui-route` (or the repo's branch-naming convention if one is evident)
- Commit per step or per logical unit; message style: `security: restrict OPcache GUI to local/dev environments only`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add environment check to OPcache GUI route

Replace the current implementation:
```php
Route::middleware('role_or_permission:op-cache')->group(function () {
    Route::get('/op', function () {
        $path = resource_path('views/op/index.php');
        if (! is_file($path)) {
            abort(404, 'OPcache GUI not found.');
        }
        include $path;
    })->name('op');
});
```

With:
```php
// OPcache GUI — only accessible in local/dev environments
if (app()->isLocal()) {
    Route::middleware('role_or_permission:op-cache')->group(function () {
        Route::get('/op', function () {
            $path = resource_path('views/op/index.php');
            if (! is_file($path)) {
                abort(404, 'OPcache GUI not found.');
            }
            include $path;
        })->name('op');
    });
}
```

**Verify**: `php -l routes/web.php` → `No syntax errors detected`

### Step 2: Run lint and tests

`vendor/bin/pint routes/web.php --test`

**Verify**: `All files pass`

`php artisan test --compact`

**Verify**: No NEW failures

## Test plan

- No new test files needed — the change is a routing guard.
- Validation: Verify the route is only accessible in local environments (manual testing with different APP_ENV values).
- After changes, run `php artisan test --compact` to confirm no regressions.

## Done criteria

ALL must hold:

- [ ] `php -l routes/web.php` exits 0
- [ ] `vendor/bin/pint routes/web.php --test` exits 0
- [ ] `php artisan test --compact` exits with no NEW failures
- [ ] `/op` route is wrapped in `app()->isLocal()` check
- [ ] No files outside `routes/web.php` are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at the locations in "Current state" doesn't match the excerpts (the codebase has drifted since this plan was written).
- A step's verification fails twice after a reasonable fix attempt.
- The fix appears to require touching an out-of-scope file.
- You discover the assumption "app()->isLocal() checks APP_ENV" is false (check Laravel docs).

## Maintenance notes

- If the OPcache GUI needs to be accessible in production, move it to a separate admin subdomain or behind a VPN.
- Consider converting the OPcache GUI to a Livewire component for better integration with Laravel's middleware and CSRF protection.
- If the OPcache GUI file is removed in a future update, the `is_file` check will return 404 (safe).