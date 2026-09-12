# Plan 003: Transactions for multi-write paths + import input validation

> **Executor instructions**: Follow step by step. Run every verification command and confirm the expected result before continuing. If a STOP condition occurs, stop and report.

## Status
- **Priority**: P1
- **Effort**: S (two related correctness fixes)
- **Risk**: MEDIUM
- **Depends on**: none
- **Category**: correctness
- **Planned at**: commit `bc1e38e`, 2026-09-12

## Why this matters
Two correctness gaps:

1. **No transactions** on multi-write operations. `HardwareController::bulkMark/bulkDelete` and
   `HardwareAuditController::restoreRecord` perform a write, then a separate audit write, then a
   `setval`, then cache/event flushes — with no `DB::transaction`. A mid-sequence failure leaves
   hardware modified but its audit trail (or rollback record) missing, breaking the
   compliance/traceability guarantee these features exist to provide.

2. **Dead import validation.** `HardwareImport::rules()` is defined but never invoked (the class
   doesn't implement `WithValidation`), and `PersonImport` has no rules at all. Import rows bypass
   the JSON API's field/type constraints (`n_code` `size:10`, integer FK typing), so a crafted
   spreadsheet can insert invalid records.

## Current state
- `app/Http/Controllers/Api/HardwareController.php:296-313` `bulkMark`, `:342-352` `bulkDelete`,
  `:358-381` `batchInsertAudits()`.
- `app/Http/Controllers/Api/HardwareAuditController.php:202-228` `restoreRecord` (write + `setval`
  + observer audit + `recordRollbackAudit`).
- `app/Imports/HardwareImport.php` — implements `ToCollection, WithCustomCsvSettings,
  WithHeadingRow` (`:13`), defines `rules()` at `:424-447` but no `WithValidation`.
- `app/Imports/PersonImport.php` — no `rules()`; writes via `Person::create($data)` at `:409`.

## Commands you will need
| Purpose | Command | Expected |
|---------|---------|----------|
| Test hardware | `XDEBUG_MODE=off php artisan test tests/Feature/` (hardware + import files) | pass |
| Import tests | `XDEBUG_MODE=off php artisan test tests/Feature/PersonImport/` | pass |
| Format | `vendor/bin/pint --dirty` | clean |

## Scope
**In scope**: `app/Http/Controllers/Api/HardwareController.php`,
`app/Http/Controllers/Api/HardwareAuditController.php`,
`app/Imports/HardwareImport.php`, `app/Imports/PersonImport.php`, and matching tests.
**Out of scope**: observers, Livewire import UI, `HardwareIndexHelpers`.

## Git workflow
- Branch off `rebecca`; commit `fix(correctness): ...`.

## Steps

### Step 1: Wrap multi-write paths in transactions
Wrap `bulkMark`, `bulkDelete`, and `restoreRecord` bodies (including the `setval` and audit
writes) in `DB::transaction(function () { ... })`.
**Verify**: `php artisan test` on hardware feature tests still passes; read code to confirm all
side-effecting writes are inside the closure.

### Step 2: Enforce import validation
- Implement `WithValidation` on `HardwareImport` (wire the existing `rules()`), and add `rules()`
  to `PersonImport` mirroring `PersonController`'s store validation (notably `n_code` `size:10` +
  integer FK ids).
- `WithValidation` failures roll back the import batch — confirm that matches intended behavior
  (client already receives `importResults` with errors; keep that contract).
**Verify**: an import with an over-length `n_code` is rejected and surfaces an error, not a row.

### Step 3: Add regression tests
- Transaction: a test (or code-review note) proving partial failure rolls back cleanly.
- Import: a bad-row CSV case asserting rejection for `n_code` length and non-integer FK.
**Verify**: new tests green in `XDEBUG_MODE=off php artisan test`.

## Test plan
- `tests/Feature/PersonImport/PersonImportEdgeCasesTest.php`: add invalid `n_code` / bad-FK rows.
- Hardware import: add a validation-failure case (or extend existing `import-export` suite).

## Done criteria
- `bulkMark`/`bulkDelete`/`restoreRecord` use `DB::transaction`.
- `HardwareImport` implements `WithValidation`; `PersonImport` has `rules()`.
- Invalid `n_code` rows rejected.
- Relevant test files green; Pint clean.

## STOP conditions
- `WithValidation` interacts badly with the two-pass preview/apply loop (it validates on the
  collection import, not the preview) — if the two-pass design can't be preserved, stop and
  propose per-row validation in `processRow` instead.