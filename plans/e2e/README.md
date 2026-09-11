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
  ├─ 004-users-crud.md          (P0, currently 500 ERROR)
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
| 004 | Users CRUD | P0 | ✅ done (tests: fixme) | none — bugs resolved, fixme tests pending |
| 005 | Tickets CRUD | P0 | ✅ done (tests: fixme) | destructive happy-path kept non-destructive |
| 006 | Personnel CRUD | P0 | ✅ done | none |
| 007 | Units CRUD | P0 | ✅ done (tests: fixme) | toggle persistence fixme (data mutation) |
| 008 | Hardware CRUD | P1 | ✅ done (tests: fixme) | bulk/rollback fixme (data mutation) |
| 009 | Reports List | P1 | ✅ done | none |
| 010 | Maps GIS | P1 | ✅ done | none |
| 011 | Dashboard Charts | P1 | ✅ done | none |
| 012 | Settings Profile | P2 | ✅ done | none |
| 013 | Search Global | P2 | ✅ done | none |
| 014 | Activity Log | P2 | ✅ done | none |
| 015 | Tools Admin | P2 | ✅ done | none |

## Test Inventory (actual, from `tests/e2e/`)

> **Last updated:** 2026-09-11 — 153 passing tests, 6 skipped (`.fixme`).

### ✅ 005 — Tickets CRUD
- `inbox.spec.ts` — fully implemented (load/tabs/filters/row-fields). ✅
- `monitoring.spec.ts` — fully implemented (load/filters/waiting-time). ✅
- `new.spec.ts` — form-render + validation + cancel. ✅ · Destructive happy-path cases (create valid, create with attachment, preselect category) marked `test.fixme` — they add a ticket+todo on every run.

### ✅ 006 — Personnel CRUD
- `list.spec.ts` — columns / 318 count / search-by-name / search-by-n_code / filter-panel-opens / **filter-by-semat**. ✅
- `import.spec.ts` — render. ✅ · `lookups.spec.ts` — 4 lookups (counts). ✅

### ✅ 007 — Units CRUD
- `units.spec.ts` — columns / rows+pagination / search / pagination-nav / toggle-buttons-render / **edit-modal-opens** / **edit-modal-shows-unit_type+parent-labels**. ✅
- `units-tree.spec.ts` — hierarchy / click-detail / search. ✅
- ❌ **toggle ticket-acceptance *persists*** — marked `test.fixme` (data mutation, unreliable in CI)

### ✅ 008 — Hardware CRUD
- `list-filters.spec.ts` — columns / 449 total / laptop-filter / clear / advanced-panel / checkboxes. ✅
- `bulk.spec.ts` — buttons-disabled / select-enables-and-counts / **multi-select-count** / **deselect-disables**. ✅
- `audit-trail.spec.ts` — modal-opens / filter-chips / **modal-closes** / **rollback-button-visible**. ✅
- `import-export.spec.ts` — import-page / export-button / **export-triggers-download**. ✅
- ❌ **bulk mark/unmark persist** — marked `test.fixme` (data mutation)
- ❌ **rollback execution** — marked `test.fixme` (data mutation)

## Known Bugs Blocking Tests

> **All resolved 2026-09-11.**

| Bug | Route | Severity | Plan impacted | Resolution |
|-----|-------|----------|---------------|------------|
| ~~BUG-001~~ | /users/create | Critical | 004 | Redirect to /users (shared form) |
| ~~BUG-002~~ | /users/{id}/edit | Critical | 004 | Redirect to /users (shared form) |
| ~~BUG-003~~ | /docs | Medium | none | Pass $content to view + self-contained layout |
