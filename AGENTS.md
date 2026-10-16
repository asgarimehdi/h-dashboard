# Health Dashboard (داشبورد سلامت) — Agent Rules

> **How this file works:** one fact lives in exactly one file. This file keeps what *every*
> session needs — the project, the vocabulary, the **hard rules** (one line each, linked to their
> detail), the commands, and the map below. Everything else lives in `references/` and
> `docs/agents/`; add links, never copies. Split from a 96 KB all-in-one file on 2026-10-16 —
> keep this file under **200 lines / 18 KB**.
>
> **Canonical repo: https://github.com/asgarimehdi/h-dashboard** (base branch `beta`).
> Every other copy is a fork — never hardcode a fork URL or branch name; read
> `git remote -v` / `git branch --show-current` first.

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

> **Detailed data model & relationships:** `references/data-model.md` — API endpoints, scheduler, deployment, performance: `references/api-endpoints.md`

---

## Area & Vocabulary

- **Person** — HR record in the directory, linked to a `User` one-to-one via `n_code`.
- **User** — authenticated account; Spatie roles/permissions; linked to Person via `n_code`.
- **Unit** — organizational unit (hospital, health center, county); tree via `parent_id`.
- **UnitType** — classification of a Unit; allowed parent types via `unit_type_relationships`.
- **Region** — hierarchical geographic division (province or county).
- **Boundary** — GIS polygon (MULTIPOLYGON, SRID 4326) representing a geographic area.
- **Location Log** — GPS point recorded by the mobile app (`location_logs`).

**Key facts:** the `persons` PK is `id` (bigint) — `n_code` is only a *unique* `string(10)` and is **not** a safe key to carry across a JS boundary. `s_id` (not `semat_id`) is the FK column on `persons` for job title. `user_units` is the many-to-many user↔unit pivot (role enum `responsible`/`staff`, `is_primary` flag).

**Abbreviations:** `n_code` national code (person unique ID); `u_id` unit FK on persons; `CTE` common table expression (recursive SQL); `GIS` geographic information system; `SRID` spatial reference identifier (4326 = WGS84).

---

## Hard Rules

Each line is the whole rule; the link holds the detail and the evidence.

- **Unit scope is opt-in, local, and fail-closed.** `AccessibleUnitIds()` may legitimately be `[]` — that means *in scope of nothing*, never *unrestricted*. Never guard a scope predicate with `when($ids, …)`, `! empty($ids)`, or the `&&`-short-circuit spelling; use an unconditional `->whereIn($col, $accessibleIds)` (or `->accessible($col)`), which compiles `[]` to `0 = 1`. There are **three** fail-open spellings and a grep for one misses the other two. → [access-control](docs/agents/access-control.md)
- **`accessibleUnitIds()` is request-scoped UI code.** In a scheduler or queue worker it is always `[]` — use `AccessService::allUnitIds()` there (#836). → [features](docs/agents/features.md)
- **Sidebar link ↔ route gate must carry the same permissions** (`SidebarPermissionsTest` pins both directions). `role_or_permission` syntax is `a|b` = ANY — **not** Sanctum's comma form. Nested `middleware(['a','b'])` and nested permission groups are **AND**, not OR. → [access-control](docs/agents/access-control.md)
- **Spatie `canAny()` returns `false` for an unknown permission name** — a gate on a permission missing from a deployment's DB silently 403s every non-admin. Before adding a *new* permission to a gate, seed it everywhere or reuse an existing one. → [access-control](docs/agents/access-control.md)
- **A page on a read-union gate that also writes must authorize inside the component** (`$this->authorize('…')`), not only at the route — and must re-validate the *submitted* unit id, not just the stored one. → [access-control](docs/agents/access-control.md)
- **`/api/*` needs `auth:sanctum` *and* a token ability** (`ability:a,b` = ANY, `abilities:a,b` = ALL); reads and writes are independent groups (#895); tokens are scoped and revoked on password change. → [api-auth](docs/agents/api-auth.md)
- **Controllers depend on `ZabbixClient`, never `ZabbixService`.** A failed `ZabbixResult` maps to **503**; the `catch (\Throwable)` wrapper is gone — do not restore it. The `ZabbixSyncLog` failure streak is serial — never parallelise run recording. → [zabbix](docs/agents/zabbix.md)
- **The Leaflet instance is owned by `Alpine.store('map')`** — never `window.map`, never a "wait until a map exists" loop, never a Leaflet instance in Alpine reactive state. `maps/map.blade.php` is the only place allowed to call `L.map()`. → [maps](docs/agents/maps.md)
- **`units.boundary_id` is `ON DELETE CASCADE`** — null it out (`$unit->update(['boundary_id' => null])`) **before** deleting the boundary row, or the unit and its subtree vanish (#702). `Boundary::geojson` is a **bare geometry** (no `Feature`, and MultiPolygon must be unwrapped) or `fitBounds` throws. → [maps](docs/agents/maps.md)
- **`descendantIds` CTE uses `UNION`, not `UNION ALL`** — deliberate: `UNION` dedupes so a `parent_id` cycle terminates instead of hanging the connection. Do not "optimize" it back. → [features](docs/agents/features.md)
- **Livewire components are single-file, referenced by dot-name; there is no `app/Livewire/*.php`.** A typed Livewire model parameter binds by the model's **route key** — `Person`'s is `n_code` — so prefer `int $id` + `findOrFail($id)` in component methods. **Livewire cannot return file downloads** — export buttons are plain `<a href>`, never `wire:click`. → [features](docs/agents/features.md)
- **By-day charts use `DailySeries`** (`generate_series` + left-join the caller's own filtered query — fills empty days, keeps the picked range), and `['days' => $days]` belongs in the **cache key**. → [features](docs/agents/features.md)
- **Ticket archiving is a timestamp, never a status** — `status = 'completed'` + `archived_at` stamped, one-way (#929). → [features](docs/agents/features.md)
- **Never write children onto a model in a tree build** — `$unit->children` is a real `hasMany` relation; accumulate into a `$childrenByParent` map keyed by parent id and never read the relation (#933). → [features](docs/agents/features.md)
- **Persian search folds the COLUMN, not just the term.** Term: `normalizeForSearch()` once, `foldedTerm($input)` per term. Column: `foldSeparatorsSql($col)` for lookup-table columns (no save hook). A second folded list indexed by the raw term positions walks off the end (#940). `normalizeForQuery()` only for columns the model normalizes on save. → [search](docs/agents/search.md)
- **Every `$x->user` in a view is nullable** (`SoftDeletes` keeps the row and resolves `null`) — labels via `App\Support\ActorLabel` («سیستم» / «کاربر غیرفعال»). → [features](docs/agents/features.md)
- **`NotificationService::send()` is the static primitive** (creates the row, invalidates the bell cache); `SendNotificationJob` is only its queued per-recipient wrapper and has no `send()` of its own. → [features](docs/agents/features.md)
- **The schedule lives in `bootstrap/app.php` (`->withSchedule(...)`)** — `app/Console/Kernel.php` was never bound and is deleted. `SyncZabbixJob` every 5 min (not the `zabbix:sync` command, which is manual-only). No `->timeout(N)` on schedule entries — throws `BadMethodCallException`. → [features](docs/agents/features.md)
- **New Feature tests use `InteractsWithTestSetup`** — never re-implement user/unit/lookup seeding. After seeding rows with **explicit IDs**, resync the Postgres sequence (`SELECT setval(...)`) or later inserts hit duplicate keys. Assert on the **database**, never on component status. → [testing](docs/agents/testing.md)
- **Run Pest only via `composer test`** — it bakes in `config:clear` + `route:clear` + `CACHE_STORE=array` + `XDEBUG_MODE=off`; a bare run fails on any of those. Stale `routes-v7.php` causes the ~75-failure Livewire endpoint-hash mismatch. → [testing](docs/agents/testing.md)
- **PHPStan baseline is line-keyed** — after editing baselined code, regenerate it and verify `git diff phpstan-baseline.neon` adds **0** entries; `Eloquent\Builder` has no larastan, so start chains `Model::query()->with([...])` and put IN-filters inside `where(Closure)`. → [testing](docs/agents/testing.md)
- **A tracked env file carries no real `APP_KEY` (#953)** and the gitignored working `.env` **must** have one. `composer verify` fails fast if `.env` is missing — deliberate, not a bug. → [testing](docs/agents/testing.md)
- **E2E only via `scripts/e2e-test.sh`** (swaps `.env`, fresh DB, `:8001`) and `.env.e2e` **must** set `APP_LOCALE=fa` — without it 11 Persian-text specs fail. Never point e2e at `h_dashboard`; the e2e DB is wiped every run. → [e2e](docs/agents/e2e.md)

---

## Development Guidelines

### Conventions
- **RTL:** All layouts use `dir="rtl"` at root level
- **CSS:** Tailwind utility classes over custom CSS
- **Pagination:** `LengthAwarePaginator` with `WithPagination` trait
- **Forms:** MaryUI `x-input`, `x-select`, `x-button` components
- **x-select key mapping:** MaryUI defaults to `optionValue='id'` / `optionLabel='name'`. If your options use `value`/`label` keys you **must** pass `option-value="value" option-label="label"`, otherwise every `<option>` renders **empty** and the control looks blank (#706). Build option lists in a component method (`typeOptions()`) and pass `:options="$this->typeOptions()"` — a bare `$typeOptions` is undefined in the Blade view.
- **Modal:** `x-modal` with `close-on-backdrop`
- **Testing:** Pest — `tests/Feature/*`, run via **`composer test`**
- **Test Review Rule:** Every code change MUST include test review. Before finalizing: (1) check if existing tests cover the changed code, (2) add/update tests for new behavior, bug fixes, or contract changes. No code change ships without corresponding test coverage verification.
- **Factories:** 15 factories exist under `database/factories/`; `Ticket` has **no** `HasFactory` (build tickets via `Ticket::create([...])`), and `created_at` is not fillable — back-date with `forceFill([...])->saveQuietly()`.
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes. Pint is enforced in CI and via pre-commit hook.
- **Tinker:** `php artisan tinker --execute '...'` — single quotes to prevent shell expansion. Prefer `database-query`/`database-schema` Boost MCP over raw SQL.
- **Artisan:** New migrations use `YYYY_MM_DD_000001_description.php` (sequential daily counter); pass `--no-interaction`.
- **Frontend rebuild:** After frontend changes run `npm run build` (or `vite build`).

### Composer Scripts
```bash
composer test         # ensure-test-app-key (only if .env.testing has none) + config:clear + route:clear + XDEBUG_MODE=off php artisan test
composer verify       # .env existence check, then scripts/verify.sh (preflight + APP_KEY guard + view:clear + pint --test + phpstan + the suite)  ← run before push
composer verify tests/Feature/TodoLivewireTest.php   # same gates, only the tests you name
composer dev          # concurrently: php artisan serve + queue:listen + npm run dev
composer pint         # Pint --dirty --format agent (auto-staged PHP)
composer phpstan      # phpstan analyse --no-progress
composer phpstan-baseline  # phpstan analyse --generate-baseline
composer hooks:install  # git config core.hooksPath .githooks
```

> Verification layers (pre-commit Pint, pre-push full PHPStan, CI as the final authority) and all hook gotchas: [testing](docs/agents/testing.md).

### Laravel Boost (MCP)
Prefer `database-query`, `database-schema`, `search-docs`, `get-absolute-url`, `browser-logs` over manual alternatives; always search docs before code changes. When no MCP transport is available: `php scripts/boost_tool.php <tool> '<json-args>'` (e.g. `php scripts/boost_tool.php application-info '{}'`).

Discover MCP tools with `tool_search`, load schemas with `tool_describe`, invoke with `tool_call`. Always use CodeGraph before grep/glob for code-understanding tasks.

---

## Code Intelligence (CodeGraph)

[CodeGraph](https://github.com/colbymchenry/codegraph) — local (100% on-machine, SQLite, no API keys) code knowledge graph. Supports PHP/Laravel (routes → handlers) and cross-language flows.

**Hermes Agent MUST use CodeGraph for code-understanding tasks.** Before crawling files with grep/glob/Read to answer a structural question, run `codegraph explore` / `codegraph query` first.

```bash
codegraph explore "how does AccessService accessibleUnitIds resolve unit hierarchy"
codegraph status .
```

> Index is per-machine. `codegraph sync` catches up if a session edited files while no index was running.

### CI/CD

`.github/workflows/test.yml` runs on PRs with four jobs: **Code Style (Pint)** (blocking), **Tests & Coverage** (`--parallel --coverage --min=80`, blocking), **Mutation Testing** (non-blocking), **PHPStan** (blocking). Coverage is CI-only.

---

## Debugging Checklist

When code fails or tests break, follow this order:
1. **CodeGraph** — `codegraph query "<class/service>" --limit 5` for context
2. **Boost MCP** — `php scripts/boost_tool.php db-schema '{}'` or `php scripts/boost_tool.php query '{"sql":"..."}'`
3. **Context7** — query Laravel docs for framework-specific questions
4. **Tinker** — `php artisan tinker --execute '...'` for quick DB checks
5. **Pest** — `composer test` to verify nothing regressed

---

## Where the Detail Lives

| Topic | File |
|---|---|
| Access control, scope semantics, sidebar ↔ route contract, hardware-audit scoping | `docs/agents/access-control.md` |
| API endpoints & token abilities, security headers, CSP reporting, notification API, CORS | `docs/agents/api-auth.md` |
| Zabbix integration (`ZabbixClient`/`ZabbixResult`, sync logs, connection test, polling) | `docs/agents/zabbix.md` |
| Leaflet maps & GIS (map-store lifecycle, boundaries, draw toolbar) | `docs/agents/maps.md` |
| Feature contracts (exports, queued jobs, scheduler, unit tree, daily windows, settings) | `docs/agents/features.md` |
| Testing & verification detail (Pest per-file counts, PHPStan baseline, git hooks) | `docs/agents/testing.md` |
| E2E (Playwright setup, `.env.e2e`, helpers, failure table) | `docs/agents/e2e.md` |
| Persian search & normalization (folding, ZWNJ, `LIKE`) | `docs/agents/search.md` |
| Data model detail | `references/data-model.md` |
| API reference, scheduler details, deployment, performance | `references/api-endpoints.md` |

### Issue tracker

Specs and issues live as local markdown under `.scratch/`. See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context layout (`CONTEXT.md` + `docs/adr/` when present). See `docs/agents/domain.md`.

### Hermes session rules

Read `docs/agents/hermes-session-rules.md` before the first commit of a session.
