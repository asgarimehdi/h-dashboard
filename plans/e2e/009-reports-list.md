# Plan 009 — Reports E2E Tests

**Priority:** P1 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 002

## Why
5 report pages aggregate unit/personnel/todo/ticket data. Chart + table rendering from
backend aggregates needs browser verification (charts are client-side).

## Scenarios (tests/e2e/reports/)

| # | Case | Expected |
|---|------|----------|
| 1 | `/reports/units` | unit-type filter (18 types) + table loads |
| 2 | `/reports/persons` | personnel summary renders |
| 3 | `/reports/todos` | todo stats render |
| 4 | `/reports/tickets` | ticket stats render |
| 5 | `/reports/map-no-boundary` | units-without-boundary list (290) |

## Files
`tests/e2e/reports/units.spec.ts`, `tickets-todos.spec.ts`, `map-no-boundary.spec.ts`

## Done criteria
`npx playwright test tests/e2e/reports/ --reporter=list`