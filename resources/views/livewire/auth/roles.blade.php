<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\Role;
use Mary\Traits\Toast;
use Livewire\WithPagination;

new class extends Component
{
    use Toast, WithPagination;

    public $name, $description;
    public int $perPage = 5;
    public $editingId = null;
    public bool $modal = false;
    public bool $canCreate = false;
    public bool $canEdit = false;
    public bool $canDelete = false;

    public function mount()
    {
        $user = auth()->user();
        $this->canCreate = $user->hasPermission('create-role');
        $this->canEdit = $user->hasPermission('edit-role');
        $this->canDelete = $user->hasPermission('delete-role');

        // برای دیباگ
        \Log::info('Mount Permissions for Roles:', [
            'canCreate' => $this->canCreate,
            'canEdit' => $this->canEdit,
            'canDelete' => $this->canDelete,
        ]);
    }

    public function createRole()
    {
        if (!$this->canCreate) {
            $this->error('شما اجازه ایجاد نقش را ندارید.');
            return;
        }

        $this->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $this->editingId,
            'description' => 'nullable|string|max:255',
        ]);

        Role::create([
            'name' => $this->name,
            'description' => $this->description,
        ]);

        $this->resetForm();
        $this->success('نقش با موفقیت ایجاد شد.');
    }

    public function editRole($id)
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش نقش را ندارید.');
            return;
        }

        $role = Role::findOrFail($id);
        $this->editingId = $id;
        $this->name = $role->name;
        $this->description = $role->description;
        $this->modal = true;
    }

    public function updateRole()
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش نقش را ندارید.');
            return;
        }

        $this->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $this->editingId,
            'description' => 'nullable|string|max:255',
        ]);

        $role = Role::findOrFail($this->editingId);
        $role->update([
            'name' => $this->name,
            'description' => $this->description,
        ]);

        $this->resetForm();
        $this->success('نقش با موفقیت به‌روزرسانی شد.');
        $this->modal = false;
    }

    public function deleteRole($id)
    {
        if (!$this->canDelete) {
            $this->error('شما اجازه حذف نقش را ندارید.');
            return;
        }

        $role = Role::findOrFail($id);
        $role->delete();
        $this->warning('نقش با موفقیت حذف شد.');
    }

    public function resetForm()
    {
        $this->reset(['name', 'description', 'editingId']);
    }

    public function openModalForCreate()
    {
        if (!$this->canCreate) {
            $this->error('شما اجازه ایجاد نقش را ندارید.');
            return;
        }

        $this->resetForm();
        $this->modal = true;
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
            ['key' => 'name', 'label' => 'نام نقش', 'class' => 'w-20'],
            ['key' => 'description', 'label' => 'توضیحات', 'class' => 'w-32'],
            ['key' => 'actions', 'label' => 'عملیات', 'class' => 'w-20'],
        ];
    }

    public function roles()
    {
        return Role::paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'roles' => $this->roles(),
            'headers' => $this->headers(),
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت نقش‌ها" separator progress-indicator>
        <x-slot:actions>
            @if($this->canCreate)
                <x-button class="btn-success btn-sm" label="ثبت جدید" wire:click="openModalForCreate" responsive icon="o-plus" rounded />
            @endif
                <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-6">
        <x-table :headers="$headers" :rows="$roles" with-pagination per-page="perPage" :per-page-values="[5, 10, 20]">
            @foreach($roles as $role)
                <tr wire:key="{{ $role->id }}">
                    <td>{{ $role->id }}</td>
                    <td>{{ $role->name }}</td>
                    <td>{{ $role->description ?? '-' }}</td>
                    <td>
                        @scope('actions', $role)
                            @if($this->canEdit)
                                <x-button icon="o-pencil" wire:click="editRole({{ $role->id }})" class="btn-ghost btn-sm text-primary" label="ویرایش" rounded />
                            @endif
                            @if($this->canDelete)
                                <x-button icon="o-trash" wire:click="deleteRole({{ $role->id }})" wire:confirm="مطمئن هستید؟" class="btn-ghost btn-sm text-error" label="حذف" rounded />
                            @endif
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    @if($this->canCreate || $this->canEdit)
        <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش نقش' : 'ثبت نقش جدید' }}" separator>
            <x-form wire:submit.prevent="{{ $editingId ? 'updateRole' : 'createRole' }}" class="grid grid-cols-2 gap-4">
                <x-input wire:model="name" label="نام نقش" placeholder="نام نقش" required rounded />
                <x-input wire:model="description" label="توضیحات" placeholder="توضیحات" rounded />
                <div class="col-span-2 flex justify-end space-x-2">
                    <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" rounded />
                    <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark" class="btn-outline" rounded />
                </div>
            </x-form>
        </x-modal>
    @endif
</div>
