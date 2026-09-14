# Plan 013: Mobile Parity & Dead Features (API gaps, reports, maintenance)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- routes/api.php routes/web.php app/Http/Controllers/Api/TodoController.php app/Http/Controllers/Api/ReportController.php app/Models/Notification.php app/Services/NotificationService.php app/Models/Attachment.php app/Models/DailyReport.php app/Models/MaintenanceSchedule.php app/Console/Kernel.php app/Console/Commands/GenerateDailyReports.php app/Console/Commands/GenerateDueMaintenance.php app/Console/Commands/GenerateRecurringTodos.php database/migrations/2026_08_29_000002_add_recurrence_fields_to_todos_table.php README.md` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P3 (features read as shipped but are dead/unreachable from mobile; no data loss)
- **Effort**: M (several small endpoints; each needs permission + tests — batch, don't gold-plate)
- **Risk**: LOW-MED (new endpoints expand surface — scope every one to `accessibleIds()`/owner; attachment upload needs mime/size caps + private disk)
- **Depends on**: 004 (reopen semantics settled first — Step 5 extends that state machine), 009 (report gating precedent)
- **Category**: direction
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

The scheduler generates recurring todos, due maintenance, and daily reports — but the API can't create recurring todos, maintenance has no UI/API at all, daily reports are readable nowhere, notifications/attachments/search/persons-reports/HR-lookups are web-only, and the ticket workflow is one-directional (no reopen; authors can't edit own comments). Flutter users get a subset that silently corrupts metrics via workaround duplicates.

## Current state (audit-verified; confirm each grep live)

- Recurrence: migration `:13-15` adds `recurrence_rule/interval/last_generated_at`; `Kernel:19` schedules generator; `TodoController:41-47` validates only `title/start_at/end_at/is_completed/unit_id`; `README:18` promises "recurring tasks".
- Notifications: model `Notification:25-33` + service `:13,40` produce; bell `notifications/bell.blade.php` web-only (`markAsRead:49`, `markAllAsRead:54` exist); zero notification routes in `api.php`.
- Attachments: `Attachment:10` fillable; `TicketController:59` eager-loads only; zero attachment routes in `api.php`/`web.php`.
- Search: `web.php:142` + `search/index.blade.php` vs zero `search` in `api.php` (trigram indexes migrated `2026_09_06_000003` ready).
- Daily reports: `DailyReport:10-16`; `Kernel:28` 06:00 schedule; zero refs in routes/views/controllers.
- Maintenance: model + migration + `Kernel:22` generator; zero refs in routes/views/controllers.
- Reports API: `ReportController:17,52,101` = units/todos/tickets; web `reports.persons` (`web.php:148`) missing from API. HR lookups (Estekhdam/Tahsil/Semat/Radif): `web.php:71-78` CRUD, no API (mobile person create at `api.php:137-141` can't fetch dropdowns).
- Tickets: `api.php:102-107` no reopen/reject (plan 004 adds `reopen`); comment edit needs `manage_unit_tickets` (`:116-119`); dual restore endpoints `:85-88` (`rollback` + `restore-record`).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Gap confirm | `grep -n 'notification\|attachment\|search\|maintenance\|daily' routes/api.php` | empty (confirm before building) |
| Tests | `composer test` | pass |

## Scope

**In scope**: `routes/api.php` (+ minimal controller methods reusing existing queries/services), one optional minimal Livewire list (maintenance due), tests.
**Out of scope**: push delivery (FCM/APNs — API list/read only; note push as follow-up), ticket domain events (plan 010), report-gate mechanics (plan 009 — reuse its pattern).

## Steps

### Step 1: Recurrence + notifications + search (read-mostly, S each)

`TodoController` store/update: whitelist `recurrence_rule:in:none,daily,weekly,monthly` + `recurrence_interval:min:1` (defaults preserve behavior) + resource fields. `GET /notifications`, `POST /{n}/read`, `POST /read-all` (owner-scoped). `GET /search?q=&type=` reusing existing scoped index queries (cap per type).
**Verify**: mobile can create recurring todo; notification round-trip; search parity spot-check; tests per endpoint (200 + 403 cross-user).

### Step 2: Reports persons + HR lookups + daily read (S each)

`GET /reports/persons` (mirror web Livewire query, gate `view_hr_dashboard` per `api.php:155`); 4 read-only `GET /hr/lookups/*`; `GET /reports/daily?unit_id=&from=&to=` (unit-scoped; confirm `GenerateDailyReports` payload schema first).
**Verify**: parity with web numbers; tests.

### Step 3: Attachments + maintenance surface (M)

`POST /tickets/{t}/attachments` (mime/size caps, private disk) + `GET /attachments/{a}` (ticket-visibility authorize, stream) + `DELETE`; `GET /maintenance/due` + minimal Livewire list with complete wired to generator output (needs ownership semantics — define who-may-complete first).
**Verify**: upload/download/delete round-trip; due→complete cycle; abuse cases (oversize, wrong mime, cross-ticket read) rejected.

### Step 4: Workflow symmetry + restore alias (S/XS)

Author-may-edit-own-comment (window or own-only — only if plan 004 deferred it); deprecate one restore endpoint (keep nested `rollback`, alias `restore-record` → after one release `410` + pointer; verify handler equivalence in `HardwareAuditController` first).
**Verify**: author edit tests; alias behavior tested.

## Test plan

- Per-endpoint Pest: 200 happy + 401/403 negative + scope-cross rejection; attachment abuse cases; reopen interplay with 004.

## Done criteria

- [ ] Every Step-1/2 endpoint live, scoped, tested
- [ ] Attachments + maintenance usable end-to-end (web or mobile)
- [ ] Comment/reopen symmetry closed with 004
- [ ] No unscoped new endpoint (`grep` review of each added route's gate)
- [ ] Scope clean

## STOP conditions

- Flutter form needs differ (HR lookups unused by mobile) → verify need first, drop unused endpoints.
- Maintenance ownership semantics ambiguous → stop, ask; don't invent permissions.
- Any new endpoint can't reuse existing scoping → stop, report; don't ship unscoped.
