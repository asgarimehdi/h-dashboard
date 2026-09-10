# Plan 004 — Users CRUD E2E Tests

**Priority:** P0 · **Base:** `a737a5b` · **Status:** 🚫 BLOCKED

## Blocker

`/users/create` and `/users/{id}/edit` both return **500 Server Error** (BUG-001,
BUG-002). Create/Update user flows cannot be tested until fixed. The list page
(`/users`) works and is testable now.

## What IS testable now (tests/e2e/users/list.spec.ts)

| # | Case | Expected |
|---|------|----------|
| 1 | users list loads | table, 20 rows/page, columns # | نام | کد ملی | واحد اصلی | نقش‌ها | وضعیت |
| 2 | search by name | filters rows (Livewire `.live.debounce`) |
| 3 | filter active/inactive | status select filters |
| 4 | search by unit | unit select filters |
| 5 | page size 10/20/50/100 | row count changes |
| 6 | paginate next/prev | page changes |
| 7 | expand row shows roles | expansion opens |

## What needs the bug fixed (tests/e2e/users/crud.spec.ts)

| # | Case |
|---|------|
| 1 | open create form (no 500) |
| 2 | create valid user → success + appears in list |
| 3 | create with empty n_code → validation |
| 4 | create with duplicate n_code → validation |
| 5 | edit user → form loads → save → changes persist |
| 6 | delete user → confirm modal → cancel keeps |
| 7 | delete user → confirm → removed |

Write these as `test.fixme('BUG-001/002')` so they report as skipped, not failing,
until the bugs are fixed.

## Files

- `tests/e2e/users/list.spec.ts` (implement NOW)
- `tests/e2e/users/crud.spec.ts` (skipped pending fix)

## Done criteria

```bash
npx playwright test tests/e2e/users/list.spec.ts --reporter=list  # pass
# crud.spec.ts: report all fixme (skipped) until bug resolved
```