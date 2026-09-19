# Plan 010: Add MaintenanceSchedule CRUD UI (single-file Livewire)

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: direction
- **Planned at**: 2026-09-19 (v2, AGENTS.md conventions)

## Why this matters
MaintenanceSchedule model exists with `isDue()` method and a daily scheduled command generates due items, but users cannot create/view/edit/delete schedules. The feature generates work silently with no user control.

## Current state
- `app/Models/MaintenanceSchedule.php`: fillable fields + isDue()
- `app/Console/Commands/GenerateDueMaintenance.php`: consumes model
- No Livewire component, no route, no view

## AGENTS.md conventions for this task
- Livewire components are **single-file**: inline anonymous class at top of Blade view
- Route: `Route::livewire('/path', 'feature.name')`
- Component lives at `resources/views/livewire/<feature>/<name>.blade.php`
- Reference by dot-name string: `'maintenance.index'`
- Use MaryUI components: `x-input`, `x-select`, `x-button`, `x-modal`, `x-table`
- Run `vendor/bin/pint --dirty --format agent` before committing

## Steps

### Step 1: Create single-file Livewire component
Create `resources/views/livewire/maintenance/index.blade.php` following the pattern from `resources/views/livewire/kargozini/estekhdam.blade.php`:

```php
<?php

use App\Models\MaintenanceSchedule;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    // ... CRUD methods following estekhdam pattern
    // Table with: name, frequency, next_due, last_run, actions
};
```

### Step 2: Add route
In `routes/web.php`, add under the existing `manage_hardware` permission group:

```php
Route::livewire('/maintenance', 'maintenance.index')->name('maintenance.index');
```

### Step 3: Add navigation link
Add to the sidebar navigation partial (find existing nav in layout).

### Step 4: Add test
Create `tests/Feature/MaintenanceLivewireTest.php` (Pest):
- test mount loads maintenance schedules
- test create schedule via modal
- test edit schedule
- test delete schedule

### Step 5: Verify
```bash
grep -rn "maintenance" routes/web.php
# → should show route
ls resources/views/livewire/maintenance/index.blade.php
# → file exists
composer test -- --filter=MaintenanceLivewireTest
# → passes
```

## Done criteria
- [ ] Single-file Livewire component with list/create/edit/delete
- [ ] Route registered
- [ ] Navigation link added
- [ ] Pest test added and passing
- [ ] `vendor/bin/pint --dirty --format agent` passes
