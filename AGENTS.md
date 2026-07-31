# Health Dashboard (داشبورد سلامت) — Documentation

## Project Overview

Health Dashboard is a Laravel 13.x application for managing hospital/healthcare center hardware inventory. Built with Livewire 4, Volt, MaryUI (DaisyUI), and Alpine.js. Fully RTL and Persian-language.

### Tech Stack

- **Framework:** Laravel 13.x (PHP 8.4+)
- **Frontend:** Livewire 4 + Volt, Alpine.js, MaryUI (DaisyUI)
- **Database:** MySQL/MariaDB (with spatial/GIS indexes)
- **Auth:** Laravel Sanctum
- **AI Agent:** Custom Agent/Tool pattern (no external AI SDK)
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

**Boundary** (`boundaries` table)
- `id`, `unit_id` (FK to `units.id`), `boundary` (geometry/POLYGON)

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

**Zone** (`zones` table)
- `id`, `name`, `description`, `color`, `slug`, `is_active`, `boundary_id` (FK to `boundaries.id`)
- Pivot table `zone_units` connects Zone → Unit with timestamps
- Relationships: `units()` (BelongsToMany), `boundary()` (BelongsTo)

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
Zone → Unit (BelongsToMany via zone_units pivot)
Zone → Boundary (BelongsTo via boundary_id)
```

---

## Access Control

Uses **Spatie Permission** package. Key features:

- `HasOrganizationalScope` trait on models for automatic unit-based filtering
- Users see only their own unit's data (plus sub-units via recursive CTE)
- Permission `manage_hardware` required for hardware CRUD and AI agent
- Roles: admin, operator, viewer

### AccessService

The `AccessService` class provides `accessibleUnitIds()` which returns an array of unit IDs the current user can access (their unit + all descendant units via recursive CTE).

---

## AI Integration Layer

### Architecture

Custom Agent/Tool pattern — no external AI SDK dependency. Uses direct HTTP calls to an OpenAI-compatible API endpoint.

**Config** (`config/ai.php`): reads from `.env` via a nested providers structure:

```php
'default' => env('AI_PROVIDER', 'openai'),
'model'   => env('AI_MODEL', 'code'),
'providers' => [
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
    ],
],
```

The `Agent::prompt()` method now checks for empty `OPENAI_API_KEY` and throws a clear `RuntimeException` with `'AI service is not configured. Please set OPENAI_API_KEY in the environment.'` instead of crashing.

### Base Classes

- **`App\Ai\Agent`** — abstract base agent with `prompt()`, `withInstructions()`, `withTool()` methods
- **`App\Ai\Tools\Tool`** — abstract base tool with `name()`, `description()`, `parameters()`, `execute()` methods

### HardwareAgent (`app/Ai/Agents/HardwareAgent.php`)

Hardware inventory assistant with 7 tools:

| Tool | Method | Description |
|---|---|---|
| `SearchHardwareTool` | `search_hardware` | Search hardware by any field (name, IP, MAC, CPU, etc.) — respects organizational scope |
| `HardwareStatsTool` | `hardware_stats` | Aggregate stats: total, by type, by OS, shutdown count — respects organizational scope |
| `PersonHardwareTool` | `person_hardware` | List all devices owned by a person (by n_code) — respects organizational scope |
| `UpdateHardwareTool` | `update_hardware` | Update fields (name, OS, CPU, RAM, IP, etc.) by ID — respects organizational scope |
| `CreateHardwareTool` | `create_hardware` | Create new hardware record (requires n_code + pc_name) — respects organizational scope |
| `DeleteHardwareTool` | `delete_hardware` | Delete a record by ID (requires `confirm=true`) — respects organizational scope |
| `ExportHardwareTool` | `export_hardware` | Export hardware inventory as CSV with optional filters (type, os, cpu, shutdown, person, unit) — respects organizational scope |

### Endpoints

- **`POST /api/ai/hardware`** — AI chat endpoint (auth:sanctum)
- **`POST /api/ai/chat`** — General AI smoke test endpoint

---

## Authentication

The application uses **Laravel Sanctum** with two authentication modes:

| Mode | Routes | Auth Method | Usage |
|---|---|---|---|
| **Web (Session)** | All Livewire UI pages (`/hardware`, `/hardware/ai`, `/units`, `/tickets`, etc.) | Cookie-based session via `web` guard | Browser access, requires login form |
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
    Route::livewire('/hardware/ai', 'hardware.ai-chat')->name('hardware.ai');
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
| GET | `/api/persons` | List with filters: `search`, `unit_id`, `semat_id`. Supports `sort_by`, `sort_dir`, `per_page` (max 100) |
| POST | `/api/persons` | Create (requires `n_code`, `f_name`, `l_name`, `t_id`, `e_id`, `s_id`, `r_id`, `u_id`) |
| GET | `/api/persons/{n_code}` | Show details with relationships (unit, semat, tahsil, estekhdam, radif) |
| PUT | `/api/persons/{n_code}` | Update (partial updates allowed) |
| DELETE | `/api/persons/{n_code}` | Delete |

### Ticket CRUD (`/api/tickets`)

All routes require `auth:sanctum` and filter by user's organizational scope.

| Method | URL | Description |
|---|---|---|
| GET | `/api/tickets` | List with filters: `status`, `priority`, `assigned_to_me`. Paginated (20/page) |
| POST | `/api/tickets` | Create (requires `subject`, `content`, `priority`, `unit_id`). Priority: `urgent`, `high`, `normal`, `medium`, `low` |
| GET | `/api/tickets/{id}` | Show with unit, user, assignee, activities, attachments |
| PUT | `/api/tickets/{id}` | Update |
| DELETE | `/api/tickets/{id}` | Delete |
| POST | `/api/tickets/{id}/assign` | Assign to user (`assignee_id`) |
| POST | `/api/tickets/{id}/accept` | Accept ticket (sets status to `accepted`) |
| POST | `/api/tickets/{id}/complete` | Complete ticket (requires `accepted` status) |

### Todo CRUD (`/api/todos`)

All routes require `auth:sanctum`.

| Method | URL | Description |
|---|---|---|
| GET | `/api/todos` | List |
| POST | `/api/todos` | Create |
| GET | `/api/todos/{id}` | Show |
| PUT | `/api/todos/{id}` | Update |
| DELETE | `/api/todos/{id}` | Delete |
| POST | `/api/todos/{id}/toggle-complete` | Toggle completion status |

### Zone CRUD (`/api/zones`)

All routes require `auth:sanctum`.

| Method | URL | Description |
|---|---|---|
| GET | `/api/zones` | List all zones with unit count |
| POST | `/api/zones` | Create zone (accepts `name`, `description`, `color`, `unit_ids[]`) |
| GET | `/api/zones/{zone}` | Show zone with its units |
| PUT | `/api/zones/{zone}` | Update zone (accepts `unit_ids[]` to replace unit assignments) |
| DELETE | `/api/zones/{zone}` | Delete zone (detaches all units first) |

### Unit CRUD (`/api/units`)

All routes require `auth:sanctum`.

| Method | URL | Description |
|---|---|---|
| GET | `/api/units` | List |
| POST | `/api/units` | Create |
| GET | `/api/units/{id}` | Show |
| PUT | `/api/units/{id}` | Update |
| DELETE | `/api/units/{id}` | Delete |

### Report Endpoints

| Method | URL | Description |
|---|---|---|
| GET | `/api/reports/units` | Units report |
| GET | `/api/reports/todos` | Todos report |
| GET | `/api/reports/tickets` | Tickets report |

### Other Endpoints

| Method | URL | Description |
|---|---|---|
| GET | `/api/zabbix/traffic` | Zabbix traffic data |
| GET | `/api/zabbix/multi-latest` | Zabbix multi-latest values |
| POST | `/api/ai/chat` | General AI smoke test |
| POST | `/api/ai/hardware` | Hardware AI Agent endpoint |

**Note:** All `/api/*` routes now return JSON for all exceptions (including validation errors, 404s, 500s) via `shouldRenderJsonWhen()` in `bootstrap/app.php`. This ensures consistent API responses for clients.

---

### Response Format (Hardware)

```json
{
  "id": 1,
  "pc_name": "PC-001",
  "type": "desktop",
  "ip_local": "192.168.1.100",
  "mac": "AA:BB:CC:DD:EE:FF",
  "mark": true,
  "shutdown": false,
  "clean_at": "2026-07-15",
  "person": { "n_code": "1234567890", "name": "علی رضایی", "unit": "فناوری اطلاعات" },
  "created_at": "2026-07-24T19:49:55+00:00",
  "updated_at": "2026-07-24T19:49:55+00:00"
}
```

---

## UI Features

### Hardware Inventory Page (`/hardware`)

- **Quick Filters:** One-click presets (laptops, servers, SSD, 16GB+, shutdown devices)
- **Advanced Filters:** Toggle panel with 12+ filter fields (type, OS, CPU, RAM, HDD, net type, shutdown status, mark, person, unit, semat)
- **Bulk Actions:** Multi-select checkboxes — delete, mark/unmark devices in bulk
- **Status Badges:** Visual indicators (active 🟢, shutdown ⬛, marked ⚑)
- **Column Visibility:** Toggle columns on/off via a panel
- **Mobile Card Layout:** Table auto-converts to cards on small screens

### Hardware AI Chat (`/hardware/ai`)

- **Livewire Chat UI** with markdown rendering (bold, italic, tables, code)
- **Session Persistence:** Chat history survives page refreshes
- **Quick Action Buttons:** Common queries ready to use
- **Thinking Block Removal:** Strips `<thinking>` and `vous` tags
- **Table Navigation:** AI can trigger filter events on the hardware table

### Hardware Excel Import (`/hardware/import`)

- **File Upload:** Accepts `.xlsx`, `.xls`, `.csv` up to 10 MB
- **Two-Phase Import:** Preview → Confirm (prevents accidental full import)
- **Compare Keys:** Choose match strategy — `pc_name`, `mac`, or `both`
- **Preview Table:** Row-by-row status badges (`create`, `update`, `unchanged`, `errors`)
- **Persian Validation:** Column validation with Persian error messages
- **Organizational Scope:** Only imports hardware into user's accessible units
- **Requires:** `manage_hardware` permission (via `safe_role_or_permission` middleware)

### Zone/Block Management (`/zones`)

- **Zone CRUD:** Create, read, update, delete zones with name, description, color
- **Unit Assignment:** Assign/unassign units to zones via multi-select UI
- **Color Picker:** Visual color selection for zone identification
- **Unit Count Display:** Shows number of units per zone in list view
- **Sidebar Access:** Available under ساختار سازمان (Organizational Structure) section
- **Requires:** `organization` permission (web middleware)

---

## Persian Text Handling

The `PersianNormalizer` trait normalizes:
- `ي` (Arabic Yeh, U+064A) → `ی` (Persian Yeh, U+06CC)
- `ك` (Arabic Kaf, U+0643) → `ک` (Persian Kaf, U+06A9)
- ZWNJ (Zero Width Non-Joiner, U+200C) → space
- ZWJ (Zero Width Joiner, U+200D) → space

Applied to all search and filter operations in both Livewire components, API controllers, and AI tools. The `Person` model auto-normalizes `f_name` and `l_name` on `saving` via the trait's boot method, ensuring Arabic characters are converted to Persian before persistence.

Unicode escape sequences (`\u{200C}`, `\u{200D}`) are used in the trait for ZWNJ/ZWJ to avoid invisible characters in source code.

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
- Eager-load relationships (`with('person')`) in list queries
- Limit API pagination to max 100 per page
- Use recursive CTE via raw SQL for unit hierarchy queries

---

## Deployment

### Environment Variables

```
AI_PROVIDER=openai
AI_MODEL=gpt-4o-mini
OPENAI_URL=https://api.openai.com/v1/chat/completions
OPENAI_API_KEY=sk-...
```

### Build Commands

```bash
composer install --no-dev --optimize-autoloader
pnpm install && pnpm run build
php artisan migrate --force
php artisan db:seed --force
```

---

## Recent Changes (July 2026 Sync — Updated 2026-07-28)

### New API Endpoints
- **Person CRUD** (`/api/persons`): Full CRUD for personnel management with organizational scope filtering
- **Todo CRUD** (`/api/todos`): Task management with toggle completion
- **Report Endpoints** (`/api/reports/*`): Units, todos, and tickets reports
- **Hardware Stats** (`/api/hardware/stats`): Dedicated stats endpoint

### AI Agent Enhancements
- Added `CreateHardwareTool` and `DeleteHardwareTool` to HardwareAgent (now 6 tools total)
- Config restructured with nested providers format
- `Agent::prompt()` now validates `OPENAI_API_KEY` presence and throws clear exception
- Uses OpenAI PHP SDK via `OpenAI\Factory` for HTTP calls
- **New AI Tools:** `SearchPersonsTool` (`search_persons`), `SearchUnitsTool` (`search_units`) — added for personnel and unit search with Persian normalization

### Bug Fixes & Improvements
- **#119**: Persian normalization on Person model — auto-normalizes Arabic ي/ك to Persian ی/ک on save via `PersianNormalizer` trait boot method
- **#121**: Hardware filter maps 'desktop' → 'pc' type alias
- **#123**: AI endpoints handle missing `OPENAI_API_KEY` gracefully with clear exception
- **#126**: API routes return JSON for all exceptions via `shouldRenderJsonWhen()` in `bootstrap/app.php`
- **#127**: Hardware API now supports PATCH method for partial updates (PUT + PATCH both allowed)
- **#130**: Ticket priority now accepts 'medium' (added to ENUM via migration)
- **#132**: Documented auth modes (web session vs API token) for Livewire pages
- **#133**: Hardware PUT update allows partial updates (no longer requires n_code + pc_name)
- **#134**: Ticket assign endpoint fixed — uses `assignee_id` instead of `user_id`
- **#135**: Persian normalization now handles Arabic ي/ك characters in hardware search
- **#136**: Hardware stats endpoint route model binding fixed

### Database & Infrastructure
- New migration: `2026_07_28_000001_add_medium_priority_to_tickets_table.php` (adds 'medium' to priority ENUM)
- New console command: `normalize:persian-text` — normalizes Arabic ي/ك to Persian ی/ک across hardware and person tables
- New models: `Tahsil`, `Estekhdam`, `Radif` with relationships on Person
- New models: `UnitType`, `Region` with relationships on Unit
- `Person` model now uses `PersianNormalizer` trait with boot method for auto-normalization on save

### Testing
- Added `PersonApiTest.php` with full CRUD test coverage
- Expanded `HardwareApiTest.php` and `TicketApiTest.php`
- Added `AiAgentTest.php` for AI integration testing

---

## Recent Changes (July 2026 Sync — Updated 2026-07-28 Later)

### Bug Fixes & Improvements
- **#140**: Hardware stats endpoint (`/api/hardware/stats`) now respects organizational scope — filters by user's accessible units instead of returning global counts
- **#115**: PersianNormalizer trait fixed — now correctly handles ZWNJ (U+200C) and ZWJ (U+200D) using proper Unicode escape sequences (`\u{200C}`, `\u{200D}`) instead of invisible characters in source code
- **Bootstrap Exception Handling**: `bootstrap/app.php` enhanced with custom `NotFoundHttpException` rendering for API routes — returns clean 404 JSON in production, detailed errors in debug mode

### Model Updates
- **Todo model** (`app/Models/Todo.php`): Added `HasFactory` trait for factory support
- **Unit model** (`app/Models/Unit.php`): Added `HasFactory` trait for factory support

### Testing
- Expanded `HardwareApiTest.php` with `test_stats_endpoint_returns_aggregated_data` and `test_stats_endpoint_respects_organizational_scope`
- Refactored `TodoApiTest.php` with shared `createUserWithUnit()` helper and added `test_delete_non_existent_todo_returns_404`

---

## Recent Changes (July 2026 Sync — Updated 2026-07-28 Later)

### Security & Access Control
- **#138** (`fe3a1e4`): AI Agent tools now enforce organizational access control — all AI tools use the `AiAccessScope` trait to filter by user accessible units (same as REST API scope). Previously AI tools bypassed unit restrictions.
- **#124** (`ca47576`): Hardware Livewire pages (`/hardware`, `/hardware/ai`) now require `manage_hardware` permission via `safe_role_or_permission` middleware. Guests still pass through; authenticated users are checked.
- **`SafeRoleOrPermission` middleware** (`app/Http/Middleware/SafeRoleOrPermission.php`): Wraps Spatie's `RoleOrPermissionMiddleware` to skip permission checks for unauthenticated guests — enables hardware pages to work with both session auth and API tokens.

### Hardware API Enhancements (`HardwareController`)
- **`assertAccessible()` method**: Private helper checks if a hardware record belongs to the user's accessible units. Applied to `show`, `update`, `destroy` endpoints.
- **`store()` now validates scope**: Creates check that the target person's unit is within the user's accessible scope (returns 403 if not).
- **`bulkMark()` / `bulkDelete()` scope enforcement**: Bulk operations now only affect records in the user's organizational scope, returning affected `count`.
- **Response improvements**: Bulk operations return `count` of affected records in the JSON response.

### AI Tool: Export
- **New `ExportHardwareTool`** (`app/Ai/Tools/Hardware/ExportHardwareTool.php`): 7th AI tool. Exports hardware inventory as CSV with optional filters (type, os, cpu, shutdown, person, unit). Uses `AiAccessScope` trait for organizational filtering. CSV fields: ID, PC Name, Type, OS, IP Valid, IP Local, MAC, CPU, RAM, HDD, Net Type, Shutdown, Marked, Owner Name, Owner Unit. Persian normalization applied to person/unit filters.
- **`HardwareAgent`** updated to register `ExportHardwareTool`.

### New Trait: `AiAccessScope`
- **`App\Ai\Traits\AiAccessScope`** (`app/Ai/Traits/AiAccessScope.php`): Trait providing `scopedHardwareQuery()` method that returns a `Hardware` query filtered to the current user's accessible units (via `AccessService::accessibleUnitIds()`). Used by all AI hardware tools to enforce the same organizational scope as the REST API.

### Bootstrap & Middleware
- **`SafeRoleOrPermission`** registered in `bootstrap/app.php` under `$middleware->alias` as `safe_role_or_permission`.

### Testing
- **New `AiAgentToolTest.php`**: Comprehensive test suite for all 7 AI tool classes (231 lines), verifying scope enforcement, parameter handling, and edge cases.
- **`AiAgentTest.php`**: Updated with test data setup and new test cases.
- **`HardwareApiTest.php`**: Expanded with 76 lines of new tests covering scope enforcement on bulk operations and CRUD endpoints.
- **`PersonApiTest.php`**, **`TodoApiTest.php`**: Minor refinements.

---

## Recent Changes (July 2026 Sync — Updated 2026-07-29)

### Security & Access Control — Scope Enforcement Sweep
- **#148** (`152e2d5`): `SearchPersonsTool` and `SearchUnitsTool` now enforce organizational access scope — both tools filter by `u_id` using `AccessService`. Previously these tools were bypassable (not in HardwareAgent or not scoped).
- **#146, #147** (`7146657`): Hardware Livewire component (`resources/views/livewire/hardware/index.blade.php`) now applies full organizational scope:
  - Added `accessibleUnitIds()` and `applyOrgScope()` helper methods to the Blade component
  - Scoped `hardwares()` query, `createHardware()`, `editHardware()`, `updateHardware()`, `delete()`, `bulkMark()`, `bulkDelete()`
  - Matches API controller pattern using `AccessService::accessibleUnitIds()`
- **#141** (`9dc2eab`): `PersonController::store()` now validates that the target unit is within the user's accessible scope before creating a person (returns 403 if not).
- **#144/#145** (`9dc2eab`): `HardwareController` CRUD (`store`/`show`/`update`/`destroy`) and bulk operations now enforce organizational access scope via `assertAccessible()` helper. `bulkMark`/`bulkDelete` only affect records in the user's accessible units, returning affected `count`.

### AI Chat Fixes
- **#143** (`9dc2eab`): Fixed double HTML escaping in `ai-chat.blade.php` — removed erroneous `e()` call inside `renderMarkdown()` that was escaping all markdown-generated HTML tags.
- **#139** (`9dc2eab`): Fixed broken think-tag regex in AI chat — replaced wrong Unicode characters with correct pattern using `\x{XXXX}` Unicode syntax for `<thinking>` tag removal.

### Test Infrastructure Fixes
- `rand()` replaced with `random_int()` across all test files (`HardwareApiTest`, `TodoApiTest`, `PersonApiTest`, `AiAgentTest`) to avoid int32 overflow on Windows.
- FK reference bugs fixed (hardcoded `u_id=1` before unit creation) in all API test files.
- MySQL `tinyint` boolean assertion fixed in `TodoApiTest` toggle test.
- `DeleteAlreadyDeletedTodoTest.php` added for 404 edge case.
- `createUserWithUnit()` helper in `HardwareApiTest` now uses `random_int()` and stores unit reference properly.

### Merge Conflict Resolution
- **#116–#145 sweep** (`9dc2eab`): Merged and resolved all outstanding issues from upstream. Frontend build works (npm registry fixed).

### Bootstrap & Exception Handling
- `bootstrap/app.php` enhanced with custom `NotFoundHttpException` rendering for API routes — returns clean 404 JSON in production, detailed errors in debug mode.

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30)

### GIS / Spatial Database Indexes (#149 — `d540e54`)
- **Spatial index** added on `boundaries.boundary` column (SPATIAL index, MySQL/MariaDB only — skipped on SQLite for testing)
- **Composite B-tree index** on `units.lat` + `units.lng` for fast coordinate-based bounding box queries
- **New spatial query scopes** on `Unit` model:
  - `scopeWithinBounds($query, $minLat, $maxLat, $minLng, $maxLng)` — bounding box filter
  - `scopeNearby($query, $lat, $lng, $radiusKm = 10)` — radius filter with approximate degree-per-km
  - `scopeContainingPoint($query, $lat, $lng)` — ST_Contains via spatial index
  - `scopeIntersectsBoundary($query, $wktPolygon)` — ST_Intersects via spatial index
  - `scopeWithinDistance($query, $lat, $lng, $radiusMeters)` — ST_Distance_Sphere

### Hardware Excel Import Feature (#150 — `85537688a5a`)
- **New route:** `/hardware/import` (`hardware.import` named route) — protected by `safe_role_or_permission:manage_hardware`
- **New import class** `App\Imports\HardwareImport` implements `ToCollection`, `WithHeadingRow`, `WithValidation`, `SkipsOnFailure`
  - Supports `.xlsx`, `.xls`, `.csv` files up to 10 MB
  - Compare key options: `pc_name`, `mac`, or `both`
  - Two-phase import: preview → confirm (avoids accidental full import)
  - Respects organizational scope — only imports to accessible units
  - Returns preview with status per row: `create`, `update`, `unchanged`, `errors`
  - Validation with Persian error messages
- **New Livewire component** `App\Livewire\Hardware\ImportHardware\ImportHardware`:
  - `importPreview()`: first pass, builds preview
  - `confirmImport()`: second pass, executes actual import
  - `compareKey` toggle: re-processes preview on change
  - Dispatches `hardware-imported` event on success
- **New UI** `resources/views/livewire/hardware/import-hardware/import-hardware.blade.php`: file upload → preview table → confirm flow with stats badges

### Database Indexes
- **Index on `parent_id`** for `units` and `regions` tables — speeds up hierarchical recursive CTE queries

### New Dependencies
- **maatwebsite/excel** (Laravel Excel) — added to `composer.json`; `config/excel.php` config file generated
- **Livewire Login** (`app/Livewire/Auth/Login.php`): new Livewire login component with rate limiting, lockout support (part of import commit)
- **New test:** `HardwareImportTest.php` covers the full import workflow

---

## Recent Changes (July 2026 Sync — Updated 2026-07-29 Late)

### Database Performance Indexes (#151 — `3d57bd9`)
- **New migration** `2026_07_29_041643_add_indexes_to_hardwares_table.php` adds indexes on filter-heavy columns:
  - Composite index `hardwares_type_os_shutdown_index` on `(type, os, shutdown)`
  - Single-column indexes on: `cpu`, `ram`, `hdd`, `shutdown`, `mark`, `net_type`, `ip_local`, `mac`
- **`HardwareImport`** updated: CSV delimiter changed to tab (`\t`), proper UTF-8 encoding, improved `clean()` for `'\\N'` NULL markers, boolean `parseBoolean()` now handles Persian `بله`/`تایید`

### GIS Performance Optimization (#152 — `7289904`)
- **`Boundary` model** (`app/Models/Boundary.php`): Fixed N+1 query in `geojson` accessor — now uses already-loaded `boundary` attribute when available instead of always querying the DB. Also added `$casts['multipolygon'] = 'multipolygon'` and `province()`/`county()` relationships.
- **`Unit` model** (`app/Models/Unit.php`): Refactored spatial query scopes:
  - `scopeContainingPoint()`: Uses `WHERE EXISTS` with `ST_GeomFromText` for better spatial index utilization (PostgreSQL → MySQL compatible)
  - `scopeIntersectsBoundary()`: Same optimization — `WHERE EXISTS` with `ST_GeomFromText`
  - `scopeWithinDistance()`: Fixed PostGIS-specific `ST_Point` → `ST_GeomFromText('POINT($lng $lat)', 4326)` for MySQL/MariaDB compatibility
- **`Unit` model** fillable updated: now includes `boundary_id` (FK to `boundaries.id`), `description`, and `is_active` (used in recursive CTE to filter active units only)
- **`Unit::descendantIds()`**: Added 15-minute cache with `md5`-based cache key for repeated hierarchical lookups
- **`Unit::boundary()`**: New `BelongsTo` relationship to `Boundary` model
- **`Unit::assignedUsers()`**: New `BelongsToMany` relationship to `User` via `user_units` pivot table

---

## Recent Changes (July 2026 Sync — Updated 2026-07-29 Late)

### Controller Scope Optimization (#153 — `8e0ccf9`)
- **Performance optimization**: Removed duplicate `accessibleUnitIds()` calls across all API controllers
- **`HardwareController`**: Store `accessibleUnitIds` in local variable for `bulkMark` and `bulkDelete` methods
- **`PersonController`**: Store `accessibleUnitIds` in local variable for all CRUD methods
- **`TicketController`**: Store `accessibleUnitIds` in local variable for all CRUD methods + pass `$user` to `accessibleUnitIds()` in `index()` for correct scoping
- **`ReportController`**: Clone base query instead of calling `accessibleUnitIds()` multiple times
- **`TodoController`**: Call `accessibleUnitIds()` once in `index()` method
- **Benefit**: Reduces redundant recursive CTE queries for unit hierarchy, improves API response times

---

## Recent Changes (July 2026 Sync — Updated 2026-07-29 Night)

### Performance Optimizations

#### #154 (`31ed4cd`): N+1 Query Fix in SearchUnitsTool
- **`SearchUnitsTool`** (`app/Ai/Tools/SearchUnitsTool.php`): Fixed N+1 query for `persons_count`
  - Changed from `$u->person()->count()` (fires separate query per result) to `withCount('person')` (single aggregated query)
  - Now uses pre-loaded `$u->persons_count` attribute instead of runtime relationship count
- **Benefit**: 20 units → 1 query instead of 21 queries (95%+ reduction)

#### #156 (`b34c417`): SQL GROUP BY for Unit Type Aggregation
- **`ReportController::units()`** (`app/Http/Controllers/Api/ReportController.php`): Moved unit type aggregation from PHP to SQL
  - Before: Eloquent `->with('unitType:...')->get()->groupBy(...)->map(..., count())` (loads all records into PHP memory)
  - After: Raw SQL `selectRaw('...COALESCE...').leftJoin('unit_types').groupBy('type_name').pluck(...)` (database-side aggregation)
- **`units.blade.php`** (`resources/views/livewire/reports/units.blade.php`): Same optimization applied to Livewire view's `chartPayload()` method
- **Benefit**: No PHP memory overhead for large datasets; single aggregated DB query instead of loading all unit records

#### Test Files Restoration (`c5708e5`)
- Restored missing test files that were absent from the working tree

### Changelog Summary
| Commit | Issue | Change |
|---|---|---|
| `31ed4cd` | #154 | N+1 fix: SearchUnitsTool uses `withCount()` |
| `b34c417` | #156 | SQL GROUP BY replaces PHP aggregation in ReportController + units view |
| `c5708e5` | — | Restored missing test files |
| `57ce505` | — | Removed stray `persons_ count` file (cleanup) |

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30)

### Database Performance Indexes (#158 — `2a42a11`)
- **New migration** `2026_07_29_235959_add_composite_indexes_to_tickets_table.php` adds composite indexes on `tickets` table for most frequent query patterns:
  - `tickets_status_created_at_idx` on `(status, created_at)` — covers status filtering + created_at ordering (inbox, monitoring, API)
  - `tickets_unit_status_created_idx` on `(unit_id, status, created_at)` — covers unit + status filtering + created_at ordering (monitoring, API)
  - `tickets_assignee_status_idx` on `(current_assignee_id, status)` — covers "assigned to me" filter (inbox, API)
- **Benefit**: Index-only scans for common ticket queries, eliminates filesorts on `created_at`

### Security & Access Control — TicketController Scope Enforcement (#157 — `b00ec23`)
- **`TicketController::store()`** now validates that the target `unit_id` is within the user's accessible scope before creating a ticket (returns 403 if not)
- **`TicketController::index()`** now passes `$user` to `accessibleUnitIds($user)` for correct scoping
- Consistent with `PersonController` and `HardwareController` patterns

### Performance Optimizations

#### #155 (`4eeff7a`): SQL GROUP BY for Todo byUnit Aggregation
- **`ReportController::todos()`** (`app/Http/Controllers/Api/ReportController.php`): Moved todo-by-unit aggregation from PHP to SQL
  - Before: Eloquent `->with('unit:...')->get()->groupBy(...)->map(..., count())` (loads all records into PHP memory)
  - After: Raw SQL `selectRaw('...COALESCE...').leftJoin('units').groupBy('unit_name').pluck(...)` (database-side aggregation)
- **Benefit**: No PHP memory overhead for large datasets; single aggregated DB query

#### SearchUnitsTool Fix (Merge Conflict Resolution — `269936e`)
- **`SearchUnitsTool`** (`app/Ai/Tools/SearchUnitsTool.php`): Fixed attribute name from `persons_count` to `person_count` (matches Laravel's `withCount('person')` naming convention)
- **`HardwareImportTest`**: Updated test CSV to include `shutdown` and `mark` columns to match database defaults

### Hardware Import Improvements (Merge Conflict Resolution — `269936e`)
- **`HardwareImport`** (`app/Imports/HardwareImport.php`) refactored:
  - Removed `WithValidation`, `SkipsOnFailure`, `SkipsFailures` interfaces (custom validation handled internally)
  - Added `WithCustomCsvSettings` for tab-delimited CSV with UTF-8 encoding
  - Improved boolean parsing: handles Persian `بله`/`تایید`, database `0`/`1`, and `\\N` NULL markers
  - Counter reset logic during confirmation pass (preview increments → confirm re-increments)
  - `normalizeForComparison()` handles null/empty/false/0/'0'/'1'/'بله'/'تایید' correctly
  - Cleaner separation between preview (buildPreview) and execution (processRow) phases

### Summary of Recent Issues Resolved
| Issue | Commit | Area |
|---|---|---|
| #154 | `31ed4cd` | N+1 fix in SearchUnitsTool |
| #155 | `4eeff7a` | Todo byUnit SQL GROUP BY |
| #156 | `b342417` | Unit type SQL GROUP BY (ReportController + units view) |
| #157 | `b00ec23` | TicketController store scope check |
| #158 | `2a42a11` | Tickets composite indexes |
| — | `c5708e5` | Test files restoration |
| — | `269936e` | SearchUnitsTool attribute fix + HardwareImport test fix |

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30)

### Database Performance Indexes (#159 — `774c694`, #160 — `3e39a6b`)
- **New migration** `2026_07_30_000001_add_composite_indexes_to_todos_table.php`: Adds 4 composite indexes on `todos` table for frequent query patterns:
  - `todos_completed_created_idx` on `(is_completed, created_at)` — covers completion status filtering + created_at ordering (reports, listing)
  - `todos_completed_end_at_idx` on `(is_completed, end_at)` — covers overdue queries (WHERE is_completed=false AND end_at < now)
  - `todos_start_at_idx` on `(start_at)` — covers GROUP BY date(start_at) in reports
  - `todos_unit_completed_idx` on `(unit_id, is_completed)` — covers filtered listing in TodoController::index
- **New migration** `2026_07_29_173757_add_composite_index_to_units_table.php`: Adds composite index `units_is_active_parent_id_index` on `(is_active, parent_id)` for Recursive CTE optimization
  - Optimizes `Unit::descendantIds()` CTE used by `AccessService::accessibleUnitIds()` across all API controllers (Hardware, Person, Ticket, Todo, Report, Unit) and Livewire components
  - Column order: `is_active` (equality filter) first, then `parent_id` (join) — enables MySQL index condition pushdown for both conditions

### Help System UI (Part of #160 — `3e39a6b`)
- **New Help System Components** added for contextual help across all major pages:
  - **`x-help-content:button`** (`resources/views/components/help/button.blade.php`): Reusable help button component with section parameter, dispatches `help-open` event
  - **`x-help-content:modal`** (`resources/views/components/help/modal.blade.php`): Modal with tabbed help content, supports 14 sections (dashboard, hardware, hardware-import, hardware-ai, persons, units, tickets, todos, reports, maps, settings, roles, permissions, users, activity-log)
  - **14 Help Content Components** in `resources/views/components/help/content/` — each provides contextual Persian documentation for its section
  - Integrated into: Dashboard, Hardware (index + AI + Import), Personnel, Units, Tickets, Todos, Reports, Maps, Settings, Roles, Permissions, Users, Activity Log

### Hardware Import Minor Fix
- **`ImportHardware.php`** (line 35): Added `public bool $showHelpModal = false;` property to support help modal integration

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30 Later)

### Performance Optimizations

#### #161 (`9e9ffad`): Dashboard Stats Caching
- **`dashboard.blade.php`** (`resources/views/livewire/dashboard.blade.php`): Major refactor of `mount()` — reduced 20+ individual DB queries per page load to cached batch queries with TTL tiers:
  - Scope-less global stats (users, roles) — cached **5 minutes**
  - Scope-dependent stats (persons, units, tickets, todos) — cached **5 minutes** per scope
  - Today-only stats (today's tickets, todos, activities) — cached **2 minutes** (more time-sensitive)
  - Ticket detail stats (urgent/normal/low/overdue counts, avg resolution days) — cached **3 minutes**
  - Dashboard chart data (`ticketChartData`, `ticketStatusData`) — cached **5 minutes**
  - Recent activities — cached **2 minutes**
- Cache keys scoped via `md5(implode(',', $accessibleIds))` for per-user-org isolation
- **Benefit**: Dashboard page load went from 20+ queries to ~1–3 cache lookups + a few missed stats

#### #162 (`64b717b`): Duplicate Query Reduction in map-no-boundary
- **`map-no-boundary.blade.php`** (`resources/views/livewire/reports/map-no-boundary.blade.php`): Extracted shared `getUnitsWithoutBoundary()` private method used by both `chartPayload()` and `getAllUnitsProperty`:
  - Before: both properties independently called `accessibleUnitIds()` + query → 2 CTEs + 2 queries per request
  - After: single cached call (5-min TTL, per-scope) shared between both properties
  - Early return when `$accessibleIds` is empty (avoids unnecessary query)
  - Also integrated help modal (`x-help:button` + `x-help:modal`) into this page
- **Benefit**: Eliminated 1 CTE + 1 query per request for this report page

### Help System Integration
- **Map/No-Boundary report** now includes help button and modal (consistent with other pages)

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30 Night)

### Persian User Guide Documentation (#66 — `aa87bde`)
- **New 12-chapter Persian user guide** in `docs/user-guide/` covering all major application sections:
  - `00-introduction.md` — overview, tech stack, access control
  - `01-login-profile.md` — authentication, profile management, password change
  - `02-unit-context.md` — organizational unit selection and scope
  - `03-personnel-management.md` — person CRUD, filters, management features
  - `04-ticket-system.md` — ticket lifecycle, creation, assignment, acceptance, completion
  - `05-map-features.md` — GIS map, unit boundaries, spatial queries
  - `06-hardware-inventory.md` — hardware CRUD, import, AI assistant
  - `07-reports.md` — all report types (units, persons, todos, tickets, advanced)
  - `08-it-monitoring.md` — Zabbix integration, traffic monitoring
  - `09-admin-settings.md` — roles, permissions, users, settings, activity log
  - `10-in-app-help.md` — contextual help system usage
  - `index.md` — table of contents and navigation
- **New route** `GET /docs/user-guide/{page?}` serves the user guide rendered from markdown
- **New view** `resources/views/docs/user-guide.blade.php` — renders markdown docs with RTL layout
- **New sidebar link** in app layout pointing to the user guide

### New Help Content Components (Part of #66 — `aa87bde`)
- 6 new help content blade components added for previously uncovered sections:
  - **`chat.blade.php`**: Chat/communication help
  - **`it-monitoring.blade.php`**: IT monitoring help (Zabbix, network traffic)
  - **`profile.blade.php`**: User profile and account settings help
  - **`search.blade.php`**: Global search functionality help
  - **`tools.blade.php`**: Tools and utilities help
  - **`networks.blade.php`**: Network section help
  - **`wireless.blade.php`**: Wireless/wi-fi section help
- **Help modal integration extended** to additional Livewire pages: Permissions, Roles, Settings, Tickets (create, inbox, monitoring), Users, Units, Activity Log, Networks, Wireless, Personnel (kargozini), Map/Unit, Reports (index, advanced, units)
- **Help modal added section prop** (`state.section`) for tracking which help section is currently active

### Heroicon Fixes in Help Components (#66 — `1bc5f3d`)
- Fixed outdated Heroicon icon names causing missing icons:
  - `o-message-square` → `o-chat-bubble-left-right` (hardware-ai help)
  - `o-history` → `o-clock` (hardware-ai help)
  - `o-headphones` → `o-chat-bubble-left-right` (app sidebar)
- Help modal now accepts `section` prop for contextual help navigation

### Performance: Livewire Reports SQL GROUP BY (#163 — `d60f2b0`)
- **`todos.blade.php`**: Replaced PHP `->get()->groupBy('unit_id')` with SQL `leftJoin('units')` + `groupBy('unit_name')` + `pluck()` for byUnit chart data
- **`persons.blade.php`**: Applied SQL GROUP BY to 4 chart methods (byTahsil, bySemat, byEstekhdam, byUnit) — replaces loading all Person records into PHP memory
- **`advanced.blade.php`**: Persons report details (byEstekhdam, byTahsil, bySemat) now use SQL-level aggregation
- **All queries** continue to respect organizational scope via `AccessService::accessibleUnitIds()`
- **Benefit**: Zero PHP memory overhead for large datasets; single aggregated DB query per chart instead of loading all records

### Performance: Map/Unit Duplicate Query Reduction (#164 — `6e4eedb`)
- **`map/unit.blade.php`** (`resources/views/livewire/maps/unit.blade.php`): Optimized `loadUnits()` method:
  - Merged query 1 (get centers) & query 2: get centers with `get()`, then `pluck('id')` in-memory — eliminates a duplicate SQL query
  - Eliminated query 4 (loadBoundaries): switch to eager-loading boundaries via `with('boundary:id,unit_id,geojson')` in initial queries instead of separate `loadBoundaries()` call
  - Cached `showSelectedCounties()` results with `Cache::remember` (5-minute TTL)
- **Benefit**: Reduced from 4–5 sequential SQL queries per filter change to 2–3 queries

### Help System Integration Summary
- Help modals now integrated across: Dashboard, Hardware (index + AI + Import), Personnel, Units, Tickets (create, inbox, monitoring), Todos, Reports (index, units, persons, advanced, todos), Maps (unit + no-boundary), Settings, Roles, Permissions, Users, Activity Log, IT Monitoring, Networks, Wireless

### Changelog Summary (Last Sync Period)

| Commit | Issue | Change |
|---|---|---|
| `aa87bde` | #66 | New 12-chapter Persian user guide + 7 new help content components + docs route |
| `1bc5f3d` | #66 | Fixed heroicon names in help components and app layout |
| `d60f2b0` | #163 | SQL GROUP BY in Livewire reports (todos, persons, advanced) |
| `6e4eedb` | #164 | Reduced duplicate queries in map/unit loadUnits by merging + caching |

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30 Final)

### Database Performance Indexes (#166 — `e65b75bc`)
- **New migration** `2026_07_30_035023_add_composite_indexes_to_persons_table.php` adds 4 composite indexes on `persons` table for frequent query patterns:
  - `persons_u_id_n_code_idx` on `(u_id, n_code)` — covers unit filtering + n_code search (PersonController::index)
  - `persons_u_id_f_name_l_name_idx` on `(u_id, f_name, l_name)` — covers unit filtering + name search
  - `persons_u_id_s_id_idx` on `(u_id, s_id)` — covers unit + semat filtering
  - `persons_u_id_s_id_n_code_idx` on `(u_id, s_id, n_code)` — covers import lookup pattern (HardwareImport)
- **Benefit**: Index-covering for all common person queries; eliminates full table scans on `n_code`, name, and semat filters within unit scope

### N+1 Query Fix in HardwareImport (#166 — `e65b75bc`)
- **`HardwareImport.php`** (`app/Imports/HardwareImport.php`): Fixed N+1 query in `buildPreview()` and `processRow()`
  - Before: queried `Person::where('n_code', ...)` once per row → O(n) DB calls for n rows
  - After: new `loadExistingPersons()` pre-loads all persons in accessible units in a single query → O(1) DB call
  - Uses in-memory `$this->existingPersons[$n_code]` lookup in `buildPreview()` and `applySelectedAction()`
- **Benefit**: Hardware import now scales from O(n) queries to O(1) regardless of file size

### Person Excel Import Feature (#166 — `0dde735`)
- **New import class** `App\Imports\PersonImport` implements `ToCollection`, `WithHeadingRow`, `WithCustomCsvSettings`
  - Supports `.xlsx`, `.xls`, `.csv` files up to 10 MB
  - Match strategy: always by `n_code` (primary key)
  - Two-phase import: preview → confirm (prevents accidental changes)
  - Returns preview with status per row: `create`, `update`, `unchanged`, `errors`
  - Validation with Persian error messages
  - Respects organizational scope via `AccessService::accessibleUnitIds()`
- **New Livewire component** `App\Livewire\Kargozini\ImportPersons\ImportPersons`:
  - `importPreview()`: first pass, builds preview with stats (new, updated, unchanged, errors)
  - `confirmImport()`: second pass, executes actual import
  - Persian normalization via `PersianNormalizer` trait
  - Help modal integration
- **New route** `/kargozini/persons/import` (`kargozini.persons.import` named route) — protected by `kargozini` middleware
- **New UI** `resources/views/livewire/kargozini/import-persons/import-persons.blade.php`: file upload → preview table → confirm flow with stats badges
- **New test** `tests/Feature/PersonImport/PersonImportTest.php` (251 lines): covers full import workflow with scope enforcement

### Summary
|| Commit | Issue | Change ||
||---|---|---|---|
|| `e65b75bc` | #166 | Composite indexes on persons table + N+1 fix in HardwareImport ||
|| `0dde735` | #166 | New PersonImport + ImportPersons Livewire component + route + UI + test ||

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30 Final)

|### Zone/Block Management Feature (#79 — `f70f9eb`)
|- **New model** `App\Models\Zone` (`app/Models/Zone.php`): stores zone name, description, color, slug, is_active; `BelongsToMany` relationship to `Unit` via `zone_units` pivot table with timestamps
- **New API controller** `App\Http\Controllers\Api\ZoneController`: full CRUD with `withCount('units')`, unit sync via `unit_ids` parameter
  - `index()`: list all zones with unit count
  - `store()`: create zone + optional `unit_ids[]` sync
  - `show()`: show zone with its units
  - `update()`: partial update + optional `unit_ids` sync (replaces existing units)
  - `destroy()`: detach all units then delete
- **New API routes** (all require `auth:sanctum`):
  - `GET /api/zones` — list all zones
  - `POST /api/zones` — create zone (accepts `name`, `description`, `color`, `unit_ids[]`)
  - `GET /api/zones/{zone}` — show zone
  - `PUT /api/zones/{zone}` — update zone
  - `DELETE /api/zones/{zone}` — delete zone
- **New UI page** `zones.zones-index` (`resources/views/livewire/zones/zones-index.blade.php`): Volt-style Livewire component with zone CRUD, color picker, unit assignment
- **New web route** `/zones` under `organization` middleware group — accessible to users with `organization` permission
- **New test** `tests/Feature/ZoneApiTest.php` (7 tests): covers auth, CRUD, unit sync
- **Sidebar link** added under ساختار سازمان (Organizational Structure) section

### N+1 Query Fix in County Map (#167 — `b2bacb2`)
- **`maps/county.blade.php`** (`resources/views/livewire/maps/county.blade.php`): Refactored `mount()` to eliminate N+1 query
  - Before: `Region::with('boundary')->get()->map(...)` → loaded Region + separate query per boundary via relationship
  - After: `Region::query()->select(...DB::raw('ST_AsGeoJSON(boundaries.boundary) as geojson')...).join('boundaries', ...)` → single query with GeoJSON computed inline
- **Benefit**: 1 SQL query instead of N+1; GeoJSON computed at DB level, no Eloquent model hydration overhead

### AppServiceProvider Help Component Registration
- **`app/Providers/AppServiceProvider.php`**: Now dynamically registers all help-content Blade components with colon syntax (`x-help-content:{name}`) in a loop — eliminating repetitive manual registrations and adding `networks` and `wireless` to the list

### Summary
||| Commit | Issue | Change ||
|||---|---|---|---|
|| `f70f9eb` | #79 | Zone/block management: model, API CRUD, Livewire UI, sidebar link, tests ||
|| `b2bacb2` | #167 | N+1 fix: county map uses single JOIN + ST_AsGeoJSON query ||
|| `e65b75bc` | #166 | Composite indexes on persons table + N+1 fix in HardwareImport ||
|| `0dde735` | #166 | PersonImport + ImportPersons Livewire component + route + UI + test ||

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30 Latest)

### Zone Organizational Scope Enforcement (#169 — `72f103e`)
- **`ZoneController`** (`app/Http/Controllers/Api/ZoneController.php`): Added full organizational scope enforcement
  - New `assertAccessible(Request $request, Zone $zone)` private helper: checks if zone has at least one unit in user's accessible unit IDs
  - `index()`: Uses new `Zone::accessible($user)` scope to filter zones by organizational scope
  - `store()`: Validates that all `unit_ids` are within user's accessible scope before creating zone
  - `show()` / `update()` / `destroy()`: All call `assertAccessible()` to verify zone access
- **`Zone` model** (`app/Models/Zone.php`): Added `scopeAccessible(Builder $query, ?User $user)` query scope
  - Filters zones to only those having at least one unit within the user's accessible unit IDs
  - Returns empty result (1=0) if user has no accessible units
|- **New migration** `2026_07_30_071813_add_unit_id_index_to_zone_unit_table.php`: Added index on `zone_units.unit_id` for faster scope queries
- **Livewire Zone Index** (`resources/views/livewire/zones/zones-index.blade.php`):
  - `zones()` query now uses `Zone::accessible()` scope
  - `saveZone()` validates selected units against user's accessible scope
  - `editZone()` and `deleteZone()` use `Zone::accessible()` to prevent unauthorized access
- **Tests** updated: `ZoneApiTest.php` now attaches zones to user's unit for proper scope testing

### Advanced Reports Optimization (#170 — `6117878`)
- **`reports/advanced.blade.php`**: Replaced custom recursive PHP `getDescendantIds()` method with cached `Unit::descendantIds()` 
  - Removed 12 lines of recursive PHP code that fetched child units one-by-one
  - Now uses `Unit::descendantIds($unitId)->toArray()` which has 15-minute cache (added in #152)
  - **Benefit**: Eliminates N recursive queries per report generation; uses single cached CTE call

### County Map GeoJSON Caching (#171 — `e5d3d6a`)
- **`maps/county.blade.php`** (`resources/views/livewire/maps/county.blade.php`): Added 5-minute cache for GeoJSON regions query
  - Before: Query executed on every page load (JOIN regions + boundaries + ST_AsGeoJSON)
  - After: Wrapped in `Cache::remember('county:regions_with_boundaries', 300, ...)`
  - **Benefit**: Reduces DB load for map page; GeoJSON computed once per 5 minutes instead of per-request

### Summary
||| Commit | Issue | Change ||
|||---|---|---|---|
|| `72f103e` | #169 | Zone API + Livewire: organizational scope enforcement, accessible scope, unit_id index ||
|| `6117878` | #170 | Advanced reports: replace recursive PHP getDescendantIds with cached Unit::descendantIds() ||
|| `e5d3d6a` | #171 | County map: cache GeoJSON regions query (5 min TTL) ||

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30 Evening)

### Zone Pivot Table Rename: `zone_unit` → `zone_units` (#173 — `40e8abc`)
- **Migration fix**: `zone_unit` pivot table renamed to `zone_units` (plural, consistent with Laravel conventions)
  - `2026_07_30_040001_create_zones_tables`: `zone_unit` → `zone_units`
  - `2026_07_30_071813_add_unit_id_index_to_zone_unit_table`: table name + index name updated (`zone_unit_unit_id_index` → `zone_units_unit_id_index`)
- **`Zone` model** (`app/Models/Zone.php`): `belongsToMany(..., 'zone_unit')` → `belongsToMany(..., 'zone_units')`
- **`ZoneController`** (`app/Http/Controllers/Api/ZoneController.php`): pluck path `'zone_unit.unit_id'` → `'zone_units.unit_id'`
- **`ZoneApiTest`**: `assertDatabaseHas('zone_unit', ...)` → `assertDatabaseHas('zone_units', ...)`
- **`Zone` model**: also adds `slug` and `is_active` fields to the model (migration-created columns)
- **Breaking change**: Existing databases with `zone_unit` table need a rename migration before deploying this update

### Zone Map Page — New Feature (#173 — `3770923`, `97d0a30`, `40e8abc`)
- **New Livewire component** `App\Livewire\Maps\ZoneMap` (`app/Livewire/Maps/ZoneMap.php`): interactive zone map with region overlay
  - `loadAvailableZones()`: loads accessible zones filtered by organizational scope + unit count
  - `loadAvailableRegions()`: cached 5 minutes via `Cache::remember('zonemap:available_regions', 300, ...)` — `#173` fix
  - `loadZoneUnits()`: loads selected zones with their unit boundaries, dispatches `zone-units-loaded` event to JS
  - `loadCountyBoundaries()`: per-region cached county boundaries (5-min TTL) dispatched to JS
- **New view** `resources/views/livewire/maps/zone-map.blade.php`: Leaflet map with zone/unit boundaries, region overlay, zone selector sidebar
- **New Volt Livewire components** (`app/Livewire/Zones/`): `Create.php`, `Edit.php`, `Index.php` — zone CRUD with organizational scope enforcement

### Ticket Bulk Actions N+1 Query Fix (#172 — `3770923`)
- **Tickets inbox** (`⚡inbox.blade.php`): `executeBulkAction()` refactored — replaces per-ticket `foreach` loop with batch queries
  - Before: ~5×N queries for N tickets (1 SELECT + 1 UPDATE + 1 INSERT + 2 ActivityLog per ticket)
  - After: ~3 queries total (1 SELECT + 1 UPDATE + 1 batch INSERT + batch ActivityLogService call)
  - Removed redundant `ActivityLogService::updated()` call in the loop
  - Added pre-filter: skips tickets already `completed` before processing bulk
  - Deleted `bulkCompleteTicket()` and `bulkForwardTicket()` private methods (logic inlined into `executeBulkAction()`)

### New Demo Script Documentation
- **`docs/DEMO_SCRIPT.md`** (406 lines): comprehensive demo script covering all major features — login, units, tickets, persons, hardware, AI assistant, import, maps, reports, admin settings

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30 Latest)

### ZoneMap Caching Optimization (#174 — `5e3f2f6`)
- **`ZoneMap::loadAvailableZones()`** (`app/Livewire/Maps/ZoneMap.php`): Added 5-minute cache (300s TTL) for available zones query
  - Before: Query executed on every page load (Zone::whereHas + withCount per request)
  - After: Wrapped in `Cache::remember('zonemap:available_zones:' . md5(implode(',', $accessibleUnitIds)), 300, ...)` — cache key includes user's accessible unit IDs for scope isolation
  - **Benefit**: Reduces DB load for zone map page; zones query computed once per 5 minutes per organizational scope

### Database Performance Indexes (#175 — `259d41a`)
- **New migration** `2026_07_30_080000_add_composite_index_to_notifications_table.php`: Adds composite index on `notifications` table:
  - `notifications_user_read_created_idx` on `(user_id, is_read, created_at)` — covers:
    1. Notification bell query: `WHERE user_id = ? ORDER BY created_at DESC LIMIT 15`
    2. Unread count query: `WHERE user_id = ? AND is_read = false`
    3. Cleanup queries: `WHERE created_at < ?`
- **Benefit**: Index-only scans for notification queries, eliminates filesorts on `created_at`

### Summary
|| Commit | Issue | Change |
||---|---|---|---|
|| `5e3f2f6` | #174 | ZoneMap loadAvailableZones() caching with 300s TTL |
|| `259d41a` | #175 | Composite index on notifications (user_id, is_read, created_at) |
|| `52d4b7f` | #176 | Add boundary relationship to Zone model |

### Zone Model Boundary Relationship (#176 — `52d4b7f`)
- **`Zone` model** (`app/Models/Zone.php`): Added `belongsTo` relationship to `Boundary` model via `boundary_id`
  - Added `boundary_id` and `is_active` to `$fillable` (alongside existing `slug`)
  - New `boundary()` method returns `BelongsTo` relationship
  - Enables zones to have their own geographic boundaries on the map

---

## Recent Changes (July 2026 Sync — Updated 2026-07-30 Late Evening)

### GitHub Actions Production Deploy Workflow (`06e0ba6`)
- **New file** `.github/workflows/deploy.yml`: GitHub Actions workflow for automated production deployment
  - Triggers on: push to `main` branch and manual `workflow_dispatch`
  - Runs on: `self-hosted` runner
  - Steps: `git pull origin main` → `php artisan view:clear` → `config:clear` → `route:clear` → `optimize` → `sudo systemctl reload apache2`
  - Target path: `/home/boxd/h-dashboard`

### Heroicon Fix — Non-existent Icon Name Causing 500 Error (#180 — `8c4c17`)
- **`config/blade-heroicons.php`**: Updated icon name mapping from `o-arrow-` to `o-arrows-` (correct Heroicons naming)
- **`public/vendor/blade-heroicons/`**: Regenerated all SVG sprite files (`c-`, `m-`, `o-`, `s-` variants) with corrected icon names
- **Affected map views**: `interactive.`, `point.`, `unit.`, `zones/index`
- **Bug**: The icon name `o-arrow-` was used but doesn't exist in the Heroicons sprite — causing a 500 Internal Server Error on the login page, preventing all users from logging in
- **Fix**: Corrected to `o-arrows-` (the plural form that exists in the sprite sheet)

### Summary
| Commit | Issue | Change |
|---|---|---|
| `06e0ba6` | — | New GitHub Actions production deploy workflow |
| `8c4c17` | #180 | Heroicon fix: `o-arrow-` → `o-arrows-` (500 error on login) |

---

## Recent Changes (July 2026 Sync — Updated 2026-07-31)

### Production Deployment Workflow (#181 — `06e0ba6`)
- **New GitHub Actions workflow** `.github/workflows/deploy.yml` for automated production deployment:
  - Triggers: push to `main` branch + manual `workflow_dispatch`
  - Runs on a **self-hosted** runner at `/home/boxd/h-dashboard`
  - Steps: `git pull origin main` → `php artisan view:clear` → `config:clear` → `route:clear` → `optimize` → `sudo systemctl reload apache2`
- **Note:** CI/CD is now the production deployment path — any merge to `main` auto-deploys the app on the production server

### Heroicon Naming Fix (#180 — `8c4c17`)
- **`config/blade-heroicons.php`**: Brand-new config file (previously absent from config/) — added full icon name mapping with correct `arrows-up-down` namespacing
- **`public/vendor/blade-heroicons/`**: Regenerated all SVG sprite files (c-, m-, o-, s- variants) with corrected icon names
- **Impact**: Fixes 500 errors on pages referencing the previously-invalid `o-arrow-down-up` icon name (login page + sortable table headers)

### Changelog Summary (This Sync Period)
| Commit | Issue | Change |
|---|---|---|
| `06e0ba6` | #181 | GitHub Actions production deploy workflow (self-hosted runner + artisan optimize) |
| `8344c17` | #180 | Heroicon fix: `o-arrow-down-up` → `o-arrows-up-down` (invalid icon name) |

---

*Previous sync: 2026-07-30 Late Evening (commits `06e0ba6`, `8c4c17`).*
