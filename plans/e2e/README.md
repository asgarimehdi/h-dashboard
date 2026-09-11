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
| 005 | Tickets CRUD | P0 | 🟡 partial | destructive-only path untested |
| 006 | Personnel CRUD | P0 | 🟡 partial | unit/semat filter not exercised |
| 007 | Units CRUD | P0 | 🟡 partial | type/region/parent filters + persist toggle |
| 008 | Hardware CRUD | P1 | 🟡 partial | bulk persist, rollback, real export |
| 009 | Reports List | P1 | ✅ done | none |
| 010 | Maps GIS | P1 | ✅ done | none |
| 011 | Dashboard Charts | P1 | ✅ done | none |
| 012 | Settings Profile | P2 | ✅ done | none |
| 013 | Search Global | P2 | ✅ done | none |
| 014 | Activity Log | P2 | ✅ done | none |
| 015 | Tools Admin | P2 | ✅ done | none |

## Test Inventory (actual, from `tests/e2e/`)

130 passing tests, 7 skipped (`.fixme`). Below is the per-plan gap list — what
is **NOT written** or **NOT fully covered** relative to each plan's scenario table.

### 🟡 004 — Users CRUD
- `list.spec.ts` — fully implemented (load/columns/search/status filter/page-size/pagination/expand). ✅
- `crud.spec.ts` — **all 7 cases written as `test.fixme`** (create valid/empty/duplicate, edit, delete×2). Skipped, not passing, blocked by **BUG-001** (`/users/create` → 500) and **BUG-002** (`/users/{id}/edit` → 500). Nothing to do until the two Livewire views (`users.create`, `users.edit`) are implemented.

### 🟡 005 — Tickets CRUD
- `inbox.spec.ts` — done. `monitoring.spec.ts` — done.
- `new.spec.ts` — **form-render + validation + cancel written**, but the plan's destructive happy-path cases are **NOT written** (kept non-destructive so each run doesn't add a ticket + auto-Todo):
  - ❌ create valid ticket → success toast + appears in inbox
  - ❌ create with attachment
  - ❌ preselect via `?category=bug`

### 🟡 006 — Personnel CRUD
- `list.spec.ts` — columns / 318 count / search-by-name / search-by-n_code / filter-panel-opens. ✅
- `import.spec.ts` — render. ✅ · `lookups.spec.ts` — 4 lookups (counts). ✅
- ❌ **filter by unit/semat** — plan scenario #5 not exercised (only the filter panel opening is asserted, no actual filter application).

### 🟡 007 — Units CRUD
- `units.spec.ts` — columns / rows+pagination / search / pagination-nav / toggle-buttons-render.
- `units-tree.spec.ts` — hierarchy / click-detail / search. ✅
- ❌ **filter by unit-type** (scenario #2)
- ❌ **filter by region** (scenario #3)
- ❌ **filter by parent unit** (scenario #4)
- ❌ **toggle ticket-acceptance *persists*** (scenario #5) — buttons render but the toggle→reload→persist loop is not asserted (mutation avoided).

### 🟡 008 — Hardware CRUD
- `list-filters.spec.ts` — columns / 449 total / laptop-filter / clear / advanced-panel / checkboxes. ✅
- `bulk.spec.ts` — buttons-disabled-until-select / select-enables-and-counts. ⚠️ UI state only.
- `audit-trail.spec.ts` — modal opens / filter chips. ⚠️ render only.
- `import-export.spec.ts` — import page + export button present. ⚠️ render only.
- ❌ **bulk mark/unmark batch persist** (scenario #4) — not asserted (state machine only)
- ❌ **export respects filters → real `.xlsx` download** (scenario #5) — button presence only, no download assertion
- ❌ **rollback a field → value restored + new audit** (scenario #8) — modal open only, no rollback

## Known Bugs Blocking Tests

> **All resolved 2026-09-11.**

| Bug | Route | Severity | Plan impacted | Resolution |
|-----|-------|----------|---------------|------------|
| ~~BUG-001~~ | /users/create | Critical | 004 | Redirect to /users (shared form) |
| ~~BUG-002~~ | /users/{id}/edit | Critical | 004 | Redirect to /users (shared form) |
| ~~BUG-003~~ | /docs | Medium | none | Pass $content to view + self-contained layout |