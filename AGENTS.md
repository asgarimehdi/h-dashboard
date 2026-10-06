# Health Dashboard (داشبورد سلامت) — Agent Rules

> **Doc review (2026-09-21):** Updated after 27+ commits since 2026-09-15. Added maintenance schedule, notification API, queued jobs, CSP/HSTS headers, normalizeForQuery, dead code removal. Reorganized to keep this file lean — detailed API, deployment, and performance patterns live in `references/`.
>
> **Doc review (2026-09-25):** 51 commits since 2026-09-21. Added: Sanctum **token abilities** on every `/api/*` route (#690), shared test trait `InteractsWithTestSetup`, `@property` PHPDoc on all models (#671), `SyncZabbixJob` dispatched every 5 min instead of the `zabbix:sync` schedule entry (plan 018), dead-code removal (#685), and the **E2E locale rule** (`APP_LOCALE=fa` in `.env.e2e`). Verified counts: `composer test` = 1461 passed, Playwright = 152 passed.
>
> **Doc review (2026-10-01):** 133 commits since 2026-09-24. Added: **Zabbix typed transport** — `ZabbixClient` interface + `ZabbixResult` value object (#741), **Zabbix sync observability** — `zabbix_sync_logs` + dashboard banner + admin alert (#740), **persons Excel export** (#729) with full breadcrumb + birth-date columns (#755), **`persons.phone`** column (#738), **8-column units export** with «شهرستان» (#722 follow-up), `POST /csp-report` + `Reporting-Endpoints` header (#742), real Zabbix `apiinfo.version` connection test (#713), batched unit-tree children (`childrenOfMany`, #722), versioned git hooks + `composer verify` preflight, `scripts/sync-beta.sh`, `scripts/build-env-e2e.sh`, PR template. Corrected stale counts and one **factual contradiction** with `references/api-endpoints.md` (the `descendantIds` CTE uses `UNION`, not `UNION ALL`). Verified counts: `composer test` = **1687 passed**, 2 risky, 4268 assertions (~289s serial).

## Project Overview

Health Dashboard is a Laravel 13.x application for managing hospital/healthcare center hardware inventory, organizational units, tickets, and todos. Built with Livewire 4, MaryUI (DaisyUI), and Alpine.js. Fully RTL and Persian-language. Served to both a web UI and a Flutter mobile app (via Sanctum API tokens).

### Tech Stack

- **Framework:** Laravel 13.x on PHP ^8.4 (CI runs 8.5; Symfony 8.1 requires ≥8.4)
- **Frontend:** Livewire 4 — **single-file (anonymous-class) components**: the PHP class lives inline at the top of its Blade view under `resources/views/livewire/<feature>/<name>.blade.php` as `return new class extends Component { ... };` (no separate file under `app/Livewire/`). Alpine.js, MaryUI (DaisyUI), Tailwind CSS 4
- **Database:** PostgreSQL 16 (Docker, `postgis/postgis:16-3.4`) with PostGIS for spatial/GIS data
- **Cache/Session/Queue:** Redis (Docker, `redis:latest`, password-protected via `REDIS_PASSWORD`)
- **Auth:** Laravel Sanctum (session guard for web, Bearer tokens for the Flutter app)
- **Package Manager:** Composer (backend); npm (frontend): `npm install` + `npm run build` / `vite build` (Node 24, npm 12)
- **E2E Testing:** Playwright (Chromium, `tests/e2e/`, `npx playwright test`)
- **Code Quality:** PHPStan level 6 with baseline, Laravel Pint (enforced in CI + pre-commit hook)

> **Detailed data model, relationships, FK behavior:** see `references/data-model.md`
> **API endpoints, UI features, scheduler, deployment, performance:** see `references/api-endpoints.md`

---

## Area & Vocabulary

- **Person** — HR record in the directory, linked to a `User` one-to-one via `n_code`.
- **User** — authenticated account; Spatie roles/permissions; linked to Person via `n_code`.
- **Unit** — organizational unit (hospital, health center, county); tree via `parent_id`.
- **UnitType** — classification of a Unit; allowed parent types via `unit_type_relationships`.
- **Region** — hierarchical geographic division (province or county).
- **Boundary** — GIS polygon (MULTIPOLYGON, SRID 4326) representing a geographic area.
- **Location Log** — GPS point recorded by the mobile app (`location_logs`).

**Abbreviations:** `n_code` national code (person unique ID); `u_id` unit FK on persons; `CTE` common table expression (recursive SQL); `GIS` geographic information system; `SRID` spatial reference identifier (4326 = WGS84).

---

## Access Control

Uses **Spatie Permission** package:

- `HasOrganizationalScope` trait on models for **opt-in** unit-based filtering via `->accessible($column)`. It is a single local scope — **there is no global scope**, so a query is unscoped until it calls it. Its `whereIn` is unconditional, so an empty scope compiles to `0 = 1` (fail-closed).
- **Never guard a unit scope with `->when($accessibleIds, fn ($q) => $q->whereIn(...))`** — `Conditionable::when()` runs the callback only for a **truthy** value, so an EMPTY `$accessibleIds` drops the predicate and the page renders rows from **every unit** (the #819 leak in five `/reports/*` components). `AccessibleUnitIds()` is legitimately `[]` for an account with no `user_units` row and no `person.u_id`, and such a user is deliberately allowed through `ValidateUnitContext`. Use a plain `->whereIn($column, $accessibleIds)` (or `->accessible($column)`) instead. The two-`when` form in `UnitsExportController` / `PersonsExportController` (`when($accessibleIds === [], whereRaw('1 = 0'))`) is also correct — but the unconditional one is one condition instead of two that must both stay right.
- Users see only their own unit's data (plus sub-units via recursive CTE)
- Permission `manage_hardware` required for hardware CRUD
- Roles: admin, operator, viewer

**AccessService** provides `accessibleUnitIds()` → unit IDs the current user can access (unit + descendants via recursive CTE). Results are cached and version-invalidated.

**Key permissions:** `manage_users`, `organization`, `kargozini`, `map`, `manage_zabbix`, `calendar`, `view_all_tickets`, `create_ticket`, `view_assigned_tickets`, `manage_roles`, `op-cache`, `manage_hardware`, `bw`, `view_hr_dashboard`, `manage_personnel`, `manage_unit_tickets`, `manage_org_chart`.

### Sidebar ↔ route permission contract

Every session starts **in `h-dashboard`** (the Hermes `terminal.cwd` default) and uses **CodeGraph first** for any
code question, plus the **superpowers** skills for process (`brainstorming`, `systematic-debugging`,
`test-driven-development`, `verification-before-completion`).

A sidebar item in `resources/views/components/layouts/app.blade.php` and the route it points at in
`routes/web.php` **must carry the same permission(s)**. A link the user cannot open is a 403 they were
invited to click; a hidden link for a permission the route accepts is a lost feature. Pinned by
`tests/Feature/SidebarPermissionsTest.php`, which renders the sidebar per permission and asserts each
link's visibility in both directions, plus a real `GET` per link.

- Groups: **ابزارهای مدیریتی** (`map`|`bw` → `/it/networks` + `/it/wireless`; `manage_zabbix` →
  `/it/zabbix-devices`; `op-cache` → `/op`, with `@production` so the link never renders where the route
  does not exist), **ابزار مدیریتی** (`manage_users` → `/tools`), **سخت افزار** (`manage_hardware` →
  `/hardware` + `/maintenance`), **گزارش ها** (`manage_personnel`, plus `manage_users` for `/activity-log`).
- `/it/networks` and `/it/wireless` live in their **own** `role_or_permission:map|bw` group — **not**
  nested inside the `map` group. Nesting re-adds `map` as an extra requirement and 403s a `bw`-only user
  (there is a regression test for exactly this). Nested `middleware(['a', 'b'])` means **AND**, not OR.
- `role_or_permission` syntax is `a|b` = ANY. It is **not** the `ability:a,b` / `abilities:a,b` comma form
  from Sanctum, and it resolves through `canAny()`, so a permission name is never treated as a role.
- `/reports/*` is gated by `manage_personnel`, deliberately **not** a new permission: a permission that
  exists in the seeder but not on a deployment's DB 403s every non-admin, because Spatie's
  `hasAnyPermission()` answers `false` for an unknown name instead of throwing. `RoleSeeder` grants
  `manage_personnel` to `unit_manager` so that role keeps the report access it had before the gate existed,
  and `PermissionSeeder` `givePermissionTo`s it for admin.
- Personnel (issue #775 decision): **read** (list `/kargozini/persons` + export) is the union
  `role_or_permission:kargozini|manage_personnel` — `kargozini` is the lookup-table permission, NOT the
  personnel gate. **Write** (import `/kargozini/persons/import`) requires `manage_personnel` alone,
  matching the API (`abilities:persons:write` + `manage_personnel`). The lookup tables
  (estekhdams/tahsils/semats/radifs) stay `kargozini`-only.
- The personnel **component** re-checks the write permission itself (issue #805). Because its route
  gate is the read union, `kargozini.person` calls `$this->authorize('manage_personnel')` in
  `startCreate()`, `savePerson()`, `editPerson()` and `delete()`, and the create/edit/delete controls
  are wrapped in `@can('manage_personnel')`. Rule of thumb: **a page on a read-union gate that also
  writes must authorize inside the component, not only in the route.** The write path also
  `syncWithoutDetaching()`s `user_units` (role `staff`, primary unit) for a linked user — it rewrites
  the actor's reachable units, so an ungated write there is a privilege escalation, not data entry.
  The update branch re-validates the **submitted** `u_id` against `accessibleUnitIds()`, not just the
  stored one, or a record can be moved into a unit the actor never had read access to. Pinned by
  `tests/Feature/Kargozini/PersonLivewireTest.php` (the `#805` block).
- `resources/views/components/help/content/permissions.blade.php` used to list permissions that never
  existed (`view_hardware`, `view_tickets`, `create_tickets`, `assign_tickets`, `manage_units`,
  `view_units`, `manage_permissions`, `view_reports`, …). Do not re-add them; keep that page in sync with
  `PermissionSeeder`.

---

## Authentication

**Laravel Sanctum** with two modes:

| Mode | Routes | Auth Method |
|---|---|---|
| **Web (Session)** | Livewire UI pages | Cookie-based session via `web` guard |
| **API (Token)** | `/api/*` routes | Bearer token via `sanctum` guard |

- Livewire components expect session-based auth. **API tokens are NOT accepted** for Livewire pages.
- Login form at `/login`. API login: `POST /api/login` with `n_code` + `password` (throttled 5/min).

### API Token Abilities (issue #690)

Every `/api/*` route group additionally requires a **token ability** — `auth:sanctum` alone is not enough (403 otherwise).

Sanctum middleware semantics: **`ability:a,b` = ANY one of them**, **`abilities:a,b` = ALL of them** (`CheckForAnyAbility` vs `CheckAbilities`).

| Route group | Read (GET) | Write (POST/PUT/DELETE) |
|---|---|---|
| `/api/units` | `ability:units:read` + `role_or_permission:organization` | `abilities:units:write` + `role_or_permission:organization` |
| `/api/zabbix/traffic`, `/api/zabbix/multi-latest` | `ability:traffic:read` + `role_or_permission:map\|bw` | — |
| `/api/hardware/*` | `ability:hardware:read` + `role_or_permission:manage_hardware` | `abilities:hardware:write` + `role_or_permission:manage_hardware` |
| `/api/tickets*` (+ comments) | `ability:tickets:read` + `role_or_permission:view_assigned_tickets\|view_all_tickets` (per-route) | `abilities:tickets:write` + per-route `permission:create_ticket` / `permission:manage_unit_tickets` |
| `/api/reports/*` | `ability:reports:read` + `role_or_permission:manage_personnel` | — |
| `/api/persons/*` | `ability:persons:read` + `role_or_permission:kargozini\|manage_personnel` | `abilities:persons:write` + `role_or_permission:manage_personnel` |
| `/api/todos*` | `ability:todos:read,todos:write` (any of the two) + `role_or_permission:calendar` | same middleware + `role_or_permission:calendar` |
| `/api/hr/*` | `ability:hr:read` + `role_or_permission:view_hr_dashboard` | `role_or_permission:view_hr_dashboard` |
| `/api/notifications*` | `ability:notifications:read` | — |
| `/api/gis*` | `ability:gis:read` + `role_or_permission:map` | `role_or_permission:map` |

- Mint a token with abilities: `$user->createToken('name', ['hardware:read'])->plainTextToken`.
- Groups that also carry `role_or_permission:*` need **both** — token ability and Spatie permission — or the request is 403.
- **`/api/tickets*` and `/api/tickets/{ticket}/comments/*` also need `role_or_permission:view_assigned_tickets|view_all_tickets`** on top of the ability — they are in neither the "read" nor "write" column of that permission set, so the table above understates the gate.
- Tokens are **scoped and revoked on password change** (#678). API tests use **real Bearer tokens** with explicit abilities, not bare `Sanctum::actingAs()` — pattern in `tests/Feature/ApiAbilityTest.php`.


**Safe Role/Permission Middleware:** `SafeRoleOrPermission` is registered but **intentionally NOT used on hardware routes**. Hardware routes require full auth via `auth` + `role_or_permission:manage_hardware`.

> **Gotcha:** `test_hardware_page_loads_without_auth` asserts **302 → /login** for guests — a security decision. Do NOT "fix" it back to 200 — that reopens the data leak.

**Unit Context Middleware:** `ValidateUnitContext` ensures `session('current_unit_id')` is set before entering unit-scoped sections.

### Security Headers

`SecurityHeaders` middleware sets on every response:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy-Report-Only` — CSP in report-only mode (validate 1-2 weeks before enforcing)
- `Reporting-Endpoints: csp-endpoint="/csp-report"` — the Reporting API destination
- `Strict-Transport-Security: max-age=31536000; includeSubDomains` (HSTS)

> **Do not add `X-XSS-Protection`** — replaced by CSP. The old header is removed.

### CSP violation reporting (issue #742)

`POST /csp-report` (name `csp.report`) — **public, no auth**, `throttle:60,1`, named in `routes/web.php`.

- The CSP header carries `report-to csp-endpoint` and the **deprecated `report-uri` directive is gone** — modern Chrome never acted on it, so it was a lie.
- Route is **CSRF-exempt** via `$middleware->validateCsrfTokens(except: ['csp-report'])` in `bootstrap/app.php` — a browser report carries no token.
- Body is read with `$request->json()->all()` (Chrome sends `application/csp-report`, others `application/json`); a garbage body decodes to `[]` instead of throwing. Logs `Log::warning('csp-report', …)` and returns **204**.
- **The same-app destination is temporary.** The decision on #742: the final target must be a **separate-domain** endpoint once that infrastructure exists — follow-up issue material.

> Gotcha: it logs at `warning` with a **user-controlled body**. A large/garbage body is rate-limited but not size-capped, so do not treat `zabbix_sync_logs`-style assumptions about log volume as safe here.

### Zabbix transport is a typed value, not an exception (issue #741)

`App\Services\Zabbix\ZabbixClient` (interface) + `ZabbixResult` (final value object) + `ServiceZabbixClient` (the only implementation, bound in `AppServiceProvider`).

- `ZabbixResult::success($data)` / `::fail($failure, $message)`; read with `isOk()`, `failed()`, `data()`, `failure()`, `message()`.
- Failure kinds: `timeout`, `connection`, `invalid_response`, `error`.
- `TrafficController` and `MultiLatestValueController` now **branch on the result** and keep the **unchanged 503** contract. The `catch (\Throwable)` that used to wrap these calls is **no longer what guards them** — do not "restore" it, and do not remove the 503 mapping.
- The point is testability: `tests/Unit/ZabbixAdapterTest.php` proves each classification (timeout, connection refused, HTTP error status, invalid JSON, unexpected) with a **stub client and no Zabbix server**.

> **Do not call `ZabbixService` directly from a controller.** Depend on `ZabbixClient`; that is the boundary that keeps failures as values.

### Zabbix sync observability (issue #740)

`zabbix_sync_logs` — one row per `SyncZabbixJob` run (`2026_09_30_000002_create_zabbix_sync_logs_table.php`), model `App\Models\ZabbixSyncLog`.

- Columns: `success`, `consecutive_failures`, `error`, `ran_at` (indexed). `consecutive_failures` is **precomputed including the current row** (0 on a success row), so the threshold check and the widget are single-row reads.
- `failed()` runs **only after the queue gives up** (tries exhausted) → one failure row per job failure; retries do **not** inflate the streak. `failed()` also calls `report($exception)`, so a dead log channel still records it.
- **The unconfigured-skip is now a recorded failure**, not a silent warning: missing `services.zabbix.out_item_id`/`in_item_id` writes a failure row (previously a misconfigured deployment stayed silently broken for weeks).
- At streak **exactly** `ZabbixSyncLog::ALERT_THRESHOLD` (= 3) it alerts every admin **once** through `NotificationService::send()` — in-app only, no email/webhook. Any success resets the streak, so further failures never re-fire the alert.
- Dashboard banner: `getZabbixSyncStatusProperty()` on `/dashboard` reads the **latest row only** and renders `ok` / `stale` (old) / `failing` (streak), showing «نمایش از کش قدیمی» when the traffic cache outlived the last good sync.

> Gotcha: the streak is computed by reading the previous row (`orderByDesc('id')->first()`), so it is **serial by nature** — do not parallelise or reorder run recording.

### Zabbix connection test is real (issue #713)

`ZabbixService::testApiConnection()` calls `apiinfo.version` and throws unless the response carries a string `result`. `testConnection()` verifies API reachability **before** item checks and surfaces the reported Zabbix version. `tests/Unit/ZabbixServiceTest.php` + `tests/Feature/ZabbixDeviceTest.php` cover it.

> The `/it` widgets' friendly «دسترسی به سرور مقدور نمی باشد» message (#703) is **client-side and only for a connection failure** — a 400 from bad item ids must keep surfacing as an error, or real bugs get hidden.

### Batched Zabbix polling (issue #722 sibling, `f002cfc`)

One **batched** poll per `/it/networks` and `/it/wireless` page, not one request per gauge.

### CORS Hardening

`config/cors.php` changes:
- `allowed_origins_patterns` now **empty array in production** (was always localhost/127.0.0.1)
- `max_age` increased from 0 to 86400 (reduces preflight requests)

---

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

---

## Leaflet map lifecycle (issue #028)

The Leaflet instance is owned by **`Alpine.store('map')`** (`resources/js/map-store.js`, registered
in `resources/js/bootstrap.js`) — **not** on `window.map`.

- **`resources/views/livewire/maps/map.blade.php` is the only place allowed to call `L.map()`.** Its
  `x-data` calls `configure()` + `use()` in `init()` and `release()` in `destroy()`.
- Host pages (`maps/point`, `maps/county`, `maps/unit`, `maps/route`, `maps/route2`,
  `reports/map-no-boundary`) call `Alpine.store('map').onReady(cb)` and attach to **the instance the
  callback receives**. They must never wait on "does a map exist" and must never create one.

Why it changed: the instance used to live on `window.map`, which is never cleared, so SPA navigation
left the **previous page's detached instance** in place. Host pages waited for `window.map` to exist
— a condition a detached instance satisfies — bound their layers to it, and then `initMap()` built a
fresh map and wiped the layers. Measured **0/6** marker renders on `/maps/point` when reached by
clicking the sidebar, versus 783 on a direct load.

`onReady()` exists because **script execution order between two Livewire components is not
guaranteed** (`@script` runs once per component instance — `evaluateScripts` in `livewire.esm.js`).
It queues the callback and flushes it when the map exists, so neither component has to know which
runs first.

Three rules, each of which cost real debugging time:

| Rule | Why |
|---|---|
| The `x-data` element must **not** be the component root | Livewire puts `wire:id` on the **outermost** element of a component's markup. An `x-data` there is claimed by Livewire and its `init()` never runs, so the map silently never appears. `map.blade.php` wraps it in a plain `<div>` for this reason. |
| The `import` of `map-store` in `bootstrap.js` must be **static** | A dynamic `import()` resolves in a microtask, i.e. **after** Livewire's `start()` already dispatched `alpine:init`. The store then registers too late, every `x-data` `init()` finds no store, and **all maps render blank with no console error**. |
| Register on `alpine:init`, not at module evaluation | `window.Alpine` does not exist when the module runs, and the event always fires before the first `x-data`. |

`$refs.map` resolves fine **across** the `wire:ignore` boundary, so the container keeps `id="map"`
and `class="h-[80lvh] rounded"` — the E2E map specs assert on both plus `clientWidth > 400`, which is
why `invalidateSize()` and the `resize` listener are preserved.

> **Out of scope, deliberately untouched:** `units/map.blade.php` (`/units/{id}/map`) owns a
> separate instance on `#unitMap`, whose E2E spec depends on `window._drawnItems`. Do not
> migrate it without a separate decision. (`maps/interactive` was removed as dead code in
> issue #775.) `map/map-dashboard.blade.php` (`/map`) used to own one too; it was migrated
> onto the shared component on 2026-10-02 at Mehdi's decision — it embeds
> `livewire:maps.map`, holds the instance and its layer groups in closure locals inside its
> Alpine factory (never on the reactive data object), and attaches via `onReady`.

---

## Reusable unit tree (issue #704)

The tree UI is generic and shared: `resources/views/livewire/unit/tree.blade.php` + `tree-node.blade.php` (single-file Livewire component `unit.tree`), backed by `app/Services/UnitTreeService.php` (scope-rooted `roots()`, `childrenOf()`, `search()`, `ancestorChain()` — all take `$accessibleIds` as an argument, never read `auth()`).

Reuse contract (documented at the top of `tree.blade.php`):

- **IN** — `badge-view` (Blade view per node, receives `$unit` + `badge-data`), `badge-data` (opaque unit-id => payload map, forwarded verbatim — the tree never interprets it), `search-placeholder`.
- **OUT** — `unit-selected` event (int id) on node click; the embedding page listens via `#[On('unit-selected')]` and fills its own detail panel. The listener **must re-check the id** against its own `accessibleUnitIds()` — `selectNode` is a public Livewire method that forwards any id.

`hr/org-chart` is the reference consumer: it contributes only `livewire/hr/personnel-badge` (count + «خالی») and the personnel detail panel. `hr/org-node.blade.php` is **deleted** — do not recreate it; node markup lives in `unit/tree-node.blade.php`. The search box and expand/collapse buttons live INSIDE `unit.tree` because they drive the child's own state — a parent cannot call a child's methods without a ref. A second consumer (e.g. covered population per unit) needs zero tree code: a badge view + an event listener.

`UnitTreeService::ancestorChain()` is the **shared breadcrumb walker** — the units export's «مسیر کامل», the personnel export's unit path, and the tree UI all resolve hierarchy through it. Do not re-implement an upward walk.

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
| `/reports/todos`, `/reports/tickets` | the page's own date-from/date-to picker | 30 days back + 30 forward |

- `App\Services\DailySeries` owns the shape. It materialises the window with `generate_series`
  and **left-joins the caller's own aggregate query** — it fills gaps in an already-filtered query
  instead of re-deriving its filters, so unit/status/date filters keep working untouched.
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

Settings page (`/settings`), component `settings.index` — **3 persisted, user-configurable features**, stored in `user.settings` as a JSON column:

- **Browser notifications** — `browser_notifications` (bool) toggle
- **Auto-refresh** — `dashboard_refresh` (int, `0` = off) dashboard auto-refresh interval
- **Compact mode** — `compact_mode` (bool) denser UI layout toggle

> **`EmailNotificationService` does not exist.** There is no email-notification setting and no such class — do not list one as a feature or wire a toggle to it. `showHelpModal` is a **transient component property** (help modal open/closed) and is deliberately **not** persisted by `save()`.

## Maintenance Schedule

`/maintenance` route — Livewire component `maintenance.index` for CRUD on `MaintenanceSchedule` records. Sits in the **same `role_or_permission:manage_hardware` group as `/hardware`**, and each mutating method also calls `$this->authorize('manage_hardware')` itself.

- Frequencies: `daily`, `weekly`, `monthly` with configurable interval
- `calculateNextDue()` uses `CarbonInterface` return type
- `maintenance:generate-due` command creates tickets from overdue schedules
- Authorization: `manage_hardware` permission required

---

## Notification API (Flutter)

REST API endpoints for the Flutter mobile app (`/api/notifications/*`):

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/notifications` | GET | Paginated notification list (20/page) |
| `/api/notifications/unread-count` | GET | Count of unread notifications |
| `/api/notifications/{id}/read` | POST | Mark single notification as read |
| `/api/notifications/read-all` | POST | Mark all notifications as read |

Controller: `App\Http\Controllers\Api\NotificationController`. Requires Sanctum auth.

> **Sanctum token expiration** reduced from 7 days (10080 min) to 24 hours (1440 min). Configurable via `SANCTUM_TOKEN_EXPIRATION` env var.

---

## Queued Jobs

Heavy operations are dispatched as queued jobs. All implement `ShouldQueue` with retry/logging:

| Job | Timeout | Tries | Purpose |
|---|---|---|---|
| `ArchiveActivityLogsJob` | 300s | 3 | Deletes activity logs older than N days |
| `CleanNotificationsJob` | 300s | 3 | Deletes notifications older than N days |
| `GenerateDailyReportsJob` | 600s | 2 | Runs `GenerateDailyReports` artisan command |
| `SyncZabbixJob` | 30s | 2 | Fetches Zabbix interface traffic, caches it as `zabbix_traffic_data` (5 min TTL); records a `zabbix_sync_logs` row per run (#740) |
| `SendNotificationJob` | 30s | 3 | Queued wrapper for one in-app notification, **per recipient**; dispatched only from `TicketCommentController` (comment create/update/delete). Not unit-scoped |

The first three jobs accept a `$unitIds` array; empty defaults to `AccessService::accessibleUnitIds()`. All four of those have `failed()` methods that `Log::error()`. `SyncZabbixJob` takes no unit scope and records a **failure row** when `services.zabbix.out_item_id` / `in_item_id` are not configured.

> Gotcha: `NotificationService::send()` is the **static** primitive — it creates the in-app notification row and invalidates the recipient's bell cache. `SendNotificationJob` is only the queued per-recipient wrapper around it and has **no static `send()` of its own** (its surface is `__construct` / `handle` / `failed`). `SyncZabbixJob::alertAdmins()` calls `NotificationService::send()` **directly and synchronously** — it does not dispatch the job. Read both before adding a second notification path.

---

## Scheduler & Console Commands

**Five commands plus one queued job** are scheduled in `app/Console/Kernel.php` (`protected function schedule(Schedule $schedule)`, the Laravel 11+ skeleton style). The commands all take `--dry-run`:

| Scheduled item | Schedule |
|---|---|
| `cache:prune-stale` | hourly |
| `todos:generate-recurring` | daily 02:00 |
| `maintenance:generate-due` | daily 03:00 |
| `data:archive` | weekly (Mon 04:00) |
| `reports:generate-daily` | daily 06:00 |
| `SyncZabbixJob` (queued, **not** the `zabbix:sync` command) | `everyFiveMinutes()`, `->withoutOverlapping()` |

`zabbix:sync` (plan 018) is **no longer scheduled** — the schedule dispatches `SyncZabbixJob` instead, so a slow Zabbix API can never block the scheduler. Run `php artisan zabbix:sync` manually when you need the command.

> Full command details, parameters, and gotchas: `references/api-endpoints.md` (Scheduler & Console Commands).
> **Do not add `->timeout(N)` to a schedule entry** — throws `BadMethodCallException`. HTTP timeout lives in `ZabbixService::request()` via `->timeout(10)`.

---

## Cache Version Namespaces

`CacheInvalidationService` uses driver-agnostic version-counter invalidation: cache keys are `{namespace}:v{version}:{scopeHash}:{extra}`, and a write bumps the counter. Hot paths use `Cache::remember(...)` with the versioned key.

**Key namespaces:** `hardware_stats`, `gis`, `maps`, `dashboard`, `hr_stats`, `unit_hierarchy`, `report_units`, `report_todos`, `report_tickets`, `calendar`, **`zabbix_devices`** (added with issue #698).

`PruneStaleCache::NAMESPACES` is the **single source of truth** — that exact `const` array lists every versioned namespace. A new namespace must be added there, or `cache:prune-stale` leaves it alive forever and stale versioned keys never get bumped.

> Performance patterns, caching strategies, and optimization details: `references/api-endpoints.md` (Performance section).

---

## Development Guidelines

### Conventions
- **RTL:** All layouts use `dir="rtl"` at root level
- **CSS:** Tailwind utility classes over custom CSS
- **Pagination:** `LengthAwarePaginator` with `WithPagination` trait
- **Forms:** MaryUI `x-input`, `x-select`, `x-button` components
- **x-select key mapping:** MaryUI defaults to `optionValue='id'` / `optionLabel='name'`. If your options use `value`/`label` keys you **must** pass `option-value="value" option-label="label"`, otherwise every `<option>` renders **empty** (`<option value=""></option>`) and the control looks blank/unreadable. This was issue #706 — reported as a "background and text are the same color" bug, but it was a key-mapping bug, not a color bug. Build option lists in a component method (`typeOptions()`) and pass `:options="$this->typeOptions()"` — a bare `$typeOptions` is undefined in the Blade view.
- **Modal:** `x-modal` with `close-on-backdrop`
- **Components:** Livewire components are **single-file** — class is an inline anonymous class at the top of the Blade view (`return new class extends Component { ... };`). There are **no** `app/Livewire/*.php` class files. Reference components by dot-name string (`'hr.dashboard'`, `'kargozini.person'`, `'auth.login'`, `'tickets.ticket-comments'`) in routes and tests.
- **Testing:** Pest — `tests/Feature/*`, run via **`composer test`**
- **Test Review Rule:** Every code change MUST include test review. Before finalizing: (1) check if existing tests cover the changed code, (2) add/update tests for new behavior, bug fixes, or contract changes. No code change ships without corresponding test coverage verification.
- **Shared test trait:** new Feature tests `use InteractsWithTestSetup;` (`tests/Support/Concerns/InteractsWithTestSetup.php`, **84 files** already do) — provides `seedLookupTables()`, `resyncSequence()`, `createUserWithUnit($permissions, $role)`, `createHardware()`, `assertCacheInvalidated()`, `assertQueryCount()`, `assertNoNPlusOne()`. Do not re-implement user/unit/lookup seeding by hand; see `tests/Feature/ApiAbilityTest.php` for the standard `setUp()` (`PermissionSeeder` + `seedLookupTables()`).
- **Models:** all **26** Eloquent models under `app/Models/` carry `@property` PHPDoc annotations (#671). When you add an attribute/cast, update the annotation too — PHPStan level 6 + baseline depends on them.
- **Factories:** **15 factories** exist (`UserFactory`, `UnitFactory`, `PersonFactory`, `HardwareFactory`, `TicketFactory`, `TodoFactory`, `SematFactory`, `TahsilFactory`, `EstekhdamFactory`, `RadifFactory`, `UnitTypeFactory`, `NotificationFactory`, `AttachmentFactory`, `TaskActivityFactory`, `ZabbixDeviceFactory`). When seeding rows with **explicit IDs** in tests, resync the Postgres sequence afterwards (`SELECT setval(...)`) or later inserts hit duplicate keys — or call `$this->seedLookupTables()` / `$this->resyncSequence($table)` from the shared trait.
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes. Pint is enforced in CI and via pre-commit hook.
- **Tinker:** `php artisan tinker --execute '...'` — single quotes to prevent shell expansion. Prefer `database-query`/`database-schema` Boost MCP over raw SQL.
- **Artisan:** New migrations use `YYYY_MM_DD_000001_description.php` (sequential daily counter); pass `--no-interaction`.
- **Frontend rebuild:** After frontend changes run `npm run build` (or `vite build`).

### Composer Scripts
```bash
composer test         # config:clear + route:clear + XDEBUG_MODE=off php artisan test
composer verify       # .env existence check, then scripts/verify.sh (preflight + view:clear + pint --test + phpstan + the suite)  ← run before push
composer verify tests/Feature/TodoLivewireTest.php   # same gates, only the tests you name
composer dev          # concurrently: php artisan serve + queue:listen + npm run dev
composer pint         # Pint --dirty --format agent (auto-staged PHP)
composer phpstan      # phpstan analyse --no-progress
composer phpstan-baseline  # phpstan analyse --generate-baseline
composer hooks:install  # git config core.hooksPath .githooks
```

> `composer verify` **fails fast if `.env` is missing** (`file_exists('.env') || exit(1)`) — that is deliberate, not a bug. Everything after that lives in `scripts/verify.sh`.

### Local Verification & Git Hooks (issue #739)

Feedback comes in three layers. The hook layers stay **light on purpose**: a
commit hook that costs a minute is a hook the team bypasses with `--no-verify`,
which is worse than no hook. CI stays the only authority.

| Layer | What runs | Why there |
|---|---|---|
| `pre-commit` | Pint on staged PHP files | Seconds; catches the most |
| `pre-push` | **Full** PHPStan; tests only if `VERIFY_TESTS` names them | Deterministic with the baseline |
| CI | Everything | The final authority |

- Hooks are **versioned** in `.githooks/` and activated with `core.hooksPath`, not
  symlinked by `composer install`. The old `post-install-cmd` symlink only appeared on
  a *fresh* install, so on an already-provisioned machine the light layer was silently
  absent. `composer hooks:install` fixes an existing clone; `php artisan verify:preflight`
  **warns** when hooks are inactive.
- `pre-push` runs PHPStan over the **whole project**, not the staged subset — a
  staged-only path is an analysis route CI never exercises, so errors surfacing only
  through dependencies would never be seen locally.
- **Test selection is manual, never heuristic.** Livewire components are single-file
  Blade views, so no `app/… → tests/…` mapping exists. A heuristic could report green
  without ever running the failing test — an intermittently broken gate is worse than
  no gate.
- `composer verify` reproduces CI's **test job, not the coverage gate**: CI also runs
  `--parallel --coverage --min=80`, which needs pcov. Coverage is CI-only. It runs the
  suite **serially** like `composer test`.
- The preflight **exits 2** (distinct from 1) so callers can tell "your machine is
  wrong" from "your code is wrong". It resolves the effective test database from
  PHPUnit's own precedence — an exported `DB_DATABASE` (or a `DB_URL`) beats
  `phpunit.xml`, because those `<env>` entries carry no `force="true"`. Never run
  `migrate:fresh` before it: a mis-resolved database destroys real data.

- Branch-sync helper (#744): `scripts/sync-beta.sh` reports `behind X, ahead Y` against the **explicit** `origin/beta` ref and fast-forwards only when safe — never auto-merges (exit 1 on divergence).

### Laravel Boost (MCP)
Prefer `database-query`, `database-schema`, `search-docs`, `get-absolute-url`, `browser-logs` over manual alternatives; always search docs before code changes.

**Boost from CLI:** when no MCP transport is available:
```bash
php scripts/boost_tool.php <tool> '<json-args>'
# e.g. php scripts/boost_tool.php application-info '{}'
# php scripts/boost_tool.php db-schema '{}'
# php scripts/boost_tool.php query '{"sql": "SELECT ..."}'
```

### MCP Tools

Four MCP servers are configured in `~/.hermes/config.yaml`:

| Server | Tools | Purpose |
|---|---|---|
| **codegraph** | `codegraph_explore` | Code intelligence — symbol resolution, call paths, blast-radius analysis |
| **context7** | `query_docs`, `list_prompts`, `list_resources`, `read_resource`, `get_prompt` | Up-to-date framework documentation |
| **laravel_boost** | `application_info`, `last_error`, `search_docs`, `database_query`, `database_schema`, `get_absolute_url`, `browser_logs` | Laravel-specific tools (DB, docs, logs) |
| **github** | `create_issue`, `list_pull_requests`, `create_pull_request`, `search_code`, + 22 more | GitHub operations (repos, PRs, issues) |

Use `tool_search` to discover available tools, `tool_describe` to load schemas, `tool_call` to invoke. Always use CodeGraph before grep/glob for code understanding tasks.

---

## Running Tests (Pest)

Pest is the test runner. Uses **Livewire 4.4**, separate PostgreSQL test database `h_dashboard_test`.

> **✅ Verified 2026-10-01:** **`composer test`** is the one-command way (**1687 passed, 2 risky, 4268 assertions**, ~289s serial). It bakes in the three environment gotchas.
>
> `2 risky` = tests with no assertions (reported, non-blocking). If a Pest run fails with `database "h_dashboard_test" does not exist` on a handful of tests while the rest pass, it is a transient Postgres hiccup — re-run the file, then the suite.

### Key test files
Counts come from `php artisan test <file> --list-tests` — the authoritative per-file number. A plain `grep` for `test(`/`it(` **undercounts**, because PHPUnit-style classes declare tests as `public function test…` with neither wrapper.

| File | Tests | Purpose |
|---|---|---|
| `tests/Feature/ApiAbilityTest.php` | 33 | Real Bearer tokens per ability (#690) |
| `tests/Feature/TodoLivewireTest.php` | 18 | Todo Livewire component |
| `tests/Feature/PersonsExportTest.php` | 30 | Personnel export (12 columns, filters, breadcrumb) |
| `tests/Feature/UnitsExportTest.php` | 22 | Units Excel export (8 columns incl. «شهرستان») |
| `tests/Feature/SyncZabbixObservabilityTest.php` | 10 | `zabbix_sync_logs` rows, streak, admin alert (#740) |
| `tests/Unit/PersianNormalizerTest.php` | 11 | `normalizeForSearch`, `escapeLikeWildcards`, `normalizeForQuery` |
| `tests/Feature/SecurityHeadersMiddlewareTest.php` | 10 | Headers incl. `Reporting-Endpoints`; CSP report bodies |
| `tests/Feature/Jobs/JobsTest.php` | 9 | Archive / clean / generate jobs |
| `tests/Feature/MaintenanceLivewireTest.php` | 9 | Maintenance schedule CRUD |
| `tests/Unit/ZabbixAdapterTest.php` | 7 | `ZabbixResult` failure classification with a stub client (#741) |
| `tests/Feature/NotificationApiTest.php` | 6 | Notification API endpoints |

> Per-file counts, not the suite total — use `composer test` output for anything you assert in CI.

### Prerequisites
```bash
docker compose -f docker-compose-pgsql-.yml up -d      # PostGIS on :5432, Redis on :6379
pg_isready -h 127.0.0.1 -p 5432                        # Verify PostGIS healthy
```

**Redis is NOT required for tests** — `phpunit.xml` forces `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`.

### Ensure test database exists
```bash
psql -h 127.0.0.1 -U h_dashboard -d postgres -c \
  "CREATE DATABASE h_dashboard_test WITH OWNER=h_dashboard;"
```

> **No `TEMPLATE=template_postgis`.** PostGIS is enabled by the migrations themselves — `2025_03_20_000009_create_boundaries_table.php` runs `CREATE EXTENSION IF NOT EXISTS postgis` before it creates any geometry column, and four later migrations do the same for `pg_trgm`/geometry. A plain database is enough, and this matches `verify:preflight`, which checks `pg_available_extensions` rather than the existence of a template. Requiring `TEMPLATE=template_postgis` fails outright on a server that has the extension available but not that template — which is exactly the plain `postgis/postgis` image the compose file uses.

### Clear cached config/routes BEFORE running (critical!)
```bash
php artisan config:clear      # must be clear so phpunit.xml can override DB_*
php artisan route:clear       # removes routes-v7.php — fixes Livewire endpoint-hash mismatch
```

### Run
```bash
composer test                 # RECOMMENDED: clears config+routes, runs with XDEBUG_MODE=off
# For a single file:
XDEBUG_MODE=off php artisan test tests/Feature/TodoApiTest.php
```

### Common failure → cause
| Symptom | Cause | Fix |
|---|---|---|
| `NOAUTH`/`WRONGPASS` on Redis | cache still on redis — only `CACHE_DRIVER` set | `config:clear` + use `CACHE_STORE=array` |
| Connection refused (mysql) | config cache from `.env.testing` wins | `config:clear` |
| `404` on `->set()`/`->call()`, mutations don't persist (~75 failures) | Livewire endpoint hash mismatch (stale `routes-v7.php`) | `config:clear && route:clear` |
| HTTP 500 on date validation: `Cannot create dynamic property DateMalformedStringException::$xdebug_message` | Xdebug `develop` mode | `XDEBUG_MODE=off` |
| bare `vendor/bin/pest` → usage text | no path argument | pass `tests/` |
| Parallel: ~35 flaky `PermissionDoesNotExist` | spatie cache shared across workers | Keep `CACHE_STORE=array` in phpunit.xml |

---

## E2E Testing (Playwright)

**~165 tests** across **40 spec files** in `tests/e2e/` (file count + `test(` count re-checked 2026-10-01). Covers auth, navigation, RBAC, CRUD for users/tickets/personnel/units/hardware, reports, maps, dashboard, settings, search, activity log, tools, org chart, and the IT/Zabbix pages.

> Not verified by an actual run on 2026-10-01 — no browser installed and no `.env.e2e` on that machine. The numbers are **static counts** from the spec files. Run `bash scripts/e2e-test.sh` to get a real total.

Newest suites since the 2026-09-25 review: `reports/daily-window`, `dashboard/ticket-trend-window`, `it/zabbix-unavailable`, `it/monitoring`, `organization/units-tree`, `organization/unit-map-boundary`, `hr/org-chart`, `personnel/list`.

### Setup (one-time, per machine)
```bash
npm install                     # includes @playwright/test + dotenv
npx playwright install chromium # one-time browser install (Chromium + headless shell)
bash scripts/build-env-e2e.sh   # builds .env.e2e from .env.e2e.example, copying secret VALUES without printing them
```

### `.env.e2e` — gitignored, must be created locally

It is in `.gitignore`, so it never ships with the repo. **`scripts/build-env-e2e.sh`** (issue #703) generates it from `.env.e2e.example` by copying `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD` out of `.env` — it edits the file **without printing the values**, because the terminal masks secrets in output and a read-and-rewrite would write a literal `***` into `.env.e2e`. Manual equivalent:

```bash
cp .env.e2e.example .env.e2e
# copy from .env: APP_KEY, DB_USERNAME, DB_PASSWORD, REDIS_PASSWORD
# then create the isolated database (NEVER point e2e at `h_dashboard` — it is wiped every run):
psql -h 127.0.0.1 -U h_dashboard -d postgres \
  -c "CREATE DATABASE h_dashboard_e2e WITH OWNER=h_dashboard;"
```

> **⚠️ `APP_LOCALE=fa` is MANDATORY in `.env.e2e`.**
> **`.env.e2e.example` now DOES include it** (fixed in `7485043`) — the earlier failure mode was the example file omitting it while `config/app.php` defaults to `en`. Without it the app renders English pagination (`Showing 1 to 20 of 318 results`, `Next »`) and English validation messages instead of the Persian strings every spec asserts → **11 tests fail** across `auth/password-change`, `hardware/list-filters`, `organization/units`, `personnel/list`, `users/list`.
> If you hand-write `.env.e2e` from an older copy of the example, re-add:
> ```
> APP_LOCALE=fa
> APP_FALLBACK_LOCALE=en
> APP_FAKER_LOCALE=en_US
> ```
> Playwright's `locale: 'fa-IR'` is browser-level only (affects `Intl`, not Laravel translations) — it does **not** replace this.

### Credentials
Test credentials live in `.env.e2e` (gitignored), read by `playwright.config.ts` via `dotenv` and sourced by the shell script for the app itself. **No fallbacks** — `tests/e2e/shared/fixtures.ts` throws if any is missing: `TEST_PASSWORD`, `TEST_N_CODE`, `TEST_UNIT_MANAGER_N_CODE`, `TEST_EXPERT_N_CODE`, `TEST_REGULAR_USER_N_CODE`.

### Run
```bash
bash scripts/e2e-test.sh               # RECOMMENDED: swap .env → config/route:clear → migrate:fresh --seed → pwd user → serve :8001 → test → restore
bash scripts/e2e-test.sh tests/e2e/auth    # single suite (fast loop)
npx playwright test --reporter=list    # only if .env is already swapped and the server is already running
```

> **Cleanup trap:** `scripts/e2e-test.sh` uses `set -e` **without** a `trap`, so a failing Playwright run exits before restore — `.env` stays swapped and the `:8001` server keeps running. Always run afterwards:
> ```bash
> [ -f .env.dev.bak ] && cp .env.dev.bak .env && rm -f .env.dev.bak
> pgrep -f 'artisan serve --port=800[1]' | xargs -r kill
> ```
> (never `pkill -f 'artisan serve'` — it kills the shared dev server too)

### Common failure → cause
| Symptom | Cause | Fix |
|---|---|---|
| 11 tests fail on `نمایش…` / `باید مطابقت داشته باشند` — DOM shows `Showing…` / English validation text | `.env.e2e` has no `APP_LOCALE=fa` (a hand-written or stale example copy) | add the three `APP_*LOCALE*` lines above |
| `fixtures.ts` throws `<VAR> env var is required` or `.run-state.json not found` | `.env.e2e` missing / bare `npx playwright test` without global setup | create `.env.e2e`; run through `scripts/e2e-test.sh` |
| `No tests found` even from `npx playwright test --list` | fixtures read `.run-state.json`, which only `scripts/e2e-test.sh` writes | run through the script; a bare invocation cannot even enumerate |
| `Executable doesn't exist … chromium` | browser not installed | `npx playwright install chromium` |
| `database "h_dashboard_e2e" does not exist` | database never created | `CREATE DATABASE h_dashboard_e2e WITH OWNER=h_dashboard` (plain — migrations enable PostGIS) |
| `.env` still the e2e one after a failed run | script aborted before restore | restore from `.env.dev.bak` manually (see cleanup above) |

### Key helpers (in `tests/e2e/shared/fixtures.ts`)
- `login(page, nCode?, password?)` — fills login form, waits for redirect
- `logout(page)` — submits the real `<form action="/logout">`
- `waitForLivewire(page)` — waits for `.wire-loading` to disappear
- `waitForSearchResults(page, selector)` — waits for Livewire debounced results
- `waitForToast(page, text?)` — waits for toast notification
- `TEST_USER` / `ROLE_ACCOUNTS` — credentials from env vars

### Config highlights
- `baseURL`: `process.env.BASE_URL || 'http://localhost:8000'`
- `locale`: `fa-IR`, `timezoneId`: `Asia/Tehran`
- Retries: 2 in CI, 0 locally
- Trace/screenshot/video on failure

---

## Code Intelligence (CodeGraph)

[CodeGraph](https://github.com/colbymchenry/codegraph) — local (100% on-machine, SQLite, no API keys) code knowledge graph. Supports PHP/Laravel (routes → handlers) and cross-language flows.

**Hermes Agent MUST use CodeGraph for code-understanding tasks.** Before crawling files with grep/glob/Read to answer a structural question, run `codegraph explore` / `codegraph query` first.

```bash
codegraph explore "how does AccessService accessibleUnitIds resolve unit hierarchy"
codegraph query "HardwareAuditObserver" --limit 5
codegraph status .
```

> Index is per-machine. `codegraph sync` catches up if a session edited files while no index was running.

### CI/CD

`.github/workflows/test.yml` runs on PRs to `main`/`beta`/`test` with four jobs:
- **Code Style (Pint)** — `vendor/bin/pint --test` (blocking)
- **Tests & Coverage (blocking)** — PHP 8.5, PostGIS + Redis containers, `./vendor/bin/pest --parallel --coverage --min=80` → Codecov
- **Mutation Testing (non-blocking)** — `--covered-only`, treat failures as informational
- **PHPStan Static Analysis** — `vendor/bin/phpstan analyse --no-progress` (blocking)

> Full CI workflow details: `references/api-endpoints.md` (CI/CD section).

---

## Debugging Checklist

When code fails or tests break, follow this order:
1. **CodeGraph** — `codegraph query "<class/service>" --limit 5` for context
2. **Boost MCP** — `php scripts/boost_tool.php db-schema '{}'` or `php scripts/boost_tool.php query '{"sql":"..."}'`
3. **Context7** — query Laravel docs for framework-specific questions
4. **Tinker** — `php artisan tinker --execute '...'` for quick DB checks
5. **Pest** — `composer test` to verify nothing regressed

--- 

## Agent skills

### Issue tracker

Specs and issues live as local markdown under `.scratch/`. See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context layout (`CONTEXT.md` + `docs/adr/` when present). See `docs/agents/domain.md`.

---

## Gotchas Quick Reference

| Gotcha | Details |
|---|---|
| Livewire component files | **No** `app/Livewire/*.php` — classes are inline anonymous classes in Blade views |
| `n_code` not `id` | Person ↔ User linked by `n_code`; Person PK is `n_code` (string), not `id` |
| `s_id` not `semat_id` | FK column on `persons` for job title |
| `user_units` pivot | Many-to-many user↔unit (role enum: `responsible`/`staff`, `is_primary` flag) |
| NotificationService | Use `NotificationService::send()` (static), NOT `create()` |
| `route('tickets.show')` | Does not exist — use `route('tickets.inbox')` |
| `->timeout(N)` on schedule | Does not exist on this Laravel version — throws `BadMethodCallException` |
| `CACHE_STORE` not `CACHE_DRIVER` | Laravel 13 ignores legacy `CACHE_DRIVER`; phpunit.xml must use `CACHE_STORE=array` |
| `routes-v7.php` stale | Causes Livewire endpoint-hash mismatch; always `route:clear` before tests |
| Hardware auth | Must be 302 → /login for guests; do NOT "fix" back to 200 |
| Postgres sequence | After seeding with explicit IDs in tests, `SELECT setval(...)` to avoid dup keys |
| Map container | Do NOT wrap `maps.map` in Bootstrap `container` class — use `relative` |
| Leaflet.Draw featureGroup | `L.Control.Draw({ edit: { featureGroup } })` only enables edit/remove when `featureGroup.getLayers().length > 0` — `_checkDisabled` in `public/js/leaflet/leaflet.draw.js` adds `.leaflet-disabled` otherwise. A layer added straight to the map is invisible to the toolbar: put it in the FeatureGroup with `eachLayer(l => drawnItems.addLayer(l))` (individual layers, never the `L.GeoJSON` group — `updateGeojson()` only serialises `L.Polygon`) |
| `units.boundary_id` is ON DELETE CASCADE | Deleting the `boundaries` row cascades the **unit** away with it. Always `$unit->update(['boundary_id' => null])` FIRST, then delete the boundary row. Reversed order silently deletes the unit and its subtree (issue #702) |
| `Boundary::geojson` is a BARE geometry | `getGeojsonAttribute()` returns `ST_AsGeoJSON(...)` = `{"type":"MultiPolygon","coordinates":[…]}` — there is NO `geometry` key, so it is not a GeoJSON Feature. Hand `L.geoJSON` a Feature you build yourself and unwrap `MultiPolygon` → `Polygon` first, or `fitBounds` throws `Bounds are not valid` inside the `try`/`catch`, the layer never loads, and the draw toolbar stays disabled (issue #702) |
| `getLatLngs()` nesting depth | A plain `L.Polygon` is `[ring]`; one built from a `MultiPolygon` is `[[ring]]` — three levels. `L.Edit.Poly` only reads two, so `editing.enabled()` returns `true` with **no error** while `.leaflet-editing-icon` count is `0` and the ring serialises as `[undefined, undefined, …]`. Normalise with `while (Array.isArray(ring[0])) ring = ring[0]` before mapping coords |
| Leaflet is minified in E2E | `layer.constructor.name` is `"e"`, never `"Polygon"`. In Playwright assert `layer instanceof window.L.Polygon` — never match a constructor-name string. Keep the real names in the returned payload so a failure prints what it got |
| `_mapGeojson` must be seeded on load | `saveMapBoundary()` calls `deleteBoundary()` whenever `_mapGeojson` is falsy, so a boundary that is never re-serialised on page load is destroyed by a plain "ذخیره" click. Call `updateGeojson()` right after loading the saved layer (issue #702) |
| Playwright on map pages | Never `await networkidle` — Leaflet keeps fetching tiles so it never goes idle and the wait times out. Wait for `#unitMap` + `.leaflet-draw-edit-edit` instead |
| Leaflet + Alpine reactivity | Never put a Leaflet instance (map or layers) in Alpine reactive state — `x-data` or `Alpine.store`. Alpine deep-wraps it in proxies and Leaflet's identity-based listener cleanup (`===` in `Marker.onRemove`) silently leaks `zoomanim` handlers; after `clearLayers()` the next zoom throws `_latLngToNewLayerPoint` and freezes markers (issue #769). Keep the instance in module scope / closure locals — see `resources/js/map-store.js` |
| `scripts/e2e-test.sh` has no `trap` | A failing run exits before restore, leaving `.env` swapped to `h_dashboard_e2e`. Recover with `cp .env.dev.bak .env && rm -f .env.dev.bak`, then kill `:8001` (use `kill $(pgrep -f 'artisan serve')` — `pkill -f` kills the calling shell) |
| Rebuilding a lost `.env` | `.env` is gitignored. Rebuild from `.env-example-github` (the committed dev template) plus the secrets already resolved in `.env.e2e`, override `APP_URL=http://127.0.0.1:8000` and `DB_DATABASE=h_dashboard`, then drop any line whose value still contains `secrets.` (CI placeholders) or artisan dies with "environment file is invalid". Confirm with `php artisan about --only=environment` (expect `local`, locale `fa`). `parse_ini_file('.env')` fails here — unquoted parens — so scan lines with a regex instead |
| Dead routes removed | `/users/create`, `/users/{user}/edit`, `/docs/{page?}` — views never existed or were deleted |
| Todo calendar | Must use `@script` block (not inline JS) for wire:navigate compatibility |
| Person search | 500ms debounce applied — do not remove, causes Livewire update floods |
| Toast auto-dismiss | Default 5s timeout; `timeout: 0` means never dismiss |
| Search | Multi-word queries split and matched independently via `scopeFilterSearch` |
| `normalizeForQuery` | Use `PersianNormalizer::normalizeForQuery()` for ALL user-supplied LIKE queries — combines Persian normalization + wildcard escaping. Do NOT inline `str_replace(['%', '_'], ...)` |
| `PersianNormalizer` trait | Located at `app/Traits/PersianNormalizer.php`. Methods: `normalizeForSearch()` (Arabic→Persian + Unicode), `escapeLikeWildcards()`, `normalizeForQuery()` (normalize + escape combined) |
| `ZabbixService` errors | `TrafficController` and `MultiLatestValueController` map a **failed `ZabbixResult`** to 503, never 500. Since #741 they do **not** wrap the call in `catch (\Throwable)` — that try/catch is gone, and the 503 mapping is what guards them |
| Controllers must depend on `ZabbixClient` | `App\Services\Zabbix\ZabbixClient` is the transport boundary (#741). Calling `ZabbixService` directly from a controller throws away the failure-as-value design and re-couples you to a live server |
| `ZabbixSyncLog` streak is serial | The failure streak is computed by reading the previous row (`orderByDesc('id')->first()`), and `failed()` runs only after retries are exhausted — do not parallelise run recording or the streak inflates |
| `/csp-report` is public and CSRF-exempt | That is deliberate (#742): browsers post reports with no token. It is throttled `60,1` but the body is **not size-capped**, and it logs a user-controlled payload at `warning` — never point a log-volume assumption at it |
| Root `/` route | `Route::redirect('/', '/dashboard')` — NOT a Livewire component. The old `index` Livewire component is removed |
| E2E locale | `.env.e2e` **must** set `APP_LOCALE=fa`. `.env.e2e.example` **does** include it now (`7485043`); a hand-written `.env.e2e` from an older copy omits it, the app falls back to `en`, and 11 Persian-text specs fail (`Showing…`, English validation messages) |
| E2E env lifecycle | `scripts/e2e-test.sh` swaps `.env` and, on a failing run, `set -e` skips restore — restore `.env.dev.bak` and kill the `:8001` server yourself |
| `.env.e2e` / `h_dashboard_e2e` | Both gitignored/local-only; the e2e DB is `migrate:fresh --seed`ed every run — never point it at `h_dashboard` or `h_dashboard_test` |
| API token abilities | `/api/*` needs `auth:sanctum` **and** a token ability; `ability:a,b` = ANY of them, `abilities:a,b` = ALL. Tests mint real tokens (`ApiAbilityTest`) |
| Shared test trait | New Feature tests use `InteractsWithTestSetup` (`tests/Support/Concerns`) — `createUserWithUnit()`, `seedLookupTables()`, `resyncSequence()`, `assertNoNPlusOne()` |
| `zabbix:sync` scheduling | Schedule dispatches `SyncZabbixJob` (queued) every 5 min; the `zabbix:sync` command itself is manual-only |
| `when($scope)` on an empty array fails **open** | `Conditionable::when($accessibleIds, …)` applies the callback only when the value is truthy, so `[]` **drops** the `whereIn` and the page shows **every unit's rows** — issue #819, five `/reports/*` components. Scope with a plain `->whereIn($col, $accessibleIds)` (`[]` compiles to `0 = 1`) or `->accessible($col)`. Never `when($ids, fn ($q) => $q->whereIn(...))` |
| `HasOrganizationalScope` is not a global scope | It is one opt-in `->accessible($column)` local scope on `Person`/`Ticket`/`Todo` — **`Unit` does not use the trait at all**. A query is unscoped until it calls it, so `AGENTS.md` used to call it "automatic" and mislead implementers |
| `map-no-boundary`'s early return is load-bearing | Its `when($accessibleIds, …)` at :36 is unreachable because :28 returns `collect()` for `[]` — keep that guard. Beyond the query it also keeps an empty result out of `Cache::remember('report:no_boundary:'.md5(implode(',',$accessibleIds)))`, which for `[]` is `md5('')` — ONE shared cache slot for every empty-scope user |
| `descendantIds` CTE | Uses `UNION`, **not** `UNION ALL` — deliberate. `UNION ALL` does not dedupe, so a `parent_id` cycle recurses forever and hangs the connection (this query scopes every authenticated page via `AccessService`). Tested in `UnitModelTest` under a `statement_timeout` |
| `@property` on models | All **26** Eloquent models under `app/Models/` carry `@property` PHPDoc — update it when a column/cast changes (PHPStan level 6) |
| x-select option keys | MaryUI defaults to `optionValue='id'`/`optionLabel='name'`. Options keyed `value`/`label` need explicit `option-value="value" option-label="label"` or every `<option>` renders empty and the field looks blank (#706). Pass `:options="$this->someOptions()"` — a bare `$someOptions` is undefined in the view |
| Persian search must fold the COLUMN, not the pattern | `normalizeForQuery()` rewrites the search term (ZWNJ U+200C → space; آ/أ/إ U+0622/0623/0625 → ا) but the stored text keeps the original code points, so `LIKE` stops matching — a unit named "حرفه" + ZWNJ + "ای" or "آموزش" becomes invisible to its own filter. Postgres `LIKE` has only `%`/`_` and **no character-class syntax**, so `[ … ]` is literal and "either spelling" is unexpressible. Use `PersianNormalizer::foldSeparatorsSql($column)` (nested `regexp_replace` applying the same `charMap()` as `normalize()`, plus one `translate()` applying `digitMap()` so Persian/Arabic-Indic digits fold to Latin exactly as `normalizeForSearch()` does) compared against `foldedTerm($input)`. Replace ZWNJ with a SPACE, never `''` — deleting it makes the regex eat the next letter ("حرفه" + ZWNJ + "ای" → "حرفهای") |
| `LIKE '%term%'` is never index-seekable | Folding the column in `foldSeparatorsSql()` is not index-friendly, but `LIKE '%term%'` already forced a full scan, so this is not a regression to worry about |
| Faker names can collide with a `LIKE` filter | `PersonFactory` draws `fa_IR` `firstNameMale()`. "علی" itself (3/3000) and names containing it like "ابوعلی" (13/3000) come up in ~0.4% of draws, so a test filtering "علی" with `assertDontSee` on its own row flakes under `executionOrder="random"`. Pin both names explicitly in the test — do NOT fix it in `PersonFactory`, other tests assert on the raw faker value |
| Cache keys collide across tests | Postgres sequences are non-transactional, so `RefreshDatabase` restarts ids at 1 every test. `AccessService` keys on `accessible_units:v{version}:{user_id}:{session_unit_id}:{md5(baseIds)}` — byte-identical across tests, so a stale answer leaks from one test into the next. Put the flush in the base `TestCase::setUp()`, not in a test class — the seeder bump happens in the child's `setUp()`, which runs after `parent::setUp()`, so one flush there covers every class and needs no ordering rule. Never flush inside a test method |
| Parallel workers get their OWN database | Pest/Laravel creates `h_dashboard_test_test_{1..N}` per worker (`TestDatabases`), so workers do NOT share a database. Verified by listing the databases. If a parallel-only failure appears, suspect shared *in-process* state (cache keys, static properties), not the DB |
| Testing a cache fix | Assert through the component or the service, never by poisoning a key and expecting it to be ignored — that tests your own poison, not the flush. A test that only passes in isolation is asserting `setUp`, not the fix; assert the behaviour a user sees |
| Factories | **15 factories** exist under `database/factories/` — do not hand-roll inserts or claim only `UserFactory` exists. **`Ticket` has no `HasFactory` trait**, so `Ticket::factory()` throws `BadMethodCallException` and `TicketFactory` is dead code — build tickets with `Ticket::create([...])` like `TicketsInboxLivewireTest` does. `created_at` is not fillable, so a back-dated ticket needs `forceFill(['created_at' => ...])->save()` |
| `persons.phone` has no format rule | Free text, `max:20`, **no regex** (#738) — `0912…`, `+98…`, `0912 345 6789` are all valid. A "stricter" validation silently rejects real rows |
| An exported `DB_DATABASE` beats `phpunit.xml` | The `DB_*` `<env>` entries in `phpunit.xml` have no `force="true"`, so PHPUnit skips them when the variable already exists (`PhpHandler.php:140`). `DB_URL` is not in `phpunit.xml` at all and outranks everything. `php artisan verify:preflight` resolves the real value — read `.env` or `phpunit.xml` directly and you will check the wrong database |
| `expectsOutputToContain` matches ONE line per expectation | `PendingCommand`'s buffered mock consumes a written line with the first substring that matches it, so two substrings on the same line can never both be asserted. Put each claim on its own output line |
| `assertStringNotContainsString` returns void | Chaining `->and()` after it dies with "Call to a member function and() on null". Same for `assertMatchesRegularExpression` — use separate statements |
| Eloquent chains vs PHPStan (no larastan) | `Eloquent\Builder` has `@mixin Query\Builder`, so a top-level `whereIn()`/`limit()`/`take()` resolves to the query builder and types the rest of the chain `Collection<int, stdClass>`. Start chains `Model::query()->with([...])` (both declared on Eloquent), put IN-filters inside `where(Closure)`, cap rows with `get()->take(N)` not `->limit(N)->get()`. Do NOT add `@method static whereIn()` to a model to silence this — it re-types every `Model::whereIn()` chain repo-wide and unmasks errors in unrelated files |
| phpstan-baseline is line-keyed | Its entries embed line numbers, so inserting even a comment into a baselined file "unmatches" its entries (`ignore.unmatched` errors). After editing baselined code, run `vendor/bin/phpstan analyse --generate-baseline`, then verify `git diff phpstan-baseline.neon` shows **0 additions** — an addition means a real new error got suppressed |
| Daily charts must fill empty days | A `GROUP BY date(...)` aggregate only returns days that exist, so a day without a ticket became a **missing column**. Use `DailySeries` (`generate_series` + left-join) — never re-derive a filtered query just to count by day. `days=1` means today alone; the window is inclusive at both ends |
| `?days=` belongs in the cache key | `CacheInvalidationServiceInterface::remember($ns, $scope, $cb, $ttl, $extra)` hashes `$extra` into the key. Omit `['days' => $days]` and a 30-day chart is served to a client that asked for 7 |
| `Ticket::create(['created_at' => ...])` is silently ignored | `created_at` is **not fillable** on `Ticket` — the attribute is dropped without error, so every row lands on today and date-window tests pass for the wrong reason. Use `$ticket->forceFill(['created_at' => ...])->saveQuietly()` |
| `Query\Builder::toBase()` does not exist | Only `Eloquent\Builder::toBase()` does. The `@mixin Query\Builder` on `Eloquent\Builder` is one-way, so PHPStan resolves `Ticket::query()` as `Query\Builder` — accept both and branch on `instanceof` rather than adding a `@method` (that re-types the chain repo-wide) |
| `DailySeries` needs explicit generics | `Eloquent\Builder` in a signature without a `TModel` produces `missingType.generics` at level 6. Annotate `@param EloquentBuilder<covariant Model>|QueryBuilder` — otherwise the fix gets buried in `phpstan-baseline.neon` |
| `hr/org-node.blade.php` removed | Replaced by `unit/tree-node.blade.php` (issue #704). Tests split: `UnitTreeLivewireTest` (generic tree contract) + `HrOrgChartPageTest` (the HR page embedding it) |
| `waitForMap` is not a pattern — use `Alpine.store('map').onReady()` | `window.map` is never cleared, so a previous page's **detached** instance satisfied the old "map exists" check; host pages bound layers to a dying map and `initMap()` wiped them (0/6 marker renders via the sidebar). Issue #028 moved ownership into an Alpine store with an explicit `release()` on `destroy()`. Do not reintroduce a "wait until a map exists" loop |
| Alpine `x-data` on a Livewire **component root** never runs `init()` | Livewire puts `wire:id` on the outermost element of the component's markup and claims that node, so an `x-data` there is never initialised by Alpine. Nest it inside a plain `<div>` — the map then renders blank with no console error |
| A **dynamic** `import()` in `bootstrap.js` registers the store too late | It resolves in a microtask, after Livewire's `start()` already dispatched `alpine:init`. Every `x-data` `init()` then finds no store and all maps stay blank with no error. Import statically and register on the event |
| `window.map` is **not** the Leaflet instance | With `id="map"` in the DOM the browser exposes the element itself as `window.map`, so it has no `getContainer`. Read the instance from `Alpine.store('map').get()`, and remember `#028` deliberately has no `window.map` |
| MaryUI `<x-menu-sub>` collapses in E2E | It renders as a `<details>`, open only when a child is active. A collapsed `<details>` still gives children a non-empty box in Chromium, so `isVisible()` is not an "is it open" test — read `details.open`, click the `<summary>`, then `scrollIntoViewIfNeeded()` before clicking (see `tests/e2e/maps/spa-navigation.spec.ts`) |
| `ORDER BY` + `LIMIT` = oldest N, not newest | `groupBy('day')->orderBy('day')->limit(30)` returns the **oldest** 30 days, because ORDER BY is applied before LIMIT (issue #734, dashboard ticket-trend chart). To show the most recent N buckets, take them in a subquery and re-sort for display: `DB::query()->fromSub($inner->orderByDesc('day')->limit(30), 'daily')->orderBy('day')->get()`. Flipping only the outer `orderBy` reverses the axis — do not "fix" it that way. The axis is deliberately **sparse** (30 most recent days *that have data*, not 30 consecutive days), so an E2E assertion of "30 consecutive days" fails against correct code |
| Nested route middleware is **AND**, not OR | `Route::middleware(['a','b'])->group(...)` — and nesting a route inside another permission group — requires **both**. To accept either, use ONE `role_or_permission:a|b` in a group of its own. This bit `/it/networks` + `/it/wireless`: putting them inside the `role_or_permission:map` group re-added `map` and 403'd a `bw`-only user. `php artisan route:list --path=X -v` prints every resolved middleware — read it instead of guessing |
| Sidebar link must match its route's gate | Every `x-menu-item` in `resources/views/components/layouts/app.blade.php` needs the same permission as the route it links to (`routes/web.php`). `SidebarPermissionsTest` pins it in both directions (link visible to every permission the route accepts, invisible otherwise — both a 403 invite and a hidden working page are bugs). `@production`-only routes (`/op`) need the same environment guard on the link |
| Spatie `canAny` is FALSE for an unknown permission name | `hasAnyPermission(['nosuchperm'])` returns `false` — it does **not** throw `PermissionDoesNotExist`. So a route gated on a permission that is missing from a deployment's DB 403s **every non-admin** (only role bypasses), silently. Before adding a NEW permission to a gate, either seed it everywhere or reuse an existing one — and remember `PermissionSeeder` alone only re-grants the permissions it explicitly lists |
| `@can`/`@canany` nesting prunes child items | A menu item can be hidden by an ancestor gate, not just its own: `/hr/org-chart` is gated `manage_org_chart|view_hr_dashboard` **and** sits inside the «مدیریت سازمان» group's `@canany`, and «تقویم» sits inside «مدیریت تیکتها». When you add an item or change a gate, add its permission to the parent `@canany` too or the item is unreachable |
| Hardware audit scope is resolved by ONE service (#816) | `App\Services\HardwareAuditScope` (live `n_code` → audit `n_code` snapshot → `persons.u_id`) is shared by `HardwareAuditController` and `HardwareIndexHelpers`. **Deny by default** — a null unit id means out of scope, so a trash row with no `n_code` in its snapshot is *hidden*, never listed (this deliberately changed the old `test_not_restorable_warning…` behaviour). Scope checks run **inside** `rollbackHistoryField()`/`restoreRecord()` before the shape/existence guards, never through `historyHardwareId` (the caller primes it with an authorized `loadHistory()`), and `restoreRecord()` reuses the **original** primary key — that is the duplicate-restore guard (`forceFill` + `setval`, because `id` is not fillable) |
| `#[Locked]` on `historyHardwareId` (#816) | First use of the attribute in this repo (it lives on the property in `app/Traits/HardwareIndexHelpers.php`, and trait attributes do reach the class). It is **defence in depth only**: the property is written server-side by `loadHistory()`/`fetchHistory()`, and `Locked` throws `CannotUpdateLockedPropertyException` (a 500, not a 422) if a legitimate flow ever lets the client set it. Do not cite it as the scope mitigation |
