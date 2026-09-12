# Plan 017: Standardize permission middleware across web and API routes

## Metadata
- **Category:** tech-debt
- **Effort:** S
- **Risk:** MED
- **Priority:** P2
- **Depends on:** none

---

## Problem

`routes/web.php` consistently uses `role_or_permission:` middleware for all protected routes, which checks both role names AND direct permission names via Spatie's `role_or_permission` guard. However, `routes/api.php` mixes `role_or_permission:` with bare `permission:` for several route groups. Users who access permissions through roles (not direct permission assignments) may get 403 errors on API routes that would work fine on the web side.

### Inconsistencies found in `routes/api.php`

| Line(s) | Route | Current middleware | Should be |
|---------|-------|--------------------|-----------|
| 86 | `POST /hardware/{id}/audits/{audit}/rollback` | `permission:manage_hardware` | `role_or_permission:manage_hardware` |
| 88 | `POST /audits/{audit}/restore-record` | `permission:manage_hardware` | `role_or_permission:manage_hardware` |
| 95 | `POST /tickets` | `permission:create_ticket` | `role_or_permission:create_ticket` |
| 99 | `PUT /tickets/{ticket}` | `permission:manage_unit_tickets` | `role_or_permission:manage_unit_tickets` |
| 101 | `DELETE /tickets/{ticket}` | `permission:manage_unit_tickets` | `role_or_permission:manage_unit_tickets` |
| 103 | `POST /tickets/{ticket}/assign` | `permission:manage_unit_tickets` | `role_or_permission:manage_unit_tickets` |
| 105 | `POST /tickets/{ticket}/accept` | `permission:create_ticket` | `role_or_permission:create_ticket` |
| 107 | `POST /tickets/{ticket}/complete` | `permission:manage_unit_tickets` | `role_or_permission:manage_unit_tickets` |
| 113 | `POST /tickets/{ticket}/comments` | `permission:create_ticket` | `role_or_permission:create_ticket` |
| 117 | `PUT /tickets/{ticket}/comments/{comment}` | `permission:manage_unit_tickets` | `role_or_permission:manage_unit_tickets` |
| 119 | `DELETE /tickets/{ticket}/comments/{comment}` | `permission:manage_unit_tickets` | `role_or_permission:manage_unit_tickets` |
| 121 | `POST /tickets/{ticket}/comments/{comment}/react` | `permission:create_ticket` | `role_or_permission:create_ticket` |
| 123 | `DELETE /tickets/{ticket}/comments/{comment}/react` | `permission:create_ticket` | `role_or_permission:create_ticket` |

**Note:** `role_or_permission:` already subsumes `permission:` — if a user has the permission directly, both checks pass. The difference is that `role_or_permission:` also passes for users who hold a role that grants the permission. Switching to `role_or_permission:` is strictly additive and backward-compatible.

---

## Implementation Steps

### Step 1: Replace `permission:` with `role_or_permission:` in ticket routes
**File:** `routes/api.php`

Change lines 95, 99, 101, 103, 105, 107:
```php
// Before
->middleware('permission:create_ticket');
->middleware('permission:manage_unit_tickets');

// After
->middleware('role_or_permission:create_ticket');
->middleware('role_or_permission:manage_unit_tickets');
```

### Step 2: Replace `permission:` with `role_or_permission:` in ticket comment routes
**File:** `routes/api.php`

Change lines 113, 117, 119, 121, 123:
```php
// Before
->middleware('permission:create_ticket');
->middleware('permission:manage_unit_tickets');

// After
->middleware('role_or_permission:create_ticket');
->middleware('role_or_permission:manage_unit_tickets');
```

### Step 3: Replace `permission:` with `role_or_permission:` in hardware audit routes
**File:** `routes/api.php`

Change lines 86 and 88:
```php
// Before
->middleware('permission:manage_hardware');

// After
->middleware('role_or_permission:manage_hardware');
```

### Step 4: Verify
```bash
# Should return 0 results — no bare permission: remains
grep -n "->middleware('permission:" routes/api.php

# Should show role_or_permission for all protected routes
grep -n "role_or_permission:" routes/api.php
```

---

## Verification Criteria

1. `grep -n "->middleware('permission:" routes/api.php` returns **zero** matches
2. Every previously `permission:`-guarded route now uses `role_or_permission:`
3. All existing API tests still pass (no behavioral change for users who have direct permissions)

## Risks & Mitigations

- **Risk:** A user who previously lacked a role but had the permission directly might now also gain access through a role they hold unexpectedly.
- **Mitigation:** This is the *desired* behavior — it aligns API access with the already-established web pattern. No permission is removed, only broadened.

## Files to Modify
- `routes/api.php`
