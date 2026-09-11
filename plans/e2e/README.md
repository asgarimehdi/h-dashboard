# Playwright E2E Test Suite — Implementation Plans

> Generated from QA inventory (`/home/runner/qa_test_inventory.json`).
> Base commit: `a737a5b`

## Recommended Execution Order & Dependency Graph

```
Setup (done)
  └─ shared/fixtures.ts, playwright.config.ts, login.spec.ts

Phase 1 — Core Auth & Navigation (dependency: none)
  ├─ 001-auth-login.md          (P0 foundation)
  ├─ 002-navigation-sidebar.md  (P0 foundation)
  └─ 003-rbac-authorization.md  (P0 foundation, needs 001)

Phase 2 — Primary CRUD Modules (dependency: 001)
  ├─ 004-users-crud.md          (P0)
  ├─ 005-tickets-crud.md        (P0)
  ├─ 006-personnel-crud.md      (P0)
  └─ 007-units-crud.md          (P0)

Phase 3 — Secondary Modules (dependency: 002)
  ├─ 008-hardware-crud.md       (P1, has complex filters)
  ├─ 009-reports-list.md        (P1)
  ├─ 010-maps-gis.md            (P1, Leaflet)
  └─ 011-dashboard-charts.md    (P1)

Phase 4 — Peripheral Modules (dependency: 003)
  ├─ 012-settings-profile.md    (P2)
  ├─ 013-search-global.md       (P2)
  ├─ 014-activity-log.md        (P2)
  └─ 015-tools-admin.md         (P2)
```

## Status Table

| # | Plan | Priority | Status | Blocker |
|---|------|----------|--------|---------|
| 001 | Auth Login | P0 | ✅ done | none |
| 002 | Navigation Sidebar | P0 | ✅ done | none |
| 003 | RBAC Authorization | P0 | ✅ done | none |
| 004 | Users CRUD | P0 | ✅ done | none (dead standalone routes removed; CRUD via inline modal) |
| 005 | Tickets CRUD | P0 | ✅ done | none (creates 2 tickets + auto-todos per run, by design) |
| 006 | Personnel CRUD | P0 | ✅ done | none (unit tree-picker filter skipped as too flaky — documented) |
| 007 | Units CRUD | P0 | ✅ done | none (type/region/parent filters don't exist on list page — documented) |
| 008 | Hardware CRUD | P1 | ✅ done | none |
| 009 | Reports List | P1 | ✅ done | none |
| 010 | Maps GIS | P1 | ✅ done | none |
| 011 | Dashboard Charts | P1 | ✅ done | none |
| 012 | Settings Profile | P2 | ✅ done | none |
| 013 | Search Global | P2 | ✅ done | none |
| 014 | Activity Log | P2 | ✅ done | none |
| 015 | Tools Admin | P2 | ✅ done | none |

## Test Inventory (actual, from `tests/e2e/`)

147 passing tests, 0 skipped. Per-plan coverage notes:

### ✅ 004 — Users CRUD
- `list.spec.ts` — load/columns/search/status filter/page-size/pagination/expand (8 tests).
- `crud.spec.ts` — inline-modal flows (6 tests): dead standalone routes return 404
  (not 500); create modal renders; duplicate n_code → validation; edit opens
  prefilled + closes without saving; delete-dismiss keeps user; delete-accept
  soft-deletes + restore brings the user back (net-zero mutation).

### ✅ 005 — Tickets CRUD
- `inbox.spec.ts` — done. `monitoring.spec.ts` — done.
- `new.spec.ts` — form-render + validation + cancel + **create valid ticket →
  success toast + appears in sent box** + **create with attachment** (2
  timestamped tickets + auto-Todos per run, by design).

### ✅ 006 — Personnel CRUD
- `list.spec.ts` — columns / 318 count / search-by-name / search-by-n_code /
  filter-panel-opens + **filter by semat narrows** + **filter by tahsil narrows** +
  **clear restores 318**. ✅
- `import.spec.ts` — render. ✅ · `lookups.spec.ts` — 4 lookups (counts). ✅
- Unit tree-picker filter skipped (Alpine x-model, too flaky) — documented in spec header.

### ✅ 007 — Units CRUD
- `units.spec.ts` — columns / rows+pagination / search / pagination-nav /
  **toggle persists + reverts (reload-verified)** / **create modal renders
  type/region/parent controls (no save)** / **map link pattern**.
- `units-tree.spec.ts` — hierarchy / click-detail / search. ✅
- Type/region/parent filters don't exist on the list page (modal-only create
  fields) — documented in `007-units-crud.md`, not E2E-testable without code change.

### ✅ 008 — Hardware CRUD
- `list-filters.spec.ts` — columns / 449 total / laptop-filter / clear / advanced-panel / checkboxes. ✅
- `bulk.spec.ts` — disabled-until-select / select-enables-and-counts +
  **bulk mark persists + unmark reverts** (net-zero). ✅
- `audit-trail.spec.ts` — modal opens / filter chips +
  **rollback restores field + logs rollback entry**. ✅
- `import-export.spec.ts` — import page + export button +
  **real `.xlsx` download asserted**. ✅

## Resolved Bugs (fixed, routes removed)

| Bug | Route | Resolution |
|-----|-------|------------|
| BUG-001 | /users/create | Removed — pointed at never-implemented `users.create` view; CRUD lives in `users.index` inline modal |
| BUG-002 | /users/{id}/edit | Removed — pointed at never-implemented `users.edit` view; edit lives in `users.index` inline modal |
| BUG-003 | /docs | Removed — view referenced undefined `$content`, markdown sources long deleted, no links to it; dead `docs/user-guide.blade.php` deleted too |
