# Feature Contracts (Exports, Jobs, Scheduler, Tree, Charts)

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-16; that file keeps the one-line rules and
| the map of where each topic lives.

## Dead Routes & Components Removed (Phase 4)

These components and routes were removed — do not recreate:
- `/` — changed from Livewire `index` component to `Route::redirect('/', '/dashboard')`
- `auth.register` — registration form removed (unused)
- `glowingcard` — demo component removed (unused)
- Tests for these: `AuthRegisterLivewireTest`, `GlowingCardLivewireTest`, `IndexRedirectLivewireTest` — all deleted

**Removed as dead code (issue #685, 2026-09-24) — do not recreate:**
- PHP: `TicketAlreadyAcceptedException`, `GisController::invalidateCache()`, `LastUserActivity::isOnline()/getLastActivity()`, `DailyReport::generatedBy()`, `HardwareExport::chunkCollection()`
- Blade views: `welcome.blade.php`, `tools/index.blade.php`, `livewire/reports/index.blade.php`, `components/stitch-parrot.blade.php`
- Test: `ReportsIndexLivewireTest.php` (covered a component that no longer exists)

---

### Units Export (issue #701)

`GET /units/export` → `units-Ymd-His.xlsx`, gated by `role_or_permission:organization` (same group as `/units`).

- **Access-scoped** — rows = `UnitScopedRequest::accessibleIds()`; empty scope → header-only file.
- **One row per unit** (flat, sortable), **8 columns**: `شناسه`, `نام واحد`, `نوع واحد`, **`شهرستان`**, `والد مستقیم`, `مسیر کامل`, `سطح`, `وضعیت`. The breadcrumb carries hierarchy instead of one column per level; **`شهرستان` was added later so the sheet filters by county as a plain Excel filter** instead of matching a path substring.
- **`شهرستان` semantics (deliberate):** a county unit writes **its own county name**; a unit attached directly to a **province writes `-`**, and a region-less unit writes `-`. It is **not** the province name — repeating the province would make one filter value swallow every unit beneath it.
- Depth-first order (parents first, siblings alphabetical). Ancestors above the caller's scope still name the path. Inactive units included as `غیرفعال`.
- RTL via `WithEvents` → `AfterSheet` → `setRightToLeft(true)`.
- Button is a plain `<a href="{{ route('units.export') }}">` in `resources/views/livewire/units/index.blade.php` — **Livewire cannot return file downloads**, so never `wire:click` it.
- Files: `app/Exports/UnitsExport.php`, `app/Http/Controllers/Api/UnitsExportController.php`, tests in `tests/Feature/UnitsExportTest.php`.

> ✅ **`descendantIds` uses `UNION`, not `UNION ALL`** — deliberate. The set operator dedupes, so a `parent_id` cycle terminates (2ms) instead of hanging the connection (proven: `UNION ALL` on a cycle runs until `statement_timeout`). Do not "optimize" it back to `UNION ALL`. The export's own `buildHierarchy()` guards its upward walk separately.

### Personnel Export (issue #729, columns #755)

`GET /kargozini/persons/export` → `persons-Ymd-His.xlsx`, named `kargozini.persons.export`, in the **same permission group as the personnel list** (`role_or_permission:kargozini|manage_personnel`, issue #775), controller `App\Http\Controllers\Api\PersonsExportController`.

- **Exports the filtered result**, not the whole table — search and every active filter are reapplied server-side, so what you download is what you were looking at.
- **12 columns:** `کد ملی`, `نام`, `نام خانوادگی`, `نام کامل`, `سمت`, `تحصیلات`, `نوع استخدام`, `ردیف سازمانی`, `واحد سازمانی`, `وضعیت`, **`تاریخ تولد`**, `تاریخ استخدام`. «تاریخ تولد» was added in #755 — `birth_date`/`hire_date` had **no cast** and were returning raw strings, so the export would have printed unformatted values.
- The unit column carries the **full breadcrumb** via `UnitTreeService::ancestorChain()` — the same contract as the units export's «مسیر کامل».
- **Relations are eager-loaded in the controller** (`semat`, `tahsil`, `estekhdam`, `radif`, `unit`) so mapping a row never queries. An empty scope uses `whereRaw('1 = 0')`, not a raw empty `IN ()`.
- Same plain-`<a>` rule: Livewire cannot return file downloads.
- Files: `app/Exports/PersonsExport.php`, `app/Http/Controllers/Api/PersonsExportController.php`, `tests/Feature/PersonsExportTest.php`, e2e `tests/e2e/personnel/list.spec.ts`.

### `persons.phone` (issue #738)

Free-text, `string(20)`, **nullable**, migration `2026_09_30_000001_add_phone_to_persons_table.php`; fillable, form field, and an Excel **import** mapping column.

> Deliberately **no format validation** — the form rule is `'phone' => 'nullable|string|max:20'`. Real Iranian numbers arrive as `0912…`, `+98…`, or `0912 345 6789`; a strict pattern would silently reject valid rows. Do not "tighten" it into a regex.

## Reusable unit tree (issue #704)

The tree UI is generic and shared: `resources/views/livewire/unit/tree.blade.php` + `tree-node.blade.php` (single-file Livewire component `unit.tree`), backed by `app/Services/UnitTreeService.php` (scope-rooted `roots()`, `childrenOf()`, `search()`, `ancestorChain()` — all take `$accessibleIds` as an argument, never read `auth()`).

Reuse contract (documented at the top of `tree.blade.php`):

- **IN** — `badge-view` (Blade view per node, receives `$unit` + `badge-data`), `badge-data` (opaque unit-id => payload map, forwarded verbatim — the tree never interprets it), `search-placeholder`.
- **OUT** — `unit-selected` event (int id) on node click; the embedding page listens via `#[On('unit-selected')]` and fills its own detail panel. The listener **must re-check the id** against its own `accessibleUnitIds()` — `selectNode` is a public Livewire method that forwards any id.

`hr/org-chart` is the reference consumer: it contributes only `livewire/hr/personnel-badge` (count + «خالی») and the personnel detail panel. `hr/org-node.blade.php` is **deleted** — do not recreate it; node markup lives in `unit/tree-node.blade.php`. The search box and expand/collapse buttons live INSIDE `unit.tree` because they drive the child's own state — a parent cannot call a child's methods without a ref. A second consumer (e.g. covered population per unit) needs zero tree code: a badge view + an event listener.

`UnitTreeService::ancestorChain()` is the **shared breadcrumb walker** — the personnel export's unit path and the tree UI resolve hierarchy through it. The units export is the exception: `UnitsExportController` walks its own `hierarchyMap()` / `buildHierarchy()` (see the note above). Do not re-implement an upward walk anywhere else.

### Batched child loading (issue #722)

`UnitTreeService::childrenOfMany(array $unitIds, array $accessibleIds)` loads the children of **N nodes in one query**; `childrenOf()` is the single-node case. `unit.tree` preloads through the batch method.

- The old per-node implementation cost **~44 queries**; the batch form holds `assertNoNPlusOne(..., 12)` in `UnitTreeLivewireTest` — verified to **fail** against the per-node code and pass with the batch. Do not revert to a per-node loop.
- `hasChildren()` answers the same question **without loading rows**; keep using it for expand/collapse affordances.
- An **empty scope short-circuits** to no query at all.

---

## One Daily Window (issue #736)

Every `by_day` aggregate — `/api/reports/{todos,tickets}`, the dashboard ticket trend, and both
report pages — covers **one defined window** and returns **one entry per day in it, including days
with no rows**. Before this, the same chart meant different things per surface (UI = 30 days, API =
whole history) and a day without a ticket was a *missing column*, not a zero.

| Surface | Window source | Default |
|---|---|---|
| `/api/reports/todos`, `/api/reports/tickets` | `?days=` query param | 30 (range 1–365) |
| `/dashboard` ticket trend | `Dashboard::TICKET_CHART_DAYS` | 30 |
| `/reports/todos` | the page's own date-from/date-to picker | 30 days back + 30 forward |
| `/reports/tickets` | the page's own date-from/date-to picker (`reports.advanced`) | 30 days back to today |

- `App\Services\DailySeries` owns the shape. It materialises the window with `generate_series`
  and **left-joins the caller's own aggregate query** — it fills gaps in an already-filtered query
  instead of re-deriving its filters, so unit/status/date filters keep working untouched.
  `between()` **keeps the picked range** (clamped to `MAX_DAYS`) rather than only its length —
  discarding it charted the wrong axis and dropped the picked rows (#866); `lastDays()` fills the
  same two fields from `now()`.
- `?days=abc` falls back to 30 (a sloppy value still renders a chart); `?days=0`, `?days=-5` and
  `?days=5000` return **422** (`App\Rules\ReportDays`).
- Output days are Jalali `Y/m/d`, ascending, oldest first. The dashboard chart labels are `m/d`.
- The window is part of the **cache key** (`remember(..., extra: ['days' => $days])`) — a chart
  cached for 30 days must not be served to a client that asked for 7.
- **Not** read from `daily_reports`: it is written by `reports:generate-daily` at 06:00, so it is
  always a day behind, and a failed schedule becomes an invisible hole in the chart.
- `tickets.completed_at` is indexed by `2026_09_29_000001_add_completed_at_index_to_tickets_table`.

---

## Settings Features

Settings page (`/settings`), component `settings.index` — **5 persisted, user-configurable settings**, stored in `user.settings` as a JSON column:

- **Browser notifications** — `browser_notifications` (bool) toggle
- **Auto-refresh** — `dashboard_refresh` (int, `0` = off) dashboard auto-refresh interval
- **Compact mode** — `compact_mode` (bool) denser UI layout toggle
- **Theme poles** (#1224, #1227) — `light_theme` / `dark_theme`, one daisyUI theme each, from
  `App\Support\ThemeFamilies`. All **35** built-in themes are enabled: light **21**, dark **14**
  (defaults `fantasy`/`dark`). The header `<x-theme-toggle>` cycles between exactly those two.
  `save()` validates both against the family **before** writing and rewrites the whole array —
  a persisted key missing from that array is deleted on the next save.

> **`EmailNotificationService` does not exist.** There is no email-notification setting and no such class — do not list one as a feature or wire a toggle to it. `showHelpModal` is a **transient component property** (help modal open/closed) and is deliberately **not** persisted by `save()`.

**Enabling a daisyUI theme** touches five places that must agree (`ThemeFamilies` is the source of
truth for the first three):

1. `ThemeFamilies::LIGHT`/`DARK` + `LABELS` — validation, the two selects, the toggle. Keep
   `themeOrder` order; the split is measured, not aesthetic (dark: `color-scheme: dark` and
   `--color-base-100` lightness ≤ 0.370; light ≥ 0.916 — the two bands are disjoint);
2. `resources/css/app.css` — the `themes:` list (**count every theme explicitly**; `themes: all`
   drops `fantasy --default`/`luxury --prefersdark` and the hybrid form silently drops 32 themes),
   the `@custom-variant dark` selector list for every dark theme, the `[data-theme=…]
   .stitch-bg-fixed` group, and the `:not([data-theme=…])` blob chain. `coffee` was enabled but
   missing from the last two, so its glass surfaces sat on the light cream backdrop (2.60:1) — the
   #1224 sweep caught it. The blob chain's specificity beats `.dark`, so **every** dark theme must
   be listed there or it paints light blobs on a dark page (#1227);
3. `THEMES` / `LIGHT_THEMES` / `DARK_THEMES` in `tests/e2e/theme/theme-helpers.ts`;
4. `SettingsThemeTest::test_enabled_themes_in_app_css_match_theme_families` — parses the `themes:`
   line and asserts set equality with `LIGHT+DARK` **and** the two flags. It is the only guard
   between the CSS and `ThemeFamilies`; before #1227 nothing connected them;
5. `tests/e2e/theme/sweep.spec.ts` fails on any sub-threshold surface, so the table stays honest.

**Sweep policy is two-layer.** The blocking job (`sweep.spec.ts`, seven themes plus
`WATCH_THEMES`, 33 cells each) runs on every PR and must stay under the 30-minute E2E cap. The
full **35 × 33 = 1155** matrix runs in `sweep-35.spec.ts` from `.github/workflows/theme-sweep-35.yml`
(dispatch, weekly schedule, or a PR labelled `theme-sweep`, 45-minute cap) — a hard lock
since #1231 (any ❌ fails the job), table in the job summary + artifact. A theme the full run
flags earns a place in `WATCH_THEMES` (and the per-PR lock) only when its non-badge cells all
pass **and** the measured E2E budget fits — with CI at ≈39 s per theme the watch list is
currently empty (the first 9-theme run hit the 30-min cap); wider findings fail the full run until their own issues land.
Both layers measure through the one shared `sweepTheme()` in `sweep-runner.ts`, which since the
#1226 merge (a4276fc8) also injects the per-variant badge probe strip: the opaque/glass matrix in
`app.css` is pinned cell by cell, and since the #1231 merge
(e43e1f60) the shared STRUCTURAL map is empty — the winter/abyss `badge solid` cells now
have real per-theme pairs.

The split is measured, not aesthetic: daisyUI declares `color-scheme` per theme, and the e2e suite
asserts each select's options agree with their theme's own `color-scheme`. The sweep applies
`mary-class` (`light`/`dark`) with the theme, exactly like `<x-theme-toggle>`, so `.dark`-keyed
rules (Leaflet invert, stitch backdrop) are measured in the state a user actually sees.


## Maintenance Schedule

`/maintenance` route — Livewire component `maintenance.index` for CRUD on `MaintenanceSchedule` records. Sits in the **same `role_or_permission:manage_hardware` group as `/hardware`**, and each mutating method also calls `$this->authorize('manage_hardware')` itself.

- Frequencies: `daily`, `weekly`, `monthly` with configurable interval
- `calculateNextDue()` uses `CarbonInterface` return type
- `maintenance:generate-due` command creates tickets from overdue schedules
- Authorization: `manage_hardware` permission required

## Queued Jobs

Heavy operations are dispatched as queued jobs. All implement `ShouldQueue` with retry/logging:

| Job | Timeout | Tries | Purpose |
|---|---|---|---|
| `ArchiveActivityLogsJob` | 300s | 3 | Deletes activity logs older than N days |
| `CleanNotificationsJob` | 300s | 3 | Deletes notifications older than N days |
| `GenerateDailyReportsJob` | 600s | 2 | Runs `GenerateDailyReports` artisan command — **not dispatched by anything** (see the gotcha below); the 06:00 schedule runs the command inline (#1021) |
| `SyncZabbixJob` | 30s | 2 | Fetches Zabbix interface traffic, caches it as `zabbix_traffic_data` (5 min TTL); records a `zabbix_sync_logs` row per run (#740) |
| `SendNotificationJob` | 30s | 3 | Queued wrapper for one in-app notification, **per recipient**; dispatched only from `TicketCommentController` (comment create/update/delete). Not unit-scoped |

The two retention jobs accept a `$unitIds` array; **empty resolves `AccessService::allUnitIds()` (org-wide), NOT `accessibleUnitIds()`** (#836). `accessibleUnitIds()` reads `auth()`/`session()` and is therefore always `[]` in a scheduler or queue worker — every caller used to read that as "nothing to do" and exit successfully. `accessibleUnitIds()` is request-scoped UI code only. `GenerateDailyReportsJob` takes no scope of its own: it shells out to `reports:generate-daily`, which resolves the unit set itself. Those jobs have `failed()` methods that `Log::error()`. `SyncZabbixJob` takes no unit scope and records a **failure row** when `services.zabbix.out_item_id` / `in_item_id` are not configured.

> Gotcha: `GenerateDailyReportsJob` has **no dispatch site on purpose** — the 06:00 schedule runs `reports:generate-daily` inline (#1021). The queue pin is **already in place** (#1010, closed by PR #1130): the job carries `#[Connection('redis-reports')]` (`app/Jobs/GenerateDailyReportsJob.php:18`), whose `retry_after` is 660s against its 600s `$timeout`; `install-guid.md` §11 documents the `h-dashboard-queue-reports` worker that consumes the `redis-reports` connection (`queues:reports` list); and `tests/Feature/Jobs/JobsTest.php:205,224,244` pins the invariant. What is still unsafe is dispatching it into a deployment running only the default `redis` worker — `queues:reports` would have no consumer. Confirm the reports worker is enabled before wiring `->job(new GenerateDailyReportsJob)` into `bootstrap/app.php`.

> Gotcha: `NotificationService::send()` is the **static** primitive — it creates the in-app notification row and invalidates the recipient's bell cache. `SendNotificationJob` is only the queued per-recipient wrapper around it and has **no static `send()` of its own** (its surface is `__construct` / `handle` / `failed`). `SyncZabbixJob::alertAdmins()` calls `NotificationService::send()` **directly and synchronously** — it does not dispatch the job. Read both before adding a second notification path.

> Gotcha: the optional `$dedupeKey` on `NotificationService::send()` is the **event identity, not a content hash** — retries/duplicates of one event pass the same key, different events pass different keys; a second send with the same `(user_id, dedupe_key)` returns the existing row (backed by the `notifications_user_dedupe_key_unique` index). Today only `SendNotificationJob` passes one (`job:{queue uuid}`); direct callers like `SyncZabbixJob::alertAdmins()` are still unkeyed (#1286 step 6 split).

---

## Scheduler & Console Commands

**Five commands plus one queued job** are scheduled in `bootstrap/app.php` via `->withSchedule(...)` — the Laravel 11+ skeleton style (#864, landed by PR #896). The previous home, `app/Console/Kernel.php`, was never bound (`withKernels()` binds the framework's base console kernel), so the schedule silently never ran; that file is deleted — do not recreate it. The commands all take `--dry-run`, except `cache:prune-stale` — since #981 it is an audit-only report and takes no options:

| Scheduled item | Schedule |
|---|---|
| `cache:prune-stale` | hourly |
| `todos:generate-recurring` | daily 02:00, `->withoutOverlapping(60)` |
| `maintenance:generate-due` | daily 03:00, `->withoutOverlapping(60)` |
| `data:archive` | weekly (Mon 04:00), `->withoutOverlapping(120)` + in-command lock |
| `reports:generate-daily` | daily 06:00, `->withoutOverlapping()` |
| `SyncZabbixJob` (queued, **not** the `zabbix:sync` command) | `everyFiveMinutes()`, `->withoutOverlapping()` |

**Lock expiry is explicit on the three writers (#1305)** — the `withoutOverlapping()` default of 1440 minutes is the entire interval of a daily run, so a crashed run would hold the lock right up to the next start. `data:archive` also takes `Cache::lock('command:data:archive', 6h)` inside `ArchiveOldRecords`, because the schedule lock is only read on the `Schedule::run()` path and a manual `php artisan data:archive` would otherwise race it. There is no `--force` flag: an emergency manual run releases the lock with `Cache::lock('command:data:archive', 1)->forceRelease()`.

`zabbix:sync` (plan 018) is **no longer scheduled** — the schedule dispatches `SyncZabbixJob` instead, so a slow Zabbix API can never block the scheduler. Run `php artisan zabbix:sync` manually when you need the command.

> Full command details, parameters, and gotchas: `references/api-endpoints.md` (Scheduler & Console Commands).
> **Do not add `->timeout(N)` to a schedule entry** — throws `BadMethodCallException`. HTTP timeout lives in `ZabbixService::request()` via `->timeout(10)`.

## Cache Version Namespaces

`CacheInvalidationService` uses driver-agnostic version-counter invalidation: cache keys are `{namespace}:v{version}:{scopeHash}:{extra}`, and a write bumps the counter. Hot paths use `Cache::remember(...)` with the versioned key.

**Key namespaces:** `hardware_stats`, `gis`, `maps`, `dashboard`, `hr_stats`, `unit_hierarchy`, `report_units`, `report_todos`, `report_tickets`, `calendar`, `global_search`, **`zabbix_devices`** (added with issue #698).

`PruneStaleCache::NAMESPACES` is the **single source of truth** — that exact `const` array lists every versioned namespace, and a new namespace must be added there so the audit and the tests see it. Since #981, `cache:prune-stale` is an honest **version audit**: it reports versions and deletes nothing — stale versioned keys become unreachable after a bump and expire by TTL, and `CacheInvalidationService::increment()` does not depend on this `const`.

> Performance patterns, caching strategies, and optimization details: `references/api-endpoints.md` (Performance section).

## Gotchas (transplanted from the former `## Gotchas Quick Reference`)

| Gotcha | Details |
|---|---|
| `route('tickets.show')` | Does not exist — use `route('tickets.inbox')` |
| Todo calendar | Must use `@script` block (not inline JS) for wire:navigate compatibility |
| Person search | 500ms debounce applied — do not remove, causes Livewire update floods |
| A typed Livewire model parameter binds by **route key**, not by `id` (#936) | A parameter like `delete(PersonModel $person)` is resolved by Livewire through `resolveRouteBinding()`, i.e. by the model's `getRouteKeyName()`. `Person` overrides it to **`n_code`** — the API deliberately depends on this (`routes/api.php` `{person}` binds by `n_code`), so it must stay — while a Blade call site almost always writes `{{ $model->id }}`. The two disagree, `resolveRouteBinding(3)` runs `WHERE n_code = '3'`, matches nothing, and throws `ModelNotFoundException` **before the method body runs**: every authorize/scope check in that method is dead code, and the request 404s. `Person` is the **only** model in `app/Models/` with a custom `getRouteKeyName()`, so the other 11 typed-model Livewire parameters (`units`, `users`, `hardware`, `roles`, `permissions`, `maintenance`, the four kargozini lookup tables, `zabbix-devices`) all resolve correctly today — each pairs with a `->id` call site on a model whose route key *is* `id` — but nothing stops a second custom route key. **Prefer `int $id` + `findOrFail($id)`** (the `editPerson($id)` style) so the value the browser sends is the value the query uses. Two traps if you must route-key it: (1) `wire:click="delete({{ $person->n_code }})"` interpolates **unquoted**, so the browser parses `0023548258` as a JS numeric literal and strips the leading zeros (V8: `d(0023548258) === 23548258`) — a real convention in Iranian national codes; (2) passing a hydrated model to `->call('delete', $fresh)` in a test **skips implicit binding entirely** (`ImplicitlyBoundMethod` skips anything already `instanceof` the class), so such a test passes against the broken code. Assert on the **database**, never on component status — Livewire's test broker allow-lists `ModelNotFoundException` in `withoutExceptionHandling` (`RequestBroker.php:30`), so the failure is swallowed and a green test can delete nothing. Pinned by the `#936` block in `tests/Feature/Kargozini/PersonLivewireTest.php` |
| Toast auto-dismiss | Default 5s timeout; `timeout: 0` means never dismiss |
| `hr/org-node.blade.php` removed | Replaced by `unit/tree-node.blade.php` (issue #704). Tests split: `UnitTreeLivewireTest` (generic tree contract) + `HrOrgChartPageTest` (the HR page embedding it) |
| `ORDER BY` + `LIMIT` = oldest N, not newest | `groupBy('day')->orderBy('day')->limit(30)` returns the **oldest** 30 days, because ORDER BY is applied before LIMIT (issue #734, dashboard ticket-trend chart). To show the most recent N buckets, take them in a subquery and re-sort for display: `DB::query()->fromSub($inner->orderByDesc('day')->limit(30), 'daily')->orderBy('day')->get()`. Flipping only the outer `orderBy` reverses the axis — do not "fix" it that way. The axis is deliberately **sparse** (30 most recent days *that have data*, not 30 consecutive days), so an E2E assertion of "30 consecutive days" fails against correct code |
| Every `$x->user` in a view is nullable, regardless of the foreign key (#906) | `User` uses `SoftDeletes`, so a soft-deleted user keeps its row, leaves `user_id` non-null, and still resolves the relation to `null`. The nullable-FK migration is a second, independent path — **not** the root cause, and it does not bound the class. This holds even on `NOT NULL` columns such as `task_activities.user_id`. To tell a genuinely ownerless row from a soft-deleted actor, eager-load the relation with soft-deleted users included **and** select `deleted_at`, or `trashed()` silently answers "not deleted". `App\Support\ActorLabel` owns the label contract («سیستم» for a null `user_id`, «کاربر غیرفعال» for a soft-deleted creator) |
| reports.advanced status filter is type-aware (#915) | `STATUS_OPTIONS` keyed by `reportType` feeds both the select and the whitelist; todos maps status onto `is_completed`/`end_at`, tickets/persons filter their real `status` columns; `updatedReportType()` resets the filter to `all` |
| Ticket archiving is a timestamp, never a status (#929) | Archived == `completed` + `archived_at` set. `archiveTickets()` (`tools.tools`) keeps `status = 'completed'` and stamps `archived_at`, idempotent via `whereNull('archived_at')` — it must never write a new status value (`'archived'` rendered «نامشخص», 422'd the API filter, counted as open, and re-armed dead inbox actions). Archived rows stay visible under completed filters and out of every open count. One-way by design (no restore UI — the tools card says so). Deployments that already carry `status = 'archived'` rows need the one-shot `UPDATE tickets SET archived_at = completed_at, status = 'completed' WHERE status = 'archived'` |
| Never write children onto a model in a tree build (#933) | `Unit` has no `children` column, so `$unit->children` resolves to the real `hasMany` relation (`Unit.php:106-108`). `$byId[$id]->children ??= []; …[] = $unit` lazy-loads every real child of that parent and then **appends on top**, duplicating each subtree multiplicatively (4,844 nodes for 834 units on beta). Accumulate children in a separate `$childrenByParent` map keyed by parent id and read the relation **never** — see `OrgChartController::orgChart()` and the same shape in `UnitsExportController::buildHierarchy()`. Pinned by `test_org_chart_emits_each_unit_once_with_exact_children` |
