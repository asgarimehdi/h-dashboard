# Plan 007 — Units CRUD E2E Tests

**Priority:** P0 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 001

## Why
832 units in a hierarchical tree (parent_id self-ref), 42 pages when paginated. Unit
tree + filters + ticket-acceptance toggle are load-bearing for org scope.

## Scenarios (tests/e2e/organization/)

| # | Case | Expected |
|---|------|----------|
| 1 | units list loads | columns incl. parent, type, region, ticket acceptance |
| 2 | filter by unit type | filters | **Not E2E-testable without code change:** the list page has no type filter — only a name search. Type select exists only in the create/edit modal (unit_type_id), which creates records, so it is not exercised here. |
| 3 | filter by region | filters | **Not E2E-testable without code change:** the list page has no region filter. Province/county selects exist only in the create/edit modal (province_id/region_id), which creates records, so they are not exercised here. |
| 4 | filter by parent unit | filters | **Not E2E-testable without code change:** the list page has no parent filter. Parent select exists only in the create/edit modal (parent_id), which creates records, so it is not exercised here. |
| 5 | toggle ticket acceptance | status toggles + persists |
| 6 | paginate 42 pages | navigation works |
| 7 | `/units/chart` tree view | hierarchy renders + unit select |

## Files
`tests/e2e/organization/units.spec.ts`, `units-tree.spec.ts`

## Done criteria
`npx playwright test tests/e2e/organization/ --reporter=list`

## Escape hatch
Toggle tests mutate data — use `test.beforeEach` to re-toggle back, or mark as
`test.fixme` if a stable read-only assertion can't be isolated.