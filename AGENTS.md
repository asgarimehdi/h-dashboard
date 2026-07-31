# Health Dashboard (داشبورد سلامت) — Documentation

## Project Overview

Health Dashboard is a Laravel 13.x application for managing hospital/healthcare center hardware inventory. Built with Livewire 4, Volt, MaryUI (DaisyUI), and Alpine.js. Fully RTL and Persian-language.

### Tech Stack

- **Framework:** Laravel 13.x (PHP 8.4+)
- **Frontend:** Livewire 4 + Volt, Alpine.js, MaryUI (DaisyUI)
- **Database:** MySQL/MariaDB (with spatial/GIS indexes)
- **Auth:** Laravel Sanctum
- **Import:** maatwebsite/excel (Laravel Excel) — `.xlsx`, `.xls`, `.csv`
- **Package Manager:** pnpm (frontend), Composer (backend)

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
- Indexes: `parent_id` (B-tree), composite `lat`+`lng` (B-tree)
- Relationships: `boundary` (hasOne), `children` (recursive), `parent`, `type`, `region`

**Region** (`regions` table)
- `id`, `name`, `parent_id` (self-referencing), `unit_type_id`
- Indexes: `parent_id` (B-tree)

**UnitType** (`unit_types` table)
- `id`, `name`

**Semat** (`semats` table)
- `id`, `name` (job titles)

**Ticket** (`tickets` table)
- `id`, `title`, `description`, `status`, `priority`, `assignee_id`, `unit_id`

**Todo** (`todos` table)
- `id`, `title`, `is_completed`, `unit_id`

**User** (`users` table)
- `id`, `n_code`, `name`, `email`, `password`

### Relationships

```
Hardware → Person (n_code)
Person → Unit (u_id → id)
Person → Semat (semat_id → id)
Person → Tahsil (t_id → id)
Person → Estekhdam (e_id → id)
Person → Radif (r_id → id)
Unit → Unit (parent_id, recursive self-join)
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

---

## Authentication

The application uses **Laravel Sanctum** with two authentication modes:

| Mode | Routes | Auth Method | Usage |
|---|---|---|---|
| **Web (Session)** | All Livewire UI pages (`/hardware`, `/units`, `/tickets`, etc.) | Cookie-based session via `web` guard | Browser access, requires login form |
| **API (Token)** | `/api/*` routes | Bearer token in `Authorization` header via `sanctum` guard | Programmatic access, cURL, external tools |

- Livewire components expect session-based authentication (web middleware group). **API tokens are NOT accepted** for Livewire pages — this is by design. To access Livewire UI, use a browser session.
- API routes accept Sanctum tokens generated via `PersonalAccessTokenFactory`.
- The login form is at `/login` (web session).
- Token generation (for testing/automation): `POST /api/sanctum/token` with valid credentials.

### Safe Role/Permission Middleware

A new middleware `SafeRoleOrPermission` (alias: `safe_role_or_permission`) allows routes to be accessible to unauthenticated guests while still enforcing Spatie permissions for authenticated users. This is used on hardware Livewire routes to support both web sessions and API token authentication patterns.

```php
Route::middleware('safe_role_or_permission:manage_hardware')->group(function () {
    Route::livewire('/hardware', 'hardware.index');
    Route::livewire('/hardware/import', 'hardware.import-hardware.import-hardware')->name('hardware.import');
});
```

---

## API Reference

### Hardware CRUD (`/api/hardware`)

All routes require `auth:sanctum` and filter by user's organizational scope.

| Method | URL | Description |
|---|---|---|
| GET | `/api/hardware` | List with filters: `search`, `type`, `os`, `cpu`, `ram`, `hdd`, `shutdown`, `net_type`, `mark`, `person`, `unit`, `semat` |
| POST | `/api/hardware` | Create (requires `n_code`, `pc_name`) |
| GET | `/api/hardware/stats` | Aggregate stats (total, by type, shutdown count) |
| GET | `/api/hardware/{id}` | Show details |
| PUT/PATCH | `/api/hardware/{id}` | Update (partial updates allowed — only sends changed fields) |
| DELETE | `/api/hardware/{id}` | Delete |
| POST | `/api/hardware/bulk-mark` | `{ids: [...], mark: true/false}` |
| POST | `/api/hardware/bulk-delete` | `{ids: [...]}` |

### Person CRUD (`/api/persons`)

All routes require `auth:sanctum` and filter by user's organizational scope.

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

All routes require `auth:sanctum`.

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

---

## Persian Text Handling

The `PersianNormalizer` trait normalizes:
- `ي` → `ی`
- `ك` → `ک`
- ZWNJ (zero-width non-joiner) → space

Applied to all search and filter operations in both Livewire components and API controllers.

---

## Development Guidelines

### New Features
1. Add a GitHub issue describing the change
2. Branch → develop → test → PR
3. Maintain RTL compatibility
4. Use MaryUI components where possible
5. Add permission checks for new routes

### Conventions
- **RTL:** All layouts use `dir="rtl"` at root level
- **CSS:** Prefer Tailwind utility classes over custom CSS
- **Pagination:** Use `LengthAwarePaginator` with `WithPagination` trait
- **Forms:** Use MaryUI `x-input`, `x-select`, `x-button` components
- **Modal:** Use `x-modal` with `close-on-backdrop` for edit forms

### Performance
- Eager-load relationships (`with('person.unit')`) in list queries
- Limit API pagination to max 100 per page
- Use recursive CTE via raw SQL for unit hierarchy queries
- Apply `PersianNormalizer` on all text search inputs

---

## Deployment

### Environment Variables

Required:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=h_dashboard
DB_USERNAME=...
DB_PASSWORD=...

SANCTUM_STATEFUL_DOMAINS=localhost
SESSION_DOMAIN=localhost
```

### Build Commands

```bash
composer install --no-dev --optimize-autoloader
pnpm install && pnpm run build
php artisan migrate --force
php artisan db:seed --force
```