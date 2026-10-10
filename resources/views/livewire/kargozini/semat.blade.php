<?php

use App\Models\Semat;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    public $name;

    public ?int $editingId = null;

    public string $search = '';

    public int $perPage = 20;

    public bool $showForm = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // #914: `sortBy` is client-settable, so only real columns are sortable.
    private const SORTABLE_COLUMNS = ['id', 'name'];

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

    public function delete(Semat $semat): void
    {
        try {
            $semat->delete();
            $this->warning("$semat->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.', position: 'toast-bottom');
        }
    }

    public function createSemat(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:semats,name',
        ]);

        Semat::create(['name' => $this->name]);

        $this->success("$this->name ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function editSemat($id): void
    {
        $this->resetValidation();
        $semat = Semat::findOrFail($id);
        $this->editingId = (int) $id;
        $this->name = $semat->name;
        $this->showForm = false;
    }

    public function updateSemat(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:semats,name,'.$this->editingId,
        ]);

        try {
            $semat = Semat::findOrFail($this->editingId);
            $semat->update(['name' => $this->name]);

            $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
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

    public function semats(): LengthAwarePaginator
    {
        $query = Semat::query();

        if (! empty($this->search)) {
            $query->where('name', 'LIKE', '%'.$this->search.'%');
        }

        $column = in_array($this->sortBy['column'] ?? '', self::SORTABLE_COLUMNS, true)
            ? $this->sortBy['column'] : 'id';
        $direction = ($this->sortBy['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction);

        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'semats' => $this->semats(),
            'headers' => $this->headers(),
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت سمت‌ها" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <x-ui.icon-button name="سمت جدید" label="سمت جدید" class="btn-success" wire:click="startCreate" responsive icon="o-plus"/>
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

        @if($showForm && ! $editingId)
            <div class="flex flex-col sm:flex-row gap-2 mb-4 p-3 bg-base-200 rounded-lg items-end">
                <div class="flex-1 w-full">
                    <x-input wire:model="name" label="عنوان سمت جدید" placeholder="عنوان" required />
                    @error('name') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>
                <div class="flex gap-2">
                    <x-button wire:click="createSemat" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                    <x-button wire:click="cancelEdit" label="لغو" icon="o-x-mark" class="btn-ghost" />
                </div>
            </div>
        @endif

        <x-table :headers="$headers" :rows="$semats" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[10, 20, 50]">
            @scope('cell_name', $semat)
                @if($this->editingId === $semat->id)
                    <div class="flex gap-2 items-center">
                        <input
                            type="text"
                            wire:model="name"
                            wire:keydown.enter="updateSemat"
                            class="input input-bordered input-sm flex-1"
                            autofocus
                        />
                        <x-ui.icon-button name="ذخیره سمت" icon="o-check" wire:click="updateSemat" class="btn-ghost btn-sm text-success" spinner />
                        <x-ui.icon-button name="انصراف از ویرایش" icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-sm" />
                    </div>
                    @if($this->nameError()) <span class="text-error text-xs">{{ $this->nameError() }}</span> @endif
                @else
                    {{ $semat->name }}
                @endif
            @endscope

            @scope('actions', $semat)
                <div class="flex gap-1">
                    @if($this->editingId !== $semat->id)
                        <x-ui.icon-button name="ویرایش سمت" icon="o-pencil" wire:click="editSemat({{ $semat->id }})" class="btn-ghost btn-sm text-primary" />
                        <x-ui.icon-button name="حذف سمت" icon="o-trash" wire:click="delete({{ $semat->id }})" wire:confirm="آیا مطمئن هستید؟" spinner class="btn-ghost btn-sm text-error" />
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>
</div>
