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
3. Consider adding an `ILIKE` variant for case-insensitive search on PostgreSQL.
4. Add tests for each scope.

## Files
- `app/Models/Hardware.php`
- `app/Http/Controllers/Api/HardwareController.php`
- `tests/Feature/HardwareApiTest.php`

## Risk: Medium
Query behavior must be identical. Requires thorough test comparison.
