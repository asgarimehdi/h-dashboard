# Plan 016: Add authorize() defense-in-depth to Livewire components

| Field      | Value                              |
|------------|------------------------------------|
| Category   | security                           |
| Effort     | M                                  |
| Risk       | MED                                |
| Priority   | P2                                 |
| Depends on | none                               |
| Base SHA   | 5f9c24e                            |
| Branch     | celin                              |

---

## 1. Problem

Routes are protected by `role_or_permission` middleware, but Livewire
Volt components themselves don't call `$this->authorize()`. If a
component is rendered via a direct Livewire request, API call, or any
code path that bypasses route middleware, it has zero permission checks.
Adding `authorize()` in `mount()` provides defense-in-depth.

## 2. What exists today

### 2a. Components that already have authorize()

| Component | Permission | File |
|-----------|-----------|------|
| `permissions/index` | `manage_roles` | `resources/views/livewire/permissions/index.blade.php:24` |
| `roles/index` | `manage_roles` | `resources/views/livewire/roles/index.blade.php:31` |
| `users/index` | `manage_users` | `resources/views/livewire/users/index.blade.php:61` |

> **Note**: `units/index` was listed as having authorize() in the findings,
> but search shows it does NOT have `$this->authorize()` — it's missing
> from the Blade file. Confirm during implementation.

### 2b. Components that NEED authorize() (priority order)

Mapping from route middleware to required permission:

| Route | Middleware Permission | Livewire Component | File |
|-------|----------------------|-------------------|------|
| `/tools` | `manage_users` (from parent group) | `tools/tools` | `resources/views/livewire/tools/tools.blade.php` |
| `/reports/*` | none (no middleware) | `reports/advanced`, `reports/units`, `reports/todos`, `reports/persons`, `reports/map-no-boundary` | Various |
| `/hardware` | `manage_hardware` (route group) | `hardware/index`, `hardware/import-hardware` | Various |
| `/activity-log` | `manage_users` (route middleware) | `activity-log/index` | `resources/views/livewire/activity-log/index.blade.php` |
| `/kargozini/*` | `kargozini` (route group) | `kargozini/person`, `kargozini/estekhdam`, etc. | Various |
| `/monitoring` | `view_all_tickets` | `tickets/monitoring` | `resources/views/livewire/tickets/⚡monitoring.blade.php` |
| `/tickets/new` | `create_ticket` | `tickets/create` | `resources/views/livewire/tickets/⚡create.blade.php` |
| `/tickets/inbox` | `view_assigned_tickets` | `tickets/inbox` | `resources/views/livewire/tickets/⚡inbox.blade.php` |
| `/hr-dashboard` | `view_hr_dashboard` | `hr/dashboard` | `resources/views/livewire/hr/dashboard.blade.php` |
| `/hr/org-chart` | `view_hr_dashboard` | `hr/org-chart` | `resources/views/livewire/hr/org-chart.blade.php` |
| `/maps/*` | `map` | Various maps components | Various |
| `/todo` | `calendar` | `todo/todo` | `resources/views/livewire/todo/todo.blade.php` |
| `/units` | `organization` | `units/index`, `units/chart`, `units/map` | Various |

### 2c. Available permissions (from PermissionSeeder)

```
kargozini, map, organization, op-cache, bw,
view_all_tickets, create_ticket, manage_unit_tickets,
view_assigned_tickets, calendar, manage_users, manage_roles,
manage_hardware, view_hr_dashboard, manage_personnel,
manage_org_chart, test-permission
```

## 3. Implementation steps

### Step 1 — Add authorize() to tools component

**File**: `resources/views/livewire/tools/tools.blade.php`

In the `mount()` method, add:
```php
$this->authorize('manage_users');
```

The `/tools` route is inside the `auth` + `unit_context` middleware group.
It does NOT have its own `role_or_permission` middleware, but the tools
page provides admin functions (archiving tickets, cleaning logs). Use
`manage_users` as the gate since tools are admin-only.

### Step 2 — Add authorize() to activity-log component

**File**: `resources/views/livewire/activity-log/index.blade.php`

In the `mount()` method, add:
```php
$this->authorize('manage_users');
```

The route middleware is `role_or_permission:manage_users`.

### Step 3 — Add authorize() to tickets components

| File | mount() addition |
|------|-----------------|
| `resources/views/livewire/tickets/⚡monitoring.blade.php` | `$this->authorize('view_all_tickets');` |
| `resources/views/livewire/tickets/⚡create.blade.php` | `$this->authorize('create_ticket');` |
| `resources/views/livewire/tickets/⚡inbox.blade.php` | `$this->authorize('view_assigned_tickets');` |

### Step 4 — Add authorize() to hardware components

| File | mount() addition |
|------|-----------------|
| `resources/views/livewire/hardware/index.blade.php` | `$this->authorize('manage_hardware');` |
| `resources/views/livewire/hardware/import-hardware/import-hardware.blade.php` | `$this->authorize('manage_hardware');` |

> **Note**: Hardware routes already have `role_or_permission:manage_hardware`
> middleware. This is pure defense-in-depth.

### Step 5 — Add authorize() to HR components

| File | mount() addition |
|------|-----------------|
| `resources/views/livewire/hr/dashboard.blade.php` | `$this->authorize('view_hr_dashboard');` |
| `resources/views/livewire/hr/org-chart.blade.php` | `$this->authorize('view_hr_dashboard');` |

### Step 6 — Add authorize() to kargozini components

| File | mount() addition |
|------|-----------------|
| `resources/views/livewire/kargozini/.../*.blade.php` (all kargozini components) | `$this->authorize('kargozini');` |

Check each kargozini component for an existing `mount()` method. If one
doesn't exist, add it:

```php
public function mount(): void
{
    $this->authorize('kargozini');
}
```

### Step 7 — Add authorize() to units components

| File | mount() addition |
|------|-----------------|
| `resources/views/livewire/units/index.blade.php` | `$this->authorize('organization');` |
| `resources/views/livewire/units/chart.blade.php` | `$this->authorize('organization');` |
| `resources/views/livewire/units/map.blade.php` | `$this->authorize('organization');` |

### Step 8 — Add authorize() to remaining priority components

| File | Permission |
|------|-----------|
| `resources/views/livewire/todo/todo.blade.php` | `$this->authorize('calendar');` |
| `resources/views/livewire/maps/*.blade.php` | `$this->authorize('map');` |
| `resources/views/livewire/it/wireless.blade.php` | `$this->authorize('map');` |
| `resources/views/livewire/it/networks.blade.php` | `$this->authorize('map');` |
| `resources/views/livewire/map/map-dashboard.blade.php` | `$this->authorize('map');` |

### Step 9 — Skip these components (no specific permission)

These are accessible to any authenticated user with a unit context:
- `select-context` — unit selection, no specific permission
- `dashboard` — general dashboard
- `settings` — user settings
- `profile` — user profile
- `search` — global search
- `notifications/bell` — notification bell
- `auth/login`, `auth/register` — auth pages

## 4. Files changed

| File | Action |
|------|--------|
| `resources/views/livewire/tools/tools.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/activity-log/index.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/tickets/⚡monitoring.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/tickets/⚡create.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/tickets/⚡inbox.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/hardware/index.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/hardware/import-hardware/import-hardware.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/hr/dashboard.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/hr/org-chart.blade.php` | **Modify** — add authorize() |
| All kargozini components | **Modify** — add authorize() |
| `resources/views/livewire/units/index.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/units/chart.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/units/map.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/todo/todo.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/maps/*.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/it/wireless.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/it/networks.blade.php` | **Modify** — add authorize() |
| `resources/views/livewire/map/map-dashboard.blade.php` | **Modify** — add authorize() |

## 5. Verification

1. `composer test` — all tests pass.
2. Spot test: Act as a user WITHOUT `manage_hardware` permission → `Livewire::test('hardware.index')` → should throw `AuthorizationException` (403).
3. Spot test: Act as a user WITH `manage_hardware` → should render normally.
4. `grep -rn "\\$this->authorize(" resources/views/livewire/` — count matches and verify alignment with route middleware.

## 6. Risks and mitigations

| Risk | Mitigation |
|------|------------|
| authorize() throws 403 for legitimate users | Only add permissions that match the route middleware exactly. Audit each mapping in step 2b. |
| Volt components don't have mount() | Volt anonymous classes can have `mount()` — add it if missing. |
| Tests fail because test users lack the permission | Update test setup to grant the required permission to test users. |
| Duplicate authorization (middleware + authorize) | Intentional — defense-in-depth. The overhead is negligible (one permission check). |
