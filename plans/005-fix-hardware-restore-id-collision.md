# Plan 005: Fix hardware restore ID collision breaking audit trail

- **Status:** Not started
- **Category:** bug
- **Effort:** S
- **Risk:** HIGH
- **Priority:** P1
- **Depends on:** none
- **Base SHA:** 5f9c24e

## Why this matters

When a deleted hardware record is restored, the audit trail (all `HardwareAudit` rows) still references the original `hardware_id`. The current code attempts to set the PK via `$restoreData['id'] = $audit->hardware_id` then calls `Hardware::create($restoreData)`, but Eloquent ignores `id` in mass assignment because it is not in `$fillable` (see `app/Models/Hardware.php:25-45`). The restored hardware gets a **new auto-incremented ID**, breaking every audit row that references the old one. The Livewire path in `HardwareIndexHelpers` doesn't even attempt to set the ID at all.

This means restored hardware cannot be correlated with its history — a data-integrity bug with audit-trail implications.

## Current state (with file:line excerpts)

### API path — `app/Http/Controllers/Api/HardwareAuditController.php:200-202`

```php
$restoreData['id'] = $audit->hardware_id;

$hardware = Hardware::create($restoreData);
```

`id` is not in `Hardware::$fillable` (lines 25-45 of `app/Models/Hardware.php`), so Eloquent silently drops it during mass assignment. The hardware is created with a new auto-incremented ID. The Postgres sequence is then advanced (lines 208-213), which is correct but moot since the wrong ID was used.

### Livewire path — `app/Traits/HardwareIndexHelpers.php:300-301`

```php
$restoreData['n_code'] = $nCode;
$restoredHardware = Hardware::create($restoreData);
```

No ID is set at all. The restored hardware always gets a new auto-incremented ID.

### Hardware model — `app/Models/Hardware.php:21`

```php
public static bool $suppressAudit = false;
```

`id` is intentionally **not** in `$fillable` to prevent accidental PK mass-assignment. This is correct — the fix should not add `id` to `$fillable`.

## Scope

### In scope
- `app/Http/Controllers/Api/HardwareAuditController.php` — `restoreRecord()` method (~line 200)
- `app/Traits/HardwareIndexHelpers.php` — Livewire restore method (~line 300)

### Out of scope
- `app/Models/Hardware.php` — do NOT add `id` to `$fillable`
- Postgres sequence advancement logic (lines 204-213) — already correct, keep as-is
- Audit observer logic — already correct

## Commands

```bash
# Verification commands (read-only)
cd /home/runner/h-dashboard
php artisan test --filter HardwareAudit
git diff --stat
```

## Steps

### Step 1: Fix API restore path

**File:** `app/Http/Controllers/Api/HardwareAuditController.php`

Replace lines 200-202:

```php
// BEFORE (broken):
$restoreData['id'] = $audit->hardware_id;

$hardware = Hardware::create($restoreData);

// AFTER (correct):
$hardware = new Hardware($restoreData);
$hardware->forceFill(['id' => $audit->hardware_id]);
$hardware->save();
```

`forceFill()` bypasses the `$fillable` guard and sets the PK. `save()` triggers Eloquent events (created + audit observer) normally.

**Verify:** Read the changed lines. Confirm `forceFill` is used and `save()` replaces `create()`.

### Step 2: Fix Livewire restore path

**File:** `app/Traits/HardwareIndexHelpers.php`

Replace line 301:

```php
// BEFORE (broken):
$restoredHardware = Hardware::create($restoreData);

// AFTER (correct):
$restoredHardware = new Hardware($restoreData);
$restoredHardware->forceFill(['id' => $audit->hardware_id]);
$restoredHardware->save();
```

**Verify:** Read the changed lines. Confirm `forceFill` with `$audit->hardware_id` is present.

### Step 3: Verify no other references

```bash
grep -rn "Hardware::create" app/ | grep -i restor
```

Confirm only the two fixed paths remain.

### Step 4: Run tests

```bash
php artisan test --filter HardwareAudit
```

Expected: All tests pass. If no existing hardware-restore tests exist, note this as a gap.

### Step 5: Manual verification (if test infra allows)

Create hardware → delete → restore via API → check restored ID matches original → check `HardwareAudit::where('hardware_id', originalId)` returns both created and rollback rows.

## Test plan

- Existing `HardwareAudit` tests should continue to pass.
- **Gap:** There may be no test for the restore-ID scenario. If so, note it but do NOT write new tests in this plan (per scope).

## Done criteria

- [ ] `Hardware::create($restoreData)` with `id` set no longer appears in either restore path
- [ ] Both paths use `forceFill(['id' => ...])` + `save()`
- [ ] `php artisan test --filter HardwareAudit` passes
- [ ] `grep -rn "suppressAudit" app/` still returns only `Hardware.php:21` and `HardwareAuditObserver.php:19`

## STOP conditions

- If `forceFill` causes Eloquent event issues (observer fires with wrong attributes), STOP and investigate whether `forceFill` needs to happen before or after the model hydration.
- If the Postgres sequence advancement (lines 208-213) fails after the fix, STOP — the sequence may need a different reset strategy.

## Maintenance notes

- `forceFill` is a recognized Eloquent pattern for setting guarded attributes. The alternative `new Model() + setId()` is more verbose; `forceFill` is preferred for this one-shot use.
- Future: consider adding a `restoreOrCreate()` method to the Hardware model to encapsulate this pattern if it appears elsewhere.
- The Postgres sequence reset logic on lines 204-213 remains important — if someone bypasses this plan and creates hardware with a forced ID, the sequence must be advanced to avoid duplicate key errors on subsequent creates.
