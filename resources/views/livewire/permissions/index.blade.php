<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\Permission\Models\Permission;
return new class extends Component
{
    use WithPagination;
    use Toast;

    public string $name, $label;
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 20;
    public bool $modal = false;
    public bool $showHelpModal = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // #914: `sortBy` is client-settable, so only real columns are sortable.
    private const SORTABLE_COLUMNS = ['id', 'label', 'name'];

    public function mount(): void
    {
        $this->authorize('manage_roles');
    }

    public function clear(): void
    {
        $this->reset();
        $this->info('فیلدها خالی شدند', position: 'toast-bottom');
    }

    public function delete(Permission $permission): void
    {
        $this->authorize('manage_roles');

        try {
            $permission->delete();
            $this->warning("$permission->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // Issue #892: re-throw so a 403 is not reported to the user as the
            // generic "still used in another table" foreign-key toast.
            throw $e;
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.", position: 'toast-bottom');
        }
    }

    public function createPermission(): void
    {
        $this->authorize('manage_roles');

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:permissions,name',
                'regex:/^[a-zA-Z0-9\s\-]+$/u',
            ],
            'label' => [
                'required',
                'string',
                'max:255',
                'unique:permissions,label',
                'regex:/^[\p{L}\p{N}\s\-]+$/u',
            ],
        ]);

        Permission::create(['name' => $this->name, 'label' => $this->label]);

        $this->success("$this->label ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset();
        $this->modal = false;
    }

    public function editPermission($id): void
    {
        $this->authorize('manage_roles');

        $permission = Permission::findOrFail($id);
        $this->editingId = $id;
        $this->name = $permission->name;
        $this->label = $permission->label;
    }

    public function updatePermission(): void
    {
        $this->authorize('manage_roles');

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:permissions,name,' . $this->editingId,
                'regex:/^[a-zA-Z0-9\s\-]+$/u',
            ],
            'label' => [
                'required',
                'string',
                'max:255',
                'unique:permissions,label,' . $this->editingId,
                'regex:/^[\p{L}\p{N}\s\-]+$/u',
            ],
        ]);

        try {
            $permission = Permission::findOrFail($this->editingId);
            $permission->update(['name' => $this->name, 'label' => $this->label]);

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
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'label', 'label' => 'عنوان', 'class' => 'flex-1'],
            ['key' => 'name', 'label' => 'نام', 'class' => 'flex-1'],
        ];
    }

    public function permissions(): LengthAwarePaginator
    {
        $query = Permission::query();

        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%')
                  ->orWhere('label', 'LIKE', '%' . $this->search . '%');
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
            'editingId' => $this->editingId,
            'permissions' => $this->permissions(),
            'headers' => $this->headers()
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت دسترسی ها" separator progress-indicator>
        <x-slot:middle class="!justify-end"></x-slot:middle>
        <x-slot:actions>
            <x-help:button section="permissions" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <x-button class="btn-success" @click="$wire.modal = true" responsive icon="o-plus"/>
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

        <x-table :headers="$headers" :rows="$permissions" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[10, 20, 50]">
            @foreach($permissions as $permission)
                <tr wire:key="{{ $permission->id }}">
                    @scope('actions', $permission)
                    <div class="flex w-1/4">
                        <x-button icon="o-pencil"
                                  wire:click="editPermission({{ $permission->id }})"
                                  class="btn-ghost btn-sm text-primary"
                                  @click="$wire.modal = true" />

                        <x-button icon="o-trash"
                                  wire:click="delete({{ $permission->id }})"
                                  wire:confirm="Are you sure?"
                                  spinner
                                  class="btn-ghost btn-sm text-error" />
                    </div>
                    @endscope
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-modal wire:model="modal" :title="$editingId ? 'ویرایش ' : 'جدید'" persistent separator>
        <x-form wire:submit.prevent="{{ $editingId ? 'updatePermission' : 'createPermission' }}" class="grid gap-4">
            <x-input
                wire:model="name"
                label="نام سطح دسترسی"
                placeholder="نام انگلیسی سطح دسترسی"
                required
                icon="o-magnifying-glass"
            />
            <x-input
                wire:model="label"
                label="عنوان سطح دسترسی"
                placeholder="عنوان فارسی سطح دسترسی"
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