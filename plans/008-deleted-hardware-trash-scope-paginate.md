# perf: the deleted-hardware trash loads all history and ignores unit scope

> **Plan 008** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P1` · formerly GitHub issue #1144 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Traits/HardwareIndexHelpers.php app/Services/HardwareAuditScope.php resources/views/livewire/hardware`

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The deleted-hardware trash modal loads **everything ever deleted**: it plucks every delete-audit `hardware_id`, dedupes in PHP, then runs three more unbounded `whereIn` queries (one selecting the full `changes` JSON) plus a live-hardware check, and parks the result in a **public** Livewire array. That array is re-serialized on every round trip while the modal is open and after every restore. Cost grows with lifetime deletions, not with what the modal shows, and the query ignores the operator's unit scope entirely — a scope gap as well as a performance one.

## Current state

- `app/Traits/HardwareIndexHelpers.php:22` — the public payload:

```php
public array $deletedHardware = [];
```

- `:308-318` — the id set (all deletes, deduped in PHP):

```php
$deletedHardwareIds = HardwareAudit::whereIn('action', HardwareAudit::DELETING_ACTIONS)
    ->pluck('hardware_id')
    ->unique()
    ->values()
    ->all();
```

- `:332-345` — two more unbounded queries over that list, the first selecting `changes`:

```php
$createdAudits = HardwareAudit::whereIn('hardware_id', $deletedHardwareIds)
    ->where('action', 'created')
    ->select(['id', 'hardware_id', 'user_id', 'changes', 'created_at'])
    ->with('user:id,n_code')
    ->get();

$deleteTimestamps = HardwareAudit::whereIn('hardware_id', $deletedHardwareIds)
    ->whereIn('action', HardwareAudit::DELETING_ACTIONS)
    ->select('hardware_id', 'created_at')->get() …
```

- `:349` — `$liveIds = Hardware::query()->whereIn('id', $deletedHardwareIds)->pluck('id')->all();`
- `:349` and `app/Services/HardwareAuditScope.php:61-76` — two more `whereIn` queries with the same list, unchunked.
- `:506` — `loadDeletedHardware()` is re-run after every restore, so the whole set is rebuilt and re-serialized.
- Opened by `resources/views/livewire/hardware/filters.blade.php:12`.
- Repo conventions: pagination via `WithPagination` + MaryUI tables (`units/index.blade.php`); audit scope is resolved by `App\Services\HardwareAuditScope` (live `n_code` → snapshot `n_code` → `persons.u_id`) and it is **deny-by-default** (a null unit id means out of scope). Do not reimplement that mapping.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditTest.php tests/Feature/HardwareIndexTest.php` (find: `grep -rl "deletedHardware\|loadDeletedHardware" tests/`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `app/Traits/HardwareIndexHelpers.php` (`loadDeletedHardware()`, the public property, the restore reload)
- `resources/views/livewire/hardware/filters.blade.php` and whatever Blade renders `$deletedHardware` rows
- The hardware trash/audit test file(s)

**Out of scope** (do NOT touch):
- `app/Services/HardwareAuditScope.php`'s mapping contract — it is shared with the API audit controller; you may *call* it per page, not change it.
- `restoreRecord()`'s scope checks and the `setval`/sequence advance (#888/#816) — restore semantics must not change.
- The audit table's schema.

## Git workflow

- Branch: `perf/trash-scope-paginate`
- Commit style: `perf: scope and paginate the deleted-hardware trash (#008)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Push "no longer live" into SQL

Replace the pluck-then-check with a single SQL-side predicate, so the id set is already the final candidate set:

```php
$query = HardwareAudit::query()
    ->where('action', 'created')
    ->whereIn('hardware_id', function ($q) {
        $q->select('hardware_id')->from('hardware_audits')
          ->whereIn('action', HardwareAudit::DELETING_ACTIONS);
    })
    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('hardwares')->whereColumn('hardwares.id', 'hardware_audits.hardware_id'));
```

(Confirm the live check direction against the current `$liveIds` filter at `:355+`: rows whose hardware is live are dropped; `whereNotExists` expresses that exactly.)

**Verify**: focused trash tests pass.

### Step 2: Paginate and scope the page

- Return a `LengthAwarePaginator` from a new `deletedHardwarePage()` method, `perPage` ~20, ordered newest-first.
- Resolve scope **for that page only**: map the page's audits through `HardwareAuditScope` (as `:349` does today) and keep only rows whose resolved unit id is in `accessibleUnitIds()` — deny-by-default, so a null unit id is dropped.
- Select only the columns the row renders; keep `changes` only if the badges/restore guard need it (it does today — keep it, but only for the page).
- Replace `public array $deletedHardware` with the paginator-backed page data (public), and move the full-set derivation out of the serializer.

**Verify**: `XDEBUG_MODE=off php artisan test <trash tests>` → all pass.

### Step 3: Preserve restore behaviour

`restoreRecord()` must still work from a row on the current page: it currently reads from `$deletedHardware`. Point it at the page model (`historyHardwareId` is `#[Locked]` and primed by `loadHistory()` — do not change that flow), and keep the "reload after restore" behaviour, which now only reloads one page.

**Verify**: restore tests pass, including the out-of-scope restore rejection (#816).

### Step 4: Regression-test the two contracts

- Pagination: seeding 30 deleted rows shows one page of `perPage`, not all 30.
- Scope: an out-of-scope deleted row does not appear (deny-by-default).

**Verify**: `XDEBUG_MODE=off php artisan test <file>` → all pass including new tests.

## Test plan

- New tests in the hardware audit/trash test file:
  1. 30 deleted rows, `perPage = 20` → the public payload holds 20 rows (bounded).
  2. A deleted row whose resolved unit is out of scope is absent (deny-by-default).
  3. A row whose hardware is live again (restored) is absent (existing behaviour, keep).
  4. Restore from a paged row still restores the right record (assert on the **database**).
- Structural pattern: existing `HardwareAuditTest`/`HardwareIndexTest`; the shared trait provides `createUserWithUnit()`/`createHardware()`.
- Verification: `XDEBUG_MODE=off php artisan test <file>` → all pass.

## Done criteria

- [ ] `grep -n "public array \$deletedHardware" app/Traits/HardwareIndexHelpers.php` returns no match (replaced by a paged structure)
- [ ] The trash load no longer plucks all delete audits into PHP (verified by reading the new query; state it in the PR)
- [ ] Trash rows are scope-filtered (new test), deny-by-default
- [ ] 30 rows render as one 20-row page (new test)
- [ ] Restore still works and is still scope-checked (existing + new tests pass)
- [ ] `XDEBUG_MODE=off php artisan test <hardware trash tests>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- The trash modal relies on the **full** list for client-side grouping/filtering that cannot be reproduced with a page — STOP and report what depends on it before paginating.
- `HardwareAuditScope` cannot be applied per page without a stored unit id (i.e. it needs the full set to resolve) — report; that would need a schema-level snapshot column, a larger change.
- Any existing test asserts the unbounded payload — report before adapting it.

## Maintenance notes

- If audit volume keeps growing, the follow-up is a scope snapshot column on `hardware_audits` so the predicate is a plain indexed `whereIn` rather than a per-page PHP mapping; that is deferred here (schema change).
- Reviewer should scrutinize: that the scope filter is deny-by-default (null unit id dropped, not kept), and that no row that was visible before is now hidden *except* legitimately out-of-scope ones.
