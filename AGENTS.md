# Health Dashboard (داشبورد سلامت) — Documentation

> **Doc review (2026-08-17):** Cross-checked against the working tree on branch `feature/mehdi` (Laravel 13.25.0 per `composer.lock`). Corrections vs. prior text: framework version (13.23.0 → 13.25.0); `pnpm-lock.yaml` does **not** exist (npm only); Person FK column is `s_id` not `semat_id`; migration count is **46** (not 21); the `zabbix:sync` schedule no longer sets `->timeout()` (reverted in `17cfd79`). See **Scheduler** and **Cache Version Namespaces** for verified current state.

## Project Overview

Health Dashboard is a Laravel 13.x application for managing hospital/healthcare center hardware inventory, organizational units, tickets, and todos. Built with Livewire 4, MaryUI (DaisyUI), and Alpine.js. Fully RTL and Persian-language. Served to both a web UI and a Flutter mobile app (via Sanctum API tokens).

### Tech Stack

- **Framework:** Laravel 13.x (`laravel/framework ^13.0`, locked at **13.25.0** in `composer.lock`) on PHP ^8.3
- **Frontend:** Livewire 4 (class-based components under `app/Livewire/`, views under `resources/views/livewire/`), Alpine.js, MaryUI (DaisyUI), Tailwind CSS 4
- **Database:** PostgreSQL 16 (Docker, `postgis/postgis:16-3.4`) with PostGIS for spatial/GIS data
- **Cache/Session/Queue:** Redis (Docker, `redis:latest`, password-protected via `REDIS_PASSWORD`)
- **Auth:** Laravel Sanctum (session guard for web, Bearer tokens for the Flutter app)
- **Import:** maatwebsite/excel (Laravel Excel) — `.xlsx`, `.xls`, `.csv`
- **Permissions:** spatie/laravel-permission ^8.0
- **Jalali calendar:** morilog/jalali (Jalalian), hekmatinasser/verta (installed)
- **AI:** openai-php/client ^0.20.1 (installed; used by feature tests, e.g. `AiAgentTest`)
- **Package Manager:** Composer (backend); frontend deps in `package.json` (Vite). Only `package-lock.json` is committed (there is **no** `pnpm-lock.yaml`), so use **npm**: `npm install` + `npm run build` / `vite build` (Node 24, npm 12)

---

## Data Model

### Core Entities

**Person** (`persons` table)
- `n_code` (PK, string)
- `f_name`, `l_name`
- `u_id` (FK to `units.id`)
- `s_id` (FK to `semats.id`) — job title (note: the column is **`s_id`**, not `semat_id`)
- `t_id` (FK to `tahsils.id`) — education level
- `e_id` (FK to `estekhdams.id`) — employment type
- `r_id` (FK to `radifs.id`) — rank/grade

**Hardware** (`hardwares` table)
- `id` (PK)
- `n_code` (FK to `persons.n_code`)
- `pc_name`, `type`, `os`, `ip_valid`, `ip_local`, `mac`
- `net_type`, `switch`, `port`, `shutdown` (boolean)
- `vlan`, `motherboard`, `cpu`, `ram`, `hdd`
- `comments`, `mark` (boolean)
- `clean_at` (nullable date)
- Relationship: `audits()` → `HardwareAudit` (field-level change audit trail, #213/#246)

**HardwareAudit** (`hardware_audits` table)
- `id`, `hardware_id` (no FK — audit trail survives hardware deletion), `user_id` (nullable FK), `action` (created, updated, deleted, bulk_mark, bulk_delete, force_deleted, rollback), `changes` (JSON: full attrs for created/deleted, `[{field, old, new}]` diff for updated), `source` (web, api, import, bulk), `ip_address`, `user_agent`
- Populated automatically by `HardwareAuditObserver` (registered in `AppServiceProvider`) — the single unified audit source (replaces the old `HardwareHistory` / `HardwareObserver`)
- Indexes: `(hardware_id, created_at)`, `user_id`, `action`
- API: `GET /api/hardware/{hardware}/audits` (paginated, filterable) + alias `GET /api/hardware/{hardware}/history`; export, show, and rollback endpoints; UI: history modal with rollback on `/hardware` page (#213/#246)

**Unit** (`units` table)
- `id`, `name`, `parent_id` (self-referencing for hierarchy), `lat`, `lng`, `unit_type_id`, `region_id`
- Indexes: `parent_id` (B-tree), composite `lat`+`lng` (B-tree), spatial indexes (PostGIS) on `boundaries`/`units`
- Relationships: `boundary` (hasOne), `children` (recursive), `parent`, `type`, `region`
- `Unit::ancestorIds()` resolves ancestor chains with a JOIN (recent performance fix, #187)

**Region** (`regions` table)
- `id`, `name`, `parent_id` (self-referencing), `unit_type_id`
- Indexes: `parent_id` (B-tree)

**Province** (`provinces` table)
- `id`, `name` (geographic province, used by map features)

**UnitType** (`unit_types` table)
- `id`, `name`; allowed parent types constrained via `unit_type_relationships`

**Semat** (`semats` table)
- `id`, `name` (job titles)

**Ticket** (`tickets` table)
- `id`, `title`, `description`, `status`, `priority`, `assignee_id`, `unit_id`
- Relationships: `unit`, `task` (→ `todos`, `task_id`), `user`, `assignee` (`current_assignee_id`), `attachments`, `activities` (`task_activities`)
- Composite index on `(task_id, status)` (recent performance fix, #183)
- Helpers: `canBeCompleted()`, `waitingDuration`, `statusName`

**Todo** (`todos` table)
- `id`, `title`, `is_completed`, `unit_id`

**TaskActivity** (`task_activities` table)
- `id`, `ticket_id`, `action` — audit trail for ticket lifecycle events (forward, assign, accept, complete)

**Attachment** (`attachments` table)
- `id`, `ticket_id`, file metadata — uploaded files attached to tickets

**ActivityLog** (`activity_logs` table)
- `id`, `user_id`, `action`, ... — user action audit trail (login/logout, CRUD), populated via `ActivityLogService`
- Composite index on `(user_id, created_at)` (recent performance fix, #191)

**Notification** (`notifications` table, custom)
- `id`, `user_id`, `title`, `body`, `icon`, `color`, `url`, `is_read`, `created_at` — in-app notifications via `NotificationService` (cached bell queries, #185)

**User** (`users` table)
- `id`, `n_code`, `name`, `email`, `password`
- BelongsToMany `units` via `user_units` pivot (with `role`, `is_primary`), `primaryUnit()`
- Spatie `HasRoles`

### Relationships

```
Hardware → Person (n_code)
Person → Unit (u_id → id)
Person → Semat (s_id → id)
Person → Tahsil (t_id → id)
Person → Estekhdam (e_id → id)
Person → Radif (r_id → id)
Unit → Unit (parent_id, recursive self-join)
Ticket → Todo (task_id)  Ticket → User (user_id / current_assignee_id)
Ticket → Attachment / TaskActivity
User ↔ Unit (user_units pivot)
```

### Key Linked Entities (from `opencode.md`)

- **User ↔ Person** are linked by `n_code` (not `id`). `Person.hasOne(User)` / `User.belongsTo(Person)`. `User` uses SoftDeletes; `User.units()` is a BelongsToMany via the `user_units` pivot (`role` enum: `responsible`/`staff`, `is_primary` flag); `primaryUnit()` returns the assignment where `is_primary = true`.
- **`user_units` pivot** — many-to-many user↔unit assignments (a user can belong to multiple units). Unique on `(user_id, unit_id)`. Seeded from `Person.u_id` via `UserUnitSeeder`.
- **Migration/style note:** FKs are explicitly named (e.g. `tickets_user_fk`, `ta_ticket_fk`). The migration count is now **46** — the earlier "21 clean migrations" consolidation has since grown with feature work (e.g. `hardware_audits`, `ticket_comments`, PostGIS geometry, GIN index). New migrations follow `YYYY_MM_DD_######_description.php`; both a sequential counter (`000001`) and a time-suffixed form (`002725`) appear in the tree. Avoid the classic `YYYY_MM_DD_HHMMSS` Laravel default and pass `--no-interaction`.

## Area & Vocabulary (from `CONTEXT.md`)

Definitions shared across codebase, docs, and team communication:

- **Person** — HR record in the directory, linked to a `User` one-to-one via `n_code`.
- **User** — authenticated account; Spatie roles/permissions; linked to Person via `n_code`.
- **Unit** — organizational unit (hospital, health center, county); tree via `parent_id`.
- **UnitType** — classification of a Unit; allowed parent types via `unit_type_relationships`.
- **Region** — hierarchical geographic division (province or county).
- **Boundary** — GIS polygon (MULTIPOLYGON, SRID 4326) representing a geographic area.
- **Location Log** — GPS point recorded by the mobile app (`location_logs`).

**Abbreviations:** `n_code` national code (person unique ID); `u_id` unit FK on persons; `CTE` common table expression (recursive SQL); `GIS` geographic information system; `SRID` spatial reference identifier (4326 = WGS84).

### FK Delete Behavior Summary (from `opencode.md` schema)

| FK | onDelete |
|----|----------|
| `users.n_code → persons` | restrict |
| `persons.e_id/t_id/s_id/r_id → lookups` | restrict |
| `units.region_id`, `units.parent_id` | restrict |
| `user_units.user_id → users` / `unit_id → units` | cascade |
| `tickets → task_activities`, `attachments` | cascade |
| `task_activities.activity_id → attachments` | cascade |
| `location_logs.user_id → users` | cascade |
| `todos.unit_id → units` | set null |
| `regions.boundary_id`, `units.boundary_id → boundaries` | cascade |

---

## Access Control

Uses **Spatie Permission** package. Key features:

- `HasOrganizationalScope` trait on models for automatic unit-based filtering
- Users see only their own unit's data (plus sub-units via recursive CTE)
- Permission `manage_hardware` required for hardware CRUD
- Roles: admin, operator, viewer

### AccessService

The `AccessService` class provides `accessibleUnitIds()` which returns an array of unit IDs the current user can access (their unit + all descendant units via recursive CTE).

### Permissions (from `PermissionSeeder`)

`manage_users`, `organization`, `kargozini` (HR lookup tables: estekhdam, tahsil, semat, radif, persons), `map` (GIS/location features), `calendar` (todo/calendar), `view_all_tickets`, `create_ticket`, `view_assigned_tickets`, `manage_roles`, `op-cache` (OPcache GUI at `/op`), `manage_hardware`, `bw` (IT monitoring: networks, wireless, server cache), plus more defined in the seeder.

---

## Authentication

The application uses **Laravel Sanctum** with two authentication modes:

| Mode | Routes | Auth Method | Usage |
|---|---|---|---|
| **Web (Session)** | All Livewire UI pages (`/hardware`, `/units`, `/tickets`, etc.) | Cookie-based session via `web` guard | Browser access, requires login form |
| **API (Token)** | `/api/*` routes | Bearer token in `Authorization` header via `sanctum` guard | Flutter app (token created with `createToken('flutter-app')`), cURL, external tools |

- Livewire components expect session-based authentication (web middleware group). **API tokens are NOT accepted** for Livewire pages — this is by design. To access Livewire UI, use a browser session.
- API routes accept Sanctum tokens generated via `PersonalAccessTokenFactory`.
- The login form is at `/login` (web session).
- Token generation (for testing/automation): `POST /api/sanctum/token` with valid credentials. The Flutter app uses `POST /api/login` with `n_code` + `password` (throttled 5/min).

### Safe Role/Permission Middleware

`SafeRoleOrPermission` (alias: `safe_role_or_permission`) is **registered and still used** (e.g. a `/test-safe-route` test route in `routes/web.php`), but it is **intentionally NOT used on hardware routes**. Issue #216 removed it from `/hardware` and `/hardware/import` because guests could see sensitive hardware data; hardware routes now require full auth via `auth` + `role_or_permission:manage_hardware`:

```php
Route::middleware(['auth', 'role_or_permission:manage_hardware'])->group(function () {
    Route::livewire('/hardware', 'hardware.index');
    Route::livewire('/hardware/import', 'hardware.import-hardware.import-hardware')->name('hardware.import');
});
```

> **Gotcha:** `test_hardware_page_loads_without_auth` (originally from #124) now asserts **302 → /login** for guests, matching the #216 security decision. Do NOT "fix" it back to 200 — that reopens the data leak.

### Unit Context Middleware

`ValidateUnitContext` middleware (alias `unit_context`) ensures `session('current_unit_id')` is set before entering unit-scoped sections; UI supports selecting a unit context (`/select-context`).

---

## Scheduler & Console Commands

Scheduled tasks are registered in `app/Console/Kernel.php` (Laravel 13 `schedule()`). Commands live in `app/Console/Commands/`:

| Command | Schedule | Class | Purpose |
|---|---|---|---|
| `cache:prune-stale` | hourly | `PruneStaleCache` | Resets all cache version counters (see **Cache Version Namespaces**) so stale versioned keys expire |
| `todos:generate-recurring` | daily 02:00 | `GenerateRecurringTodos` | Creates recurring todos (#214) |
| `maintenance:generate-due` | daily 03:00 | `GenerateDueMaintenance` | Generates due maintenance records |
| `data:archive` | weekly (Mon 04:00) | `ArchiveOldRecords` | Archives old records (#450) |
| `reports:generate-daily` | daily 06:00 | `GenerateDailyReports` | Builds daily reports (#450) |
| `zabbix:sync` | every 5 min | `SyncZabbix` | Pulls Zabbix traffic/latest values |

`zabbix:sync` uses `->withoutOverlapping()` + `->runInBackground()` to avoid blocking the scheduler. **Do not add `->timeout(N)` to the schedule** — that method does not exist on the installed Laravel version and throws `BadMethodCallException` (was attempted and reverted in `17cfd79`; the per-request HTTP timeout lives in `ZabbixService::request()` via `->timeout(10)` instead).

Other commands: `NormalizePersianText` (one-off Persian normalization), plus the standard `migrate`, `db:seed`, `queue:listen` (see `composer.json` `dev` script).

---

## Cache Version Namespaces

The `CacheInvalidationService` (`app/Services/CacheInvalidationService.php`, interface `CacheInvalidationServiceInterface`) implements driver-agnostic version-counter invalidation: cache keys are `{namespace}:v{version}:{scopeHash}:{extra}`, and a write bumps the counter so old keys become unreachable and expire via TTL (no full cache flush). Hot paths use it via `Cache::remember(...)` with the versioned key.

**Verified namespaces and what bumps them (current HEAD):**

| Namespace | Bumped by | Used for |
|---|---|---|
| `hardware_stats` | `Hardware::flushStatsCache()` (saved/deleted) | hardware stats aggregation |
| `gis` | Hardware, Unit, AppServiceProvider unit namespaces | GIS/map geometry |
| `maps` | Hardware (`flushStatsCache`), Person (saved/deleted) | map view |
| `dashboard` | Hardware (`flushStatsCache`), Person (saved/deleted) | dashboard counts |
| `hr_stats` | Person (saved/deleted), Unit | HR dashboard + org chart (`hr:dashboard:*`, `hr:orgchart:*`) |
| `unit_hierarchy` | Unit (ancestor/descendant queries) | `Unit::ancestorIds()` / `descendantIds()` |
| `report_units` / `report_todos` / `report_tickets` | (unit/calendar namespaces) | report endpoints |
| `calendar` | (calendar namespace) | todo/calendar |

`PruneStaleCache` resets all of: `hardware_stats, gis, maps, dashboard, hr_stats, report_todos, report_tickets, report_units, unit_hierarchy, calendar`.

> **Historical note:** Issue #457 narrowed these bumps (Person→`hr_stats` only, Hardware→`hardware_stats`+`gis`), but later commits `f721d13` (Person also bumps `dashboard`/`maps`) and `b9128b0` (Hardware also bumps `maps`/`dashboard`) **re-broadened** invalidation. The table above reflects current code, not the #457 summary.

---

## API Reference

All `/api/*` routes require `auth:sanctum` (Bearer token) and filter by the user's organizational scope. Token route: `POST /api/login` (`n_code` + `password`, throttled).

### Hardware CRUD (`/api/hardware`)

| Method | URL | Description |
|---|---|---|
| GET | `/api/hardware` | List with filters: `search`, `type`, `os`, `cpu`, `ram`, `hdd`, `shutdown`, `net_type`, `mark`, `person`, `unit`, `semat` |
| POST | `/api/hardware` | Create (requires `n_code`, `pc_name`) |
| GET | `/api/hardware/stats` | Aggregate stats (total, by type, shutdown count) — cached 10 min per org scope, invalidated on hardware writes (#217) |
| GET | `/api/hardware/{id}` | Show details |
| PUT/PATCH | `/api/hardware/{id}` | Update (partial updates allowed — only sends changed fields) |
| DELETE | `/api/hardware/{id}` | Delete |
| POST | `/api/hardware/bulk-mark` | `{ids: [...], mark: true/false}` |
| POST | `/api/hardware/bulk-delete` | `{ids: [...]}` |
| GET | `/api/hardware/{hardware}/history` | **Backward-compat alias** for `/audits` (paginated change history, action filter, org scope) |
| GET | `/api/hardware/{hardware}/audits` | Paginated audit trail — filters: `field`, `user_id`, `date_from`, `date_to`, `action`, `source`, `per_page` (max 50) |
| GET | `/api/hardware/{hardware}/audits/export` | Export audit trail as Excel/CSV (compliance report, Jalali dates) |
| GET | `/api/hardware/{hardware}/audits/{audit}` | Single audit record with full field diff + Persian labels |
| POST | `/api/hardware/{hardware}/audits/{audit}/rollback` | `{field: ...}` — restore a field to its previous value; creates a new `rollback` audit entry |

### Hardware Audit Trail (`/api/hardware/{hardware}/audits`)

Unified field-level audit trail (Issue #246 — merged with the old `/history` system; `hardware_histories` was migrated into `hardware_audits` and dropped).

| Action | Description |
|--------|-------------|
| `created` | Hardware record created (initial field snapshot) |
| `updated` | Field-level changes with old/new values |
| `deleted` | Hardware record deleted (captures hardware_id before deletion) |
| `bulk_mark` | Bulk mark/unmark operation (`source=bulk`) |
| `bulk_delete` | Bulk delete operation (`source=bulk`) |
| `force_deleted` | Force-deleted hardware |
| `rollback` | A field was rolled back to its previous value |

**Source tracking:** `web`, `api` (Sanctum/mobile), `import` (Excel import), `bulk` (bulk operations). Auto-detected by `HardwareAuditObserver`.

**Query Parameters (index):**
- `per_page` (max 50), `page`
- `field` (filter by changed field), `user_id`, `date_from`, `date_to`
- `action` (created/updated/deleted/bulk_mark/bulk_delete/rollback), `source` (web/api/import/bulk)

**Response:** Paginated audits with user info (n_code, name), source, IP, ISO + Jalali timestamps, and field-level changes.

**Scope:** Respects organizational scope — users only see audits for hardware in their accessible units (403 otherwise).

**Livewire Component:** "History / تغییرات" modal on `/hardware` page — shows date (Jalali), user, action badge, **source badge**, changed fields (old ← new badges), IP, and a **↺ بازگردانی (rollback)** button per field with confirmation. Rollback restores the field and logs a new `rollback` audit entry.

### Hardware Import (`/hardware/import`)

| Method | URL | Description |
|---|---|---|
| GET | `/api/persons` | List with filters: `search`, `unit_id`, `semat_id`. Supports `sort_by`, `sort_dir`, `per_page` |
| POST | `/api/persons` | Create (requires `n_code`, `f_name`, `l_name`) |
| GET | `/api/persons/{n_code}` | Show details with relationships (unit, semat, tahsil, estekhdam, radif) |
| PUT | `/api/persons/{n_code}` | Update |
| DELETE | `/api/persons/{n_code}` | Delete |

### Unit CRUD (`/api/units`)

| Method | URL | Description |
|---|---|---|
| GET | `/api/units` | List all units (tree structure with `parent_id`) |
| POST | `/api/units` | Create unit |
| GET | `/api/units/{unit}` | Show unit with children |
| PUT | `/api/units/{unit}` | Update |
| DELETE | `/api/units/{unit}` | Delete (cascades if children exist) |

### Ticket CRUD (`/api/tickets`)

| Method | URL | Description |
|---|---|---|
| GET | `/api/tickets` | List with filters: `status`, `priority`, `unit_id`, `assignee_id` |
| POST | `/api/tickets` | Create ticket |
| GET | `/api/tickets/{ticket}` | Show details |
| PUT | `/api/tickets/{ticket}` | Update |
| DELETE | `/api/tickets/{ticket}` | Delete |
| POST | `/api/tickets/{ticket}/assign` | Assign to user |
| POST | `/api/tickets/{ticket}/accept` | Accept assigned ticket |
| POST | `/api/tickets/{ticket}/complete` | Mark complete |

### Ticket Comments (`/api/tickets/{ticket}/comments`) — issue #222

| Method | URL | Description |
|---|---|---|
| GET | `.../comments` | List root comments (`?threaded=true` includes children, paginated) |
| POST | `.../comments` | Create comment (`body` required, `parent_id` for replies, max depth 3) |
| GET | `.../comments/{comment}` | Show comment with children + reactions |
| PUT/PATCH | `.../comments/{comment}` | Update (author only, within 15 min) |
| DELETE | `.../comments/{comment}` | Soft delete (author or admin/`manage_tickets`) |
| POST | `.../comments/{comment}/react` | Add reaction (`+1,-1,heart,tada,rocket,eyes`; idempotent) |
| DELETE | `.../comments/{comment}/react` | Remove reaction |
| GET | `.../comments/{comment}/reactions` | List reactions grouped with counts + users |

Web UI: `TicketComments` Livewire modal on the tickets inbox page (add/reply/edit/delete/reactions).
**Gotchas:** notifications use `NotificationService::send()` (static; NOT `create()`) and `route('tickets.inbox')` — `tickets.show` does not exist.

### Todo CRUD (`/api/todos`)

| Method | URL | Description |
|---|---|---|
| GET | `/api/todos` | List with filters: `unit_id`, `is_completed` |
| POST | `/api/todos` | Create |
| GET | `/api/todos/{todo}` | Show |
| PUT | `/api/todos/{todo}` | Update |
| DELETE | `/api/todos/{todo}` | Delete |
| POST | `/api/todos/{todo}/toggle-complete` | Toggle completion |

### Reports (`/api/reports`)

| Method | URL | Description |
|---|---|---|
| GET | `/api/reports/units` | Unit statistics |
| GET | `/api/reports/todos` | Todo statistics |
| GET | `/api/reports/tickets` | Ticket statistics |

### HR (`/api/hr`) — Issue #223

| Method | URL | Description |
|---|---|---|
| GET | `/api/hr/org-chart` | Full org tree with personnel counts per unit (nested JSON) |
| GET | `/api/hr/stats` | Aggregated HR stats (total, by unit/semat/tahsil/estekhdam/radif) |
| GET | `/api/hr/vacancies` | Units with zero personnel |
| GET | `/api/hr/personnel` | Paginated personnel list; filters: `search`, `unit_id`, `semat_id`, `tahsil_id`, `estekhdam_id`, `radif_id`, `status` |
| GET | `/api/hr/personnel/{n_code}` | Personnel detail with full HR profile (403 if out of scope) |

All scoped via `AccessService::accessibleUnitIds()`. Web pages: `/hr-dashboard` + `/hr/org-chart` (permission `view_hr_dashboard`).

### Zabbix (`/api/zabbix`)

| Method | URL | Description |
|---|---|---|
| GET | `/api/zabbix/traffic` | Network traffic from Zabbix (via `ZabbixService`) |
| GET | `/api/zabbix/multi-latest` | Multi-item latest values (cached) |

---

## UI Features

### Hardware Inventory Page (`/hardware`)

- **Quick Filters:** One-click presets (laptops, servers, SSD, 16GB+, shutdown devices)
- **Advanced Filters:** Toggle panel with 12+ filter fields (type, OS, CPU, RAM, HDD, net type, shutdown status, mark, person, unit, semat)
- **Bulk Actions:** Multi-select checkboxes — delete, mark/unmark devices in bulk
- **Status Badges:** Visual indicators (active 🟢, shutdown ⬛, marked ⚑)
- **Column Visibility:** Toggle columns on/off via a panel
- **Mobile Card Layout:** Table auto-converts to cards on small screens
- **Real-time n_code Validation:** Live validation against `persons` table with name/unit display
- **History / تغییرات modal (Audit Trail, #246):** per-device history button (🕐) opens a modal showing the unified `hardware_audits` trail — action badge, **source badge** (وب/API/ایمپورت/گروهی), user, IP, Jalali timestamp, field-level diff (old ← new), action filters (همه/ایجاد/ویرایش/حذف/علامت گروهی/حذف گروهی/بازگردانی), and a **↺ بازگردانی** rollback button per field (with confirmation). Rollback restores the field value and logs a new `rollback` audit entry.

### Hardware Import (`/hardware/import`)

- Excel/CSV import with validation
- Persian normalization for search fields
- Bulk create with duplicate handling

### Maps (`/maps`)

- Unit map, interactive map, county map, point map, route maps — all with organizational scope applied
- GIS data via PostGIS (boundaries as MULTIPOLYGON, SRID 4326); unit lat/lng with bounding-box queries (`withinBounds`)
- **Map container:** shared `maps.map` component renders `#map` with `h-[80lvh]`; pages must NOT wrap it in a Bootstrap `container` class (restricts width) — use `relative` so overlays position correctly; `invalidateSize()` runs after init + on resize so Leaflet never locks a half-width
- **Gotchas:** county map query joins `boundaries` — always qualify `regions.id` (ambiguous column error on pgsql otherwise)

### Other Pages

- Dashboard, users management, units (chart/map), roles/permissions, settings, profile, notifications, todos, tickets, tools (Zabbix), reports, activity log, kargozini (HR), IT monitoring
- **HR Dashboard** (`/hr-dashboard`, permission `view_hr_dashboard`): personnel stats (by unit/semat/tahsil/estekhdam/radif) + vacancies; **Org Chart** (`/hr/org-chart`): recursive unit tree with expand/collapse, personnel counts, empty-unit badges. Components under `app/Livewire/Hr/`, views under `resources/views/livewire/hr/`. Aggregations cached 5 min per org scope (`hr:dashboard:*`, `hr:orgchart:*`).

### Help System (راهنما)

In-app help is a per-page modal (`?` button in page headers):

- **Components:** `resources/views/components/help/` — `button.blade.php` (dispatches `help-open` with the section) + `modal.blade.php` (listens via `Livewire.on('help-open')`, switches content with Alpine `x-if` on `helpSection`, opens by setting the page's `showHelpModal` property)
- **Content:** one file per section under `resources/views/components/help/content/<section>.blade.php`, registered in `AppServiceProvider::boot()` (`$helpContents`) as `help-content:<section>` components
- **Wiring a new page:** add `public bool $showHelpModal = false;`, `<x-help:button section="<section>" wireModel="showHelpModal" />` in the header actions, and `<x-help:modal wireModel="showHelpModal" />`; create the content file + register it + add an `x-if` case in the modal
- **Sections (20):** dashboard, hardware, hardware-import, persons-import, personnel, units, tickets, todos, reports, maps, settings, roles, permissions, users, activity-log, networks, wireless, tools, search, profile
- **Gotchas:** escape Blade directives in content with `@@` (e.g. `@@can(...)`); use only icons present in the heroicons set (`o-*` in `vendor/blade-ui-kit/blade-heroicons/resources/svg/`)
- **Tests:** `tests/Feature/HelpSystemTest.php` — page renders + all 20 content sections render

---

## Design System & Frontend Conventions

### Design tokens (from `DESIGN.md`)

- **CSS:** Tailwind CSS v4 · **Components:** DaisyUI v5 (Tailwind plugin) + maryUI v2.8 (Livewire components)
- **Palette:** Primary `emerald` (success/online), Secondary `orange` (warning/pending), Error `red` (danger/offline), Info `blue`
- **Themes:** dark `synthwave`, light `fantasy` — set via `data-theme` on `<html>`; sidebar uses `currentColor` SVG for theme adaptability
- **Typography:** **Vazirmatn** (Persian) via `@font-face`; fallback system stack
- **Icons:** Heroicons via maryUI (`o-` outline, `s-` solid); custom SVGs in `public/icons/` (unit-type icons)
- **Layouts:** `resources/views/components/layouts/app.blade.php` (sidebar) and `auth.blade.php` (minimal)

### maryUI component inventory (used across pages)

`<x-nav>`, `<x-main>`, `<x-menu>` / `<x-menu-item>` / `<x-menu-sub>`, `<x-app-brand>`, `<x-header>` (w/ `:middle` / `:actions` slots), `<x-card shadow>`, `<x-table>` (+ `@scope` cell / `expansion`), `<x-stat>`, `<x-badge>`, `<x-icon>`, `<x-form>`, `<x-input>`, `<x-select>`, `<x-textarea>`, `<x-checkbox>`, `<x-toggle>`, `<x-file>`, `<x-choices-offline>`, `<x-errors>`, `<x-modal>` (with `persistent`, `<x-slot:actions>`), `<x-button>` (with `wire:confirm`, `external`, `responsive`), `<x-toast>`, `<x-theme-toggle darkTheme="synthwave" lightTheme="fantasy" />` (wrapped as `<x-theme-selector>`).

- **Layout slot structure:** `<x-nav sticky>` with `<x-slot:brand>` + `<x-slot:actions>`; `<x-main>` with `<x-slot:sidebar>` + `<x-slot:content>`.
- **Toasts** (`Mary\Traits\Toast`): `$this->success/warning/error/info('msg', position: 'toast-bottom')`. SweetAlert2 via `$this->dispatch('swal', [...])`.

### Standard CRUD page pattern (class-based Livewire)

```
PHP class:
  - use WithPagination, Toast
  - public bool $modal; string $search; array $sortBy; int $perPage
  - headers(): array; items(): LengthAwarePaginator (with withAggregate())
  - resetModal(): clears all form fields (MUST call on create button AND cancel)

Blade:
  <x-header title="" separator progress-indicator>
  <x-card shadow>
    <x-button class="btn-success" wire:click="resetModal" @click="$wire.modal=true" icon="o-plus"/>  // create
    <x-input wire:model.live.debounce="search"/>
    <x-table :headers :rows :sort-by with-pagination> @scope('actions') ... @endscope </x-table>
  </x-card>
  <x-modal wire:model="modal" persistent separator>
    <x-form wire:submit.prevent="save" class="grid grid-cols-2 gap-4"> ... <x-button type="submit" class="btn-primary"/> </x-form>
  </x-modal>
```

### Third-party JS libraries (loaded in app layout)

- **Leaflet** — `leaflet.js`, `leaflet.draw.js`, `leaflet-routing-machine.min.js`, `leaflet.geometryutil.js`
- **Highcharts** — `highcharts.js`, `treemap.js`, `treegraph.js`, `exporting.js`
- **FullCalendar** — `full-calendar.min.js` (RTL/Farsi locale)
- **Jalali datepicker** — `jalalidatepicker.min.js` (`data-jdp` attribute)
- **SweetAlert2** — via `Livewire.on('swal', ...)`

### Responsive patterns

- Hide table columns on small screens: `class="hidden sm:table-cell"`, `hidden 2xl:table-cell`
- Grid layouts: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4`
- Keep icon, hide text: `<span class="hidden 2xl:inline">ویرایش</span>`
- Sidebar: `collapsible` + `drawer="main-drawer"` for mobile

---

## Persian Text Handling

The `PersianNormalizer` trait normalizes:
- `ي` → `ی`
- `ك` → `ک`
- ZWNJ (zero-width non-joiner) → space

Applied to all search and filter operations in both Livewire components and API controllers.

---

## Jalali Dates

Jalali (Persian) calendar formatting via `Morilog\Jalali\Jalalian` (e.g., in `ReportController`, `Ticket::statusName`). `hekmatinasser/verta` is also installed. Use `Jalalian::fromCarbon(...)` for API date output and display.

---

## Development Guidelines

### New Features
1. Add a GitHub issue describing the change
2. Branch → develop → test → PR
3. Maintain RTL compatibility
4. Use MaryUI components where possible
5. Add permission checks for new routes
6. Apply organizational scope (`HasOrganizationalScope`) to any new list/query

### Conventions
- **RTL:** All layouts use `dir="rtl"` at root level
- **CSS:** Prefer Tailwind utility classes over custom CSS
- **Pagination:** Use `LengthAwarePaginator` with `WithPagination` trait
- **Forms:** Use MaryUI `x-input`, `x-select`, `x-button` components
- **Modal:** Use `x-modal` with `close-on-backdrop` for edit forms
- **Components:** Class-based Livewire components (`app/Livewire/<Feature>/...`) with Blade views under `resources/views/livewire/<feature>/`
- **Testing:** Pest (PHPUnit under the hood) — `tests/Feature/*`, run with `./vendor/bin/pest` or `php artisan test --compact` / `--filter=testName`
- **Factories:** use factories with custom states (only `UserFactory` exists; other models have seeders). Don't delete tests without approval.
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes.
- **Tinker:** `php artisan tinker --execute '...'` — single quotes to prevent shell expansion. Prefer `database-query`/`database-schema` Boost MCP over raw SQL in tinker. Don't create models in tinker without approval.
- **Laravel Boost (MCP):** prefer `database-query`, `database-schema`, `search-docs`, `get-absolute-url`, `browser-logs` over manual alternatives; always search docs before code changes.
- **Artisan:** new migrations use `YYYY_MM_DD_000001_description.php` (sequential daily counter), not timestamps; pass `--no-interaction` to all Artisan commands.
- **Frontend rebuild:** after frontend changes run `npm run build` (or `vite build`); Livewire for dynamic UI, Alpine.js for client-side interactions.

### Performance (recent fixes pattern)
- Cache hot queries with `Cache::remember(...)` (stats, notification bell, search, tools) and invalidate on writes
- Version-counter invalidation: `hardware_stats_version` bumps on hardware writes; stats keys `hardware_stats:v<N>:<md5(accessibleIds)>` become unreachable and expire via TTL (driver-agnostic, avoids full cache flush) (#217)
- Eager-load relationships (`with('person.unit')`) in list queries
- Limit API pagination to max 100 per page
- Use recursive CTE via raw SQL for unit hierarchy queries; `Unit::ancestorIds()` for ancestor chains
- Add composite indexes for hot filter paths (e.g. `(task_id, status)` on tickets, `(user_id, created_at)` on activity_logs)
- Apply `PersianNormalizer` on all text search inputs

### Recent Issues Resolved
- **#463** — Prevent Duplicate Hardware Audit Entries on Bulk Operations
  - **Problem**: Bulk operations (e.g., `bulk-mark`, `bulk-delete`) on hardware records could create **duplicate audit entries** in the `hardware_audits` table. While the current implementation bypasses Eloquent events, there was a risk of duplicates if Eloquent events were ever used for bulk operations in the future.
  - **Solution**:
    - Added a `Hardware::$suppressAudit` flag to suppress audit logging during bulk operations.
    - Updated the `HardwareAuditObserver` to respect this flag.
    - Set the flag in `bulkMark` and `bulkDelete` methods before performing the operations.
  - **Files**:
    - `app/Models/Hardware.php` (flag addition).
    - `app/Observers/HardwareAuditObserver.php` (flag check).
    - `app/Http/Controllers/Api/HardwareController.php` (flag usage in bulk operations).
  - **Impact**: Eliminated the risk of duplicate audit entries during bulk operations.

- **#462** — ZabbixSync HTTP Timeout + Scheduler Blocking Prevention
  - **Problem**: The `zabbix:sync` command could hang indefinitely due to:
    - No HTTP timeout for Zabbix API calls.
    - No protection against overlapping executions.
    - Blocking the Laravel scheduler while waiting for the sync to complete.
  - **Solution**:
    - **HTTP Timeout**: Added `->timeout(10)` to all Zabbix API calls in `ZabbixService::request()`.
    - **Scheduler Protection**: The schedule uses `withoutOverlapping()` + `runInBackground()` to prevent overlap and scheduler blocking. A `->timeout(15)` on the schedule was attempted but **reverted** — that method does not exist on the installed Laravel version and threw `BadMethodCallException` (commit `17cfd79`). See the **Scheduler** section.
    - **Error Handling**: The `SyncZabbix` command now catches exceptions and logs warnings without crashing.
  - **Files**:
    - `app/Services/ZabbixService.php` (HTTP timeout).
    - `app/Console/Kernel.php` (scheduler improvements).
    - `app/Console/Commands/SyncZabbix.php` (error handling).
  - **Impact**: Eliminated scheduler blocking and prevented indefinite hangs in Zabbix API calls.

- **#460** — HR Vacancy Trend N+1 Query Performance Fix
  - **Problem**: `HrController::vacancyTrend()` used an N+1 query loop (1 query per month), causing 13-25 queries for the default 12-24 month range.
  - **Solution**: PostgreSQL single query with `generate_series` + `CROSS JOIN`; SQLite PHP fallback. (Same root fix as **#461**; #460/#461 are duplicate issue numbers for this work.)
  - **Files**: `app/Http/Controllers/Api/HrController.php`.
  - **Impact**: Reduced query count from O(N*M) to O(1) (PostgreSQL).

- **#459** — ZabbixSync HTTP Timeout and Scheduler Blocking
  - **Problem**: `ZabbixService::request()` had no HTTP timeout, risking scheduler blockage if the Zabbix API was slow.
  - **Solution**: Added `->timeout(10)` to `Http::post()` in `ZabbixService`; Kernel schedule uses `withoutOverlapping()` + `runInBackground()`. Note: a `->timeout(15)` on the **schedule** was attempted here but later **reverted** (`17cfd79`) — that method does not exist on the installed Laravel version. See the **Scheduler** section and issue **#462**.
  - **Files**: `app/Services/ZabbixService.php`, `app/Console/Kernel.php`.
  - **Impact**: Prevents scheduler blockage and overlapping executions.

- **#458** — XSS in `TicketCommentController` via Unquoted HTML Event Attributes
  - **Problem**: `sanitizeUrl()` removed quotes but allowed unquoted event attributes (e.g. `onmouseover=alert(1)`) in URLs, causing stored XSS when rendered in `<a href="...">`.
  - **Solution**: Replaced the `preg_replace` approach with `htmlspecialchars(ENT_QUOTES | ENT_HTML5)` to escape all dangerous characters (spaces, quotes, angle brackets) in URLs.
  - **Files**: `app/Http/Controllers/Api/TicketCommentController.php`.
  - **Impact**: Blocks XSS payloads like `[click](http://example.com onmouseover=alert(1))`.

- **#461** — HR Vacancy Trend N+1 Query — Single Query with generate_series (PostgreSQL)
  - **Problem**: The `/api/hr/analytics/vacancy-trend` endpoint suffered from an N+1 query problem. The original implementation fetched personnel records month-by-month, resulting in a separate query for each month and unit.
  - **Solution**: Replaced the N+1 logic with a **single PostgreSQL query using `generate_series`** to compute vacancy counts for all months in one pass. The query:
    - Uses `generate_series` to create a time series of months.
    - Joins with `accessible_units` (user's organizational scope).
    - Counts personnel per unit per month via `personnel_counts`.
    - Computes vacant units as `COUNT(au.id) - COUNT(pc.u_id)`.
  - **Fallback**: Non-PostgreSQL databases (e.g., SQLite) use a PHP-based fallback that preloads all personnel records and computes vacancy counts in memory.
  - **Files**: `app/Http/Controllers/Api/HrController.php` (lines 380–459).
  - **Impact**: Reduced query count from **O(N*M)** (N = months, M = units) to **1 query** for PostgreSQL users.

- **#457** — Cache Version Counter Over-Invalidation
  - **Problem**: `Hardware`, `Person`, and `Unit` models incremented unrelated cache version counters (e.g., `maps`, `dashboard`), causing unnecessary cache invalidation and performance degradation.
  - **Solution**: Removed over-broad increments:
    - `Hardware`: Keep `hardware_stats` and `gis` (hardware affects GIS data).
    - `Person`: Keep `hr_stats` only.
    - `Unit`: Keep `report_units`, `unit_hierarchy`, `gis`, `hr_stats`.
  - **Files**: `app/Models/Hardware.php`, `app/Models/Person.php`, `app/Providers/AppServiceProvider.php`.
  - **Impact**: Reduced cache misses by **60-80%** for map/dashboard users at the time. **Caveat:** later commits (`f721d13`, `b9128b0`) re-broadened invalidation to also bump `dashboard`/`maps` when Persons/Hardware change — the **Cache Version Namespaces** section reflects current HEAD, not this #457 description.

- **#456** — GIS Bounding Box Cache Precision Fix
  - **Problem**: `normalizedBbox()` rounded coordinates to **2 decimal places** (≈1.1 km precision), causing cache keys to mismatch the actual bbox used in SQL queries. This led to incorrect map data (e.g., showing units outside the viewport).
  - **Solution**: Increased precision to **4 decimal places** (≈11 m precision) in `GisController::normalizedBbox()`. Cache keys now match the bbox used in SQL filters.
  - **Files**: `app/Http/Controllers/Api/GisController.php` (lines 63-87).
  - **Tests**: No new tests added — verified via manual testing and cache key inspection.

- **#195** — Interactive GIS Map Dashboard for Health Units
  - **MapDashboard Livewire Component** (`app/Livewire/Map/MapDashboard.php`): Full-screen Leaflet map at `/map` with unit/hardware/ticket layers, stats panel, unit detail modal, bbox-driven data loading
  - **GIS API Controller** (`app/Http/Controllers/Api/GisController.php`): 5 GeoJSON endpoints (`units`, `hardware`, `tickets`, `stats`, `clusters`) using lat/lng columns (not geom), bbox spatial filtering, accessible-units scope via `AccessService`
  - **Routes**: `GET /map` (web, `role_or_permission:map`), `GET /api/gis/*` (Sanctum, named `api.gis.*`)
  - **Blade Template** (`resources/views/livewire/map/map-dashboard.blade.php`): Alpine.js `mapDashboard()` with `x-data`, layer toggles, filters, Leaflet markers/divIcons, `@this` Livewire-Alpine communication
  - **Tests**: `tests/Feature/GisApiTest.php` (10 tests, GeoJSON structure, bbox filtering, permissions), `tests/Feature/MapDashboardTest.php` (12 tests, Livewire events, Alpine methods, auth, rendering)
  - **Frontend**: Added "داشبورد GIS" to sidebar under "کار با نقشه"
- **#213** — Hardware Change History & Audit Trail API (`hardware_audits` table, `HardwareAuditObserver`, `GET /api/hardware/{hardware}/audits`, Livewire history modal with Jalali dates and rollback). Merged with #246: the old `HardwareHistory`/`hardware_histories` system was migrated into the unified `HardwareAudit`/`hardware_audits` trail.
- **#246** — Hardware Field-Level Audit Trail & Change History: source tracking (web/api/import/bulk), rollback endpoint + Livewire per-field ↺ بازگردانی, Excel/CSV compliance export (`HardwareAuditsExport`), filterable API (`field`/`user_id`/`date_from`/`date_to`/`action`/`source`), no FK cascade on `hardware_id` so audit survives deletes. Tests: `HardwareAuditTest`, `HardwareAuditDetailTest`, `HardwareAuditLivewireTest`, `HardwareAuditMigrationTest`.
- **#217** — Hardware Stats Caching with version-counter invalidation (10-min TTL, driver-agnostic, auto-invalidated on all hardware writes)
- **#218** — In-app Help System completion (20 content sections, `HelpSystemTest`, Alpine-based modal switching)
- **#238** — Fixed `Undefined variable $request` in 6 API methods missing the `Request` parameter (`TodoController::toggleComplete/destroy`, `PersonController::destroy`, `TicketController::show/destroy`, `ReportController::units`)

### Recent Changes (Last 7 Days — August 2026)
- **Hardware Audit GIN Index** — New migration `2026_08_11_000001_add_gin_index_to_hardware_audits_changes.php` adds a GIN index on `hardware_audits.changes` JSONB column for faster `whereJsonContains` queries (#454).
- **HR Controller Performance Suite** — Multiple optimizations:
  - Single-pass `JSON_AGG` aggregation for `HrController::stats` (#436)
  - Org chart cached with `hr_stats_version` key (#435)
  - JOIN-based personnel list, dropping 5 eager-load queries (#440)
  - Fixed cross-join inflating `HR stats` JSON_AGG counts (#441)
  - SQLite compatibility fix for tests (#455)
- **Hardware Controller** — JOIN-based filtering in `index` to drop `whereHas` (#430); JOIN-based scope checks in `stats/bulkMark/bulkDelete` (#433); eager-load `person.unit` in store/show/update to kill N+1 (#405).
- **TicketComments Livewire** — Eager-load `children.user.person` to fix N+1 in `loadTicket` (#455).
- **Todo Controller** — Explicit unit scope validation + `primaryUnit` fallback in `store` (#431).
- **GIS Controller** — Cluster cache TTL raised from 30s to 60m, consistent with `gis_version` invalidation (#437).
- **Security** — Hardened `sanitizeUrl` against attribute-breaking chars in ticket comments (#425).
- **Models** — Corrected `person()` FK on `Semat`/`Tahsil`/`Estekhdam`/`Radif` models (#404).
- **NotificationService** — Fixed UUID generation in `notifyUnit()` (#434).
- **Test Coverage Expansion** — Added 30+ new test files covering:
  - `HardwareBulkOperationsTest` (bulk-mark, bulk-delete, audit entries, auth)
  - `ActivityLogPageLivewireTest` (auth, mount, filters, detail modal, pagination)
  - `ToolsLivewireTest` (stats, archive tickets, clean activities/notifications, validation)
  - `LookupSimpleModelsTest` (Region, Boundary, UnitType, UnitTypeRelationship, Semat, Tahsil, Estekhdam, Radif)
  - `ActivityLogModelTest`, `ChangePasswordTest`, `SettingsProfileTest`, `OtherModelsTest` (TaskActivity, Attachment, TicketCommentReaction)
  - `ReportsApiTest` (units/todos/tickets reports, auth, scope)
  - `HardwareAuditModelTest` (relationships, changes cast, survives deletion, action/source types)
  - `HasOrganizationalScopeTest` (unit filtering, child units, related loading)
  - `UnitModelTest` (hierarchy, ancestorIds, descendantIds, scopes, relationships)
  - `TodoModelTest`, `UsersPageTest`, `ApiLoginTest`, `LogoutTest`, `PersonModelTest`
  - `TicketCommentModelTest`, `NotificationModelTest`, `HardwareModelTest`, `TicketModelTest`, `UserModelTest`
  - `ActivityLogServiceTest`, `NotificationServiceTest`, `AccessServiceTest`
  - `TicketCommentPolicyTest`, `PersianNormalizerExtraTest`, `HardwareAuditObserverTest`, `HardwareObserverTest`, `PagesRenderTest`, `AppBrandTest`
  - Page-render and middleware coverage for Dashboard, IT, Maps, Permissions, Profile, Roles, Search, SelectContext, Settings, Tools, ValidateUnitContext

---

## Deployment

### Docker (local development)

`docker-compose-pgsql-.yml` runs the full stack:

| Service | Image | Port |
|---|---|---|
| PostGIS | `postgis/postgis:16-3.4` | 5432 |
| Redis | `redis:latest` (requirepass `REDIS_PASSWORD`) | 6379 |
| pgAdmin | `dpage/pgadmin4` | 8082 |
| phpRedisAdmin | `erikdubbelboer/phpredisadmin` | 8083 |

`docker compose -f docker-compose-pgsql-.yml up -d`

> **Note:** Postgres only reads `POSTGRES_PASSWORD` on first init of the `postgis_data` volume. To change a password on an existing volume: `docker exec -it h-dashboard-postgis psql -U <user> -d <db> -c "ALTER USER <user> WITH PASSWORD '<new>';"`. Recreating without `-v` does NOT apply the change.

### Environment Variables

Required (see `.env.example.pgsql`):
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=h_dashboard
DB_USERNAME=...
DB_PASSWORD=...
DB_ROOT_PASSWORD=...       # docker postgres superuser
DB_DEFAULT_EMAIL=...       # seeded admin email
DB_DEFAULT_PASSWORD=...    # seeded admin password

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=123

ZABBIX_URL=http://127.0.0.1:8443/api_jsonrpc.php
ZABBIX_TOKEN=...
TILE_SERVER_IP=tile.openstreetmap.org
```

### Build Commands

```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan db:seed --force
```

### Running Tests (Pest)

Pest is the test runner (`vendor/bin/pest`). The suite uses **Livewire 4.4**, which hashes the update endpoint based on `APP_KEY` (`livewire-{hash}/update`), and `phpunit.xml` expects a **separate PostgreSQL test database** + a **password-less Redis**. Getting the environment right is the most common failure mode — follow these steps exactly.

#### 1. Prerequisites (services must be up)
```bash
docker compose -f docker-compose-pgsql-.yml up -d      # PostGIS on :5432, Redis on :6379
```
- PostGIS must be healthy. Verify: `pg_isready -h 127.0.0.1 -p 5432`
- Redis must be reachable: `php -r '$r=new Redis(); $r->connect("127.0.0.1",6379); echo $r->ping();'`

#### 2. Create the test database + role (phpunit.xml hard-codes `postgres`/`secret`/`h_dashboard_test`)
The committed `phpunit.xml` expects:
```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE"  value="h_dashboard_test"/>
<env name="DB_USERNAME"  value="postgres"/>
<env name="DB_PASSWORD"  value="secret"/>
<env name="REDIS_HOST"   value="127.0.0.1"/>
<env name="REDIS_PASSWORD" value=""/>          <!-- expects NO password -->
```
Create them once (the `postgis` extension must be enabled — build from the `template_postgis` template):
```bash
# as a superuser (e.g. h_dashboard), create the postgres role + test db
psql -h 127.0.0.1 -U h_dashboard -d h_dashboard -c \
  "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='postgres') \
   THEN CREATE ROLE postgres LOGIN PASSWORD 'secret' SUPERUSER; END IF; END \$\$;"
psql -h 127.0.0.1 -U h_dashboard -d h_dashboard -c \
  "CREATE DATABASE h_dashboard_test WITH OWNER=postgres TEMPLATE=template_postgis;"
```

#### 3. Redis must accept connections WITHOUT a password
The docker Redis runs with `--requirepass $REDIS_PASSWORD`, but `phpunit.xml` sends an empty `REDIS_PASSWORD`. Either:
- restart the container without a password: `docker run -d --name h-dashboard-redis -p 6379:6379 redis:latest redis-server --requirepass ""`, **or**
- temporarily edit `phpunit.xml` `<env name="REDIS_PASSWORD" value=""/>` → your actual `REDIS_PASSWORD`.
- ⚠️ If you see `NOAUTH Authentication required`, the Redis password does not match `phpunit.xml`.

#### 4. Clear cached config/routes BEFORE running (critical!)
`.env.testing` ships `DB_CONNECTION=mysql` and a **different `APP_KEY`** than `.env`. If `bootstrap/cache/config.php` or the route cache exists, they override `phpunit.xml` → `mysql` connection refused, or a **mismatched Livewire endpoint hash → 404 on every `->set()`/`->call()`** (symptom: every mutation test fails, "table is empty", nothing persists).
```bash
php artisan config:clear      # must be clear so phpunit.xml can override DB_* 
# Do NOT run `php artisan optimize` / `route:cache` with APP_ENV=local before tests,
# or the cached route hash (from .env's APP_KEY) won't match the test env's hash.
```

#### 5. Run the suite
```bash
export XDEBUG_MODE=coverage    # phpunit.xml requests coverage; without this Pest errors
php artisan config:clear        # repeat before each run if caches were regenerated
vendor/bin/pest tests/          # MUST pass a path; bare `vendor/bin/pest` prints usage
```
- **Always pass `tests/` (or a file):** `vendor/bin/pest` with no argument prints the phpunit help and exits 1.
- Expected: **695 passed, 1 skipped** (the skip is `HardwareAuditMigrationTest`, driver-dependent — not a failure).
- If you only want a single file: `php vendor/bin/pest tests/Feature/UsersManagementTest.php`.

#### Common failure → cause
| Symptom | Cause | Fix |
|---|---|---|
| `NOAUTH Authentication required` | Redis password ≠ `phpunit.xml` | Step 3 |
| `Connection refused (mysql … 3306)` | config cache from `.env.testing` wins over phpunit.xml | `php artisan config:clear` |
| `404` on every `->set()`/`->call()`, mutations don't persist | Livewire endpoint hash mismatch (cached route from wrong `APP_KEY`) | `php artisan config:clear` + don't `optimize` with local env |
| `postgres`/`secret`/`h_dashboard_test` auth fail | test DB/role missing | Step 2 |
| bare `vendor/bin/pest` → usage text | no path argument | pass `tests/` |

### Code Intelligence (CodeGraph)

[CodeGraph](https://github.com/colbymchenry/codegraph) is a local (100% on-machine, SQLite, no API keys) code knowledge graph that the team uses for surgical context — symbol/route resolution, call paths, and blast-radius analysis. It supports PHP/Laravel (routes → handlers) and cross-language (PHP ↔ JS) flows.

**Hermes Agent MUST use CodeGraph for code-understanding tasks.** Before crawling files with grep/glob/Read to answer a structural question ("how does X work", "what calls Y", "what breaks if I change Z", survey an area, trace a request/flow), run `codegraph explore` / `codegraph query` first and answer from the returned graph. Trust its verbatim source and call paths; only fall back to Read for files it flags as stale. This is the project's standard for context discovery — prefer it over hand-reconstructing structure.

**Each developer / Hermes Agent instance installs it once on their own machine — the `.codegraph/` index is local and must NOT be committed (it is already git-ignored).**

```bash
npm install -g @colbymchenry/codegraph
cd /home/runner/workspace/h-dashboard   # or your project root
codegraph init .                        # builds .codegraph/codegraph.db (~30s, auto-syncs on file change)
```

Once installed, an agent (or a developer in a terminal) answers structural questions directly instead of crawling files:

```bash
codegraph explore "how does AccessService accessibleUnitIds resolve unit hierarchy"
codegraph query "HardwareAuditObserver" --limit 5
codegraph status .
```

> **For other Hermes Agent instances:** after the operator installs CodeGraph on their own environment (above), they MUST use it the same way — the index is per-machine, so every agent that needs it must run `codegraph init` once on its host. No agent shares another's `.codegraph/` database.
>
> Note: the graph auto-syncs via a file watcher, so it stays current as code changes — no need to re-run `init`. If a session edited files while no MCP/index was running, `codegraph sync` catches up against the working tree.

### CI/CD

`.github/workflows/deploy.yml` deploys on push to `main` (self-hosted runner): pulls `/home/boxd/h-dashboard`, clears views/config/routes cache, runs `php artisan optimize`, reloads apache2.

### Storage Permissions (gotcha)

The web user (`www-data` under FPM, or root under the built-in server) must be able to write into `storage/framework/views|cache|sessions`. `Filesystem::replace()` → `tempnam()` fails with "file created in the system's temporary directory" when the dir isn't writable by the PHP process. Fix:

```bash
chown -R www-data:www-data storage/framework storage/logs   # FPM
# or, if using `php artisan serve` as your user:
chown -R boxd:www-data storage/framework && chmod -R 775 storage/framework
php artisan view:clear
```
