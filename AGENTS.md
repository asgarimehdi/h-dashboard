# Health Dashboard (داشبورد سلامت) — Agent Rules

> **Doc review (2026-09-21):** Updated after 27+ commits since 2026-09-15. Added maintenance schedule, notification API, queued jobs, CSP/HSTS headers, normalizeForQuery, dead code removal. Reorganized to keep this file lean — detailed API, deployment, and performance patterns live in `references/`.
>
> **Doc review (2026-09-25):** 51 commits since 2026-09-21. Added: Sanctum **token abilities** on every `/api/*` route (#690), shared test trait `InteractsWithTestSetup` (71 test files), `@property` PHPDoc on all 24 models (#671), `SyncZabbixJob` dispatched every 5 min instead of the `zabbix:sync` schedule entry (plan 018), dead-code removal (#685), and the **E2E locale rule** (`APP_LOCALE=fa` in `.env.e2e`). Verified counts (2026-09-26): `composer test` = 1551 passed, Playwright = 173 passed.

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

- `HasOrganizationalScope` trait on models for automatic unit-based filtering
- Users see only their own unit's data (plus sub-units via recursive CTE)
- Permission `manage_hardware` required for hardware CRUD
- Roles: admin, operator, viewer

**AccessService** provides `accessibleUnitIds()` → unit IDs the current user can access (unit + descendants via recursive CTE). Results are cached and version-invalidated.

**Key permissions:** `manage_users`, `organization`, `kargozini`, `map`, `manage_zabbix`, `calendar`, `view_all_tickets`, `create_ticket`, `view_assigned_tickets`, `manage_roles`, `op-cache`, `manage_hardware`, `bw`, `view_hr_dashboard`, `manage_personnel`, `manage_unit_tickets`, `manage_org_chart`.

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
| `/api/units` | `ability:units:read` | `abilities:units:write` + `role_or_permission:organization` |
| `/api/zabbix/traffic`, `/api/zabbix/multi-latest` | `ability:traffic:read` | — |
| `/api/hardware/*` | `ability:hardware:read` | `abilities:hardware:write` + `role_or_permission:manage_hardware` |
| `/api/tickets*` (+ comments) | `ability:tickets:read` | `abilities:tickets:write` |
| `/api/reports/*` | `ability:reports:read` | — |
| `/api/persons/*` | `ability:persons:read` | `abilities:persons:write` + `role_or_permission:manage_personnel` |
| `/api/todos*` | `ability:todos:read,todos:write` (any of the two) | same middleware + `role_or_permission:calendar` |
| `/api/hr/*` | `ability:hr:read` | `role_or_permission:view_hr_dashboard` |
| `/api/notifications*` | `ability:notifications:read` | — |
| `/api/gis*` | `ability:gis:read` | `role_or_permission:map` |

- Mint a token with abilities: `$user->createToken('name', ['hardware:read'])->plainTextToken`.
- Groups that also carry `role_or_permission:*` need **both** — token ability and Spatie permission — or the request is 403.
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
- `Strict-Transport-Security: max-age=31536000; includeSubDomains` (HSTS)

> **Do not add `X-XSS-Protection`** — replaced by CSP. The old header is removed.

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

## Units Export (issue #701)

`GET /units/export` → `units-Ymd-His.xlsx`, gated by `role_or_permission:organization` (same group as `/units`).

- **Access-scoped** — rows = `UnitScopedRequest::accessibleIds()`; empty scope → header-only file.
- **One row per unit** (flat, sortable): `شناسه`, `نام واحد`, `نوع واحد`, `والد مستقیم`, `مسیر کامل`, `سطح`, `وضعیت`. Breadcrumb carries hierarchy instead of one column per level.
- Depth-first order (parents first, siblings alphabetical). Ancestors above the caller's scope still name the path. Inactive units included as `غیرفعال`.
- RTL via `WithEvents` → `AfterSheet` → `setRightToLeft(true)`.
- Button is a plain `<a href="{{ route('units.export') }}">` in `resources/views/livewire/units/index.blade.php` — **Livewire cannot return file downloads**, so never `wire:click` it.
- Files: `app/Exports/UnitsExport.php`, `app/Http/Controllers/Api/UnitsExportController.php`, tests in `tests/Feature/UnitsExportTest.php`.

> ✅ **`descendantIds` uses `UNION`, not `UNION ALL`** — deliberate. The set operator dedupes, so a `parent_id` cycle terminates (2ms) instead of hanging the connection (proven: `UNION ALL` on a cycle runs until `statement_timeout`). Do not "optimize" it back to `UNION ALL`. The export's own `buildHierarchy()` guards its upward walk separately.

---

## Reusable unit tree (issue #704)

`<livewire:unit.tree>` is the **single** implementation of the unit tree. Pages compose it instead of rebuilding tree logic.

- **Files:** `resources/views/livewire/unit/tree.blade.php` (state + controls) → `resources/views/livewire/unit/tree-node.blade.php` (recursive node) → `app/Services/UnitTreeService.php` (access-scoped queries). `resources/views/livewire/hr/org-node.blade.php` was **deleted** — do not recreate it.
- **Props:** `badge-view` (view rendered inside every node, e.g. `livewire.hr.personnel-badge`), `badge-data` (array keyed by unit id), `search-placeholder`.
- **Event:** the tree dispatches `unit-selected` (`id`); the page fills its own panel via `#[On('unit-selected')]`. `selectUnit()` lives in the **tree**, never in the page.
- **Default view:** first three levels, loaded **one query per level** — never one query per node (N+1 guard). `loadExpandedChildren()` batch-loads every expanded unit missing its children in a single `whereIn('parent_id', …)`.
- **Reuse rule:** a page supplies `badge-view` + `badge-data` for its per-node data; the tree never queries the page's own data (`personCounts` for HR).
- **PHPStan query shape:** every `UnitTreeService` chain ends on an **Eloquent-defined** call (`where()` / `whereKey()`). PHPStan resolves `whereIn()` through `Query\Builder`'s mixin, so any Eloquent-only call asked afterwards (`with`, `withCount`, model-returning `get()`) reports as undefined. Keep the trailing-call ordering — it produces identical SQL.
- **Tests:** `tests/Feature/UnitTreeServiceTest.php` (15), `tests/Feature/UnitTreeLivewireTest.php` (16), `tests/e2e/hr/org-chart.spec.ts` (4).

> ⚠️ **Known limitation kept for parity:** `expandAll()` only opens the roots' children — `collectAllIds()` is non-recursive, so only the root ids reach `expanded`. This is pre-existing behavior (the original `hr.org-chart` did the same) while the button label promises more. Issue #704 is a refactor with **no user-facing change**, so it was left untouched — candidate for a follow-up issue.

---

## Settings Features

Settings page (`/settings`) includes 4 user-configurable features:
- **Email notifications** — toggle email alerts via `EmailNotificationService`
- **Browser notifications** — push notification toggle
- **Auto-refresh** — dashboard auto-refresh interval (configurable via `dashboard_refresh` setting)
- **Compact mode** — denser UI layout toggle

---

## Maintenance Schedule

`/maintenance` route — Livewire component `maintenance.index` for CRUD on `MaintenanceSchedule` records.

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

Heavy operations are dispatched as queued jobs (plan 012). All implement `ShouldQueue` with retry/logging:

| Job | Timeout | Tries | Purpose |
|---|---|---|---|
| `ArchiveActivityLogsJob` | 300s | 3 | Deletes activity logs older than N days |
| `CleanNotificationsJob` | 300s | 3 | Deletes notifications older than N days |
| `GenerateDailyReportsJob` | 600s | 2 | Runs `GenerateDailyReports` artisan command |
| `SyncZabbixJob` | 30s | 2 | Fetches Zabbix interface traffic, caches it as `zabbix_traffic_data` (5 min TTL) |

The first three jobs accept a `$unitIds` array; empty defaults to `AccessService::accessibleUnitIds()`. All four have `failed()` methods that `Log::error()`. `SyncZabbixJob` takes no unit scope — it skips itself (warning log) when `services.zabbix.out_item_id` / `in_item_id` are not configured.

---

## Scheduler & Console Commands

Six commands plus one queued job are scheduled in `app/Console/Kernel.php`. The commands all take `--dry-run`:

| Scheduled item | Schedule |
|---|---|
| `cache:prune-stale` | hourly |
| `todos:generate-recurring` | daily 02:00 |
| `maintenance:generate-due` | daily 03:00 |
| `data:archive` | weekly (Mon 04:00) |
| `reports:generate-daily` | daily 06:00 |
| `SyncZabbixJob` (queued, **not** the `zabbix:sync` command) | every 5 min, `->withoutOverlapping()` |

`zabbix:sync` (plan 018) is **no longer scheduled** — the schedule dispatches `SyncZabbixJob` instead, so a slow Zabbix API can never block the scheduler. Run `php artisan zabbix:sync` manually when you need the command.

> Full command details, parameters, and gotchas: `references/api-endpoints.md` (Scheduler & Console Commands).
> **Do not add `->timeout(N)` to a schedule entry** — throws `BadMethodCallException`. HTTP timeout lives in `ZabbixService::request()` via `->timeout(10)`.

---

## Cache Version Namespaces

`CacheInvalidationService` uses driver-agnostic version-counter invalidation: cache keys are `{namespace}:v{version}:{scopeHash}:{extra}`, and a write bumps the counter. Hot paths use `Cache::remember(...)` with the versioned key.

**Key namespaces:** `hardware_stats`, `gis`, `maps`, `dashboard`, `hr_stats`, `unit_hierarchy`, `report_units`, `report_todos`, `report_tickets`, `calendar`.

`PruneStaleCache` resets all of them.

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
- **Shared test trait:** new Feature tests `use InteractsWithTestSetup;` (`tests/Support/Concerns/InteractsWithTestSetup.php`, 71 files already do) — provides `seedLookupTables()`, `resyncSequence()`, `createUserWithUnit($permissions, $role)`, `createHardware()`, `assertCacheInvalidated()`, `assertQueryCount()`, `assertNoNPlusOne()`. Do not re-implement user/unit/lookup seeding by hand; see `tests/Feature/ApiAbilityTest.php` for the standard `setUp()` (`PermissionSeeder` + `seedLookupTables()`).
- **Models:** all 24 Eloquent models carry `@property` PHPDoc annotations (#671). When you add an attribute/cast, update the annotation too — PHPStan level 6 + baseline depends on them.
- **Factories:** 14 factories exist (`UserFactory`, `UnitFactory`, `PersonFactory`, `HardwareFactory`, `TicketFactory`, `TodoFactory`, `SematFactory`, `TahsilFactory`, `EstekhdamFactory`, `RadifFactory`, `UnitTypeFactory`, `NotificationFactory`, `AttachmentFactory`, `TaskActivityFactory`). When seeding rows with **explicit IDs** in tests, resync the Postgres sequence afterwards (`SELECT setval(...)`) or later inserts hit duplicate keys — or call `$this->seedLookupTables()` / `$this->resyncSequence($table)` from the shared trait.
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes. Pint is enforced in CI and via pre-commit hook.
- **Tinker:** `php artisan tinker --execute '...'` — single quotes to prevent shell expansion. Prefer `database-query`/`database-schema` Boost MCP over raw SQL.
- **Artisan:** New migrations use `YYYY_MM_DD_000001_description.php` (sequential daily counter); pass `--no-interaction`.
- **Frontend rebuild:** After frontend changes run `npm run build` (or `vite build`).

### Composer Scripts
```bash
composer test      # config:clear + route:clear + XDEBUG_MODE=off php artisan test
composer dev       # concurrently: php artisan serve + queue:listen + npm run dev
composer pint      # Pint --dirty --format agent (auto-staged PHP)
composer phpstan   # phpstan analyse --no-progress
```

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

> **✅ Verified 2026-09-26:** **`composer test`** is the one-command way (**1551 passed, 2 risky, 3884 assertions**, ~5.6 min serial, ~50s parallel). It bakes in the three environment gotchas.
>
> `2 risky` = tests with no assertions (reported, non-blocking). If a Pest run fails with `database "h_dashboard_test" does not exist` on a handful of tests while the rest pass, it is a transient Postgres hiccup — re-run the file, then the suite.

### Key test files
| File | Tests | Purpose |
|---|---|---|
| `tests/Feature/Jobs/JobsTest.php` | 14+ | Tests queued jobs (archive, clean, generate) |
| `tests/Feature/MaintenanceLivewireTest.php` | 14+ | Maintenance schedule CRUD |
| `tests/Feature/NotificationApiTest.php` | 14+ | Notification API endpoints |
| `tests/Feature/TodoLivewireTest.php` | 17 | Todo Livewire component |
| `tests/Feature/UnitTreeServiceTest.php` | 15 | `UnitTreeService` — roots, scope, children, search, subtree |
| `tests/Feature/UnitTreeLivewireTest.php` | 16 | Reusable `unit.tree` — expand/collapse, search, badges, `unit-selected` |
| `tests/Unit/PersianNormalizerTest.php` | 6+ | `normalizeForSearch`, `escapeLikeWildcards`, `normalizeForQuery` |

### Prerequisites
```bash
docker compose -f docker-compose-pgsql-.yml up -d      # PostGIS on :5432, Redis on :6379
pg_isready -h 127.0.0.1 -p 5432                        # Verify PostGIS healthy
```

**Redis is NOT required for tests** — `phpunit.xml` forces `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`.

### Ensure test database exists
```bash
psql -h 127.0.0.1 -U h_dashboard -d h_dashboard -c \
  "CREATE DATABASE h_dashboard_test WITH OWNER=h_dashboard TEMPLATE=template_postgis;"
```

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

**173 tests** across **38 spec files** in `tests/e2e/` (verified 2026-09-26). Covers auth, navigation, RBAC, CRUD for users/tickets/personnel/units/hardware, reports, maps, dashboard, settings, search, activity log, tools, and the HR org chart (`tests/e2e/hr/org-chart.spec.ts`).

### Setup (one-time, per machine)
```bash
npm install                     # includes @playwright/test + dotenv
npx playwright install chromium # one-time browser install (Chromium + headless shell)
```

### `.env.e2e` — gitignored, must be created locally

It is in `.gitignore`, so it never ships with the repo. Create it once per machine:

```bash
cp .env.e2e.example .env.e2e
# copy from .env: APP_KEY, DB_USERNAME, DB_PASSWORD, REDIS_PASSWORD
# then create the isolated database (NEVER point e2e at `h_dashboard` — it is wiped every run):
psql -h 127.0.0.1 -U h_dashboard -d postgres \
  -c "CREATE DATABASE h_dashboard_e2e WITH OWNER=h_dashboard TEMPLATE=template_postgis;"
```

> **⚠️ `APP_LOCALE=fa` is MANDATORY in `.env.e2e`.**
> `.env.e2e.example` does **not** include `APP_LOCALE`, and `config/app.php` defaults to `en`. Without it the app renders English pagination (`Showing 1 to 20 of 318 results`, `Next »`) and English validation messages instead of the Persian strings every spec asserts → **11 tests fail** across `auth/password-change`, `hardware/list-filters`, `organization/units`, `personnel/list`, `users/list`.
> Required lines:
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
| 11 tests fail on `نمایش…` / `باید مطابقت داشته باشند` — DOM shows `Showing…` / English validation text | `.env.e2e` has no `APP_LOCALE=fa` (example file lacks it) | add the three `APP_*LOCALE*` lines above |
| `fixtures.ts` throws `<VAR> env var is required` or `.run-state.json not found` | `.env.e2e` missing / bare `npx playwright test` without global setup | create `.env.e2e`; run through `scripts/e2e-test.sh` |
| `database "h_dashboard_e2e" does not exist` | database never created | `CREATE DATABASE … TEMPLATE=template_postgis` |
| `Executable doesn't exist … chromium` | browser not installed | `npx playwright install chromium` |
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

`.github/workflows/deploy.yml` deploys on push to `main` (self-hosted runner).

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
| `scripts/e2e-test.sh` has no `trap` | A failing run exits before restore, leaving `.env` swapped to `h_dashboard_e2e`. Recover with `cp .env.dev.bak .env && rm -f .env.dev.bak`, then kill `:8001` (use `kill $(pgrep -f 'artisan serve')` — `pkill -f` kills the calling shell) |
| Rebuilding a lost `.env` | `.env` is gitignored. Rebuild from `.env-example-github` (the committed dev template) plus the secrets already resolved in `.env.e2e`, override `APP_URL=http://127.0.0.1:8000` and `DB_DATABASE=h_dashboard`, then drop any line whose value still contains `secrets.` (CI placeholders) or artisan dies with "environment file is invalid". Confirm with `php artisan about --only=environment` (expect `local`, locale `fa`). `parse_ini_file('.env')` fails here — unquoted parens — so scan lines with a regex instead |
| Dead routes removed | `/users/create`, `/users/{user}/edit`, `/docs/{page?}` — views never existed or were deleted |
| Todo calendar | Must use `@script` block (not inline JS) for wire:navigate compatibility |
| Person search | 500ms debounce applied — do not remove, causes Livewire update floods |
| Toast auto-dismiss | Default 5s timeout; `timeout: 0` means never dismiss |
| Search | Multi-word queries split and matched independently via `scopeFilterSearch` |
| `normalizeForQuery` | Use `PersianNormalizer::normalizeForQuery()` for ALL user-supplied LIKE queries — combines Persian normalization + wildcard escaping. Do NOT inline `str_replace(['%', '_'], ...)` |
| `PersianNormalizer` trait | Located at `app/Traits/PersianNormalizer.php`. Methods: `normalizeForSearch()` (Arabic→Persian + Unicode), `escapeLikeWildcards()`, `normalizeForQuery()` (normalize + escape combined) |
| `ZabbixService` errors | `TrafficController` and `MultiLatestValueController` catch `Throwable` and return 503, never 500 — do not remove try/catch |
| Root `/` route | `Route::redirect('/', '/dashboard')` — NOT a Livewire component. The old `index` Livewire component is removed |
| E2E locale | `.env.e2e` **must** set `APP_LOCALE=fa` — `.env.e2e.example` omits it, the app falls back to `en`, and 11 Persian-text specs fail (`Showing…`, English validation messages) |
| E2E env lifecycle | `scripts/e2e-test.sh` swaps `.env` and, on a failing run, `set -e` skips restore — restore `.env.dev.bak` and kill the `:8001` server yourself |
| `.env.e2e` / `h_dashboard_e2e` | Both gitignored/local-only; the e2e DB is `migrate:fresh --seed`ed every run — never point it at `h_dashboard` or `h_dashboard_test` |
| API token abilities | `/api/*` needs `auth:sanctum` **and** a token ability; `ability:a,b` = ANY of them, `abilities:a,b` = ALL. Tests mint real tokens (`ApiAbilityTest`) |
| Shared test trait | New Feature tests use `InteractsWithTestSetup` (`tests/Support/Concerns`) — `createUserWithUnit()`, `seedLookupTables()`, `resyncSequence()`, `assertNoNPlusOne()` |
| `zabbix:sync` scheduling | Schedule dispatches `SyncZabbixJob` (queued) every 5 min; the `zabbix:sync` command itself is manual-only |
| `descendantIds` CTE | Uses `UNION`, **not** `UNION ALL` — deliberate. `UNION ALL` does not dedupe, so a `parent_id` cycle recurses forever and hangs the connection (this query scopes every authenticated page via `AccessService`). Tested in `UnitModelTest` under a `statement_timeout` |
| `@property` on models | All 24 Eloquent models carry `@property` PHPDoc — update it when a column/cast changes (PHPStan level 6) |
| x-select option keys | MaryUI defaults to `optionValue='id'`/`optionLabel='name'`. Options keyed `value`/`label` need explicit `option-value="value" option-label="label"` or every `<option>` renders empty and the field looks blank (#706). Pass `:options="$this->someOptions()"` — a bare `$someOptions` is undefined in the view |
| Persian search must fold the COLUMN, not the pattern | `normalizeForQuery()` rewrites the search term (ZWNJ U+200C → space; آ/أ/إ U+0622/0623/0625 → ا) but the stored text keeps the original code points, so `LIKE` stops matching — a unit named "حرفه" + ZWNJ + "ای" or "آموزش" becomes invisible to its own filter. Postgres `LIKE` has only `%`/`_` and **no character-class syntax**, so `[ … ]` is literal and "either spelling" is unexpressible. Use `PersianNormalizer::foldSeparatorsSql($column)` (nested `regexp_replace` applying the same `charMap()` as `normalize()`, plus one `translate()` applying `digitMap()` so Persian/Arabic-Indic digits fold to Latin exactly as `normalizeForSearch()` does) compared against `foldedTerm($input)`. Replace ZWNJ with a SPACE, never `''` — deleting it makes the regex eat the next letter ("حرفه" + ZWNJ + "ای" → "حرفهای") |
| `LIKE '%term%'` is never index-seekable | Folding the column in `foldSeparatorsSql()` is not index-friendly, but `LIKE '%term%'` already forced a full scan, so this is not a regression to worry about |
| Faker names can collide with a `LIKE` filter | `PersonFactory` draws `fa_IR` `firstNameMale()`. "علی" itself (3/3000) and names containing it like "ابوعلی" (13/3000) come up in ~0.4% of draws, so a test filtering "علی" with `assertDontSee` on its own row flakes under `executionOrder="random"`. Pin both names explicitly in the test — do NOT fix it in `PersonFactory`, other tests assert on the raw faker value |
| Cache keys collide across tests | Postgres sequences are non-transactional, so `RefreshDatabase` restarts ids at 1 every test. `AccessService` keys on `accessible_units:v{version}:{user_id}:{session_unit_id}:{md5(baseIds)}` — byte-identical across tests, so a stale answer leaks from one test into the next. Put the flush in the base `TestCase::setUp()`, not in a test class — the seeder bump happens in the child's `setUp()`, which runs after `parent::setUp()`, so one flush there covers every class and needs no ordering rule. Never flush inside a test method |
| Parallel workers get their OWN database | Pest/Laravel creates `h_dashboard_test_test_{1..N}` per worker (`TestDatabases`), so workers do NOT share a database. Verified by listing the databases. If a parallel-only failure appears, suspect shared *in-process* state (cache keys, static properties), not the DB |
| Testing a cache fix | Assert through the component or the service, never by poisoning a key and expecting it to be ignored — that tests your own poison, not the flush. A test that only passes in isolation is asserting `setUp`, not the fix; assert the behaviour a user sees |
| Factories | 14 factories exist under `database/factories/` — do not hand-roll inserts or claim only `UserFactory` exists |
