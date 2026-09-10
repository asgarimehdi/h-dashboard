# Plan 005 — Tickets CRUD E2E Tests

**Priority:** P0 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 001

## Why

Tickets are the core workflow (50 tickets, 5 statuses: new/accepted/redirected/
completed/rejected, 3 priorities). The inbox + create flow is the highest-traffic
user path in the app.

## Key UI facts (from QA inventory)

- `/tickets/inbox`: tabs `ورودی‌ها | ارسالی‌ها | همه`, filter tabs `در انتظار | انجام | تکمیل`
- `/tickets/new`: form fields unit (select), priority (select: عادی/متوسط/فوری),
  subject (text), description (textarea), attachment (file)
- `/monitoring`: tabs همه/انتظار/انجام/تکمیل, table with waiting-time column

## Scenarios (tests/e2e/tickets/)

### inbox.spec.ts
| # | Case | Expected |
|---|------|----------|
| 1 | inbox loads | ticket table visible |
| 2 | switch tabs ورودی/ارسالی/همه | content changes |
| 3 | filter در انتظار/انجام/تکمیل | rows filter |
| 4 | ticket shows fields | creator, priority, status, subject, unit |

### new.spec.ts
| # | Case | Expected |
|---|------|----------|
| 1 | create valid ticket | redirect inbox, success toast |
| 2 | create with attachment | ticket + attachment |
| 3 | empty required fields | validation errors |
| 4 | cancel | returns, no ticket created |
| 5 | preselect category via `?category=bug` | category preselected |

### monitoring.spec.ts
| # | Case | Expected |
|---|------|----------|
| 1 | monitoring loads all | full table |
| 2 | filter tabs | rows filter |
| 3 | waiting time shown | days/hours format |

## Files

- `tests/e2e/tickets/inbox.spec.ts`
- `tests/e2e/tickets/new.spec.ts`
- `tests/e2e/tickets/monitoring.spec.ts`

## Done criteria

```bash
npx playwright test tests/e2e/tickets/ --reporter=list  # all pass
```

## Escape hatch

If ticket creation needs a mandatory "receiving unit" that isn't populated for the test
user's scope, STOP and confirm which unit to use before hardcoding a unit id.