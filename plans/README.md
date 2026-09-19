# Deep Audit — h-dashboard

> **Audit date:** 2026-09-19
> **Branch:** rebecca (synced with origin/beta)
> **Auditor:** Rebecca (Hermes Agent)
> **Method:** `/improve deep` — 4 parallel subagents covering 9 audit categories

## Summary

| Category | Findings | P1 | P2 | P3 |
|----------|----------|----|----|-----|
| Security | 11 | 4 | 3 | 4 |
| Correctness | 8 | 2 | 3 | 3 |
| Performance | 13 | 0 | 4 | 9 |
| Test Coverage | 8 | 0 | 1 | 7 |
| Tech Debt/Arch | 8 | 2 | 3 | 3 |
| Direction/Features | 18 | 0 | 4 | 14 |
| **Total** | **66** | **8** | **18** | **40** |

## Priority Definitions

- **P1** — Security risk, data integrity risk, or auth inconsistency. Fix before any feature work.
- **P2** — Performance degradation, test gap, or tech debt with high leverage (low effort, clear fix).
- **P3** — Nice-to-have improvement, cleanup, or feature gap.

## Plans Index

| Plan | Title | Priority | Effort | Risk |
|------|-------|----------|--------|------|
| [001](001-fix-security-headers.md) | Fix security headers (CSP, HSTS, remove XSS-Protection) | P1 | M | MED |
| [002](002-fix-hardcoded-creds-and-token-expiry.md) | Remove hardcoded DB creds + reduce Sanctum token expiry | P1 | S | LOW |
| [003](003-fix-rejectTicket-auth-inconsistency.md) | Fix rejectTicket() auth to use AccessService | P1 | S | LOW |
| [004](004-fix-like-wildcard-injection.md) | Centralize LIKE wildcard escaping in normalizeForSearch() | P1 | S | LOW |
| [005](005-fix-ticket-model-casts-and-deprecated-dates.md) | Add Ticket $casts + remove deprecated User.$dates | P2 | S | LOW |
| [006](006-fix-zabbix-error-leakage.md) | Hide Zabbix errors from API clients + fix CORS max_age | P2 | S | LOW |
| [007](007-fix-n-plus-one-queries.md) | Fix N+1 queries (ticket inbox, org-node, comment notification) | P2 | S | LOW |
| [008](008-cleanup-dead-code-and-stub-routes.md) | Remove dead code: glowingcard, register view, index redirect, commented routes | P2 | S | LOW |
| [009](009-fix-cors-and-env-config.md) | Fix CORS localhost patterns + .env.example APP_DEBUG | P2 | S | LOW |
| [010](010-add-maintenance-schedule-ui.md) | Add MaintenanceSchedule CRUD UI | P3 | M | LOW |
| [011](011-add-notification-api-for-mobile.md) | Add Notification API endpoints for Flutter | P3 | S | LOW |
| [012](012-queue-heavy-operations.md) | Queue bulk delete, import, and scheduled commands | P3 | M | MED |
| [013](013-add-missing-test-coverage.md) | Add Todo Livewire test + expand e2e coverage | P3 | H | LOW |
| [014](014-consolidate-maps-route-versions.md) | Merge maps/route and maps/route2 into single component | P3 | M | LOW |
