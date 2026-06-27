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
- **Tickets**: Ticket workflow with statuses `created`, `forwarded`, etc. Has `TaskActivity` and `Attachment`.
- **Todos**: `Todo` with `is_completed` flag. API endpoints for Flutter app.
- **Maps**: Location logs, tile server (`TILE_SERVER_IP` in `config/map.php`)
- **IT Monitoring**: `ZabbixService` fetches network traffic/wireless data (`ZABBIX_URL`, `ZABBIX_TOKEN`)

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

- **Livewire inline classes**: anonymous `return new class extends Component {}` in blade files
- **maryUI components**: `x-header`, `x-card`, `x-stat`, `x-table`, `x-icon`, `x-button`, `x-theme-selector`
- **Persian/RTL**: All UI text is Farsi. Use Jalali dates (verta/morilog).
- Routes use `Route::livewire()` pattern

## Skills

Domain-specific skills in `.github/skills/`:
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
