# Plan 008 — Hardware CRUD E2E Tests

**Priority:** P1 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 001

## Why
Hardware inventory with 7 quick-filter presets + 12+ advanced filters, bulk actions,
Excel import/export, and an audit trail with field-level rollback. Highest filter
complexity in the app.

## Quick filters (from inventory)
laptops, servers, ram_16gb_plus, ssd_only, powered_on, marked, deleted

## Scenarios (tests/e2e/hardware/)

| # | Case | Expected |
|---|------|----------|
| 1 | list loads | table with hardware columns |
| 2 | each quick filter applies | rows filter |
| 3 | advanced filter toggle opens | panel visible |
| 4 | bulk select + mark/unmark | batch action works |
| 5 | export respects filters | `.xlsx` download |
| 6 | import page loads | file upload |
| 7 | history modal opens | audit trail shown |
| 8 | rollback a field | confirm → value restored + new audit |

## Files
`tests/e2e/hardware/list-filters.spec.ts`, `bulk.spec.ts`, `audit-trail.spec.ts`, `import-export.spec.ts`

## Done criteria
`npx playwright test tests/e2e/hardware/ --reporter=list`

## Escape hatch
Bulk/rollback mutate data. Isolate with a disposable test record created via the UI
itself, or mark `fixme` if no safe mutation path exists.