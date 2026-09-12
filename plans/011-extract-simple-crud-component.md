# Plan 011: Extract SimpleCrudComponent for Kargozini CRUD pages

- **Category:** tech-debt
- **Effort:** M
- **Risk:** MED
- **Priority:** P2
- **Depends on:** none
- **Status:** proposed

## Problem

Four kargozini CRUD pages contain nearly identical code (~200 lines each) with only the Model class name, table name, and display label differing. Any bug fix or feature addition must be replicated 4 times.

### Files and their differences

| File | Model | Table (validation) | Display Label |
|------|-------|-------------------|---------------|
| `estekhdam.blade.php` | `Estekhdam` | `estekhdams` | استخدامی |
| `radif.blade.php` | `Radif` | `radifs` | ردیف سازمانی |
| `tahsil.blade.php` | `Tahsil` | `tahsils` | تحصیلی |
| `semat.blade.php` | `Semat` | `semats` | سمت |

### What's identical (4× duplicated)

**PHP class (lines 1-120 in each file):**
- Properties: `$name`, `$editingId`, `$search`, `$perPage`, `$showForm`, `$sortBy`
- Methods: `cancelEdit()`, `startCreate()`, `delete()`, `create{Model}()`, `edit{Model}()`, `update{Model}()`, `nameError()`, `headers()`, `{models}()` (paginated query), `with()`
- Only differences: Model class name, method suffix, unique validation rule table name

**Blade template (lines 122-203 in each file):**
- Header title (Persian label)
- Create form label text
- Table with identical structure: `cell_name` scope, inline edit, delete confirm
- Only differences: method names (`createEstekhdam` vs `createRadif`), form label text, header title

### Example: `createEstekhdam()` vs `createRadif()`

```php
// estekhdam.blade.php
public function createEstekhdam(): void {
    $this->validate(['name' => 'required|string|max:255|unique:estekhdams,name']);
    Estekhdam::create(['name' => $this->name]);
    $this->success("$this->name ایجاد شد", 'با موفقیت', position: 'toast-bottom');
    $this->cancelEdit();
}

// radif.blade.php — identical logic, different names
public function createRadif(): void {
    $this->validate(['name' => 'required|string|max:255|unique:radifs,name']);
    Radif::create(['name' => $this->name]);
    $this->success("$this->name ایجاد شد", 'با موفقیت', position: 'toast-bottom');
    $this->cancelEdit();
}
```

## Proposed Fix

### Approach: Config-driven anonymous class + shared Blade template

Since this project uses **anonymous-class Livewire components** (inline in Blade), we can't create a traditional base class. Instead, use a **trait** + **config registry** + **shared Blade partial**.

### Step 1: Create the SimpleCrud trait

```php
// app/Livewire/Traits/SimpleCrud.php
namespace App\Livewire\Traits;

use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

trait SimpleCrud
{
    use WithPagination;

    public $name;
    public ?int $editingId = null;
    public string $search = '';
    public int $perPage = 20;
    public bool $showForm = false;
    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    abstract protected function modelClass(): string;
    abstract protected function tableName(): string;
    abstract protected function displayName(): string;

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->reset(['name', 'editingId', 'showForm']);
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->reset(['name', 'editingId']);
        $this->showForm = true;
    }

    public function delete($model): void
    {
        try {
            $model->delete();
            $this->warning("{$model->name} حذف شد", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.', position: 'toast-bottom');
        }
    }

    public function create(): void
    {
        $this->validate([
            'name' => "required|string|max:255|unique:{$this->tableName()},name",
        ]);
        $this->modelClass()::create(['name' => $this->name]);
        $this->success("{$this->name} ایجاد شد", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function edit($id): void
    {
        $this->resetValidation();
        $model = $this->modelClass()::findOrFail($id);
        $this->editingId = (int) $id;
        $this->name = $model->name;
        $this->showForm = false;
    }

    public function update(): void
    {
        $this->validate([
            'name' => "required|string|max:255|unique:{$this->tableName()},name,{$this->editingId}",
        ]);
        try {
            $model = $this->modelClass()::findOrFail($this->editingId);
            $model->update(['name' => $this->name]);
            $this->success("{$this->name} بروزرسانی شد", 'با موفقیت', position: 'toast-bottom');
            $this->cancelEdit();
        } catch (\Exception $e) {
            $this->error('خطا در ویرایش', position: 'toast-bottom');
        }
    }

    public function nameError(): ?string
    {
        return $this->getErrorBag()->first('name');
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'name', 'label' => 'عنوان', 'class' => 'flex-1'],
        ];
    }

    public function records(): LengthAwarePaginator
    {
        $query = $this->modelClass()::query();
        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }
        $query->orderBy(...array_values($this->sortBy));
        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'records' => $this->records(),
            'headers' => $this->headers(),
            'displayName' => $this->displayName(),
        ];
    }
}
```

### Step 2: Each component becomes ~10 lines

```php
// resources/views/livewire/kargozini/estekhdam.blade.php
<?php
use App\Livewire\Traits\SimpleCrud;
use App\Models\Estekhdam;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast, SimpleCrud;

    protected function modelClass(): string { return Estekhdam::class; }
    protected function tableName(): string { return 'estekhdams'; }
    protected function displayName(): string { return 'استخدامی'; }
};
```

```php
// radif.blade.php — same 10-line pattern
<?php
use App\Livewire\Traits\SimpleCrud;
use App\Models\Radif;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast, SimpleCrud;

    protected function modelClass(): string { return Radif::class; }
    protected function tableName(): string { return 'radifs'; }
    protected function displayName(): string { return 'ردیف سازمانی'; }
};
```

`tahsil.blade.php` and `semat.blade.php` follow the same pattern.

### Step 3: Create shared Blade template

```blade
{{-- resources/views/livewire/kargozini/_simple-crud.blade.php --}}
@props(['records', 'headers', 'displayName'])

<div>
    <x-header title="مدیریت وضعیت‌های {{ $displayName }}" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <x-button class="btn-success" wire:click="startCreate" responsive icon="o-plus"/>
            <div class="flex-1">
                <x-input
                    placeholder="جستجو..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
        </div>

        @if($showForm && !$editingId)
            <div class="flex flex-col sm:flex-row gap-2 mb-4 p-3 bg-base-200 rounded-lg items-end">
                <div class="flex-1 w-full">
                    <x-input wire:model="name" label="عنوان {{ $displayName }} جدید" placeholder="عنوان" required />
                    @error('name') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>
                <div class="flex gap-2">
                    <x-button wire:click="create" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                    <x-button wire:click="cancelEdit" label="لغو" icon="o-x-mark" class="btn-ghost" />
                </div>
            </div>
        @endif

        <x-table :headers="$headers" :rows="$records" :sort-by="$sortBy"
                 with-pagination per-page="perPage" :per-page-values="[10, 20, 50]">
            @scope('cell_name', $record)
                @if($this->editingId === $record->id)
                    <div class="flex gap-2 items-center">
                        <input type="text" wire:model="name" wire:keydown.enter="update"
                               class="input input-bordered input-sm flex-1" autofocus />
                        <x-button icon="o-check" wire:click="update" class="btn-ghost btn-sm text-success" spinner />
                        <x-button icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-sm" />
                    </div>
                    @if($this->nameError()) <span class="text-error text-xs">{{ $this->nameError() }}</span> @endif
                @else
                    {{ $record->name }}
                @endif
            @endscope

            @scope('actions', $record)
                <div class="flex gap-1">
                    @if($this->editingId !== $record->id)
                        <x-button icon="o-pencil" wire:click="edit({{ $record->id }})"
                                  class="btn-ghost btn-sm text-primary" />
                        <x-button icon="o-trash" wire:click="delete({{ $record->id }})"
                                  wire:confirm="آیا مطمئن هستید؟" spinner class="btn-ghost btn-sm text-error" />
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>
</div>
```

### Step 4: Update each component to use the shared template

After the PHP class in each file:

```blade
{{-- estekhdam.blade.php after PHP class --}}
@include('livewire.kargozini._simple-crud', [
    'records' => $records,
    'headers' => $headers,
    'displayName' => $displayName,
])
```

## Files to Create

1. **`app/Livewire/Traits/SimpleCrud.php`** — trait with all CRUD logic
2. **`resources/views/livewire/kargozini/_simple-crud.blade.php`** — shared Blade template

## Files to Modify

1. **`resources/views/livewire/kargozini/estekhdam.blade.php`** — reduce from 203 lines to ~15
2. **`resources/views/livewire/kargozini/radif.blade.php`** — reduce from 187 lines to ~15
3. **`resources/views/livewire/kargozini/tahsil.blade.php`** — reduce from 187 lines to ~15
4. **`resources/views/livewire/kargozini/semat.blade.php`** — reduce from 188 lines to ~15

**Total: ~765 lines → ~75 lines (90% reduction)**

## Verification

1. **Functional test:** Navigate to each of the 4 pages (`/kargozini/estekhdams`, `/kargozini/radifs`, `/kargozini/tahsils`, `/kargozini/semats`). Verify: search works, create works, inline edit works, delete with confirm works, pagination works.
2. **Pest tests:** Run any existing kargozini tests. Add a reusable data provider test if none exist.
3. **Livewire wire:click naming:** The shared template uses generic names (`create`, `update`, `edit`, `delete`). Verify these don't conflict with Livewire's built-in methods. If they do, prefix with the CRUD config name.
4. **Route compatibility:** Routes reference `kargozini.estekhdam`, `kargozini.radif`, etc. These resolve by file path — no route changes needed.

## Risks

- **Risk:** Trait method names (`create`, `update`, `delete`) may conflict with Livewire built-in methods. **Mitigation:** `Livewire\Component` has `delete()` already. Rename trait methods to `createRecord()`, `updateRecord()`, `deleteRecord()` if conflict occurs. Test this during implementation.
- **Risk:** Livewire anonymous classes with traits — verify trait `$this` context works correctly. **Mitigation:** Livewire supports traits on anonymous classes (the Toast trait already proves this pattern).
- **Risk:** `@scope` in shared Blade uses generic `$record` variable name. **Mitigation:** The variable name matches what's passed in `with()`. Verify MaryUI x-table scope naming compatibility.
