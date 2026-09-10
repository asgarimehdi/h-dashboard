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
| 001 | Auth Login | P0 | 📝 planned | none |
| 002 | Navigation Sidebar | P0 | 📝 planned | none |
| 003 | RBAC Authorization | P0 | 📝 planned | 001 |
| 004 | Users CRUD | P0 | 🚫 blocked | BUG-001 (500) |
| 005 | Tickets CRUD | P0 | 📝 planned | none |
| 006 | Personnel CRUD | P0 | 📝 planned | none |
| 007 | Units CRUD | P0 | 📝 planned | none |
| 008 | Hardware CRUD | P1 | 📝 planned | none |
| 009 | Reports List | P1 | 📝 planned | none |
| 010 | Maps GIS | P1 | 📝 planned | none |
| 011 | Dashboard Charts | P1 | 📝 planned | none |
| 012 | Settings Profile | P2 | 📝 planned | none |
| 013 | Search Global | P2 | 📝 planned | none |
| 014 | Activity Log | P2 | 📝 planned | none |
| 015 | Tools Admin | P2 | 📝 planned | none |

## Known Bugs Blocking Tests

| Bug | Route | Severity | Plan impacted |
|-----|-------|----------|---------------|
| BUG-001 | /users/create | Critical | 004 |
| BUG-002 | /users/{id}/edit | Critical | 004 |
| BUG-003 | /docs | Medium | none |