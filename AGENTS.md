# Health Dashboard (داشبورد سلامت) — Documentation

## Project Overview

Health Dashboard is a Laravel 13.x application for managing hospital/healthcare center hardware inventory. Built with Livewire 4, Volt, MaryUI (DaisyUI), and Alpine.js. Fully RTL and Persian-language.

### Tech Stack

- **Framework:** Laravel 13.x (PHP 8.4+)
- **Frontend:** Livewire 4 + Volt, Alpine.js, MaryUI (DaisyUI)
- **Database:** MySQL/MariaDB
- **Auth:** Laravel Sanctum
- **AI Agent:** Custom Agent/Tool pattern (no external AI SDK)
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
- `id`, `name`, `parent_id` (self-referencing for hierarchy)

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

Hardware inventory assistant with 6 tools:

| Tool | Method | Description |
|---|---|---|
| `SearchHardwareTool` | `search_hardware` | Search hardware by any field (name, IP, MAC, CPU, etc.) |
| `HardwareStatsTool` | `hardware_stats` | Aggregate stats: total, by type, by OS, shutdown count |
| `PersonHardwareTool` | `person_hardware` | List all devices owned by a person (by n_code) |
| `UpdateHardwareTool` | `update_hardware` | Update fields (name, OS, CPU, RAM, IP, etc.) by ID |
| `CreateHardwareTool` | `create_hardware` | Create new hardware record (requires n_code + pc_name) |
| `DeleteHardwareTool` | `delete_hardware` | Delete a record by ID (requires `confirm=true`) |

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
| PUT | `/api/hardware/{id}` | Update (partial updates allowed — only sends changed fields) |
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
- **Thinking Block Removal:** Strips `<thinking>` and `<think>` tags
- **Table Navigation:** AI can trigger filter events on the hardware table

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

## Recent Changes (July 2026 Sync)

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

### Bug Fixes & Improvements
- **#121**: Hardware filter maps 'desktop' → 'pc' type alias
- **#123**: AI endpoints handle missing `OPENAI_API_KEY` gracefully
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

### Testing
- Added `PersonApiTest.php` with full CRUD test coverage
- Expanded `HardwareApiTest.php` and `TicketApiTest.php`
- Added `AiAgentTest.php` for AI integration testing
