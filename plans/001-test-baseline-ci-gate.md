# Plan 001: Test Baseline = One-Command CI Gate

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- composer.json tests/Pest.php tests/Feature/UnitTicketCapabilityTest.php tests/Feature/ItLivewireTest.php tests/Feature/HardwareExportTest.php tests/Feature/ReportApiTest.php tests/Feature/ReportsApiTest.php tests/Feature/ExampleTest.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P1 (unblocks everything — no green floor, no safe refactors)
- **Effort**: M
- **Risk**: MED (touches shared test config + duplicate suites)
- **Depends on**: none (do FIRST)
- **Category**: tests
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

`composer test` runs plain `php artisan test`, but CI gates on `pest --parallel --coverage --min=80` (`.github/workflows/test.yml:129`). Devs get local green then fail CI on coverage/parallel ordering. Placeholder asserts (`assertTrue(true)`) and hardcoded UI-data counts inflate/brittle the suite. Two report-API suites cover the same routes with different fixtures and will diverge.

## Current state

- `composer.json` `"test"`: `config:clear`, `route:clear`, `XDEBUG_MODE=off php artisan test` (no parallel, no coverage gate).
- `tests/Feature/UnitTicketCapabilityTest.php:114`: `$this->assertTrue(true); // Should not throw`.
- `tests/Feature/ItLivewireTest.php:73`: `assertCount(25, $items)` + `assertEquals('فیبر اصلی', …)` + `assertEquals('73638', …)`.
- `tests/Feature/HardwareExportTest.php:452,528`: `assertCount(4/2, $headings)`.
- `tests/Feature/ReportApiTest.php:48-115` and `tests/Feature/ReportsApiTest.php:55-136`: both cover `GET /api/reports/units|todos|tickets`.
- `tests/Pest.php:14-20`: documents double-`RefreshDatabase` transaction race under parallel. `tests/Feature/ExampleTest.php:5`: lone file without `RefreshDatabase`.
- Repo gotchas: `CACHE_STORE` (not `CACHE_DRIVER`); always `route:clear` before tests; guest hardware page must stay 302 → /login.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Full suite (repo way) | `composer test` | all pass |
| CI-equivalent | `./vendor/bin/pest --parallel --coverage --min=80` | ≥80%, pass |
| Lint | `vendor/bin/pint --test` | exit 0 (Blade SFCs excluded per `.pint.json` — do not "fix" by adding Blade) |

## Scope

**In scope**: `composer.json`, `tests/Pest.php`, `tests/Feature/UnitTicketCapabilityTest.php`, `tests/Feature/ItLivewireTest.php`, `tests/Feature/HardwareExportTest.php`, `tests/Feature/ReportApiTest.php`, `tests/Feature/ReportsApiTest.php`, `tests/Feature/ExampleTest.php`, `.github/workflows/test.yml` (grep-guard job only).
**Out of scope**: any `app/`, `resources/`, `routes/`, `database/` source; E2E files (plan 002); Zabbix/mutation work (plan 003 has TEST-09/10? no — TEST-09/10 live in plan 003; do NOT touch).

## Steps

### Step 1: Add `test:ci` + `check` composer scripts

Add (exact keys; keep existing `test` untouched):
```json
"test:ci": ["@php artisan config:clear", "@php artisan route:clear", "XDEBUG_MODE=off ./vendor/bin/pest --parallel --coverage --min=80"],
"check": ["@php vendor/bin/pint --test", "@php phpstan analyse --no-progress", "@composer test:ci"]
```
**Verify**: `composer test:ci 2>&1 | tail -5` → coverage ≥80%, all pass. (Needs DB per AGENTS.md test setup; if DB missing, STOP.)

### Step 2: Parallel-safety guard + ExampleTest

Add CI grep guard in `test.yml` (new step before tests): `grep -rn '^uses(.*RefreshDatabase' tests/Pest.php && exit 1 || echo OK` → expect OK. Delete `tests/Feature/ExampleTest.php` OR tag it non-parallel per file header comment if deletion breaks CI file-count assumptions.
**Verify**: guard step passes; `composer test` still green.

### Step 3: Placeholder assert → real behavior

In `UnitTicketCapabilityTest.php:114`, replace `assertTrue(true)` with: `expect(Unit::find(99999))->toBeNull()` + `assertDatabaseMissing('units', ['id' => 99999])`, or drop the test if it covers nothing.
**Verify**: `./vendor/bin/pest --filter=UnitTicketCapability` → pass.

### Step 4: Hardcoded counts → shape asserts

`ItLivewireTest.php:73`: replace `assertCount(25…)` + exact Persian strings/numbers with `assertNotEmpty` + key presence + pin ONE canonical row. `HardwareExportTest.php:452,528`: assert heading keys present, not exact counts.
**Verify**: `./vendor/bin/pest --filter='ItLivewire|HardwareExport'` → pass.

### Step 5: Merge duplicate report suites

Merge `ReportApiTest` into `ReportsApiTest` (keep auth+scope+shape+Jalali `by_day` coverage from both), delete the emptied file.
**Verify**: `./vendor/bin/pest --filter='Report'` → pass; `grep -rn 'ReportApiTest' tests/ | head` shows no stale references.

## Test plan

- Full: `composer test:ci` → pass, coverage ≥80%.
- Existing suites used as patterns: `TicketApiTest.php:156-251` (lifecycle + negatives — protect, do not weaken).

## Done criteria

- [ ] `composer test:ci` passes at ≥80% coverage
- [ ] CI has parallel-safety grep guard
- [ ] No `assertTrue(true)` placeholder remains (`grep -rn 'assertTrue(true)' tests/` empty)
- [ ] No hardcoded full-cardinality UI counts in the two files above
- [ ] One report-API suite only
- [ ] No files outside scope modified

## STOP conditions

- DB unavailable for tests (no PostGIS/Redis per AGENTS.md) → stop, report env gap.
- Coverage <80% after merge (suites were double-counting) → stop, report numbers per suite.
- Any step verification fails twice → stop, report.
