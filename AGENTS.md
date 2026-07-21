# Health Dashboard (داشبورد سلامت)

Organizational health/HR management dashboard with Persian UI. Handles personnel, units, maps/GIS, IT monitoring (Zabbix), tickets, todos, and reports. Provides a Flutter mobile app via Sanctum API.

## Tech Stack
- PHP 8.3+, Laravel v13, Livewire v4, Tailwind CSS v4
- maryUI v2.8, DaisyUI v5, Vite v8
- Spatie Laravel Permission v8 (roles & permissions)
- Laravel Sanctum v4 (API tokens)
- Jalali dates: hekmatinasser/verta v9, morilog/jalali v3.5
- Testing: Pest v4, Laravel Pint v1
- Dev tools: Laravel Boost v2 (MCP), Debugbar, Pail
- Locales: `fa` (primary), `en` (fallback). Pagination and UI strings in `lang/fa.json`.

## Branding & Theme
- **Logo**: Inline SVG in `app/View/Components/AppBrand.php` (class component, overrides blade). Gradient cross+heart icon with "داشبورد سلامت" text. Theme-aware via CSS (`data-theme="synthwave"` for dark mode).
- **Favicon**: `public/favicon.svg` — gradient cross+heart design. Referenced in `app.blade.php` and `auth.blade.php` layouts.
- **Sidebar SVG**: `public/logo-sidebar.svg` — uses `currentColor` for theme adaptability.
- **Theme selector**: DaisyUI themes via `data-theme` attribute. Dark mode: `synthwave`. Light mode: `fantasy`.

## App Structure

### Livewire Components (`resources/views/livewire/`)
| Directory | Purpose |
|-----------|---------|
| `activity-log/` | Activity logging and audit trail |
| `auth/` | Login, register, password reset |
| `it/` | IT monitoring (Zabbix integration) |
| `kargozini/` | Administrative: estekhdam, radif, tahsil, semat, person |
| `maps/` | GIS map views with Leaflet |
| `notifications/` | User notifications |
| `permissions/` | Permission management (Spatie) |
| `profile/` | User profile settings |
| `reports/` | Reports: units, persons, todos, map-no-boundary, tickets |
| `roles/` | Role management (Spatie) |
| `search/` | Global search |
| `settings/` | App settings |
| `tickets/` | Ticket system (inbox, monitoring) |
| `todo/` | Todo management |
| `tools/` | Utility tools |
| `units/` | Unit/organization management |
| `users/` | User management |

### API Controllers (`app/Http/Controllers/Api/`)
- `UnitController` — Unit CRUD for Flutter app
- `TodoController` — Todo API
- `TrafficController` — Traffic data
- `MultiLatestValueController` — Zabbix multi-latest values

### Services (`app/Services/`)
- `AccessService` — Data scope control (recursive CTE for unit hierarchy)
- `ActivityLogService` — Activity logging
- `NotificationService` — Notification management
- `ZabbixService` — Zabbix API integration

### View Components
- `AppBrand` (`app/View/Components/AppBrand.php`) — Class component with inline SVG logo. Takes precedence over blade files.

## Database Structure & Relationships

### Core Tables
| Table | Primary Key | Important Columns | Relations |
|-------|-------------|-------------------|----------|
| `persons` | `id` | `n_code` (unique), `e_id`, `t_id`, `s_id`, `r_id`, `u_id` | `belongsTo` Estekhdam, Tahsil, Semat, Radif; `hasOne` User via `n_code`; optional `unit_id` (FK to `units.id` for default unit) |
| `users` | `id` | `n_code` (FK → `persons.n_code`), `password`, `deleted_at` | `belongsTo` Person; `belongsToMany` Unit via `user_units`; Spatie `HasRoles` |
| `user_units` | `id` | `user_id`, `unit_id`, `role`, `is_primary` | Pivot linking Users ↔ Units (many‑to‑many). Unique (`user_id`,`unit_id`). |
| `units` | `id` | `name`, `parent_id`, `unit_type_id`, `region_id`, `boundary_id`, `lat`, `lng`, `is_active`, `can_receive_tickets` | `belongsTo` parent Unit, Region, UnitType, Boundary; `hasMany` child Units; `belongsToMany` Users via `user_units`; `hasMany` Persons, Todos |
| `unit_types` | `id` | `name` (unique), `description` | `belongsToMany` self via `unit_type_relationships` (allowed parent types) |
| `unit_type_relationships` | — | `child_unit_type_id`, `allowed_parent_unit_type_id` | Defines which unit types may be parents of others |
| `regions` | `id` | `name`, `type` (`province`/`county`), `parent_id`, `boundary_id` | Self‑referential hierarchy; `hasMany` Units |
| `boundaries` | `id` | `boundary` (MULTIPOLYGON, SRID 4326) | Used by Regions and Units for GIS polygons |
| `todos` | `id` | `title`, `start_at`, `end_at`, `is_completed`, `unit_id` (nullable) | `belongsTo` Unit (null‑on‑delete) |
| `tickets` | `id` | `ticket_code` (unique), `user_id`, `unit_id`, `subject`, `content`, `priority`, `status`, `current_assignee_id`, `accepted_at`, `completed_at` | `belongsTo` creator User, Unit, optional assignee User; `hasMany` TaskActivities, Attachments |
| `task_activities` | `id` | `ticket_id`, `user_id`, `action`, `description`, `to_unit_id`, `to_user_id`, `is_internal` | `belongsTo` Ticket, User; `hasMany` Attachments |
| `attachments` | `id` | `ticket_id`, `user_id`, `file_path`, `file_name`, `file_size`, `activity_id` (nullable) | `belongsTo` Ticket, User, optional TaskActivity |
| `location_logs` | `id` | `user_id`, `latitude`, `longitude` | `belongsTo` User |
| `activity_logs` | `id` | `user_id`, `description`, `created_at` | `belongsTo` User |
| `notifications` | `id` | `user_id`, `data`, `created_at` | `belongsTo` User |

### Relationship Highlights
- **User ↔ Person**: One‑to‑one via `n_code`. Person is the parent; User stores the foreign key.
- **User ↔ Unit**: Many‑to‑many via `user_units`. Each assignment carries a `role` (`responsible`/`staff`) and an `is_primary` flag.
- **Unit Hierarchy**: Self‑referential `parent_id` builds a tree; `Unit::descendantIds()` (recursive CTE) provides all child IDs for hierarchical access control.
- **Unit Types**: `unit_type_relationships` restrict which unit types may contain which child types.
- **Geography**: `regions` and `units` can reference a `boundary` polygon for GIS mapping.
- **Todos** belong to a Unit; deleting a Unit sets `unit_id` to `NULL`.
- **Tickets** belong to a Unit and a creator User; may be assigned to another User.
- **TaskActivities** log actions on a Ticket and can forward to another Unit or User.
- **Attachments** can be attached to a Ticket or a TaskActivity.
- **LocationLogs** store GPS points per User (used by map features).

## Access Control
- **Functional permissions** (Spatie) control *what* actions a user may perform (e.g., `create_ticket`, `map`, `calendar`).
- **Data scope** (service `AccessService`) controls *which* Units' data a user can see – the current unit plus all descendants via recursive CTE. Middleware `ValidateUnitContext` ensures `session('current_unit_id')` is set.

## FK Delete Behaviour (summary)
- `users.n_code → persons`: **restrict**
- Lookup FK columns (`e_id`, `t_id`, `s_id`, `r_id`): **restrict**
- `units.region_id`, `units.parent_id`: **restrict**
- `user_units.user_id → users`: **cascade**
- `user_units.unit_id → units`: **cascade**
- `tickets.ticket_id → task_activities, attachments`: **cascade**
- `task_activities.activity_id → attachments`: **cascade**
- `location_logs.user_id → users`: **cascade**
- `todos.unit_id → units`: **set null**
- `regions.boundary_id`, `units.boundary_id`: **cascade**

*Keep this file synchronized with any future schema changes.*
