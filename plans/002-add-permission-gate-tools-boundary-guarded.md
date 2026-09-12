# Plan 002: Add permission gate to Tools component + fix Boundary $guarded

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in the STOP conditions occurs, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat 5f9c24e..HEAD -- routes/web.php app/Models/Boundary.php resources/views/livewire/tools/tools.blade.php`

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: HIGH
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `5f9c24e`, 2026-09-12

## ⚠️ TL;DR فارسی

**مشکل:** صفحه ابزارها فقط auth داره — هر کاربری می‌تونه حذف دسته‌جمعی کنه. Boundary $guarded=[].

**راه‌حل:** middleware role_or_permission + $fillable.

**ریسک:** 🟡 متوسط — permission اشتباه = قفل کاربران


## Why this matters
Two independent security issues compound: (1) The `/tools` route that allows bulk deletion of tickets, activity logs, and notifications is protected only by `auth` — any logged-in user can access it regardless of role, enabling data destruction. (2) The `Boundary` model uses `$guarded = []` which disables mass-assignment protection entirely, meaning any field (including `id`) can be mass-assigned.

## Current state

### Issue 1: Tools route lacks permission middleware

`routes/web.php:45-159` — The `/tools` route sits inside the `auth` + `unit_context` middleware groups but has no `role_or_permission` middleware:

```
Line 45: Route::middleware('auth')->group(function () {
Line 48:     Route::middleware('unit_context')->group(function () {
...
Line 157:         Route::livewire('/tools', 'tools.tools')->name('tools');
Line 158:     }); // unit_context
Line 159: });
```

Compare with properly gated routes like:
```
Line 58: Route::middleware('role_or_permission:manage_users')->group(function () {
```

The Tools component (`resources/views/livewire/tools/tools.blade.php:19-42`) has no `$this->authorize()` call in its `mount()` method. The component performs destructive operations: archiving tickets (line 49-52), deleting activity logs (line 63-65), and deleting notifications (line 76-78).

### Issue 2: Boundary model has no mass-assignment protection

`app/Models/Boundary.php:12`:
```php
protected $guarded = [];
```

The `boundaries` table columns (from migration `2025_03_20_000009_create_boundaries_table.php`): `id` (auto-increment), `boundary` (MULTIPOLYGON), `timestamps`. With `$guarded = []`, all columns including `id` are mass-assignable.

## Commands you will need
| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 5f9c24e..HEAD -- routes/web.php app/Models/Boundary.php` | No changes |
| Verify tools route middleware | `php artisan route:list --path=tools` | Shows `role_or_permission` middleware |
| Verify Boundary $fillable | `php artisan tinker --execute="echo implode(', ', (new App\Models\Boundary)->getFillable());"` | Shows `['boundary']` |
| Run tests | `composer test` | All tests pass |

## Scope
**In scope**: `routes/web.php` (tools route middleware), `resources/views/livewire/tools/tools.blade.php` (authorize call in mount), `app/Models/Boundary.php` ($guarded → $fillable).
**Out of scope**: Other models' mass-assignment protection; the Tools component's data cleanup logic (it already scopes via `AccessService`); adding role_or_permission to other unprotected routes.

## Git workflow
- Branch: `advisor/002-tools-permission-boundary-guarded`

## Steps

### Step 1: Create the feature branch
```bash
git checkout celin
git checkout -b advisor/002-tools-permission-boundary-guarded
```
**Verify**: `git branch --show` → `advisor/002-tools-permission-boundary-guarded`

### Step 2: Add role_or_permission middleware to the /tools route

In `routes/web.php`, wrap the tools route with `role_or_permission:manage_users` middleware. The tools component performs admin-level cleanup operations, so `manage_users` is the appropriate permission (it's the broadest admin permission already used in the project).

```php
# BEFORE (line 157):
        Route::livewire('/tools', 'tools.tools')->name('tools');

# AFTER:
        Route::middleware('role_or_permission:manage_users')->group(function () {
            Route::livewire('/tools', 'tools.tools')->name('tools');
        });
```

**Verify**: `php artisan route:list --path=tools` shows `role_or_permission:manage_users` in the middleware column.

### Step 3: Add $this->authorize() to Tools component mount()

In `resources/views/livewire/tools/tools.blade.php`, add an authorization check at the start of the `mount()` method as a defense-in-depth measure (route middleware alone could be bypassed if the component is rendered in another context):

```php
# BEFORE (line 19-20):
    public function mount(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

# AFTER:
    public function mount(): void
    {
        $this->authorize('manage_users');
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
```

This requires adding `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` if not already present. Check the existing imports — the component currently has:
```php
use App\Models\{Ticket, ActivityLog, Notification};
use App\Models\User;
use App\Services\AccessService;
use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Cache;
```

Add the `AuthorizesRequests` trait usage:
```php
return new class extends Component {
    use Toast;
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
```

**Verify**: `grep -n "authorize\|AuthorizesRequests" resources/views/livewire/tools/tools.blade.php` shows both the trait and the `$this->authorize()` call.

### Step 4: Fix Boundary model mass-assignment protection

Replace `$guarded = []` with `$fillable` in `app/Models/Boundary.php`. The only mass-assignable column (besides auto-increment `id` and timestamps) is `boundary`:

```php
# BEFORE (line 12):
    protected $guarded = [];

# AFTER:
    protected $fillable = ['boundary'];
```

**Verify**: `php artisan tinker --execute="echo json_encode((new App\Models\Boundary)->getFillable());"` outputs `["boundary"]`.

### Step 5: Verify Boundary usage patterns
Check that no code in the project relies on mass-assigning `id` or other non-fillable fields into Boundary:
```bash
grep -rn "Boundary::create\|new Boundary" app/ --include="*.php"
```
**Verify**: No results found (confirmed earlier — Boundary is not mass-created anywhere in application code).

### Step 6: Format and lint
```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```
**Verify**: No errors.

### Step 7: Run tests
```bash
composer test
```
**Verify**: All tests pass.

### Step 8: Commit
```bash
git add routes/web.php resources/views/livewire/tools/tools.blade.php app/Models/Boundary.php
git commit -m "fix(security): gate Tools route behind manage_users + fix Boundary \$guarded

- Add role_or_permission:manage_users middleware to /tools route
- Add \$this->authorize('manage_users') in Tools mount() as defense-in-depth
- Replace Boundary \$guarded = [] with \$fillable = ['boundary'] for
  mass-assignment protection"
```

## Test plan
1. Log in as a user WITHOUT `manage_users` permission → navigate to `/tools` → should get 403
2. Log in as a user WITH `manage_users` permission → navigate to `/tools` → should load normally
3. Attempt to mass-assign `id` to Boundary via tinker → should fail silently (attribute not fillable)
4. Verify existing unit creation/editing still works (Boundary is not mass-created in normal flow)

## Done criteria
- [ ] `/tools` route shows `role_or_permission:manage_users` in `php artisan route:list`
- [ ] Tools component `mount()` contains `$this->authorize('manage_users')`
- [ ] Boundary model has `$fillable = ['boundary']` instead of `$guarded = []`
- [ ] All tests pass

## STOP conditions
- If adding middleware to the route breaks the route registration (artisan route:list errors)
- If the `AuthorizesRequests` trait conflicts with the `Toast` trait
- If any existing Boundary usage breaks due to the $fillable restriction
- If tests fail after changes

## Maintenance notes
- The dual protection (route middleware + component authorize) is intentional defense-in-depth — Livewire components can be embedded or routed differently in the future
- If Boundary ever needs mass-assignment for additional fields, add them to `$fillable` explicitly rather than reverting to `$guarded = []`
- Consider auditing other models for `$guarded = []` in a future review
