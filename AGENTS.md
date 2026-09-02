# Health Dashboard (داشبورد سلامت) — Agent Rules

> **Doc review (2026-09-02):** Split AGENTS.md into 3 files (this + `references/data-model.md` + `references/api-endpoints.md`). This file stays under 20K chars to avoid truncation.

## Project Overview

Health Dashboard is a Laravel 13.x application for managing hospital/healthcare center hardware inventory, organizational units, tickets, and todos. Built with Livewire 4, MaryUI (DaisyUI), and Alpine.js. Fully RTL and Persian-language. Served to both a web UI and a Flutter mobile app (via Sanctum API tokens).

### Tech Stack

- **Framework:** Laravel 13.x (locked at **13.29.0** in composer.lock) on PHP ^8.3
- **Frontend:** Livewire 4 — **single-file (anonymous-class) components**: the PHP class lives inline at the top of its Blade view under `resources/views/livewire/<feature>/<name>.blade.php` as `return new class extends Component { ... };` (no separate file under `app/Livewire/`). Alpine.js, MaryUI (DaisyUI), Tailwind CSS 4
- **Database:** PostgreSQL 16 (Docker, `postgis/postgis:16-3.4`) with PostGIS for spatial/GIS data
- **Cache/Session/Queue:** Redis (Docker, `redis:latest`, password-protected via `REDIS_PASSWORD`)
- **Auth:** Laravel Sanctum (session guard for web, Bearer tokens for the Flutter app)
- **Package Manager:** Composer (backend); `package-lock.json` only (no pnpm-lock.yaml) → use **npm**: `npm install` + `npm run build` / `vite build` (Node 24, npm 12)

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

**Key permissions:** `manage_users`, `organization`, `kargozini`, `map`, `calendar`, `view_all_tickets`, `create_ticket`, `view_assigned_tickets`, `manage_roles`, `op-cache`, `manage_hardware`, `bw`, `view_hr_dashboard`, `manage_personnel`, `manage_unit_tickets`, `manage_org_chart`.

---

## Authentication

**Laravel Sanctum** with two modes:

| Mode | Routes | Auth Method |
|---|---|---|
| **Web (Session)** | Livewire UI pages | Cookie-based session via `web` guard |
| **API (Token)** | `/api/*` routes | Bearer token via `sanctum` guard |

- Livewire components expect session-based auth. **API tokens are NOT accepted** for Livewire pages.
- Login form at `/login`. API login: `POST /api/login` with `n_code` + `password` (throttled 5/min).

**Safe Role/Permission Middleware:** `SafeRoleOrPermission` is registered but **intentionally NOT used on hardware routes**. Hardware routes require full auth via `auth` + `role_or_permission:manage_hardware`.

> **Gotcha:** `test_hardware_page_loads_without_auth` asserts **302 → /login** for guests — a security decision. Do NOT "fix" it back to 200 — that reopens the data leak.

**Unit Context Middleware:** `ValidateUnitContext` ensures `session('current_unit_id')` is set before entering unit-scoped sections.

---

## Scheduler & Console Commands

| Command | Schedule | Purpose |
|---|---|---|
| `cache:prune-stale` | hourly | Resets cache version counters |
| `todos:generate-recurring` | daily 02:00 | Creates recurring todo instances |
| `maintenance:generate-due` | daily 03:00 | Generates due maintenance tickets |
| `data:archive` | weekly (Mon 04:00) | Moves old `activity_logs` → `activity_log_archives` |
| `reports:generate-daily` | daily 06:00 | Builds `daily_reports` rows per accessible unit |
| `zabbix:sync` | every 5 min | Pulls Zabbix traffic/latest values |

All commands take `--dry-run`. `reports:generate-daily` also supports `--unit=N`.

> **Do not add `->timeout(N)` to zabbix:sync schedule** — method doesn't exist, throws `BadMethodCallException`. HTTP timeout lives in `ZabbixService::request()` via `->timeout(10)`.

---

## Cache Version Namespaces

`CacheInvalidationService` uses driver-agnostic version-counter invalidation: cache keys are `{namespace}:v{version}:{scopeHash}:{extra}`, and a write bumps the counter. Hot paths use `Cache::remember(...)` with the versioned key.

**Key namespaces:** `hardware_stats`, `gis`, `maps`, `dashboard`, `hr_stats`, `unit_hierarchy`, `report_units`, `report_todos`, `report_tickets`, `calendar`.

`PruneStaleCache` resets all of them.

---

## Development Guidelines

### Conventions
- **RTL:** All layouts use `dir="rtl"` at root level
- **CSS:** Tailwind utility classes over custom CSS
- **Pagination:** `LengthAwarePaginator` with `WithPagination` trait
- **Forms:** MaryUI `x-input`, `x-select`, `x-button` components
- **Modal:** `x-modal` with `close-on-backdrop`
- **Components:** Livewire components are **single-file** — class is an inline anonymous class at the top of the Blade view (`return new class extends Component { ... };`). There are **no** `app/Livewire/*.php` class files. Reference components by dot-name string (`'hr.dashboard'`, `'kargozini.person'`, `'auth.login'`, `'tickets.ticket-comments'`) in routes and tests.
- **Testing:** Pest — `tests/Feature/*`, run via **`composer test`**
- **Factories:** Only `UserFactory` exists; other models have seeders. When seeding rows with **explicit IDs** in tests, resync Postgres sequence afterwards (`SELECT setval(...)`) or later inserts hit duplicate keys.
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes.
- **Tinker:** `php artisan tinker --execute '...'` — single quotes to prevent shell expansion. Prefer `database-query`/`database-schema` Boost MCP over raw SQL.
- **Artisan:** New migrations use `YYYY_MM_DD_000001_description.php` (sequential daily counter); pass `--no-interaction`.
- **Frontend rebuild:** After frontend changes run `npm run build` (or `vite build`).

### Laravel Boost (MCP)
Prefer `database-query`, `database-schema`, `search-docs`, `get-absolute-url`, `browser-logs` over manual alternatives; always search docs before code changes.

**Boost from CLI:** when no MCP transport is available:
```bash
php scripts/boost_tool.php <tool> '<json-args>'
# e.g. php scripts/boost_tool.php application-info '{}'
# php scripts/boost_tool.php db-schema '{}'
# php scripts/boost_tool.php query '{"sql": "SELECT ..."}'
# php scripts/boost_tool.php docs '{"query": "..."}'
```

---

## Running Tests (Pest)

Pest is the test runner. Uses **Livewire 4.4**, separate PostgreSQL test database `h_dashboard_test`.

> **✅ Working as of 2026-09-01:** **`composer test`** is the one-command way (**928 passed**, ~4 min). It bakes in the three environment gotchas.

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

`.github/workflows/test.yml` runs on PRs to `main`/`beta`/`test`:
- **Tests & Coverage (blocking)** — PHP 8.5, PostGIS + Redis containers, `./vendor/bin/pest --parallel --coverage --min=80` → Codecov
- **Mutation Testing (non-blocking)** — `--covered-only`, treat failures as informational

---

## Debugging Checklist

When code fails or tests break, follow this order:
1. **CodeGraph** — `codegraph query "<class/service>" --limit 5` for context
2. **Boost MCP** — `php scripts/boost_tool.php db-schema '{}'` or `php scripts/boost_tool.php query '{"sql":"..."}'`
3. **Context7** — query Laravel docs for framework-specific questions
4. **Tinker** — `php artisan tinker --execute '...'` for quick DB checks
5. **Pest** — `composer test` to verify nothing regressed

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
