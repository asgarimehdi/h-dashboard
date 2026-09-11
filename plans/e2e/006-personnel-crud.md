# Plan 006 — Personnel CRUD E2E Tests

**Priority:** P0 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 001

## Why
318 personnel records, linked 1:1 to users by `n_code`. Data integrity between persons
and users is a core invariant.

## Scenarios (tests/e2e/personnel/)

| # | Case | Expected |
|---|------|----------|
| 1 | list loads | columns: کد ملی | نام | نام خانوادگی | تحصیلات | استخدام | سمت | ردیف | واحد |
| 2 | count matches users | 318 records |
| 3 | search by name | filters |
| 4 | search by n_code | filters |
| 5 | filter by unit/semat | filters |
| 6 | import page loads | file upload + preview button |

## Lookup tables (list-only, verify render + pagination)
- `/kargozini/estekhdams` (5) · `/kargozini/tahsils` (6) · `/kargozini/radifs` (55) · `/kargozini/semats` (56)

## Files
`tests/e2e/personnel/list.spec.ts`, `import.spec.ts`, `lookups.spec.ts`

## Done criteria
`npx playwright test tests/e2e/personnel/ --reporter=list`