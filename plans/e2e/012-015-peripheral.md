# Plans 012–015 — Peripheral Modules (P2)

**Base:** `a737a5b` · **Depends:** 003

## 012 — Settings & Profile
- `/settings`: 4 sub-features (email notif, browser notif, auto-refresh 15s/30s/1min/off, compact mode). Test toggle + persist after reload.
- `/profile`: view/update profile fields.
- Files: `tests/e2e/settings/settings.spec.ts`, `tests/e2e/settings/profile.spec.ts`

## 013 — Global Search
- `/search`: min 2 chars, searches tickets/users/units/todos. Test empty state ("حداقل ۲ کاراکتر"), valid search, no-results.
- Files: `tests/e2e/search/global.spec.ts`

## 014 — Activity Log
- `/activity-log`: summary stats (create/edit/delete/login/logout counts), filter tabs, Persian dates.
- Files: `tests/e2e/activity-log/list.spec.ts`

## 015 — Admin Tools
- `/tools`: archive old tickets, clean logs, clean notifications (3 destructive buttons + counts). Test buttons require confirmation; assert count badges render.
- Files: `tests/e2e/tools/admin.spec.ts`

## Done criteria
```bash
npx playwright test tests/e2e/settings tests/e2e/search tests/e2e/activity-log tests/e2e/tools --reporter=list
```

## Note
All destructive actions (archive/clean) MUST assert a confirmation dialog first. Prefer
`test.fixme` over mutating real data in these peripheral tests.