# 026 — Fix Hardware Audit Restore Loses Original ID

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | HIGH (Correctness) |
| **Effort** | S |
| **Risk** | Low — single `$fillable` addition |
| **Base commit** | `a106d38` |
| **Files** | `app/Models/Hardware.php` |

## Problem

`HardwareAuditController::restoreRecord()` (line 200) sets `$restoreData['id'] = $audit->hardware_id` before calling `Hardware::create($restoreData)`. However, `id` is **not** in `Hardware::$fillable` (lines 25–45), so Eloquent silently drops it. The restored record gets a new auto-incremented ID instead of the original one, breaking audit trail linkage and confusing users.

The controller already advances the Postgres sequence (lines 208–213) assuming the original ID was restored — but since `create()` drops the `id`, the sequence advancement is wasted, and the actual ID is unpredictable.

### Evidence (file:line)

- **`app/Models/Hardware.php:25-45`** — `$fillable` array: `n_code, pc_name, type, os, ip_valid, ip_local, mac, net_type, switch, port, shutdown, vlan, motherboard, cpu, ram, hdd, comments, mark, clean_at` — **`id` is missing**.
- **`app/Http/Controllers/Api/HardwareAuditController.php:200-202`**:
```php
$restoreData['id'] = $audit->hardware_id;
$hardware = Hardware::create($restoreData);
```
- **`HardwareAuditController.php:208-213`** — sequence advancement code that assumes `id` was preserved.

### Current code (Hardware.php:25-45)

```php
protected $fillable = [
    'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
    'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
    'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
];
```

## Decision

Add `'id'` to `Hardware::$fillable`. This is the minimal fix. The sequence advancement code (lines 208–213) already handles the case correctly — it calls `setval` to advance past the restored `id`, which is exactly the right behavior when an explicit ID is inserted.

Adding `id` to `$fillable` is safe here because:
1. Hardware CRUD routes all go through controller validation — never mass-assigned from user input.
2. The `id` is only ever set in `restoreRecord()` from `$audit->hardware_id` (a trusted DB value).
3. Standard Laravel practice: models with explicit-ID restore patterns add `id` to `$fillable`.

## Commands

```bash
cd /home/runner/h-dashboard
XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditObserverRequestTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditModalLivewireTest.php -v
vendor/bin/pint --dirty --format agent
```

## Steps

### Phase 1 — Add `id` to `$fillable`

In `app/Models/Hardware.php`, add `'id'` to the `$fillable` array:

```php
protected $fillable = [
    'id',          // needed for HardwareAuditController::restoreRecord()
    'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
    'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
    'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
];
```

### Phase 2 — Verify the sequence advancement still works

The existing code at `HardwareAuditController.php:208-213` correctly handles the restored ID. No changes needed there.

### Phase 3 — Run tests

```bash
XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditObserverRequestTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditModalLivewireTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/HardwareScopeTest.php -v
```

### Phase 4 — Add a test for restore-with-ID

Add a test in `tests/Feature/HardwareAuditObserverRequestTest.php` (or a new file) that:
1. Creates a Hardware record with known ID.
2. Deletes it (hard delete).
3. Creates an audit record with `action: 'created'` and the original fields.
4. Calls `POST /api/audits/{audit}/restore-record`.
5. Asserts the restored hardware has the **same ID** as the original.

## Test plan

- Existing audit tests pass.
- New test: restore a hardware record and assert `$hardware->id === $originalId`.
- Run `php artisan test --filter=Hardware` to confirm no regressions.

## Done criteria

- [ ] `id` is in `Hardware::$fillable`
- [ ] Restore test asserts original ID is preserved
- [ ] All Hardware-related tests pass
- [ ] `vendor/bin/pint --dirty` clean

## STOP conditions

- If adding `id` to `$fillable` causes any existing test to break (e.g., a test that mass-assigns an `id` it shouldn't), STOP and investigate the test — the fix is correct but the test may need updating.
