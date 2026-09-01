# Plan 002: Deny hardware restore when organizational scope cannot be determined

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

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (can run parallel with 001; touches different method)
- **Category**: security
- **Planned at**: commit `70e35c2`, 2026-09-01

## Why this matters

`POST /api/hardware/audits/{audit}/restore-record` recreates a hard-deleted hardware row from its `created` audit. It calls `assertAccessibleFromAudit()` to check org scope via the audit's stored `n_code`. But that helper does `if ($nCode !== null) { check }` with no `else`. If changes lack `n_code` (legacy data, corrupted row, manual insert) the check is skipped and any user with `manage_hardware` can restore the record regardless of unit. This is an IDOR / scope bypass; the fix is to deny by default when scope cannot be proven.

## Current state

Files:

- `app/Http/Controllers/Api/HardwareAuditController.php` — `restoreRecord()` at ~149-210 and helper `assertAccessibleFromAudit()` at ~384-409
- `app/Services/AccessService.php:16` — `accessibleUnitIds(?User $user): array`
- `app/Models/HardwareAudit.php:10` — fillable `hardware_id,user_id,action,changes,source,ip_address,user_agent`

Current helper at `app/Http/Controllers/Api/HardwareAuditController.php:384-409`:

```php
private function assertAccessibleFromAudit(Request $request, HardwareAudit $audit): void
{
    $user = $request->user();
    $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);
    $nCode = null;
    $hw = DB::table('hardwares')->where('id', $audit->hardware_id)->first();
    if ($hw && isset($hw->n_code)) {
        $nCode = $hw->n_code;
    } elseif (is_array($audit->changes)) {
        foreach ($audit->changes as $change) {
            if (($change['field'] ?? null) === 'n_code' && isset($change['new'])) {
                $nCode = $change['new'];
                break;
            }
        }
    }
    if ($nCode !== null) {
        $unitId = DB::table('persons')->where('n_code', $nCode)->value('u_id');
        if ($unitId && ! in_array($unitId, $accessibleIds, true)) {
            abort(403, 'Hardware record not accessible.');
        }
    }
}
```

`restoreRecord` calls it at `~168`:

```php
$user = $request->user();
$this->assertAccessibleFromAudit($request, $audit);
```

Note: `HardwareAuditObserver::created` always writes `n_code` when non-empty (`HardwareAuditObserver.php:22-36`), but historical rows or direct DB inserts may not.

Repo conventions (AGENTS.md):

- Org-scope helpers abort with `abort(403, 'Hardware record not accessible.')` (see `HardwareController::assertAccessible:32-34` and `HardwareAuditController::assertAccessible:372-374`) — satisfies **Access Control / AccessService** and **Guidelines #5-6** (permission `manage_hardware` already required on route `POST /audits/{audit}/restore-record` via `permission:manage_hardware` in `routes/api.php:84-85`, keep it).
- Use `DB::table` for lookups when model may be deleted (see existing helper) — hardware row is hard-deleted, FK deliberately absent on `hardware_audits.hardware_id`.
- Tests use Pest, `actingAs($user,'sanctum')`, `InteractsWithTestSetup`-like setup (see `tests/Feature/HardwareDeletedRestoreTest.php`) and `covers()` annotations for mutation testing (AGENTS.md **CI: Mutation Testing**).
- Keep RTL/Persian UI untouched — API messages stay as current 403 string for backward compat; `dir="rtl"` not affected.
- Code intelligence: `codegraph explore "HardwareAuditController restoreRecord assertAccessibleFromAudit"` before editing.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareAuditController.php` | empty |
| Lint | `vendor/bin/pint --dirty --format agent` (AGENTS.md **Formatting**) | exit 0 |
| Tests | `composer test` — `config:clear + route:clear + XDEBUG_MODE=off php artisan test` (AGENTS.md **Running Tests**, hermetic) | 884 baseline + new pass |
| Single file | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareDeletedRestoreTest.php` | pass |
| CodeGraph | `codegraph explore "HardwareAuditController restoreRecord"` | symbols |

## Scope

**In scope**:

- `app/Http/Controllers/Api/HardwareAuditController.php`
- `tests/Feature/HardwareRestoreScopeTest.php` (create)

**Out of scope**:

- `app/Http/Controllers/Api/HardwareController.php`
- `app/Observers/HardwareAuditObserver.php`
- Migrations / seeders
- Frontend

## Git workflow

- Branch: `advisor/002-restore-scope-deny` (from `kimya`/`main` per AGENTS.md **New Features** workflow)
- Commit: `fix(api): deny restore when audit scope cannot be determined` (match `fix(api): ...` style in `git log`)
- Do NOT push — plans only, no execution per user request
- Frontend: API-only, no `npm run build` needed (AGENTS.md **Frontend rebuild**)

## Steps

### Step 1: Make `assertAccessibleFromAudit` deny-by-default

In `app/Http/Controllers/Api/HardwareAuditController.php:384-409`, change the final `if` block to deny when `$nCode` is null or when `$unitId` cannot be resolved:

```php
private function assertAccessibleFromAudit(Request $request, HardwareAudit $audit): void
{
    $user = $request->user();
    $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

    $nCode = null;
    $hw = DB::table('hardwares')->where('id', $audit->hardware_id)->first();
    if ($hw && isset($hw->n_code)) {
        $nCode = $hw->n_code;
    } elseif (is_array($audit->changes)) {
        foreach ($audit->changes as $change) {
            if (($change['field'] ?? null) === 'n_code' && isset($change['new'])) {
                $nCode = $change['new'];
                // The observer stores formatted display value; it is the raw n_code string for n_code field.
                $nCode = is_string($change['new']) && $change['new'] !== '—' ? $change['new'] : null;
                break;
            }
        }
    }

    if ($nCode === null) {
        abort(403, 'Hardware record not accessible.');
    }

    $unitId = DB::table('persons')->where('n_code', $nCode)->value('u_id');
    if (!$unitId || !in_array($unitId, $accessibleIds, true)) {
        abort(403, 'Hardware record not accessible.');
    }
}
```

Key changes: (1) add `else abort` path, (2) also abort if `unitId` is null (person missing), (3) handle formatted `—` case. Keep `DB::table` usage. Ensure `Request` import remains `Illuminate\Http\Request`.

**Verify**: `vendor/bin/pint --dirty --format agent` → 0. `XDEBUG_MODE=off php artisan test tests/Feature/HardwareDeletedRestoreTest.php` → still pass (existing audits have n_code, so no behavior change for valid data).

### Step 2: Harden `restoreRecord` early validation order

No structural change beyond step 1, but confirm `restoreRecord` still validates `action === 'created'` and `exists` check before calling `assertAccessibleFromAudit`. The scope check must stay before `Hardware::create` (it already does at line 168). No move needed — just verify.

**Verify**: `composer test` still passes.

### Step 3: Write regression tests

Create `tests/Feature/HardwareRestoreScopeTest.php` (Pest), modeled on `tests/Feature/HardwareDeletedRestoreTest.php`:

- Cases:
  1. `restore denied when audit changes has no n_code` — create `HardwareAudit` with `action=created` but `changes` missing `n_code` field (only `pc_name`), `hardware_id` points to non-existing hardware row, authenticated user in unit A → `POST /api/hardware/audits/{id}/restore-record` → 403
  2. `restore denied when audit changes is null` → 403 (or 422 depending on earlier validation — assert 403 after fix, or at least not 200)
  3. `restore denied when audit n_code points to out-of-scope unit` — audit `changes` has `n_code=new` pointing to person in unrelated unit → 403
  4. `restore succeeds when audit n_code is in-scope` — audit `changes` has in-scope `n_code`, hardware row deleted → 201/200 and `assertDatabaseHas('hardwares', ['id'=>audit->hardware_id])`
  5. `restore still checks exists guard` — when hardware row still exists → 422 (existing behavior preserved)

Use `actingAs($user,'sanctum')`, create persons/units via factories or direct `Person::create` as in existing tests. Ensure audit is created with `HardwareAudit::create([...])` bypassing observer (so you can craft missing-n_code case).

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/HardwareRestoreScopeTest.php` → 5 passed. `composer test` → ~889 (or 890 if both 001 and 002 new tests counted).

## Test plan

- New file `tests/Feature/HardwareRestoreScopeTest.php` with 5 Pest tests.
- Pattern: `tests/Feature/HardwareDeletedRestoreTest.php:1-60` for setup and `tests/Feature/HardwareAuditControllerTest.php` for `@covers` annotation.
- Edge: ensure test that inserts audit without `n_code` does not hit observer formatting — insert directly via `DB::table('hardware_audits')->insert(...)` or `HardwareAudit::create` with `changes` already formatted.

## Done criteria

- [ ] `vendor/bin/pint --dirty --format agent` exits 0
- [ ] `XDEBUG_MODE=off php artisan test tests/Feature/HardwareRestoreScopeTest.php` exits 0, 5 tests pass
- [ ] `composer test` exits 0 (full suite)
- [ ] `grep -n "if (\$nCode === null" app/Http/Controllers/Api/HardwareAuditController.php` shows the deny-by-default branch
- [ ] No out-of-scope files modified (`git status`)
- [ ] `plans/README.md` row for 002 updated to DONE

## STOP conditions

- The helper `assertAccessibleFromAudit` does not match the excerpt (drift) — e.g., method renamed or removed.
- Existing `HardwareDeletedRestoreTest` starts failing because valid audits now incorrectly abort — indicates the `n_code` extraction changed format (observer stores `new` as formatted string; check `HardwareAuditObserver::formatValueForDisplay`).
- `HardwareAudit` model or `DB::table('persons')` lookup returns different type (e.g., `n_code` stored with different casing) — verify Persian normalization impact.
- Fix requires touching `HardwareAuditObserver` — stop, this plan is controller-only.

## Maintenance notes

- If `HardwareAuditObserver` ever stops writing `n_code` to `changes` (e.g., field becomes nullable), this deny-by-default will block legitimate restores — update observer or helper together.
- Reviewer: confirm 403 is correct vs 404 for scope-hiding; repo uses 403 for hardware scope (`HardwareController:33`) so 403 is consistent.
- Follow-up: consider adding a DB NOT NULL or app-level invariant that `created` audits always contain `n_code` and `pc_name` to make the error case impossible.
