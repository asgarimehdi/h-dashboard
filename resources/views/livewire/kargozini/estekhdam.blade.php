<?php

use App\Models\Estekhdam;
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

    public int $perPage = 5;

    public bool $showForm = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

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

    public function delete(Estekhdam $estekhdam): void
    {
        try {
            $estekhdam->delete();
            $this->warning("$estekhdam->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.', position: 'toast-bottom');
        }
    }

    public function createEstekhdam(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:estekhdams,name',
        ]);

        Estekhdam::create(['name' => $this->name]);

        $this->success("$this->name ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function editEstekhdam($id): void
    {
        $this->resetValidation();
        $estekhdam = Estekhdam::findOrFail($id);
        $this->editingId = (int) $id;
        $this->name = $estekhdam->name;
        $this->showForm = false;
    }

    public function updateEstekhdam(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:estekhdams,name,'.$this->editingId,
        ]);

        try {
            $estekhdam = Estekhdam::findOrFail($this->editingId);
            $estekhdam->update(['name' => $this->name]);

            $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
            $this->cancelEdit();
        } catch (\Exception $e) {
            $this->error('خطا در ویرایش', position: 'toast-bottom');
        }
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'name', 'label' => 'عنوان', 'class' => 'flex-1'],
        ];
    }

    public function estekhdams(): LengthAwarePaginator
    {
        $query = Estekhdam::query();

        if (! empty($this->search)) {
            $query->where('name', 'LIKE', '%'.$this->search.'%');
        }

        $query->orderBy(...array_values($this->sortBy));

        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'estekhdams' => $this->estekhdams(),
            'headers' => $this->headers(),
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت وضعیت های استخدامی" separator progress-indicator>
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

        @if($showForm && ! $editingId)
            <div class="flex flex-col sm:flex-row gap-2 mb-4 p-3 bg-base-200 rounded-lg items-end">
                <div class="flex-1 w-full">
                    <x-input wire:model="name" label="عنوان استخدامی جدید" placeholder="عنوان" required />
                    @error('name') <span class="text-error text-xs">{{ $message }}</span> @enderror
                </div>
                <div class="flex gap-2">
                    <x-button wire:click="createEstekhdam" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                    <x-button wire:click="cancelEdit" label="لغو" icon="o-x-mark" class="btn-ghost" />
                </div>
            </div>
        @endif

        <x-table
            :headers="$headers"
            :rows="$estekhdams"
            :sort-by="$sortBy"
            with-pagination
            per-page="perPage"
            :per-page-values="[3, 5, 10]"
        >
            @scope('cell_name', $estekhdam)
                @if($editingId === $estekhdam->id)
                    <div class="flex gap-2 items-center">
                        <input
                            type="text"
                            wire:model="name"
                            wire:keydown.enter="updateEstekhdam"
                            class="input input-bordered input-sm flex-1"
                            autofocus
                        />
                        <x-button icon="o-check" wire:click="updateEstekhdam" class="btn-ghost btn-sm text-success" spinner />
                        <x-button icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-sm" />
                    </div>
                    @error('name') <span class="text-error text-xs">{{ $message }}</span> @enderror
                @else
                    {{ $estekhdam->name }}
                @endif
            @endscope

            @scope('actions', $estekhdam)
                <div class="flex gap-1">
                    @if($editingId !== $estekhdam->id)
                        <x-button
                            icon="o-pencil"
                            wire:click="editEstekhdam({{ $estekhdam->id }})"
                            class="btn-ghost btn-sm text-primary"
                        />
                        <x-button
                            icon="o-trash"
                            wire:click="delete({{ $estekhdam->id }})"
                            wire:confirm="آیا مطمئن هستید؟"
                            spinner
                            class="btn-ghost btn-sm text-error"
                        />
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>
</div>
