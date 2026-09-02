# Plan 023: Decompose hardware/index.blade.php (1366 Lines)

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

The hardware index Livewire component is a 1366-line monolith mixing 5+ responsibilities: filters, table, modals, trash modal, and audit history. This makes the file hard to navigate, test, and maintain.

### Current File Structure

**File:** `resources/views/livewire/hardware/index.blade.php` (1366 lines)

| Section | Approx Lines | Responsibility |
|---------|-------------|---------------|
| PHP class (inline) | 1-660 | Component logic: filters, CRUD, trash, audit |
| Filters UI | ~661-750 | Search, dropdowns, status filter |
| Table UI | ~751-950 | Hardware table with rows |
| Help Modal | ~951-990 | Help/guide modal |
| Audit History Modal | ~991-1100 | Audit log display |
| Trash Modal | ~1101-1366 | Deleted hardware list, restore |

### Current Class Properties (PHP Section)

**File:** `resources/views/livewire/hardware/index.blade.php:1-30`

```php
return new class extends Component
{
    use PersianNormalizer;
    use Toast;
    use WithPagination;

    public string $search = '';
    public int $perPage = 20;
    public bool $showHelpModal = false;
    public bool $showHistoryModal = false;
    public bool $showTrashModal = false;
    // ... many more properties for filters, selected items, audit data
```

---

## Solution

Extract sub-components as Livewire components. Since this project uses **single-file Livewire components** (anonymous class at top of Blade), each sub-component follows the same pattern.

### New Component Files

**1. `resources/views/livewire/hardware/filters.blade.php`**

Extract the filter UI section (search, dropdowns, status toggles) as a Livewire component:

```php
<?php

use Livewire\Component;

return new class extends Component
{
    public string $search = '';
    public string $statusFilter = '';
    public string $typeFilter = '';
    // ... filter properties

    public function updatedSearch(): void
    {
        $this->dispatch('filters-changed', search: $this->search, status: $this->statusFilter);
    }
    // ... other filter methods
};
```

**2. `resources/views/livewire/hardware/table.blade.php`**

Extract the hardware table with sorting, pagination, and row actions:

```php
<?php

use Livewire\Component;
use Livewire\WithPagination;

return new class extends Component
{
    use WithPagination;

    public $hardware = [];
    public array $selectedIds = [];
    // ... table properties

    public function render()
    {
        return view('livewire.hardware.table');
    }
};
```

**3. `resources/views/livewire/hardware/trash-modal.blade.php`**

Extract the trash modal (deleted hardware list, restore functionality):

```php
<?php

use Livewire\Component;
use App\Models\HardwareAudit;

return new class extends Component
{
    public bool $show = false;
    public array $deletedHardware = [];
    // ... trash properties

    public function loadDeletedHardware(): void
    {
        // Move the N+1-buggy code here (also fix per Plan 017)
    }

    public function restoreRecord(int $auditId): void
    {
        // Move restore logic here
    }
};
```

**4. `resources/views/livewire/hardware/audit-modal.blade.php`**

Extract the audit history modal:

```php
<?php

use Livewire\Component;

return new class extends Component
{
    public bool $show = false;
    public int $hardwareId = 0;
    public array $auditHistory = [];

    public function loadHistory(int $hardwareId): void
    {
        // Move audit history loading logic here
    }
};
```

### Parent Component Changes

**File:** `resources/views/livewire/hardware/index.blade.php`

Replace the extracted sections with component includes:

```blade
{{-- Filters --}}
<livewire:hardware.filters
    :search="$search"
    :statusFilter="$statusFilter"
/>

{{-- Table --}}
<livewire:hardware.table
    :hardware="$hardware"
    :selectedIds="$selectedIds"
/>

{{-- Trash Modal --}}
<livewire:hardware.trash-modal />

{{-- Audit Modal --}}
<livewire:hardware.audit-modal />
```

Remove the extracted properties and methods from the parent class. The parent retains: mount(), CRUD operations (store, update, delete), and high-level state.

### Communication Between Components

Use Livewire events for inter-component communication:

```blade
{{-- In filters component --}}
wire:change="$refresh"

{{-- In parent --}}
protected $listeners = ['filters-changed' => 'applyFilters'];
```

---

## Verification

1. **Run hardware tests:**
   ```bash
   composer test -- --filter=Hardware
   ```
   Expected: all hardware tests pass.

2. **Manual UI test:**
   - Open hardware index page
   - Test filters (search, status, type dropdowns)
   - Test table pagination
   - Test trash modal (open, view deleted items, restore)
   - Test audit history modal
   - All interactions work as before

3. **File sizes check:**
   ```bash
   wc -l resources/views/livewire/hardware/*.blade.php
   ```
   Expected: each file under 400 lines.

---

## STOP Conditions

- If Livewire event communication between components doesn't work, fall back to `wire:model` and `$refresh`.
- If the parent component's state can't be shared with child components, use Livewire 4's public properties and `wire:model`.
- If any hardware test fails, investigate whether the component name changed and update the test.

---

## Out of Scope

- Extracting the PHP class methods into a service class (separate refactor).
- Adding new filter options.
- Changing the table column layout.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer test -- --filter=Hardware` | All pass |
| 2 | Open hardware index → filters work | Search, filter, reset |
| 3 | Trash modal opens and shows deleted items | Correct data |
| 4 | Audit modal shows history | Correct data |
| 5 | `wc -l resources/views/livewire/hardware/*.blade.php` | Each <400 lines |
| 6 | `vendor/bin/pint --dirty --format agent` | Clean |

---

## Maintenance Notes

- **Naming convention:** Sub-components use dot notation: `livewire:hardware.filters`. Files go in `resources/views/livewire/hardware/`.
- **Blade includes vs Livewire components:** Livewire components are used here because each sub-section has its own state and methods. Blade `@include` would share the parent's scope — not suitable for modals with independent state.
- **Livewire 4 single-file:** Each sub-component follows the same anonymous-class pattern as the parent.
- **Test impact:** Tests that reference `livewire.hardware.index` directly may need updating if they assert DOM structure that changed.
