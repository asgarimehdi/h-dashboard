# 004 — Improve Hardware Search Performance with Query Scopes

## Problem
`HardwareController::index()` builds a complex query with 12+ conditional `where` clauses inline. This makes the controller hard to read and test.

Additionally, the `CONCAT` raw queries for person name search could benefit from the existing `pg_trgm` GIN indexes but are using `LIKE` instead of the `ILIKE` or trigram operators.

## Proposal
1. Move filter logic to `Hardware` model as query scopes:
   - `scopeFilterSearch($query, $term)`
   - `scopeFilterType($query, $type)`
   - `scopeFilterPerson($query, $term)`
   - `scopeFilterUnit($query, $term)`
   - `scopeFilterSemat($query, $term)`
2. Simplify `HardwareController::index()` to chain scopes.
3. Switch person-name/CONCAT `LIKE` lookups to `ILIKE` where safe.
4. Add tests for each scope.

### ⚠️ Implementation constraints (verified against current code)
- `index()` runs on a **joined** query: `Hardware::join('persons', …)->whereIn('persons.u_id', $accessibleIds)->select('hardwares.*')->distinct()`.
  Scopes must be written to work on this joined builder — always qualify ambiguous columns (`hardwares.n_code`, `persons.f_name`, …).
- `scopeFilterUnit` / `scopeFilterSemat` currently use `whereExists` subqueries on `units`/`semats` via `persons.u_id`/`persons.s_id` — preserve that shape.
- `type` filter applies alias mapping (`desktop`→`pc`, `پی‌سی`→`pc`) **before** matching — move that into the scope.
- `search`/`person`/`unit`/`semat` inputs pass through `PersianNormalizer::normalizeForSearch()` — the normalizer call must move with the scope (or be applied before chaining).
- Boolean filters (`shutdown`, `mark`) accept `'true'`/`'1'` strings — keep exact semantics.
- **LIKE → ILIKE caveat:** Persian text has no case, but `pc_name`, `mac`, `ip_*` are latin — `ILIKE` can *widen* results (behavior change, not pure perf). Safer: keep `LIKE` semantics and only verify the pg_trgm GIN indexes are actually used (`EXPLAIN`); the indexes were built for these columns on `persons` fullname forms and `hardwares` fields. If ILIKE is adopted, mirror it in `HardwareExportController` and the Livewire hardware page filters so all three filter surfaces stay consistent.
- Pagination/sort guardrails (`allowedSortColumns`, `per_page` max 100) stay in the controller.

## Files
- `app/Models/Hardware.php`
- `app/Http/Controllers/Api/HardwareController.php`
- `tests/Feature/HardwareApiTest.php`

## Risk: Medium
Query behavior must be identical. Requires thorough test comparison.
