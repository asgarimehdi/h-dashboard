# Plan 010: Add MaintenanceSchedule CRUD UI

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: direction
- **Planned at**: 2026-09-19

## Why this matters
MaintenanceSchedule model exists with `isDue()` method and a daily scheduled command generates due items, but users cannot create/view/edit/delete schedules. The feature generates work silently with no user control.

## Current state
- `app/Models/MaintenanceSchedule.php`: fillable fields + isDue()
- `app/Console/Commands/GenerateDueMaintenance.php`: consumes model
- No Livewire component, no route, no view

## Steps

### Step 1: Create Livewire Volt component
Create `resources/views/livewire/maintenance/index.blade.php` following the kargozini/estekhdam.blade.php pattern: table with CRUD modals.

### Step 2: Add route
In `routes/web.php`, add under manage_hardware permission group:
```php
Route::middleware(['auth', 'permission:manage_hardware'])->group(function () {
    Route::livewire('/maintenance', 'maintenance.index')->name('maintenance.index');
});
```

### Step 3: Add navigation link
Add to the sidebar navigation (find existing nav partial).

### Step 4: Verify
**Verify**: `cd /home/runner/h-dashboard && grep -rn "maintenance" routes/web.php` → should show route
**Verify**: `ls resources/views/livewire/maintenance/index.blade.php` → file exists

## Done criteria
- [ ] Livewire component with list/create/edit/delete
- [ ] Route registered
- [ ] Navigation link added
- [ ] pint passes
