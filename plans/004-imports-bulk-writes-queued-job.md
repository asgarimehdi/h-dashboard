# perf: both Excel imports write one row per Eloquent call in the request

> **Plan 004** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P1` · formerly GitHub issue #1140 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Imports app/Models/Hardware.php app/Observers/HardwareAuditObserver.php resources/views/livewire/hardware/import-hardware`

## Status

- **Priority**: P1
- **Effort**: M (queue it: L)
- **Risk**: MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

Both Excel imports write **one row per Eloquent call inside the web request**. Each hardware save additionally fires 4 cache-version increments and one audit `INSERT`, and each import walks the whole in-scope hardware table to build its lookup maps — all inside one PHP request, twice (preview, then confirm). A 5,000-row sheet means ~5,000 writes, ~5,000 audit inserts, ~20,000 cache increments and two full-table hydrations, risking timeouts and worker starvation for every user on the box. The codebase already has the bulk primitives that fix this; the import just does not use them.

## Current state

- `app/Imports/HardwareImport.php:340,349` — per-row writes:

```php
if ($action === 'update' || $action === 'auto') {
    $existing['record']->update($data);       // one commit per row
    $this->importResults['updated']++;
}
...
Hardware::create($data);                      // one commit per row
```

- `app/Models/Hardware.php:100,113-120` — every save bumps **four** cache namespaces:

```php
static::saved(fn () => self::flushStatsCache());
static::deleted(fn () => self::flushStatsCache());
...
public static function flushStatsCache(): void
{
    $cache = app(CacheInvalidationServiceInterface::class);
    $cache->increment('hardware_stats');
    $cache->increment('gis');
    $cache->increment('maps');
    $cache->increment('dashboard');
}
```

- `app/Observers/HardwareAuditObserver.php:142` — `HardwareAudit::create([...])` per row.
- `app/Imports/HardwareImport.php:84-95` — hydrates every in-scope hardware row (20 columns) into PHP lookup maps.
- `app/Imports/PersonImport.php:108,411,420` — the same per-row shape for persons (audit columns differ).
- `resources/views/livewire/hardware/import-hardware/import-hardware.blade.php:86` (preview) and `:129` (confirm) — `Excel::import()` runs inline in a Livewire action both times.
- **The bulk primitives already exist** — reuse them, do not reinvent:
  - `app/Http/Controllers/Api/HardwareController.php:311-323,356-370` uses `HardwareBulkAuditWriter` + `CacheInvalidationService::batch()` to collapse the four version bumps into one.
  - Find the classes: `grep -rn "class HardwareBulkAuditWriter\|function batch(" app/`.
- Repo conventions: queued jobs implement `ShouldQueue` with `failed()`, `$timeout`/`$tries` set (see `app/Jobs/*.php`); jobs hold **no** `auth()`/`session()` state — `accessibleUnitIds()` is `[]` in a worker and must be passed in as data (`App\Services\AccessService::allUnitIds()` is the org-wide, actor-free resolver).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused import tests | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareImportTest.php` (find it: `grep -rl "Import" tests/Feature`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `app/Imports/HardwareImport.php`
- `app/Imports/PersonImport.php`
- `resources/views/livewire/hardware/import-hardware/import-hardware.blade.php`
- `resources/views/livewire/kargozini/import-persons/import-persons.blade.php`
- The existing test files for both imports

**Out of scope** (do NOT touch):
- `app/Models/Hardware.php` — the 4-increment `flushStatsCache()` is used by normal CRUD too; changing it affects every write path.
- `app/Observers/HardwareAuditObserver.php` behaviour — the audit row must still be written; only *how* it is batched changes, via the existing writer.
- The Excel **file format** or column mapping — `PersonImport`/`HardwareImport` header handling stays identical.
- Any change to the import preview UI structure (that is a separate issue).

## Git workflow

- Branch: `perf/bulk-import-writes`
- Commit style: `perf: collapse per-row import writes into batches (#004)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Measure the baseline

Write a throwaway benchmark (do not commit) that imports a generated 500-row CSV through `HardwareImport` directly in a test and counts DB queries + cache increments (the shared trait has `assertQueryCount()`/`assertNoNPlusOne()` — see `tests/Support/Concerns/InteractsWithTestSetup.php`). Record both numbers. This is your before/after evidence.

**Verify**: you have two numbers recorded (queries, increments) for 500 rows.

### Step 2: Batch the audit writes and the cache bumps

In `HardwareImport`, replace per-row `HardwareAudit::create()` (whatever the import currently relies on the observer for) with the existing `HardwareBulkAuditWriter`, and wrap the whole import in `CacheInvalidationService::batch()` so the four namespaces bump once. Delete nothing that changes behaviour: normalization, audit rows, and the stats flush must all still happen.

Important: bulk paths bypass model events, so you must invoke explicitly whatever the observer did — Persian normalization of the name/comment fields and the audit rows. Confirm the exact list by reading the observer (`app/Observers/HardwareAuditObserver.php`) before removing reliance on it.

**Verify**: focused import test passes; benchmark query/increment counts drop sharply.

### Step 3: Batch the row writes themselves

Replace per-row `update()`/`create()` with a chunked write: resolve all matching existing rows with one `whereIn` over the compare keys, compute changes in PHP, then `upsert()` (or chunked bulk update/insert) per 500–1000 rows. Persian normalization must still be applied to every written value — reuse the same `normalizeForSearch()` calls the model hook uses (see `Hardware.php:88-95`).

**Verify**: focused import tests still pass; benchmark query count is now roughly linear in chunks, not rows.

### Step 4: Move confirm into a queued job

Create `App\Jobs\ImportHardwareJob` (mirror the structure of `app/Jobs/*.php`: `ShouldQueue`, `$timeout`, `$tries`, `failed()` with `Log::error`). It receives the stored file path, the compare key, the selected actions, and the explicit `accessibleUnitIds` array captured at dispatch time (never re-resolved inside the worker). The Livewire `confirmImport()` action dispatches it and shows a "in progress" state instead of blocking.

Keep the preview path synchronous (it only parses and diffs), but see the paired plan for the preview payload.

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/HardwareImportTest.php` → all pass, including a new test that `confirmImport()` dispatches the job (`Queue::fake()` + `assertPushed`).

### Step 5: Same treatment for `PersonImport`

Apply steps 2–4's shape to `PersonImport` and its Livewire component. Do not invent a second batching mechanism — reuse the same writer/service.

**Verify**: person-import tests pass; benchmark counts drop.

## Test plan

- New tests (one file per import, or the existing import tests):
  1. A 500-row import produces the same final DB state as before the change (assert row counts and a sample of normalized values — **assert on the database**, not on component status).
  2. Audit rows are still written for each changed row (count them).
  3. `Queue::fake()` → `confirmImport()` pushes exactly one job with the right payload including a non-empty `accessibleUnitIds`.
  4. Job failure path: `failed()` logs (mirror an existing job test in `tests/Feature/Jobs/JobsTest.php`).
- Structural pattern: `tests/Feature/Jobs/JobsTest.php` for queued-job tests; existing import tests for fixtures.
- Anti-pattern to avoid: asserting an exact query count that depends on the DB driver; use the trait's `assertQueryCount` with a ceiling, or compare before/after ratios.

## Done criteria

- [ ] Benchmark (recorded in the PR body) shows per-row queries and cache increments collapsed to per-chunk
- [ ] Import results are byte-identical for the same fixture (row counts + normalized values asserted)
- [ ] Audit rows still written for every changed row (test asserts the count)
- [ ] `confirmImport()` dispatches a queued job (`Queue::fake()` test passes)
- [ ] `XDEBUG_MODE=off php artisan test <import tests>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] No files outside the in-scope list are modified (`git status --porcelain`)
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- Bulk writes cannot reproduce a side effect the per-row path had (e.g. an observer-driven relation or an audit field) — STOP and report which side effect, rather than dropping it.
- `accessibleUnitIds()` cannot be captured at dispatch time (e.g. the component has no request context at that point) — STOP; running the worker with `[]` scope would silently import nothing or everything.
- The existing bulk primitives (`HardwareBulkAuditWriter`, `CacheInvalidationService::batch()`) do not exist under those names — report what you found instead of writing a parallel implementation.
- A step's verification fails twice after a reasonable fix attempt.

## Maintenance notes

- The preview/confirm pair now has different sync/async behaviour: any future change to the confirm button must keep the file available until the job runs (the queued job needs the stored upload path, not the temp file).
- Reviewer should scrutinize: that normalization still runs on bulk-written rows, that the four cache namespaces bump exactly once per import, and that the job carries an explicit scope.
- Deferred: a progress UI for long imports (job status polling) — separate feature.
