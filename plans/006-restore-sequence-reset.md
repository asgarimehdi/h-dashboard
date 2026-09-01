# Plan 006: Reset Postgres sequence after restoring hardware with explicit id

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareAuditController.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: 002 (scope fix in same controller; apply after or alongside)
- **Category**: bug
- **Planned at**: commit `70e35c2`, 2026-09-01

## Why this matters

`HardwareAuditController::restoreRecord` recreates a hard-deleted hardware row with its original id: `$restoreData['id'] = $audit->hardware_id; Hardware::create($restoreData)`. Postgres sequences (`hardwares_id_seq`) are not advanced by explicit-id inserts, so the next auto-increment `INSERT` without an id can collide with the restored id (`duplicate key violates unique constraint "hardwares_pkey"`). The same bug is documented in `AGENTS.md` for test seeders and fixed there with `SELECT setval(...)`. Production restore hits the same path.

## Current state

File `app/Http/Controllers/Api/HardwareAuditController.php:182-210` at `70e35c2`:

```php
if (empty($restoreData)) {
    return response()->json(['message' => 'No field data found to restore.'], 422);
}

$restoreData['id'] = $audit->hardware_id;

$hardware = Hardware::create($restoreData);

// Note: Hardware::create() already fires HardwareAuditObserver::created()
// (registered in AppServiceProvider), which writes the 'created' audit —
// so we must NOT call it again here (would duplicate the audit row).

// Log an explicit 'rollback' audit for traceability
$rollbackChanges = array_map(
    fn ($change) => ['field' => $change['field'], 'old' => 'حذف شده', 'new' => $change['new']],
    $audit->changes
);
app(HardwareAuditObserver::class)->recordRollbackAudit(
    $hardware,
    $rollbackChanges,
    $user?->id
);

return response()->json([...]);
```

Note: `DB::table('hardwares')->where('id', $audit->hardware_id)->exists()` guard at line 157 ensures we only restore when row is gone, so the explicit id is free — but sequence still stale.

Existing fix pattern in tests (per `AGENTS.md`): `SELECT setval('hardwares_id_seq', (SELECT MAX(id) FROM hardwares))` or `SELECT setval(pg_get_serial_sequence('hardwares','id'), MAX(id))`.

Repo conventions (AGENTS.md):

- **Factories & Sequences**: when seeding rows with explicit IDs, resync the Postgres sequence via `SELECT setval(...)` or inserts hit duplicate keys — see `LookupSimpleModelsTest` and AGENTS.md **Conventions > Factories**. Apply same pattern here.
- Use `DB::statement` / `DB::select` with `pg_get_serial_sequence` for portability across environments (the test DB is `h_dashboard_test` with `postgis:16-3.4`, same schema). Migration naming is `YYYY_MM_DD_######` with `--no-interaction` (AGENTS.md **Conventions > Artisan**) — this fix needs no migration.
- Keep `Hardware::create` (fires `HardwareAuditObserver::created` registered in `AppServiceProvider` + `Hardware::flushStatsCache` → `CacheInvalidationService` `hardware_stats` bump) — don't switch to `DB::table()->insert`.
- Order: create → bump sequence → record rollback audit.
- Code intelligence: `codegraph explore "HardwareAuditController restoreRecord"` before editing; `DB_CONNECTION=pgsql` in `phpunit.xml` (AGENTS.md **Running Tests**).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareAuditController.php` | empty |
| Lint | `vendor/bin/pint --dirty --format agent` (AGENTS.md **Formatting**) | exit 0 |
| Tests | `composer test` — `config:clear + route:clear + XDEBUG_MODE=off php artisan test` (AGENTS.md **Running Tests**, hermetic) | 884 baseline pass |
| Single file | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareDeletedRestoreTest.php` | pass |
| CodeGraph | `codegraph explore "HardwareAuditController restoreRecord"` | symbols |

## Scope

**In scope**:

- `app/Http/Controllers/Api/HardwareAuditController.php`
- `tests/Feature/HardwareRestoreSequenceTest.php` (create)

**Out of scope**:

- `app/Models/Hardware.php`
- Migrations / seeders
- Any other controller

## Git workflow

- Branch: `advisor/006-restore-sequence-reset` (from `kimya`/`main`, AGENTS.md **New Features** flow)
- Commit: `fix(api): reset hardware sequence after restore with explicit id`
- Do NOT push — plans only, no execution per user request
- Frontend: API-only, no `npm run build` needed

## Steps

### Step 1: Bump sequence after `Hardware::create` in `restoreRecord`

In `app/Http/Controllers/Api/HardwareAuditController.php` after ` $hardware = Hardware::create($restoreData);` (around line 188), add:

```php
// Advance the Postgres sequence past the restored id so the next auto-increment
// does not collide. pg_get_serial_sequence resolves the sequence name regardless
// of whether it was created via SERIAL or IDENTITY.
if (DB::connection()->getDriverName() === 'pgsql') {
    $seq = DB::selectOne("SELECT pg_get_serial_sequence('hardwares','id') as seq");
    if ($seq && $seq->seq) {
        DB::statement("SELECT setval(?, (SELECT COALESCE(MAX(id), 0) FROM hardwares))", [$seq->seq]);
        // Alternative without pg_get_serial_sequence: DB::statement("SELECT setval('hardwares_id_seq', (SELECT COALESCE(MAX(id),0) FROM hardwares))");
    }
}
```

Keep it inside the pgsql guard so SQLite tests (if any) still pass — tests run on `pgsql` (`phpunit.xml` `DB_CONNECTION=pgsql`) so guard is effectively always true in CI, but safe for local sqlite.

Place it immediately after `Hardware::create` and before the rollback audit, so even if `recordRollbackAudit` throws, the sequence is already correct (or wrap both in try if you prefer — but sequence bump should not fail silently).

If you use the two-arg `setval(seq, max)` form, the next `nextval` returns `max+1`; no `is_called` flag needed because `MAX(id)` handles the restored row.

**Verify**: `vendor/bin/pint --dirty --format agent` → 0. `XDEBUG_MODE=off php artisan test tests/Feature/HardwareDeletedRestoreTest.php` → pass.

### Step 2: Write regression test for sequence

Create `tests/Feature/HardwareRestoreSequenceTest.php` (Pest), modeled on `tests/Feature/HardwareDeletedRestoreTest.php`:

- Cases:
  1. `restore advances sequence so next auto-insert succeeds` — create hardware `hw1` with high id via audit restore, then `Hardware::create(['n_code'=>..., 'pc_name'=>'after-restore', ...])` without explicit id → assert it gets `id > hw1->id` and no duplicate-key exception. Steps:
     - Create unit/person/user with `manage_hardware`.
     - Create original hardware `orig` then delete it (hard delete) or use an audit with `action=created` and explicit `hardware_id` that does not yet exist.
     - Call `POST /api/hardware/audits/{audit}/restore-record` as `actingAs($user,'sanctum')` → 200.
     - Assert `Hardware::find($audit->hardware_id)` exists.
     - Then `Hardware::create(['n_code'=>$person->n_code, 'pc_name'=>'next-auto'])` → `expect($next->id)->toBeGreaterThan($audit->hardware_id)` and `expect($next->id)->not->toBe($audit->hardware_id)`.
     - Also assert `DB::selectOne("SELECT last_value FROM hardwares_id_seq")` or `SELECT MAX(id)` shows sequence ≥ `MAX(id)`.
  2. `restore without explicit sequence bump would collide (source assertion)` — optional: assert the controller source contains `pg_get_serial_sequence` or `setval` and `hardwares_id_seq`.

If the test DB is not Postgres, skip with `if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('...')` — but `phpunit.xml` is pgsql so this branch won't run in CI.

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/HardwareRestoreSequenceTest.php` → 2 passed. `composer test` → full suite passes.

## Test plan

- New file `tests/Feature/HardwareRestoreSequenceTest.php` with 2 tests.
- Pattern: `tests/Feature/HardwareDeletedRestoreTest.php:1-40` for setup, Pest `test()` syntax.
- If controlling `hardware_id` is hard via observer, insert audit directly via `HardwareAudit::create` with `changes` that include `id`? But `restoreRecord` uses `audit->hardware_id`, not changes's id. So craft audit with `hardware_id = 999999` and `changes` containing at least `n_code`, `pc_name`.

## Done criteria

- [ ] `vendor/bin/pint --dirty --format agent` exits 0
- [ ] `XDEBUG_MODE=off php artisan test tests/Feature/HardwareRestoreSequenceTest.php` exits 0
- [ ] `composer test` exits 0
- [ ] `grep -n "setval\|pg_get_serial_sequence" app/Http/Controllers/Api/HardwareAuditController.php` returns a match
- [ ] Restoring a hardware with explicit id does not break the next auto-insert (behavioral test passes)
- [ ] No out-of-scope files modified
- [ ] `plans/README.md` row for 006 updated to DONE

## STOP conditions

- `restoreRecord` at `app/Http/Controllers/Api/HardwareAuditController.php:149-210` does not match excerpt (drift — maybe restore moved to service or removed).
- `hardwares` table no longer uses a sequence (e.g., switched to UUID) — check `database/migrations/*_create_hardwares_table.php` before applying setval.
- Existing `HardwareDeletedRestoreTest` fails because sequence bump changes transaction handling — inspect; if the bump runs outside the test transaction (Postgres `setval` is not transactional in the same way), it may affect other tests — consider wrapping in same DB connection.
- Fix requires touching `Hardware` model or migration — stop, this plan is controller-only.

## Maintenance notes

- If `hardwares` ever switches from `bigIncrements`/`id` to UUID, remove this `setval` logic.
- Reviewer: confirm `setval` with `MAX(id)` is correct vs `setval(seq, max, true)` — both work; `true` means next `nextval` is `max+1`. Plain `setval(seq, max)` with two args defaults to `is_called=true` in Postgres 14+, so next is `max+1`.
- Follow-up: centralize sequence fix into a `RestoresHardware` trait or service if more models gain restore-from-audit.
