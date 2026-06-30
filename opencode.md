# Health Dashboard (داشبورد مدیریت اطلاعات سلامت)

Organizational health/HR management dashboard with Persian (Farsi) UI. Manages personnel, organizational units, maps/GIS, IT monitoring (Zabbix), tickets, and todos. Has a Flutter mobile app consuming the Sanctum API.

## Tech Stack

- PHP 8.3+, Laravel v13, Livewire v4, Tailwind CSS v4
- maryUI v2.8 (UI components), DaisyUI v5 (Tailwind plugin), Vite v8
- Spatie Laravel Permission v8 (roles & permissions)
- Laravel Sanctum v4 (API tokens for Flutter app)
- Jalali dates: hekmatinasser/verta v9, morilog/jalali v3.5
- Testing: Pest v4, Laravel Pint v1
- Dev tools: Laravel Boost v2 (MCP), Debugbar, Pail

## Domain Model

- **Personnel (kargozini)**: `Person` linked to `Estekhdam` (employment type), `Tahsil` (education), `Semat` (position), `Radif` (rank)
- **Units**: Hierarchical org tree — `Unit`, `UnitType`, `UnitTypeRelationship`, `Boundary`, `Region`, `Province`
- **Users**: Auth by `n_code` (national code), linked to `Person` via `n_code` FK. SoftDeletes enabled.
- **User-Unit Assignments**: Many-to-many via `user_units` pivot. A user can belong to multiple units (e.g., work in both HQ and a health base). Each assignment has a `role` (responsible/staff) and `is_primary` flag.
- **Hierarchical Access Control**: Two-layer system:
  - **Functional permissions** (Spatie): what actions a user can perform (`create_ticket`, `map`, etc.)
  - **Data scope** (AccessService): which units' data a user can see (current unit + all descendants via recursive CTE)
- **Context Switching**: Users with multiple unit assignments select their active unit via `session('current_unit_id')`. Middleware `ValidateUnitContext` enforces this.
- **Tickets**: Ticket workflow with statuses `created`, `forwarded`, etc. Has `TaskActivity` and `Attachment`.
- **Todos**: `Todo` with `is_completed` flag, belongs to `Unit`. API endpoints for Flutter app.
- **Maps**: Location logs, tile server (`TILE_SERVER_IP` in `config/map.php`)
- **IT Monitoring**: `ZabbixService` fetches network traffic/wireless data (`ZABBIX_URL`, `ZABBIX_TOKEN`)

## Database Schema & Relations

### Lookup Tables (id, name, timestamps)

| Table | Model | Description |
|-------|-------|-------------|
| `estekhdams` | `Estekhdam` | Employment types (رسمی، پیمانی، ...) |
| `tahsils` | `Tahsil` | Education levels (دیپلم، کارشناسی، ...) |
| `semats` | `Semat` | Job positions/titles |
| `radifs` | `Radif` | Organizational ranks |

Each has `hasMany(Person)` inverse.

### Personnel & Auth

**`persons`** — Personnel records (table name: `persons`, not `people`)
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `n_code` | string(10) | unique, indexed — national code |
| `f_name` | string | first name |
| `l_name` | string | last name |
| `e_id` | FK → `estekhdams.id` | onDelete restrict, onUpdate cascade |
| `t_id` | FK → `tahsils.id` | onDelete restrict, onUpdate cascade |
| `s_id` | FK → `semats.id` | onDelete restrict, onUpdate cascade |
| `r_id` | FK → `radifs.id` | onDelete restrict, onUpdate cascade |
| `u_id` | FK → `units.id` | indexed (no FK constraint in migration) |

Relationships: `belongsTo` Estekhdam(`e_id`), Tahsil(`t_id`), Semat(`s_id`), Radif(`r_id`), Unit(`u_id`). `hasOne` User (via `n_code`).

**`users`** — Auth accounts (SoftDeletes)
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `n_code` | string(10) | unique, indexed, FK → `persons.n_code` (onDelete restrict, onUpdate cascade) |
| `password` | string | hashed cast |
| `deleted_at` | timestamp | soft delete |

Relationships: `belongsTo` Person (via `n_code` ↔ `n_code`). Traits: `HasApiTokens`, `HasRoles`, `SoftDeletes`.
Accessors: `name` (computed from `person.f_name + l_name`, session-cached), `unit_name` (via `person.unit.name`).

**Key pattern**: User ↔ Person linked by `n_code` (not `id`). Person is the parent (`unique n_code`), User is the child (`FK n_code`). `Person.hasOne(User)` / `User.belongsTo(Person)`.

**`user_units`** — Many-to-many pivot: user ↔ unit assignments
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `user_id` | FK → `users.id` | cascadeOnDelete |
| `unit_id` | FK → `units.id` | cascadeOnDelete |
| `role` | enum | 'responsible', 'staff' (default) |
| `is_primary` | boolean | default false |

Unique constraint on (`user_id`, `unit_id`). Seeded from `Person.u_id` via `UserUnitSeeder`.

Relationships: `User.units()` BelongsToMany, `Unit.assignedUsers()` BelongsToMany.

### Organizational Units

**`unit_types`** — Types of organizational units
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `name` | string | unique |
| `description` | string | nullable |

Relationships: `belongsToMany` self via `unit_type_relationships` pivot (as `allowedParentTypes`).

**`unit_type_relationships`** — Pivot: which unit types can be parents of which
| Column | Type | Constraint |
|--------|------|------------|
| `child_unit_type_id` | FK → `unit_types.id` | onDelete cascade |
| `allowed_parent_unit_type_id` | FK → `unit_types.id` | onDelete cascade |

**`boundaries`** — GIS boundary polygons
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `boundary` | geometry(MULTIPOLYGON) | SRID 4326 |

Relationships: `hasOne` Province, County, Unit. Appends `geojson` attribute (via `ST_AsGeoJSON`).

**`regions`** — Hierarchical geographic regions (replaces old provinces/counties)
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `name` | string | |
| `type` | string | 'province' or 'county' |
| `parent_id` | FK → `regions.id` | nullable, onDelete restrict |
| `boundary_id` | FK → `boundaries.id` | nullable, cascadeOnDelete |

Relationships: self-referential `parent`/`children`. `hasMany` Unit. `belongsTo` Boundary.

**`units`** — Organizational units (hierarchical)
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `name` | string | |
| `description` | text | nullable |
| `region_id` | FK → `regions.id` | nullable, onDelete restrict |
| `parent_id` | FK → `units.id` | nullable, onDelete restrict |
| `unit_type_id` | FK → `unit_types.id` | nullable |
| `boundary_id` | FK → `boundaries.id` | nullable, cascadeOnDelete |
| `lat`, `lng` | double | nullable, GPS coords |
| `is_active` | boolean | default true |
| `can_receive_tickets` | boolean | default false |

Relationships: `belongsTo` UnitType, Region, Boundary, self(`parent`). `hasMany` self(`children`), Person. `belongsToMany` User (via `user_units` as `assignedUsers`). `childrenRecursive` for full tree loading. Static `descendantIds(int|array $unitIds)` returns all descendant IDs via recursive CTE.

### Tickets & Activities

**`tickets`** — Support/task tickets
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `ticket_code` | string | unique |
| `user_id` | FK → `users.id` | constrained (creator) |
| `unit_id` | FK → `units.id` | constrained (target unit) |
| `subject` | string | |
| `content` | text | |
| `priority` | enum | 'low', 'normal' (default), 'urgent' |
| `status` | string | default 'created'. Values: created, forwarded, accepted, completed, rejected |
| `current_assignee_id` | FK → `users.id` | nullable |
| `accepted_at` | timestamp | nullable |
| `completed_at` | timestamp | nullable |

Relationships: `belongsTo` User(`user_id`), User(`current_assignee_id` as `assignee`), Unit. `hasMany` TaskActivity (via `ticket_id`), Attachment.

**`task_activities`** — Ticket activity log
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `ticket_id` | FK → `tickets.id` | cascadeOnDelete |
| `user_id` | FK → `users.id` | constrained (performer) |
| `action` | string | 'created', 'forwarded', 'rejected', 'finished' |
| `description` | text | nullable |
| `to_unit_id` | FK → `units.id` | nullable (forwarded to unit) |
| `to_user_id` | FK → `users.id` | nullable (forwarded to user) |
| `is_internal` | boolean | default false |

Relationships: `belongsTo` User. `hasMany` Attachment (via `activity_id`).

**`attachments`** — File attachments for tickets/activities
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `ticket_id` | FK → `tickets.id` | cascadeOnDelete |
| `user_id` | FK → `users.id` | constrained |
| `file_path` | string | |
| `file_name` | string | |
| `file_size` | integer | |
| `activity_id` | FK → `task_activities.id` | nullable, cascadeOnDelete |

Relationships: `belongsTo` User, Ticket.

### Todos

**`todos`** — Task items (Flutter app API)
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `title` | string | |
| `start_at` | datetime | |
| `end_at` | datetime | nullable |
| `is_completed` | boolean | default false |
| `unit_id` | FK → `units.id` | nullable, nullOnDelete |

Relationships: `belongsTo` Unit.

### Maps & Tracking

**`location_logs`** — GPS location tracking from mobile app
| Column | Type | Constraint |
|--------|------|------------|
| `id` | bigint | PK |
| `user_id` | FK → `users.id` | cascadeOnDelete |
| `latitude` | decimal(10,7) | |
| `longitude` | decimal(10,7) | |

### Other System Tables

- `personal_access_tokens` — Sanctum API tokens
- `password_reset_tokens` — password reset (PK: `email`)
- `sessions` — session store (FK: `user_id`)
- `cache`, `cache_locks` — cache store
- `jobs`, `job_batches`, `failed_jobs` — queue system
- `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` — Spatie Permission

### Performance Indexes

Additional indexes beyond FKs: `tickets`(status, unit_id, created_at, user_id), `persons`(u_id), `task_activities`(ticket_id, user_id), `attachments`(ticket_id, activity_id), `location_logs`(user_id).

### FK Delete Behavior Summary

| FK | onDelete |
|----|----------|
| `users.n_code → persons` | restrict |
| `persons.e_id/t_id/s_id/r_id → lookups` | restrict |
| `units.region_id, parent_id` | restrict |
| `tickets.ticket_id → task_activities, attachments` | cascade |
| `task_activities.activity_id → attachments` | cascade |
| `location_logs.user_id → users` | cascade |
| `todos.unit_id → units` | set null |
| `regions.boundary_id, units.boundary_id` | cascade |

## Authentication

- Web login uses `n_code` + password (not email)
- API: POST `/api/login` returns Sanctum token for Flutter app
- User → Person linked via `n_code` foreign key (not `id`)
- RBAC permissions: `map`, `calendar`, `op-cache` gate route groups

## Dev Environment

```
composer run dev    # runs artisan serve + queue:listen + npm run dev concurrently
npm run build       # production build
```

Local dev on Laragon (Windows).

## UI Patterns

- **Livewire inline classes**: anonymous `return new class extends Component {}` in blade files (Volt single-file components)
- **Persian/RTL**: All UI text is Farsi. `dir="rtl"` on `<html>`. Font: Vazirmatn (loaded from `/fonts/`).
- **Jalali dates**: Use `jdate()` helper in blade, `Jalalian` class for conversion. Jalali date picker via `data-jdp` attribute on inputs.
- Routes use `Route::livewire()` pattern

### maryUI Components Used in This Project

All components use `<x-*>` blade syntax. Icons use Heroicons: `o-` (outline), `s-` (solid).

**Layout & Navigation** (in `layouts/app.blade.php`):
- `<x-nav sticky>` — top navbar (mobile), with `<x-slot:brand>` and `<x-slot:actions>`
- `<x-main>` — main layout wrapper with `<x-slot:sidebar>` and `<x-slot:content>`
- `<x-menu activate-by-route>` — sidebar navigation menu
- `<x-menu-item title="" icon="" link="" wire:navigate />` — menu links
- `<x-menu-sub title="" icon="">` — collapsible menu groups
- `<x-menu-separator />` — visual divider
- `<x-app-brand />` — app brand/logo component
- `<x-list-item :item="" value="" ...>` — user profile item in sidebar with `<x-slot:actions>`
- `<x-toast />` — toast notification container (required once in layout)
- `<x-theme-toggle darkTheme="dark" lightTheme="fantasy" />` — theme switcher (wrapped as `<x-theme-selector>`)

**Page Structure** (used on every page):
- `<x-header title="" separator progress-indicator>` — page header with `<x-slot:middle>` and `<x-slot:actions>`
- `<x-card shadow>` — content card container

**Data Display**:
- `<x-table :headers="" :rows="" :sort-by="" with-pagination per-page="" :per-page-values="">` — sortable paginated table
  - Uses `@scope('actions', $item)` for row action buttons
  - Uses `@scope('cell_column_name', $item)` for custom cell rendering
  - Uses `@scope('expansion', $item)` with `expandable` for row expansion
  - Header format: `['key' => 'column', 'label' => 'عنوان', 'class' => 'w-10 hidden sm:table-cell']`
- `<x-stat title="" value="" icon="" color="" description="" />` — stats cards (dashboard)
- `<x-badge :value="" class="badge-primary" rounded />` — status/role badges
- `<x-icon name="o-icon-name" class="" />` — standalone icons

**Forms** (inside modals or cards):
- `<x-form wire:submit="" class="">` — form wrapper with `<x-slot:actions>` for buttons
- `<x-input wire:model="" label="" placeholder="" icon="" clearable />` — text input
- `<x-input type="password" ...>` — password field
- `<x-select wire:model="" label="" :options="" option-value="" option-label="" placeholder="" />` — dropdown select
- `<x-textarea wire:model="" label="" rows="" />` — multi-line text
- `<x-checkbox wire:model="" label="" />` — checkbox
- `<x-toggle wire:model="" label="" />` — toggle switch (used in todo)
- `<x-file wire:model="" label="" multiple icon="" accept="" />` — file upload
- `<x-choices-offline wire:model="" :options="" option-label="" option-value="" searchable clearable />` — multi-select picker (used for roles/permissions)
- `<x-errors title="" description="" icon="" />` — validation errors block

**Modals**:
- `<x-modal wire:model="" title="" persistent separator>` — modal dialog
  - `persistent` prevents closing on backdrop click
  - `separator` adds a divider line below the title
  - `<x-slot:actions>` for modal footer buttons

**Buttons**:
- `<x-button label="" icon="" class="btn-primary" spinner />` — primary action
- `<x-button icon="" class="btn-ghost btn-sm text-primary" wire:click="" />` — icon-only action
- `<x-button ... wire:confirm="آیا مطمئن هستید" />` — with confirmation dialog
- `<x-button ... link="" external target="_blank" />` — external link button
- `<x-button ... responsive />` — hides label on small screens, shows icon only
- Common DaisyUI classes: `btn-primary`, `btn-success`, `btn-error`, `btn-ghost`, `btn-outline`, `btn-xs`, `btn-sm`

**Toasts** (via `Mary\Traits\Toast` in PHP class):
- `$this->success('message', position: 'toast-bottom')` — green toast
- `$this->warning('message', position: 'toast-bottom')` — yellow toast
- `$this->error('message', position: 'toast-bottom')` — red toast
- `$this->info('message', position: 'toast-bottom')` — blue toast
- Also supports `$this->dispatch('swal', [...])` for SweetAlert2 toasts

### Standard CRUD Page Pattern

Every CRUD page follows this structure:

```
PHP class:
  - use WithPagination, Toast
  - public bool $modal, string $search, array $sortBy, int $perPage
  - headers(): array — table columns
  - items(): LengthAwarePaginator — paginated query with withAggregate()
  - save/create/update/delete methods
  - with(): array — passes data to view

Blade:
  <x-header title="..." separator progress-indicator>
  <x-card shadow>
    <div class="breadcrumbs flex gap-2 items-center">
      <x-button class="btn-success" icon="o-plus" />  // create button
      <x-input wire:model.live.debounce="search" />    // search
    </div>
    <x-table :headers :rows :sort-by with-pagination>
      @scope('actions', $item) ... @endscope
    </x-table>
  </x-card>
  <x-modal wire:model="modal" persistent separator>
    <x-form wire:submit.prevent="save" class="grid grid-cols-2 gap-4">
      ...inputs/selects...
      <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary" />
      <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark" />
    </x-form>
  </x-modal>
```

### Third-Party JS Libraries (loaded in layout)

- **Leaflet** — maps (`leaflet.js`, `leaflet.draw.js`, `leaflet-routing-machine.min.js`, `leaflet.geometryutil.js`)
- **Highcharts** — charts (`highcharts.js`, `treemap.js`, `treegraph.js`, `exporting.js`)
- **FullCalendar** — calendar view (`full-calendar.min.js`) with RTL/Farsi locale
- **Jalali Datepicker** — Persian date input (`jalalidatepicker.min.js`, `data-jdp` attribute)
- **SweetAlert2** — alert notifications via `Livewire.on('swal', ...)` event

### Responsive Patterns

- Hide columns on small screens: `class="hidden sm:table-cell"`, `hidden xl:table-cell`, `hidden 2xl:table-cell`
- Grid layouts: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4`
- Hide text on small screens: `<span class="hidden 2xl:inline">ویرایش</span>` (keep icon visible)
- Sidebar: `collapsible` with `collapse-text`, `drawer="main-drawer"` for mobile

## Skills

Domain-specific skills in `.github/skills/`:
- `maryui-frontend` — maryUI, daisyUI, Tailwind CSS component patterns
- `volt-development`, `livewire-development`, `pest-testing`, `tailwindcss-development`
- `laravel-best-practices` (sub-rules: eloquent, migrations, routing, security, testing, etc.)

Activate the relevant skill when working in that domain.

## API Endpoints (Sanctum-protected)

- `POST /api/login` — token auth
- `GET /api/user`, `GET /api/me` — current user
- `GET /api/unit` — units
- `POST /api/location` — store location log
- `GET /api/zabbix/traffic`, `GET /api/zabbix/multi-latest` — Zabbix data
- `GET/POST/PUT/DELETE /api/todos` — todo CRUD

## Conventions

- Follow existing code conventions. Check sibling files for structure and naming.
- Use descriptive names: `isRegisteredForDiscounts`, not `discount()`.
- Reuse existing components before creating new ones.
- Don't create new base folders or change dependencies without approval.
- Only create documentation files if explicitly requested.

## PHP Style

- Always use curly braces for control structures, even single-line bodies.
- Use PHP 8 constructor property promotion.
- Use explicit return type declarations and type hints.
- Use TitleCase for Enum keys.
- Prefer PHPDoc blocks over inline comments. Use array shape type definitions.

## Artisan & File Creation

- Use `php artisan make:` commands. Pass `--no-interaction` to all Artisan commands.
- When creating models, also create factories and seeders.
- Note: only `UserFactory` exists currently. Other models lack factories but have seeders.

## Laravel Boost Tools

Prefer Boost MCP tools over manual alternatives:

- `database-query` — read-only queries instead of raw SQL in tinker
- `database-schema` — inspect table structure before writing migrations/models
- `search-docs` — always search docs before code changes (broad topic queries, no package names)
- `get-absolute-url` — resolve correct URL before sharing with user
- `browser-logs` — recent browser logs/errors

## Testing

- Pest: `php artisan make:test --pest {name}` (don't include suite directory in name)
- Run: `php artisan test --compact` or `--filter=testName`
- Use factories; check for custom states before manual setup.
- Don't delete tests without approval.

## Code Formatting

Run before finalizing PHP changes:

```
vendor/bin/pint --dirty --format agent
```

## Tinker

- Single quotes to prevent shell expansion: `php artisan tinker --execute 'Code();'`
- Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`
- Don't create models in tinker without approval; prefer tests with factories.

## Frontend

- If changes aren't reflected: `npm run build`, `npm run dev`, or `composer run dev`
- Livewire for dynamic interfaces. Alpine.js for client-side interactions.
- Two layouts: `layouts/app.blade.php` (main with sidebar) and `layouts/auth.blade.php` (minimal, no sidebar)
- Custom component: `components/theme-selector.blade.php` wraps `<x-theme-toggle darkTheme="dark" lightTheme="fantasy" />`
- Views organized by domain: `livewire/kargozini/`, `livewire/tickets/`, `livewire/units/`, `livewire/maps/`, `livewire/it/`, `livewire/todo/`, `livewire/auth/`
- Ticket views use `⚡` prefix in filenames (Volt convention): `⚡inbox.blade.php`, `⚡create.blade.php`, `⚡monitoring.blade.php`
- Static assets in `public/`: `js/leaflet/`, `js/chart/`, `js/other/`, `css/leaflet/`, `css/other/`, `fonts/`
