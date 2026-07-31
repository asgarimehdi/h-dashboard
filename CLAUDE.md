# Health Dashboard (داشبورد سلامت) — Project Guide for AI Coding Agents

## Project Overview
Laravel 13.x application for managing hospital/healthcare center hardware inventory. Livewire 4 + Volt + MaryUI (DaisyUI) frontend. Fully RTL Persian.

## Quick Commands
```bash
# Development
php artisan serve
pnpm dev

# Build
pnpm build
php artisan optimize

# Database
php artisan migrate:fresh --seed
php artisan db:seed --class=HardwareSeeder
```

## Code Architecture

### Key Directories
- `app/Http/Controllers/Api/` — REST API controllers
- `app/Traits/` — Reusable traits (`PersianNormalizer`)
- `app/Services/` — Business logic (`AccessService`)
- `resources/views/livewire/hardware/` — Volt components (`index.blade.php`, `import-hardware.blade.php`)

## Database Models

### Person
- Primary key: `n_code` (string, not auto-increment)
- `f_name`, `l_name`, `u_id` (FK→units), `semat_id` (FK→semats)

### Hardware
- `n_code` FK→persons, `pc_name`, `type`, `os`, `ip_valid`, `ip_local`, `mac`
- `shutdown` (boolean), `mark` (boolean), `clean_at` (nullable date)
- All relationships: `belongsTo(Person::class, 'n_code', 'n_code')`

### Unit
- `parent_id` self-referencing FK for hierarchy
- `hasMany(Person::class, 'u_id')`

## Access Control
- Spatie Permission package
- `HasOrganizationalScope` trait — auto-filters queries by user's accessible units
- `AccessService::accessibleUnitIds($user)` — returns unit IDs (self + descendants via recursive CTE)
- Permission `manage_hardware` for hardware CRUD

## Persian Text Handling
Always normalize user input for Persian character variants:
```php
use App\Traits\PersianNormalizer;
```
- `ي` → `ی`, `ك` → `ک`, ZWNJ → space
- Apply `self::normalizeForSearch($value)` in all LIKE queries

## API Conventions
- All routes under `auth:sanctum`
- Hardware routes: `Route::prefix('hardware')`
- Filters: `search`, `type`, `os`, `cpu`, `ram`, `hdd`, `shutdown`, `mark`, `person`, `unit`, `semat`
- All queries include unit-based access filter via `whereHas('person', fn)`
- Pagination max 100 per page

## UI Conventions
- **RTL:** All layouts `dir="rtl"`
- **Components:** MaryUI (`x-input`, `x-table`, `x-modal`, `x-button`, `x-badge`, `x-select`)
- **Volt components:** Anonymous Livewire components (`return new class extends Component`)
- **Filter variables:** `$filter*` naming convention
- **Toast:** `$this->success()`, `$this->error()`, `$this->warning()` (from `Mary\Traits\Toast`)

## Hardware Table Columns
`['id', 'pc_name', 'person_name', 'type', 'os', 'ip_local', 'cpu', 'ram', 'hdd', 'status']`
- `status` computed: `mark ? 'mark' : (shutdown ? 'off' : 'on')`
- Marked rows: `bg-warning/20 border-r-4 border-r-warning`
- Mobile: card layout via `grid grid-cols-1 md:hidden`