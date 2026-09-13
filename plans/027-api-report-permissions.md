# 027 — Add Permission Middleware to API Report Routes

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | HIGH (Security — authorization bypass) |
| **Effort** | S |
| **Risk** | Low — additive middleware only |
| **Base commit** | `a106d38` |
| **Files** | `routes/api.php` |

## Problem

`routes/api.php:128-130` defines three report endpoints with **no permission middleware**:

```php
Route::get('/reports/units', [ReportController::class, 'units']);
Route::get('/reports/todos', [ReportController::class, 'todos']);
Route::get('/reports/tickets', [ReportController::class, 'tickets']);
```

Any authenticated user (with a valid Sanctum token, no role/permission check) can access these report endpoints. This is inconsistent with the rest of the API where every route group has `role_or_permission` middleware. The reports contain organizational statistics that should only be visible to users with an appropriate permission.

### Evidence (file:line)

- **`routes/api.php:128-130`** — Report routes inside `auth:sanctum` group but with **no** `role_or_permission` middleware.
- Compare with neighboring routes:
  - Line 58: `->middleware('role_or_permission:organization')` for unit writes
  - Line 73: `->middleware('role_or_permission:manage_hardware')` for hardware writes
  - Line 92-93: `->middleware('role_or_permission:view_assigned_tickets|view_all_tickets')` for ticket views
  - Line 145: `->middleware('role_or_permission:calendar')` for todos
  - Line 155: `->middleware('role_or_permission:view_hr_dashboard')` for HR
  - Line 174: `->middleware('role_or_permission:map')` for GIS

### Current code (lines 127-131)

```php
// Report API routes
Route::get('/reports/units', [ReportController::class, 'units']);
Route::get('/reports/todos', [ReportController::class, 'todos']);
Route::get('/reports/tickets', [ReportController::class, 'tickets']);
```

## Decision

Wrap the three report routes in a `role_or_permission` middleware group. Based on the existing permission schema, the appropriate permission is `report` (or a combined `view_assigned_tickets|view_all_tickets` for the tickets report). The simplest and most consistent approach is a shared `role_or_permission:report` middleware, assuming the permission exists or creating it.

**Permission choice:** Use `role_or_permission:report` if the permission already exists in the seeder. If not, use `role_or_permission:manage_hardware|manage_unit_tickets|calendar` to match the permissions used by the individual data sources these reports aggregate. The simplest approach: add a new `report` permission and assign it to admin/operator roles.

## Commands

```bash
cd /home/runner/h-dashboard
# Check if 'report' permission exists
php artisan tinker --execute 'use Spatie\Permission\Models\Permission; dd(Permission::whereName("report")->first());'

# If not, create it via seeder or command
php artisan permission:create-report-role
```

## Steps

### Phase 1 — Verify/create report permission

Check if `report` permission exists:
```bash
php artisan tinker --execute 'use Spatie\Permission\Models\Permission; echo Permission::whereName("report")->exists() ? "EXISTS" : "MISSING";'
```

If missing, add it to `Database\Seeders\PermissionSeeder` and run:
```bash
php artisan db:seed --class=PermissionSeeder
```

### Phase 2 — Add middleware to report routes

In `routes/api.php`, replace lines 127-131:

```php
// Report API routes
Route::middleware('role_or_permission:report')->group(function () {
    Route::get('/reports/units', [ReportController::class, 'units']);
    Route::get('/reports/todos', [ReportController::class, 'todos']);
    Route::get('/reports/tickets', [ReportController::class, 'tickets']);
});
```

### Phase 3 — Run tests

```bash
XDEBUG_MODE=off php artisan test tests/Feature/ReportsApiTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/ReportsPersonsLivewireTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/ReportsAdvancedLivewireTest.php -v
```

### Phase 4 — Add permission test

Add a test that verifies an unauthenticated user gets 401 and a user without the `report` permission gets 403:

```php
// In tests/Feature/ReportsApiTest.php or a new file
test('report endpoints require report permission', function () {
    $user = User::create([...]);
    // No role assigned
    $this->actingAs($user, 'sanctum');
    $this->getJson('/api/reports/units')->assertForbidden();
});
```

## Test plan

- `ReportsApiTest` — existing tests for report endpoints pass with authorized user.
- New test: verify 403 for user without `report` permission.
- Verify `role_or_permission:report` is enforced on all three endpoints.

## Done criteria

- [ ] All three report routes have `role_or_permission` middleware
- [ ] `report` permission exists in the permissions table
- [ ] Tests pass for authorized access
- [ ] Tests verify 403 for unauthorized access
- [ ] `vendor/bin/pint --dirty` clean

## STOP conditions

- If the `report` permission doesn't exist and adding it to the seeder breaks the seeder's idempotency, STOP and check for existing permission IDs.
- If the Flutter app calls these endpoints without the `report` permission assigned to its API user, STOP — the mobile app needs the permission added first.
