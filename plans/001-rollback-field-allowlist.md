# Plan 001: Enforce allow-list on hardware audit rollback field

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareAuditController.php app/Models/Hardware.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `70e35c2`, 2026-09-01

## Why this matters

`POST /api/hardware/{hw}/audits/{audit}/rollback` takes `field` from the request as a raw column name and does `$hardware->update([$field => $restoredValue])`. Validation is only `required|string`. An attacker who can list audits can pick any `field` that appears in that audit's `changes` array and move a hardware record across organizational units by rolling back `n_code` to a person in another unit, without any check that the target unit is accessible. Allow-listing to fillable hardware columns plus an org-scope check on the restored `n_code` closes the IDOR/mass-assignment vector.

## Current state

Relevant files:

- `app/Http/Controllers/Api/HardwareAuditController.php` — audit index/show/rollback/restore/export; source of the bug (lines ~85-141)
- `app/Models/Hardware.php:26-46` — fillable list is the allow-list source of truth
- `app/Observers/HardwareAuditObserver.php:1-40` — writes `changes` as `[{field, old, new}]` with formatted display values

Current buggy code at `app/Http/Controllers/Api/HardwareAuditController.php:93-121`:

```php
$request->validate([
    'field' => 'required|string',
]);
// ...
$fieldChange = null;
foreach ($changes as $change) {
    if (($change['field'] ?? '') === $request->field) {
        $fieldChange = $change;
        break;
    }
}
if (! $fieldChange) { return response()->json([...], 422); }
$oldValue = $fieldChange['old'] ?? '—';
$restoredValue = $this->parseValueForRestore($oldValue, $request->field);
$hardware->update([$request->field => $restoredValue]);
```

Model allow-list at `app/Models/Hardware.php:26-46`:

```php
protected $fillable = [
    'n_code','pc_name','type','os','ip_valid','ip_local','mac','net_type',
    'switch','port','shutdown','vlan','motherboard','cpu','ram','hdd',
    'comments','mark','clean_at',
];
```

Repo conventions to match (AGENTS.md):

- Validation uses `Illuminate\Validation\Rule::in(...)` elsewhere (see `app/Http/Controllers/Api/PersonController.php:84` for `size:10` pattern and `TicketController.php:72` for `in:urgent,...`).
- Org-scope checks use `app(AccessService::class)->accessibleUnitIds($user)` then `in_array` (see `HardwareAuditController.php:363-374` and `HardwareController.php:23-34`). When `n_code` changes, `HardwareController::update` verifies the new person's unit is accessible (lines 273-279) — follow that pattern. This satisfies AGENTS.md **Access Control / HasOrganizationalScope** and **Development Guidelines #5-6** (permission + org scope required for every hardware write).
- Persian messages and `success:true` response shape are kept (see `HardwareAuditController.php:133-141`). Project is **fully RTL/Persian** (`dir="rtl"`, Vazirmatn) — keep user-facing strings in Persian where existing code does (`بازگردانی` rollback label); API JSON `message` keeps current language for backward compat.
- Formatting: `vendor/bin/pint --dirty --format agent` before committing (AGENTS.md **Conventions > Formatting**).
- Cache: hardware writes already bump `Hardware::flushStatsCache()` which increments `CacheInvalidationService` namespaces `hardware_stats/gis/maps/dashboard` (AGENTS.md **Cache Version Namespaces**) — no extra cache change needed here.
- Code intelligence: run `codegraph explore "HardwareAuditController rollback"` before editing to confirm call paths (AGENTS.md **Code Intelligence** — Hermes MUST use CodeGraph first).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareAuditController.php app/Models/Hardware.php` | no output or only expected changes |
| Tests | `composer test` — runs `php artisan config:clear && php artisan route:clear && XDEBUG_MODE=off php artisan test` (AGENTS.md **Running Tests**) — hermetic (`CACHE_STORE=array`, no Redis), 884 passed baseline + new tests | exit 0 |
| Lint/format | `vendor/bin/pint --dirty --format agent` (AGENTS.md **Formatting**) | exit 0, no diff or formatted |
| Single file | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditControllerTest.php` | pass |
| CodeGraph | `codegraph explore "HardwareAuditController rollback"` | shows symbols before edit |

## Scope

**In scope** (only files you should modify):

- `app/Http/Controllers/Api/HardwareAuditController.php`
- `tests/Feature/HardwareAuditRollbackAllowlistTest.php` (create)

**Out of scope** (do NOT touch):

- `app/Models/Hardware.php` — fillable is source of truth, don't expand it here
- `app/Http/Controllers/Api/HardwareController.php` — separate fix (plan 003)
- `app/Observers/HardwareAuditObserver.php` — formatting stays as-is
- Any migration or front-end file

## Git workflow

- Branch: `advisor/001-rollback-field-allowlist` (from `kimya`/`main` per AGENTS.md **New Features: Branch → develop → test → PR**; do not commit to working branch `it-test5` directly)
- Commit style: conventional, e.g. `fix(api): allow-list rollback field and verify target unit` (match `git log --oneline` style: `fix(api): ...`, `fix(hardware): ...`)
- Do NOT push or open PR unless instructed — plans only, no execution per user request
- Frontend: no rebuild needed (`npm run build` only after frontend changes — AGENTS.md **Frontend rebuild**); this plan is API-only, keep RTL/layout untouched

## Steps

### Step 1: Add allow-list validation and target-unit scope check to `rollback()`

In `app/Http/Controllers/Api/HardwareAuditController.php`, inside `rollback()` before the field lookup:

1. Build allow-list from the model: `$allowed = (new \App\Models\Hardware)->getFillable();` or a hardcoded array mirroring `Hardware::$fillable` (prefer reading from model so it stays in sync).
2. Change validation to:
   ```php
   $request->validate([
       'field' => ['required','string', \Illuminate\Validation\Rule::in($allowed)],
   ]);
   ```
   Keep `required|string` behavior for error messages; invalid field should return 422 via validator (Laravel's default 422 JSON).
3. After `$restoredValue = $this->parseValueForRestore(...)`, if `$request->field === 'n_code'` and `$restoredValue` is not null, verify the target person's unit is accessible:
   ```php
   if ($request->field === 'n_code' && $restoredValue !== null) {
       $person = \App\Models\Person::where('n_code', $restoredValue)->first();
       if (!$person) { return response()->json(['message' => 'Person not found.'], 422); }
       $accessibleIds = app(\App\Services\AccessService::class)->accessibleUnitIds($request->user());
       if (!in_array($person->u_id, $accessibleIds, true)) {
           return response()->json(['message' => 'Cannot restore hardware to a person in an inaccessible unit.'], 403);
       }
   }
   ```
   Mirror the check in `HardwareController::update:273-279`.

**Verify**: `vendor/bin/pint --dirty --format agent` → exit 0. `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditControllerTest.php` → still pass.

### Step 2: Add explicit 422 when field not in audit diff (keep existing) and ensure non-fillable is rejected by validator

No code change beyond step 1 — but confirm the order: validator runs first, so `field=id` or `field=created_at` now fails with 422 before the loop. The existing `Field not found in audit record` 422 remains as second layer for fields that are fillable but not in that specific audit's `changes`.

**Verify**: `composer test` still passes (existing rollback tests should still pass for valid fillable fields).

### Step 3: Write regression tests

Create `tests/Feature/HardwareAuditRollbackAllowlistTest.php` modeled after `tests/Feature/HardwareAuditControllerTest.php` and `tests/Feature/HardwareBulkOperationsTest.php`:

- Setup: use `Tests\Support\Concerns\InteractsWithTestSetup` trait pattern seen in existing hardware tests (create units, persons, users with `manage_hardware`, hardware record, audit trail via observer). Use `actingAs($user, 'sanctum')`.
- Cases:
  1. `rollback with non-fillable field id is rejected` → `POST .../rollback {field: id}` → 422, hardware unchanged
  2. `rollback with non-fillable field created_at is rejected` → 422
  3. `rollback n_code to out-of-scope person is forbidden` → create two units (ancestor/descendant vs unrelated), person in out-of-scope unit, audit for `n_code`, attempt rollback → 403, hardware `n_code` unchanged
  4. `rollback n_code to in-scope person succeeds` → 200, hardware `n_code` updated, new `rollback` audit created
  5. `rollback valid fillable field like type succeeds` → 200 (happy path)

Follow Pest `covers()` annotation style added in recent commits: `/** @covers \App\Http\Controllers\Api\HardwareAuditController::rollback */` or `covers(\App\Http\Controllers\Api\HardwareAuditController::class)` helper used in repo (see `tests/Feature/HardwareAuditControllerTest.php`).

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditRollbackAllowlistTest.php` → 5 passed. Then `composer test` → 889 passed (884 + 5).

## Test plan

- New file `tests/Feature/HardwareAuditRollbackAllowlistTest.php` with 5 tests listed above.
- Existing pattern: `tests/Feature/HardwareAuditControllerTest.php:1-50` — Pest `test('...', function(){ ... })` with `actingAs`, `assertStatus`, `assertDatabaseHas`.
- Also verify mutation: run `vendor/bin/pint --dirty` and ensure no duplicate validation messages break existing tests that assert 422 structure.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `vendor/bin/pint --dirty --format agent` exits 0
- [ ] `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditRollbackAllowlistTest.php` exits 0 with 5 passed
- [ ] `composer test` exits 0 (full suite, ~889)
- [ ] `grep -n "validate.*field.*required|string" app/Http/Controllers/Api/HardwareAuditController.php` shows the allow-list Rule::in variant, not bare `required|string`
- [ ] `grep -n "parseValueForRestore" app/Http/Controllers/Api/HardwareAuditController.php` still present and called before the `n_code` scope check
- [ ] No files outside in-scope list modified (`git status --short` only shows those two files)
- [ ] `plans/README.md` row for 001 updated to DONE

## STOP conditions

Stop and report back (do not improvise) if:

- The `rollback` method at `HardwareAuditController.php:85-141` does not match the excerpt (drift).
- `Hardware::getFillable()` returns empty or does not contain `n_code` — check model, don't hardcode blindly.
- Existing `HardwareAuditControllerTest` fails after changing validation because it tests `field` with a non-fillable value expecting 422 with a different message — inspect and adapt test, don't suppress validation.
- `AccessService::accessibleUnitIds` signature changed or requires different injection — read `app/Services/AccessService.php:16`.

## Maintenance notes

- If `Hardware::$fillable` adds a new column, the rollback allow-list automatically widens (since it reads from model). Review whether the new column should be rollback-able (e.g., future `asset_tag` yes, `deleted_at` no — consider explicit deny list).
- Reviewers: check that 403 vs 422 choice matches API contract (403 for scope, 422 for validation). Ensure error response shape matches existing 403 JSON (`message`).
- Follow-up deferred: `restoreRecord` allow-list (plan 002) and bulk audit suppression (plan 003) are separate.
