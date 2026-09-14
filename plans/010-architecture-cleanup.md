# Plan 010: Architecture Cleanup (scopes, cache keys, god components, events)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- app/Traits/HasOrganizationalScope.php app/Http/Requests/UnitScopedRequest.php app/Services/CacheInvalidationService.php app/Services/AccessService.php app/Models/Hardware.php app/Listeners/HardwareGisCacheListener.php app/Events/HardwareUpdated.php app/Providers/EventServiceProvider.php "resources/views/livewire/tickets/⚡inbox.blade.php" resources/views/livewire/hardware/index.blade.php resources/views/livewire/dashboard.blade.php resources/views/livewire/tools/tools.blade.php app/Traits/HardwareIndexHelpers.php app/Http/Controllers/Api/HardwareController.php app/Http/Controllers/Api/HardwareAuditController.php app/Http/Controllers/Api/TicketController.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P3 (structural; pays down every future plan's risk)
- **Effort**: L (largest plan; consider splitting per-step execution across sessions)
- **Risk**: HIGH (touches 30+ blades + invalidation paths; GIS staleness if wrong)
- **Depends on**: 001, 004 (needs green baseline + stable ticket semantics first)
- **Category**: tech-debt
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

Three parallel access-scope mechanisms (~30 raw call sites), two cache-key schemes with divergent TTLs, triple GIS invalidation (API double-bumps, web relies on model boot), 900-line Volt components, a 316-line trait used once, and ticket side effects emitted from five places with no domain event. Every future feature re-pays this tax.

## Current state (all verified by audit; re-verify live before editing)

- `HasOrganizationalScope.php:10` (`scopeAccessible`, ~8 uses) vs `UnitScopedRequest:16-36` (API-only) vs raw `app(AccessService::class)->accessibleUnitIds()` + `whereIn` in 30+ blades.
- Hand-rolled `Cache::get('{ns}_version')` in `dashboard.blade.php:43,176,198,214`, `HrStatsController:112,222`, `ReportController:20-21,55-56,104-105`, `OrgChartController:73,151` vs `CacheInvalidationServiceInterface::cacheKey()/remember()` (`CacheInvalidationService:25-38`). TTLs 120–300s inconsistent.
- Invalidation: `Hardware:84-87` model boot bumps `hardware_stats/gis/maps/dashboard`; `HardwareGisCacheListener:16` bumps `gis`; `HardwareController:185,221,234,310,349` fires `HardwareUpdated` → double-bump; zero `event(new HardwareUpdated` in web path.
- God components: `⚡inbox.blade.php:916` (~25 methods), `hardware/index:643`, `dashboard:580`, `todo/todo:561`. `HardwareIndexHelpers:1-316` used only by `hardware/index:18`.
- Ticket side effects: `TicketCommentController:359,376,407` (`NotificationService::send`) vs inbox `:560` + create `:149` (`notifyUnit`) + `ActivityLogService::*` (inbox `:286,326,469`, create `:143`); console `maintenance:generate-due` notifies nothing.
- Near-identical `assertAccessible`: `HardwareController:62` vs `HardwareAuditController:388` (+`:409`); `TicketController` inlines `assertAccessibleUnit` 7× (`:48-183`).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Raw scope sites | `grep -rln 'app(AccessService::class)' resources/views/ \| wc -l` | ~30 before, →0 after |
| Event sites | `grep -rn 'event(new HardwareUpdated' app/ resources/ \| wc -l` | count before decision |
| Tests | `composer test` | pass after EVERY step (not just at end) |

## Scope

**In scope**: files above + new `app/Actions/*`, `app/Services/TicketService.php`, `AssertsHardwareAccess` trait, split child components.
**Out of scope**: LIKE-scope dedup + export JOINs (plan 007), policy enforcement (plan 009), any behavior change to ticket state machine (plan 004 owns it — this plan moves code, preserves semantics).

## Steps

### Step 1: One scope idiom + one assert trait

API controllers → `UnitScopedRequest` + `scopeAccessible` exclusively; Livewire → trait scope. Forbid raw `app(AccessService::class)` in views (documented rule + PHPStan/Pint grep-guard in CI). Extract `AssertsHardwareAccess` trait (replacing both `assertAccessible` copies); `TicketController` 7× inline → `$request->assertAccessibleUnit` helper already exists — use it uniformly.
**Verify**: raw-site count → 0; suites pass.

### Step 2: Single cache-key path

Route every versioned read through `CacheInvalidationServiceInterface` (`cacheKey()`/`remember()`); delete hand-rolled key strings; unify TTLs (pick the interface default; note deviations). Single invalidation source: keep model-observer, REMOVE the event *or* make all writers fire it — decide by test (whichever keeps GIS fresh on both API+web writes), never both.
**Verify**: key-scheme grep shows one idiom; GIS staleness test (write via API, write via web, read GIS) passes.

### Step 3: Split god components + trait → services

Inbox → `TicketList` + `TicketDetail` + `BulkActions`; dashboard stats → cached query object; `HardwareIndexHelpers` → `HardwareHistoryService` + `HardwareRestoreService` (trait becomes thin delegation, then delete). Ticket side effects → `TicketCreated/Forwarded/Completed` events from a `TicketService`; one listener fans out (notifications + activity + `report_tickets/gis/calendar` bumps, replacing inbox `:263-266,307-309`).
**Verify**: per-split suites pass; Livewire hydration payloads shrink; no behavior diff (run ticket/hardware E2E paths).

### Step 4: LIKE-escape dedup (DEBT-09)

`PersianSearch::escapeLike(string)` + Eloquent `scopeSearch($term)` on `Hardware`/`Person`; controllers call the scope. (Plan 007 preserves semantics in export JOINs — coordinate order: 007 first if both scheduled.)
**Verify**: search suites pass; `grep -rn "str_replace(\['%'" app/Http/Controllers/ | wc -l` → 0.

## Test plan

- After EACH step: full `composer test`. New: scope-idiom guard test, GIS-freshness cross-path test, event fan-out test.

## Done criteria

- [ ] Zero raw `app(AccessService::class)` in views (CI-guarded)
- [ ] One cache-key idiom; one invalidation source (proven by cross-path test)
- [ ] Inbox split; trait deleted; TicketService + events live
- [ ] `scopeSearch` dedup done
- [ ] Suite green throughout; scope clean

## STOP conditions

- Any step's suite goes red twice → stop, report, do NOT proceed to next step.
- Event-vs-observer decision affects prod GIS freshness ambiguously → stop, report measurements.
- Map sprawl (DEBT-11, 7 overlapping pages) tempts scope creep → explicitly deferred, do not touch here.
