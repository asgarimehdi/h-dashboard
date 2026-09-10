# Plan 011 — Dashboard Charts E2E Tests

**Priority:** P1 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 001

## Why
Highcharts (bar + pie) + MaryUI stat cards. Chart rendering is client-side — must be
verified in browser, not just backend values.

## Scenarios (tests/e2e/dashboard/)

| # | Case | Expected |
|---|------|----------|
| 1 | 7 stat cards load | کاربران 318, پرسنل 318, واحدها 832, نقش‌ها 4, تیکت‌ها 50, باز 23, تکمیل 6 |
| 2 | ticket trend bar chart | 30 data points rendered |
| 3 | status pie chart | 5 slices (SVG paths) |
| 4 | task progress 58% | progress bar |
| 5 | recent activity list | entries with user/action/time |
| 6 | "مشاهده همه" navigates `/activity-log` | |

## Files
`tests/e2e/dashboard/stats.spec.ts`, `charts.spec.ts`

## Done criteria
```bash
npx playwright test tests/e2e/dashboard/ --reporter=list
```

## Note
Assert Highcharts via `.highcharts-series *` or count `.highcharts-point` SVG nodes.
Stat values may vary if data changes — assert presence + non-empty, not exact numbers,
except where the count is structurally stable.