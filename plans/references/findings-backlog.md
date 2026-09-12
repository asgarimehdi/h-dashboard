# Findings Backlog (raw audit output)

> Raw, prioritized findings from the 4 `improve` inspectors. Do not edit — this is the evidence base.
> Selected items are promoted to plans (see `../README.md`).

## Security & Correctness (deleg_a68a3a7a)

### [AUTH-01] Zabbix proxy endpoints: no permission/scope gate — High
- `routes/api.php:64-65` `/zabbix/traffic`, `/zabbix/multi-latest` only `auth:sanctum` + `throttle`. `TrafficController:12`, `MultiLatestValueController:14` accept plain `Request`.
- Any authed user proxies arbitrary Zabbix item IDs → SSRF-relay to internal Zabbix.

### [TRAN-01] Multi-write paths lack DB transactions — Medium
- `HardwareController::bulkMark:296-313`, `bulkDelete:342-352`, `HardwareAuditController::restoreRecord:202-228` do multiple writes + `setval` + audits without `DB::transaction`.

### [INPUT-01] Import validation is dead code — Medium
- `HardwareImport:424-447 rules()` never invoked (no `WithValidation`). `PersonImport` has no rules. `n_code` size:10 not enforced on import.

### [INFO-01] Zabbix error leaked to client — Low
- `MultiLatestValueController:32-36` returns `$e->getMessage()` in 500 body.

### [SECRET-01] Weak placeholder creds + many .env files — Low
- `.env.example:58 REDIS_PASSWORD=123`, `:30 ZABBIX_TOKEN=...`. Multiple `.env*` variants in root.

### [IDOR-01] Auth delegated, not centrally enforced — Medium (structural)
- `UnitScopedRequest::authorize()` returns true unconditionally. One forgotten manual check = IDOR.

### [INJECT-01] LIKE wildcards not escaped in Hardware scopes — Low
- `Hardware.php` scopeFilterSearch et al. interpolate `%{$term}%` unescaped (vs export path which escapes).

### [INJECT-02] Raw spatial WKT via PDO::quote in map.blade — Low
- `units/map.blade.php:54,58` `DB::raw("ST_GeomFromGeoJSON(".quote(...).")")`.

### [ERROR-01] Thin error handling; import aborts on first bad row — Low
- Few catch sites; per-row import errors not collected.

## Performance (deleg_1d718357)

### [N+1-01] Ticket monitoring lazy-loads user.person — Low effort
- `tickets/⚡monitoring.blade.php:69` `with(['user:id,n_code','unit:id,name'])` but `:224` renders `user->person`. 20 extra queries/page.

### [N+1-02] Org-chart queries children per node — Medium effort
- `hr/org-chart.blade.php:91,116,138` `Unit::where('parent_id',...)->get()` in foreach.

### [QUERY-03] reports aggregate re-run per render/keystroke — Medium
- `reports/advanced.blade.php:86-184`, `reports/index.blade.php:46-108` + `chartPayload()` double-invocation + 7-filter `$watch`.

### [SCOPE-04] reports/index mounts Unit::all() — unbounded — Low
- `reports/index.blade.php:27` `$this->units = Unit::all()`; scoped `getUnitsProperty()` unused.

### [INDEX-05] hardware filter text columns lack trigram index — Medium
- `ip_valid/ip_local/mac/comments/os/cpu/ram/hdd/net_type` no `gin_trgm_ops`.

### [N+1-06] hr/dashboard vacancies run uncached — Low
- `hr/dashboard.blade.php:77-84` vacancies outside `Cache::remember`.

### [SYNC-07] zabbix:sync fetches + discards — Medium
- `SyncZabbix.php:28-31` fetches `history.get`, only logs count. Every minute.

### [SYNC-08] multi-gauge polls Zabbix synchronously in-request — Medium
- 14 gauges × 30s polling → 14 client→server→Zabbix chains.

### [CACHE-09] GisController::stats COUNT per bbox — Low
### [QUERY-10] distinct() on wide hardwares rows — Low
### [IMPORT-11] imports synchronous in Livewire request — Low
### [INDEX-12] daily_reports.generated_by no index — Low

## Test Coverage (deleg_3f96c297)

### [TESTQUALITY-01] TicketWorkflowTest is 100% dead — High
- `tests/Feature/TicketWorkflowTest.php:18-115` 7 methods without `test_` prefix / `#[Test]` / `@test`. Ticket lifecycle timestamp behavior unverified.

### [MODEL-02] MaintenanceSchedule/TaskActivity/DailyReport zero direct tests — Medium
### [ACCESS-03] Unit::descendantIds CTE tested only depth-1 — High
### [E2E-04] Person import thin E2E (2 tests) vs 18 integration — Medium
### [SERVICE-05] TrafficController/Zabbix error-path untested — Medium
### [MODEL-06] 13 models zero covers() — Low–Medium (Attachment security-relevant)
### [QUALITY-07] Smoke tests assert presence not behavior — Low
### [OBSERVATION-08] Only 1 policy + 1 observer — architectural note

## Tech Debt & Architecture (deleg_88c15df9)

### [DUPLICATION-01] Org-scope filtering copy-pasted 12+ files, 3 divergent patterns — High
- `whereIn('u_id',...)` vs `whereHas('person',...)` vs `whereIn('unit_id',...)`. `HasOrganizationalScope::scopeAccessible()` exists but partially adopted.

### [DUPLICATION-02] Access-check boilerplate 4 divergent idioms — Medium
- `TicketCommentController` 6× verbatim; `assertAccessibleUnit`, `assertAccessible`, `assertAccessibleFromAudit`.

### [DUPLICATION-03] Hardware filter scopes duplicated in blade — Medium
### [DUPLICATION-04] Markdown/URL-sanitize diverges API vs Livewire — High
- XSS fix (Issue #458) only on API path; web path `nl2br(e(...))` only.

### [DUPLICATION-05] restoreAuditValue/parseValueForRestore identical — Low
### [DUPLICATION-06] PersonImport/HardwareImport ~80% identical — Medium (L effort)

### [GOD-OBJECT-01] HardwareAuditController 441 lines, 4 clusters — Medium
### [GOD-OBJECT-02] HardwareIndexHelpers trait 316 lines — Medium (L effort)

### [LAYERING-01] DB/query logic in 16+ single-file blades — Medium (L effort)
- reports, dashboard, tools, hr/dashboard, maps all self-contained SQL.

### [DEAD-CODE-01] TicketAlreadyAcceptedException never thrown — Low/Medium
### [DEAD-CODE-02] reports/index.blade.php orphaned — Low
### [DEAD-CODE-03] welcome/glowingcard scaffold — Low

### [INCONSISTENT-01] 3 return-style contracts for index() — Medium
### [INCONSISTENT-02] abort(403) vs json(403) split — Low
### [MIGRATION-01] pg_trgm down() no-op (intentional) — Low
### [MIGRATION-02] 13 pure-index migrations, no consolidation — Low/Medium