# Plan 006: Import/Export at Scale (queue + stream, kill the fake chunk)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- app/Exports/HardwareExport.php app/Exports/HardwareAuditsExport.php app/Http/Controllers/Api/HardwareExportController.php app/Http/Controllers/Api/HardwareAuditController.php app/Imports/HardwareImport.php app/Imports/PersonImport.php "resources/views/livewire/hardware/import-hardware/import-hardware.blade.php"` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P2 (large CSVs time out in-request; large exports OOM despite "chunked" comments)
- **Effort**: L
- **Risk**: HIGH (behavior parity: per-row model events/audits, validation errors, Persian normalize/escape; queue infra must exist)
- **Depends on**: 001 (needs green baseline before risky refactor)
- **Category**: perf
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

Imports write row-by-row inside the web request (`Hardware::create()` per row, per-row audits). Exports implement `FromCollection` whose `collection()` does `$this->query->get()` — the `WithChunkReading` interface only chunks `FromQuery`, so the custom `chunkCollection()` is never called by the exporter. Both paths die on real data sizes.

## Current state

- `app/Exports/HardwareExport.php:15,62-84` (verified): `implements FromCollection, ..., WithChunkReading`; `collection() { return $this->query->get(); }`; `chunkCollection()` paginates by `lastId` but nothing calls it. Wired to `Excel::download` at `HardwareExportController:111-114`.
- `app/Exports/HardwareAuditsExport.php:7-10,27`: `FromCollection` + "return the raw models" + `ShouldAutoSize`.
- `app/Imports/HardwareImport.php:330,339`: `->update()` / `Hardware::create()` per row; `PersonImport:369+` same; triggered via `Excel::import(...)` at `import-hardware.blade.php:75,111`.
- `maatwebsite/excel 4.0.2` current (PhpSpreadsheet ^5.3 absorbed) — no version work needed.
- Gotcha: `LIKE`-escape + Persian-normalize is copy-pasted ~10× in `HardwareExportController:37-101` — preserve exact semantics when moving to joins (plan 007 owns the dedup; here just don't break it).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Import/export tests | `XDEBUG_MODE=off php artisan test --filter='Import|Export|HardwareBulk'` | pass |
| Queue check | `grep -n 'QUEUE_CONNECTION' .env.testing phpunit.xml` | `sync` in tests (keep sync for tests!) |
| Full | `composer test` | pass |

## Scope

**In scope**: the 7 files above + one new Job class + tests.
**Out of scope**: LIKE-escape dedup (plan 007), export-filter JOIN rewrite (plan 007 — coordinate: this plan changes the concern base, that plan changes the filters; run 006 first), notification of completion (DIR adjacent-possible — note as follow-up, don't build).

## Steps

### Step 1: Export → FromQuery streaming

Convert `HardwareExport` (and `HardwareAuditsExport` if same shape) to `FromQuery` + `WithChunkReading`: implement `query()` returning the scoped query, `chunkSize()` (1000), remove `collection()`/`chunkCollection()` fallback. Drop `ShouldAutoSize` on large exports (or keep only for small ones — autosize materializes everything).
**Verify**: export of scoped dataset streams (memory flat-ish); existing export tests pass unchanged.

### Step 2: Import → queued Batch job

Dispatch a queued job from the Livewire component (keep sync behavior under `QUEUE_CONNECTION=sync` so tests/E2E don't change). Inside: `upsert()` in chunks of 500–1000; write audits as ONE bulk insert per chunk; preserve validation-error collection + Persian normalize/escape semantics row-for-row.
**Verify**: fixture CSV imports identical rows + identical audit rows vs before (diff test); timeout-class tests (if any) pass.

### Step 3: Parity tests

Add: chunked-import parity test (row count, audit count, error rows), export memory/streaming smoke test, validation-error equivalence.
**Verify**: full import/export suites green.

## Test plan

- Parity tests + existing `HardwareBulkOperationsTest`, import Livewire tests, export tests.

## Done criteria

- [ ] No `FromCollection` on large exports; `query()` + `chunkSize()` stream
- [ ] Imports run in queued job, chunked upserts, bulk audits
- [ ] Row-for-row parity proven by tests
- [ ] `QUEUE_CONNECTION=sync` preserved in test env
- [ ] No out-of-scope changes

## STOP conditions

- Queue driver unavailable in prod env (no Redis/worker) → stop, report; fallback is sync-chunks only.
- Per-row model events carry side effects that `upsert` skips (observers!) → stop, list the side effects, do not silently drop them.
- Verification fails twice → stop, report.
