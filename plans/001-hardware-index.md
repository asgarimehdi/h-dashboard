# Plan 001: Add comprehensive Livewire coverage for hardware.index

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat af29080..HEAD -- tests/`
> If any test file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: L
- **Risk**: LOW
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `af29080`, 2026-09-03
- **Issue**: https://github.com/asgarimehdi/h-dashboard/issues/560

## Why this matters

The `hardware.index` Livewire component currently has **no dedicated Livewire test
coverage**. Adding Pest tests via `Livewire::test()` ensures that auth gates,
scope filtering, interactions, and edge cases are verified — preventing
regressions and documenting expected behavior for future contributors.

## Current state

- **Component**: `hardware.index` — single-file anonymous Livewire 4 class under
  `resources/views/livewire/` (see AGENTS.md: no `app/Livewire/*.php` files).
- **Route**: `/hardware` — protected by `manage_hardware` (see `routes/web.php`).
- **Conventions**: Pest tests under `tests/Feature/`, run via `composer test`.
  Model after `tests/Feature/ActivityLogPageLivewireTest.php` and
  `tests/Feature/HrLivewireTest.php`.
- **Key services**: `AccessService::accessibleUnitIds()` for org-scope
  filtering (recursive CTE, cached, version-invalidated).
- **Auth**: session-based for Livewire (`actingAs`); Sanctum Bearer tokens NOT
  accepted for Livewire pages.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|-------------------|
| Start DB | `docker compose -f docker-compose-pgsql-.yml up -d` | PostGIS healthy on :5432 |
| Config clear | `php artisan config:clear && php artisan route:clear` | No stale caches |
| Run focused | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareIndexLivewireTest.php` | All pass |
| Full suite | `composer test` | All pass (928 baseline) |
| Format | `vendor/bin/pint --dirty --format agent` | Clean |

## Scope

**In scope** (the ONLY file you may create/modify):
- `tests/Feature/HardwareIndexLivewireTest.php` (CREATE)

**Out of scope** (do NOT touch, even though they look related):
- Any production source file (`app/`, `resources/views/`, `routes/`)
- Any existing test file
- Config, migrations, seeders, `composer.json`, `package.json`

## Steps

### Step 1: Verify current state on disk

Open the component file and the route definition yourself; confirm paths and
middleware match "Current state" above. Record any drift as a STOP.

```bash
ls resources/views/livewire/hardware/index.blade.php
grep -n "hardware.index" routes/web.php
grep -rn "Livewire::test(\"hardware.index\")" tests/ | head -5
```

**Verify**: component file exists; route line matches; no existing dedicated
test file for this component.

### Step 2: Write the test file skeleton with setUp helper

Create `tests/Feature/HardwareIndexLivewireTest.php` following the exemplar pattern below
(adapted from `tests/Feature/ActivityLogPageLivewireTest.php`):

- `use RefreshDatabase;`
- `setUp()`: seed `PermissionSeeder::class`, then insert lookup rows with
  explicit ids into `tahsils`, `estekhdams`, `semats`, `radifs`
  (id=1, name='Test' each)
- helper `createUserWithUnit(string $perm)`: create `Unit`, create `Person`
  (n_code = 10-digit unique string, f_name/l_name, t_id/e_id/s_id/r_id = 1,
  u_id = unit id), create `User` (n_code + hashed password),
  `$user->givePermissionTo($perm)`, attach unit via `user_units` pivot with
  `['role' => 'staff', 'is_primary' => true]`
- where the route needs it: `Session::put('current_unit_id', $unit->id)`

NOTE: When seeding rows with explicit IDs, resync the Postgres sequence
afterwards (`SELECT setval(...)`) or later inserts hit duplicate keys.

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/HardwareIndexLivewireTest.php`
→ file loads (may have 0 tests yet, but no fatal).

### Step 3: Add smoke tests

Write test methods for:
- guest request → 302 to `/login`
- authenticated user WITHOUT the required permission → 403
- authenticated user WITH permission (+ `current_unit_id` session where
  required) → 200 and component-specific content visible (`assertSee`)

**Verify**: focused test run → all smoke tests pass.

### Step 4: Add interaction tests

- S1: Smoke: guest 302, no-perm 403, renders with pagination defaults (perPage/sortBy id desc)
- S2: Search with PersianNormalizer + debounce
- S3: Each of the 11 filters applies; toggleFilter toggles off; clearFilters resets; hasActiveFilters true/false
- S4: Column visibility defaults + toggleColPanel
- S5: Person lookup: updatedPersonSearch (<2 clears, >=2 returns max 10 scoped), updatedNCode validation
- S6: CRUD: startCreate/createHardware (scoped n_code required), editHardware/updateHardware, delete (soft)
- S7: Bulk: bulkMark/bulkDelete empty->error, mixed scope->only scoped, clearSelection
- S8: exportExcel dispatches download
- S9: loadHistory paginated.

Use `Livewire::test('hardware.index')` with `actingAs($user)`; assert state with
`assertSet`, events with `assertDispatched`, validation with
`assertHasErrors`, DB effects with `assertDatabaseHas`/`assertDatabaseMissing`.

**Verify**: focused test run → all interaction tests pass.

### Step 5: Add edge-case tests

- E1: Empty accessibleUnitIds -> 0 rows
- E2: perPage extremes
- E3: Invalid sort column ignored
- E4: Out-of-scope n_code rejected with toast
- E5: History pagination boundaries.

**Verify**: focused test run → all edge tests pass.

### Step 6: Format and run the full gate

```bash
vendor/bin/pint --dirty --format agent
composer test
```

**Verify**: both commands exit 0; no regressions in the full suite.

## Test plan

New tests to write in `tests/Feature/HardwareIndexLivewireTest.php` (model structure on
`tests/Feature/ActivityLogPageLivewireTest.php`):

- `test_guest_redirects_to_login`
- `test_unauthorized_user_gets_403`
- `test_authorized_user_renders`
- `test_search_filters_by_name`
- `test_toggle_filter_sets_and_clears`
- `test_clear_filters_resets_all`
- `test_column_visibility_defaults`
- `test_person_search_scoped`
- `test_create_hardware_scoped`
- `test_update_hardware_scoped`
- `test_delete_soft_deletes`
- `test_bulk_operations_scoped`
- `test_export_dispatches`
- `test_load_history_paginated`

## Done criteria

Machine-checkable. ALL must hold:

- [ ] Focused run `XDEBUG_MODE=off php artisan test tests/Feature/HardwareIndexLivewireTest.php` exits 0
- [ ] Every method in "Test plan" exists and passes
- [ ] `vendor/bin/pint --dirty` reports no changes
- [ ] `git status -- tests/` shows ONLY the new test file
- [ ] `plans/README.md` status row updated to DONE

## STOP conditions

Stop and report back (do not improvise) if:

- The component file does not exist at the expected path (codebase drifted).
- A focused test run fails due to environment (PostGIS down, config cache,
  Redis NOAUTH) — fix env first via AGENTS.md checklist, do not relax asserts.
- The component needs a permission or middleware not listed in "Current
  state" — report, do not guess.
- A step's verification fails twice after a reasonable fix attempt.
- The work appears to require touching an out-of-scope file.

## Maintenance notes

- For the owner of this code afterwards: if filters/actions are added to the
  component, add the matching `Livewire::test` method in the same file.
- A reviewer should scrutinize: auth/permission branches, scope assertions
  (`accessibleUnitIds`), and that no production file was modified.
