# Health Dashboard (داشبورد سلامت) — Documentation

## Project Overview

Health Dashboard is a Laravel 13.x application for managing hospital/healthcare center hardware inventory, organizational units, tickets, and todos. Built with Livewire 4, MaryUI (DaisyUI), and Alpine.js. Fully RTL and Persian-language. Served to both a web UI and a Flutter mobile app (via Sanctum API tokens).

### Tech Stack

- **Framework:** Laravel 13.x (`laravel/framework ^13.0`, currently 13.23.0) on PHP ^8.3
- **Frontend:** Livewire 4 (class-based components under `app/Livewire/`, views under `resources/views/livewire/`), Alpine.js, MaryUI (DaisyUI), Tailwind CSS 4
- **Database:** PostgreSQL 16 (Docker, `postgis/postgis:16-3.4`) with PostGIS for spatial/GIS data
- **Cache/Session/Queue:** Redis (Docker, `redis:latest`, password-protected via `REDIS_PASSWORD`)
- **Auth:** Laravel Sanctum (session guard for web, Bearer tokens for the Flutter app)
- **Import:** maatwebsite/excel (Laravel Excel) — `.xlsx`, `.xls`, `.csv`
- **Permissions:** spatie/laravel-permission ^8.0
- **Jalali calendar:** morilog/jalali (Jalalian), hekmatinasser/verta (installed)
- **AI:** openai-php/client ^0.20.1 (installed; used by feature tests, e.g. `AiAgentTest`)
- **Package Manager:** Composer (backend); frontend deps in `package.json` (Vite). Both `package-lock.json` (npm) and `pnpm-lock.yaml` exist in the repo; `npm run build` / `vite build` is the current build path (Node 24, npm 12)

---

## Data Model

### Core Entities

**Person** (`persons` table)
- `n_code` (PK, string)
- `f_name`, `l_name`
- `u_id` (FK to `units.id`)
- `semat_id` (FK to `semats.id`)
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
Person → Semat (semat_id → id)
Person → Tahsil (t_id → id)
Person → Estekhdam (e_id → id)
Person → Radif (r_id → id)
Unit → Unit (parent_id, recursive self-join)
Ticket → Todo (task_id)  Ticket → User (user_id / current_assignee_id)
Ticket → Attachment / TaskActivity
User ↔ Unit (user_units pivot)
```

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

A new middleware `SafeRoleOrPermission` (alias: `safe_role_or_permission`) allows routes to be accessible to unauthenticated guests while still enforcing Spatie permissions for authenticated users. This is used on hardware Livewire routes to support both web sessions and API token authentication patterns.

```php
Route::middleware('safe_role_or_permission:manage_hardware')->group(function () {
    Route::livewire('/hardware', 'hardware.index');
    Route::livewire('/hardware/import', 'hardware.import-hardware.import-hardware')->name('hardware.import');
});
```

### Unit Context Middleware

`ValidateUnitContext` middleware (alias `unit_context`) ensures `session('current_unit_id')` is set before entering unit-scoped sections; UI supports selecting a unit context (`/select-context`).

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

### Person CRUD (`/api/persons`)

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

### Hardware Import (`/hardware/import`)

- Excel/CSV import with validation
- Persian normalization for search fields
- Bulk create with duplicate handling

### Maps (`/maps`)

- Unit map, interactive map, county map, point map, route maps — all with organizational scope applied
- GIS data via PostGIS (boundaries as MULTIPOLYGON, SRID 4326); unit lat/lng with bounding-box queries (`withinBounds`)

### Other Pages

- Dashboard, users management, units (chart/map), roles/permissions, settings, profile, notifications, todos, tickets, tools (Zabbix), reports, activity log, kargozini (HR), IT monitoring

### Help System (راهنما)

In-app help is a per-page modal (`?` button in page headers):

- **Components:** `resources/views/components/help/` — `button.blade.php` (dispatches `help-open` with the section) + `modal.blade.php` (listens via `Livewire.on('help-open')`, switches content with Alpine `x-if` on `helpSection`, opens by setting the page's `showHelpModal` property)
- **Content:** one file per section under `resources/views/components/help/content/<section>.blade.php`, registered in `AppServiceProvider::boot()` (`$helpContents`) as `help-content:<section>` components
- **Wiring a new page:** add `public bool $showHelpModal = false;`, `<x-help:button section="<section>" wireModel="showHelpModal" />` in the header actions, and `<x-help:modal wireModel="showHelpModal" />`; create the content file + register it + add an `x-if` case in the modal
- **Sections (20):** dashboard, hardware, hardware-import, persons-import, personnel, units, tickets, todos, reports, maps, settings, roles, permissions, users, activity-log, networks, wireless, tools, search, profile
- **Gotchas:** escape Blade directives in content with `@@` (e.g. `@@can(...)`); use only icons present in the heroicons set (`o-*` in `vendor/blade-ui-kit/blade-heroicons/resources/svg/`)
- **Tests:** `tests/Feature/HelpSystemTest.php` — page renders + all 20 content sections render

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
- **Testing:** Pest (PHPUnit under the hood) — `tests/Feature/*`, run with `./vendor/bin/pest`

### Performance (recent fixes pattern)
- Cache hot queries with `Cache::remember(...)` (stats, notification bell, search, tools) and invalidate on writes
- Version-counter invalidation: `hardware_stats_version` bumps on hardware writes; stats keys `hardware_stats:v<N>:<md5(accessibleIds)>` become unreachable and expire via TTL (driver-agnostic, avoids full cache flush) (#217)
- Eager-load relationships (`with('person.unit')`) in list queries
- Limit API pagination to max 100 per page
- Use recursive CTE via raw SQL for unit hierarchy queries; `Unit::ancestorIds()` for ancestor chains
- Add composite indexes for hot filter paths (e.g. `(task_id, status)` on tickets, `(user_id, created_at)` on activity_logs)
- Apply `PersianNormalizer` on all text search inputs

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
npm install && npm run build     # or: pnpm install && pnpm run build
php artisan migrate --force
php artisan db:seed --force
```

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
