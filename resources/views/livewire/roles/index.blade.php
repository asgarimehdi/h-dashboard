<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\Permission\Models\Role;

new class extends Component {
    use WithPagination;
    use Toast;

    public string $name, $label;
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 5;
    public bool $modal = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    public function clear(): void
    {
        $this->reset();
        $this->info('فیلدها خالی شدند', position: 'toast-bottom');
    }

    public function delete(Role $role): void
    {
        try {
            $role->delete();
            $this->warning("$role->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.", position: 'toast-bottom');
        }
    }

    public function createRole(Role $role): void
    {
        $this->validate([
            'name' => 'required|string|alpha_num:ascii|max:255|unique:roles,name',
            'label' => 'required|string|max:255|unique:roles,label',
        ]);

        $role::create(['name' => $this->name,'label' => $this->label]);

        $this->success("$this->label ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset();
        $this->modal = false;
    }

    public function editRole($id)
    {
        $role = Role::findOrFail($id);
        $this->editingId = $id;
        $this->name = $role->name;
        $this->label = $role->label;
    }

    public function updateRole(): void
    {
        $this->validate([
            'name' => 'required|string|alpha_num:ascii|max:255|unique:roles,name,' . $this->editingId,
            'label' => 'required|string|max:255|unique:roles,label,' . $this->editingId,
        ]);

        try {
            $role = Role::findOrFail($this->editingId);
            $role->update(['name' => $this->name,'label' => $this->label]);

            $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
            $this->reset();
            $this->modal = false;
        } catch (\Exception $e) {
            $this->error("خطا در ویرایش", position: 'toast-bottom');
        }
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell',],
            ['key' => 'label', 'label' => 'عنوان', 'class' => 'flex-1'],
            ['key' => 'name', 'label' => 'نام', 'class' => 'flex-1'],
        ];
    }

    public function roles(): LengthAwarePaginator
    {
        $query = Role::query();

        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%')->orWhere('label', 'LIKE', '%' . $this->search . '%');
        }
        $query->orderBy(...array_values($this->sortBy));
        return $this->roles = $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'editingId' => $this->editingId,
            'roles' => $this->roles(),
            'headers' => $this->headers()
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت نقش ها" separator progress-indicator>
        <x-slot:middle class="!justify-end"></x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <x-button class="btn-success" @click="$wire.modal = true" responsive icon="o-plus"/>
            <div class="flex-1">
                <x-input
                    placeholder="Search..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
        </div>

        <x-table :headers="$headers" :rows="$roles" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[3, 5, 10]">
            @foreach($roles as $role)
                <tr wire:key="{{ $role->id }}">
                    <td>{{ $role->id }}</td>
                    <td>{{ $role->name }}</td>
                    <td>{{ $role->label }}</td>
                    <td>
                        @scope('actions', $role)
                        <div class="flex w-1/4">
                            <x-button icon="o-pencil"
                                      wire:click="editRole({{ $role->id }})"
                                      class="btn-ghost btn-sm text-primary"
                                      @click="$wire.modal = true">
                                <span class="hidden sm:inline">ویرایش</span>
                            </x-button>

                            <x-button icon="o-trash"
                                      wire:click="delete({{ $role->id }})"
                                      wire:confirm="Are you sure?"
                                      spinner
                                      class="btn-ghost btn-sm text-error">
                                <span class="hidden sm:inline">حذف</span>
                            </x-button>
                        </div>
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-modal wire:model="modal" :title="$editingId ? 'ویرایش ' : 'جدید'" persistent
             separator>
        <x-form wire:submit.prevent="{{ $editingId ? 'updateRole' : 'createRole' }}" class="grid gap-4">
            <x-input
                wire:model="name"
                label="نام نقش"
                placeholder="نام انگلیسی نقش"
                required
                icon="o-magnifying-glass"
            />
            <x-input
                wire:model="label"
                label="عنوان فارسی نقش"
                placeholder="عنوان فارسی نقش"
                required
                icon="o-magnifying-glass"
            />

            <div class="flex gap-4">
                <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary pl-6" spinner/>
                <x-button label="بستن" icon="o-x-mark" wire:click="clear" class="btn-default pl-6" spinner/>
            </div>
        </x-form>
    </x-modal>
</div>
