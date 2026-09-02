# h-dashboard Improvement Plans

> **Audit:** `/improve deep` — 2026-09-02, branch `tannaz`
> **Planned at:** commit `cf3cf9c`
> **Total:** 29 plans (156 KB)

## Execution Order

Plans are grouped into phases. Within each phase, plans are independent unless marked with ⚠️.

### Phase 1 — Quick Wins (security + correctness, S effort)
Execute first. Low risk, high impact per minute.

| Plan | Finding | Category | Status |
|------|---------|----------|--------|
| [001](PLAN-001-fix-ticket-code-collision.md) | Ticket code collision (uniqid → Str::random) | Bug | TODO |
| [004](PLAN-004-fix-api-priority-validation-mismatch.md) | API priority validation vs DB enum mismatch | Bug | TODO |
| [007](PLAN-007-fix-restore-audit-value-type-mismatch.md) | restoreAuditValue casts ram/vlan/port to int | Bug | TODO |
| [008](PLAN-008-fix-cors-restrict-origins.md) | CORS wildcard + credentials enabled | Security | TODO |
| [009](PLAN-009-fix-logout-csrf-get-to-post.md) | Logout route is GET (CSRF-vulnerable) | Security | TODO |
| [010](PLAN-010-add-sanctum-token-expiration.md) | Sanctum tokens never expire | Security | TODO |
| [011](PLAN-011-enable-session-encryption-secure-cookie.md) | Session not encrypted, cookie not secure | Security | TODO |
| [012](PLAN-012-fix-stored-xss-audit-diff-html.md) | Stored XSS in hardware audit diff HTML | Security | TODO |
| [015](PLAN-015-remove-unused-packages.md) | Remove verta + openai-php/client | Deps | TODO |
| [016](PLAN-016-fix-bulk-ops-cache-bypass.md) | Bulk ops bypass CacheInvalidationService | Perf | TODO |
| [029](PLAN-029-fix-duplicate-migration-timestamp.md) | Duplicate migration timestamp 2025_12_26_000002 | Migration | TODO |

### Phase 2 — Correctness (bug fixes, S-M effort)
Fix real logic bugs. Some are interdependent.

| Plan | Finding | Category | Status | Depends on |
|------|---------|----------|--------|------------|
| [002](PLAN-002-fix-accept-race-condition.md) | Ticket accept race condition | Bug | TODO | — |
| [003](PLAN-003-fix-hardcoded-old-status-in-accept-ticket.md) | Hardcoded old status in activity log | Bug | TODO | ⚠️ with 002 (same file) |
| [005](PLAN-005-fix-bulk-complete-missing-task-auto-complete.md) | Bulk complete misses task auto-complete | Bug | TODO | — |
| [006](PLAN-006-fix-todo-calendar-drag-drop-creates.md) | Todo calendar drag creates instead of updates | Bug | TODO | — |
| [017](PLAN-017-fix-n1-loadDeletedHardware.md) | N+1 query in loadDeletedHardware | Perf | TODO | — |
| [018](PLAN-018-fix-hardware-export-memory.md) | HardwareExport loads all into memory | Perf | TODO | — |

### Phase 3 — Data Integrity (migrations, S-M effort)
⚠️ Migrations must be executed in order. 013 before 014.

| Plan | Finding | Category | Status | Depends on |
|------|---------|----------|--------|------------|
| [013](PLAN-013-fix-activity-logs-cascade-delete.md) | activity_logs CASCADE deletes audit trail | Migration | TODO | — |
| [014](PLAN-014-fix-missing-fk-persons-u-id.md) | Missing FK on persons.u_id | Migration | TODO | — |

### Phase 4 — Performance Optimization (M effort)

| Plan | Finding | Category | Status | Depends on |
|------|---------|----------|--------|------------|
| [019](PLAN-019-optimize-dashboard-queries.md) | Dashboard runs 14+ queries on cold cache | Perf | TODO | — |
| [020](PLAN-020-deduplicate-accessibleIds-unitScopedRequest.md) | 35× duplicated accessibleIds auth pattern | TechDebt | TODO | — |

### Phase 5 — Architecture (M-L effort)
⚠️ Plan 021 should land before 023 and 022 (reduces merge conflicts).

| Plan | Finding | Category | Status | Depends on |
|------|---------|----------|--------|------------|
| [021](PLAN-021-split-HrController.md) | HrController God class (596 lines) | TechDebt | TODO | — |
| [022](PLAN-022-fix-gis-cross-controller-coupling.md) | GisController cross-controller coupling | TechDebt | TODO | — |
| [023](PLAN-023-decompose-hardware-index.md) | hardware/index.blade.php 1366 lines | TechDebt | TODO | ⚠️ after 020 |

### Phase 6 — DX & Tooling (M effort)
Independent of code changes; can be parallelized.

| Plan | Finding | Category | Status | Depends on |
|------|---------|----------|--------|------------|
| [024](PLAN-024-add-phpstan-level-6.md) | No static analysis configured | DX | TODO | — |
| [025](PLAN-025-enforce-pint-ci-precommit.md) | Pint not enforced in CI or pre-commit | DX | TODO | — |
| [026](PLAN-026-gate-deploy-on-tests.md) | Deploy doesn't gate on tests | DX | TODO | — |
| [027](PLAN-027-rewrite-readme-bilingual.md) | README Persian-only, broken references | Docs | TODO | — |
| [028](PLAN-028-add-sentry-error-tracking.md) | No error tracking in production | DX | TODO | — |

---

## Dependency Graph

```
Phase 1 (quick wins):      All independent, execute in any order
Phase 2 (correctness):     002 → 003 (same file, do together)
Phase 3 (migrations):      013, 014 independent
Phase 4 (perf):            019, 020 independent
Phase 5 (architecture):    021 before 023; 020 before 023
Phase 6 (DX):              All independent
```

## Considered and Rejected

| Finding | Reason |
|---------|--------|
| SyncZabbix fetches but never stores | Zabbix-related — excluded per user request |
| Direction findings (D1-D6) | Excluded per user request |
| Hardware::$suppressAudit concurrency | Correct under PHP-FPM (per-request process) |
| SafeRoleOrPermission bypasses auth | By design — documented in AGENTS.md |
| ValidateUnitContext passes unauthenticated | Correct — always behind auth middleware |
| Login dummy hash | Not a real credential — bcrypt of "password" for constant-time comparison |

## Status Legend

- **TODO** — Not started
- **IN PROGRESS** — Being worked on
- **DONE** — Implemented and verified
- **BLOCKED** — Cannot proceed (dependency or environment issue)
- **REJECTED** — Found to be invalid after deeper review
