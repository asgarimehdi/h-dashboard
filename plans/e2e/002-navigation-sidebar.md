# Plan 002 — Navigation & Sidebar E2E Tests

**Priority:** P0 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 001

## Why

Sidebar is the primary navigation surface (MaryUI drawer). 43+ routes, 10 menu
categories. A broken link or collapsed section blocks whole modules from users.

## Scenarios (tests/e2e/navigation/)

| # | Case | Expected | Selector hint |
|---|------|----------|---------------|
| 1 | sidebar renders 10 sections | `منابع انسانی`, `مدیریت تیکت‌ها`, `مدیریت سازمان`, `کار با نقشه`, `ابزارهای مدیریتی`, `گزارش‌ها`, `مدیریت`, `راهنما و پشتیبانی`, `پروفایل من`, `تنظیمات` visible | drawer text |
| 2 | each menu item navigates | assert URL + page header matches | `text=<label>` click → URL |
| 3 | section expand/collapse | submenu toggles | MaryUI `<x-menu-sub>` |
| 4 | active state highlight | current page menu highlighted | `.menu-active` / active class |
| 5 | mobile drawer opens | hamburger opens `main-drawer` | `#main-drawer` checkbox |
| 6 | header user dropdown | profile/password/settings/logout links | click `.dropdown` avatar |
| 7 | no broken links (smoke loop) | every href returns non-500 | iterate collected hrefs |

## Files

- `tests/e2e/navigation/sidebar.spec.ts`
- `tests/e2e/navigation/smoke-links.spec.ts` (collect `a[href^="/"]`, GET each, assert != 500)

## Done criteria

```bash
npx playwright test tests/e2e/navigation/ --reporter=list  # all pass
```

## Note

The smoke-links test will legitimately FAIL on `/users/create`, `/users/{id}/edit`,
`/docs` until BUG-001/002/003 are fixed — mark those `test.fixme()` with a comment
pointing at the bug IDs.