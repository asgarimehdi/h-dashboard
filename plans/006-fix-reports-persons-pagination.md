# Plan 006: Fix reports/persons pagination + unscoped units query

- **Status:** Not started
- **Category:** perf
- **Effort:** S
- **Risk:** HIGH
- **Priority:** P1
- **Depends on:** none
- **Base SHA:** 5f9c24e

## ⚠️ TL;DR فارسی

**مشکل:** گزارش پرسنل ۳۱۸ رکورد یکجا (OOM). واحدها بدون scope.

**⚠️ بررسی:** Blade template pagination link داره؟ اگه نه اول template.

**ریسک:** 🟡 متوسط


## Why this matters

Two performance / correctness problems in the persons report page:

1. **OOM risk:** `chartPayload()` fetches ALL matching persons into memory via `->get()` (line 96-108 of `persons.blade.php`). With 318+ persons and lazy-loaded relations (`unit`, `tahsil`, `semat`, `estekhdam`), each render sends ~318×4 extra queries and loads the full collection into PHP memory. Under Octane, this compounds.

2. **Unscoped units dropdown:** `mount()` loads `Unit::all()` (line 26) for the units filter dropdown. This exposes ALL units globally, not just those the current user can access. The dropdown is used for display purposes (`accessibleUnits` computed property exists on line 120-124 but the `mount()` data is never used for the dropdown — see line 142 which uses `$this->accessibleUnits`). However, the unused `$this->units` property still wastes memory.

## Current state (with file:line excerpts)

### Unscoped units — `resources/views/livewire/reports/persons.blade.php:26`

```php
$this->units = \App\Models\Unit::all();
```

Loads every unit. This property is declared but **not used** in the Blade template — the dropdown on line 142 iterates `$this->accessibleUnits` instead. So `$this->units` is dead code that wastes memory.

### Unpaginated persons — `resources/views/livewire/reports/persons.blade.php:96-108`

```php
$persons = (clone $baseQuery)
    ->orderBy('n_code')
    ->get()
    ->map(fn($p) => [
        'id' => $p->id,
        'n_code' => $p->n_code,
        'name' => $p->name ?? '—',
        'unit' => $p->unit?->name ?? '—',
        'tahsil' => $p->tahsil?->name ?? '—',
        'semat' => $p->semat?->name ?? '—',
        'estekhdam' => $p->estekhdam?->name ?? '—',
    ])
    ->toArray();
```

`->get()` returns all rows. Each person triggers 4 lazy-loaded relations (unit, tahsil, semat, estekhdam). With N persons, this is N×4+1 queries.

### Existing scoped accessor — `resources/views/livewire/reports/persons.blade.php:120-124`

```php
public function getAccessibleUnitsProperty()
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    return \App\Models\Unit::whereIn('id', $accessibleIds)->get();
}
```

This is correct and already used in the Blade template. The `$this->units` from mount is redundant.

## Scope

### In scope
- `resources/views/livewire/reports/persons.blade.php` — single file, all changes

### Out of scope
- Chart data queries (byTahsil, bySemat, byEstekhdam, byUnit) — these use `pluck/count` which is memory-efficient and already scoped
- Other report pages
- AccessService logic

## Commands

```bash
cd /home/runner/h-dashboard
php artisan test --filter Reports
git diff --stat
```

## Steps

### Step 1: Remove dead `$this->units` assignment in mount()

**File:** `resources/views/livewire/reports/persons.blade.php`

Remove line 26:

```php
// BEFORE:
$this->units = \App\Models\Unit::all();

// AFTER:
// (delete this line)
```

Also remove the `$public $units = [];` declaration on line 17 (it's unused).

**Verify:** Read the mount() method. Confirm `$this->units` is no longer assigned.

### Step 2: Add pagination to persons query

**File:** `resources/views/livewire/reports/persons.blade.php`

Replace the `chartPayload()` method's persons section (lines 96-118). Add a `personsPage` property, and paginate the persons query:

```php
// Add as class property:
public int $personsPage = 1;

// In chartPayload(), replace lines 96-108 with:
$personsPaginator = (clone $baseQuery)
    ->orderBy('n_code')
    ->paginate(50, ['*'], 'persons_page', $this->personsPage);

$persons = $personsPaginator
    ->getCollection()
    ->map(fn($p) => [
        'id' => $p->id,
        'n_code' => $p->n_code,
        'name' => $p->name ?? '—',
        'unit' => $p->unit?->name ?? '—',
        'tahsil' => $p->tahsil?->name ?? '—',
        'semat' => $p->semat?->name ?? '—',
        'estekhdam' => $p->estekhdam?->name ?? '—',
    ])
    ->toArray();
```

Update the return array to include pagination metadata:

```php
return [
    'total' => $total,
    'byTahsil' => $byTahsil,
    'bySemat' => $bySemat,
    'byEstekhdam' => $byEstekhdam,
    'byUnit' => $byUnit,
    'persons' => $persons,
    'personsPaginator' => $personsPaginator,
];
```

### Step 3: Add pagination controls in Blade template

**File:** `resources/views/livewire/reports/persons.blade.php`

After the closing `</table>` tag (line 247) but before the closing `</div>` of the card, add:

```blade
@if($chart['personsPaginator']->hasPages())
<div class="flex justify-center mt-4">
    <div class="join">
        @if($chart['personsPaginator']->previousPageUrl())
        <button class="join-item btn btn-sm" wire:click="$set('personsPage', {{ $chart['personsPaginator']->currentPage() - 1 }})">«</button>
        @endif
        <button class="join-item btn btn-sm btn-disabled">{{ $chart['personsPaginator']->currentPage() }} / {{ $chart['personsPaginator']->lastPage() }}</button>
        @if($chart['personsPaginator']->nextPageUrl())
        <button class="join-item btn btn-sm" wire:click="$set('personsPage', {{ $chart['personsPaginator']->currentPage() + 1 }})">»</button>
        @endif
    </div>
</div>
@endif
```

### Step 4: Reset persons page on filter changes

Add `resetPage` logic for the persons page when filters change. The `personsPage` should reset to 1 when any filter changes. Add this to the existing Livewire updated hooks or use a Livewire `updatedPersonsPage` method — simplest approach: use `$this->dispatch('$refresh')` or manually reset.

```php
// Add to the component:
public function updatedSelectedUnitId(): void { $this->personsPage = 1; }
public function updatedSelectedTahsilId(): void { $this->personsPage = 1; }
public function updatedSelectedSematId(): void { $this->personsPage = 1; }
public function updatedSelectedEstekhdamId(): void { $this->personsPage = 1; }
```

### Step 5: Verify

```bash
cd /home/runner/h-dashboard
php artisan test --filter Reports
```

Expected: All tests pass (or no Reports tests exist — note gap).

## Test plan

- Existing tests (if any) should pass.
- **Gap:** There may be no test for the persons report page. Note but do not write new tests.
- Manual verification: Load reports/persons with 318 persons. Confirm page loads in <2s. Confirm table shows 50 rows with prev/next controls. Confirm unit dropdown only shows accessible units (verify via inspecting the rendered HTML or checking the query).

## Done criteria

- [ ] `$this->units` property and its assignment in `mount()` are removed
- [ ] Persons query uses `->paginate(50)` instead of `->get()`
- [ ] Pagination controls render in the Blade template
- [ ] Filter changes reset persons page to 1
- [ ] No memory spike when loading 318 persons

## STOP conditions

- If the `chartPayload()` method is called from JavaScript via `$wire.chartPayload()` (line 290 of the `@script` block) and the return format change breaks the chart rendering, STOP and adjust the JS to handle the new return structure.
- If pagination causes issues with Highcharts rendering (charts need all data), separate the persons pagination from chart data entirely.

## Maintenance notes

- The chart queries (byTahsil, bySemat, etc.) are already efficient — they use aggregate queries and don't need pagination.
- The `personsPaginator` is returned inside `chartPayload()` which is also called from JavaScript for chart rendering. The charts only use `byTahsil`, `bySemat`, `byEstekhdam`, `byUnit` — the `persons` and `personsPaginator` keys are ignored by the JS. This is safe.
- Future: consider moving the chart data and persons table data into separate Livewire methods to decouple them.
