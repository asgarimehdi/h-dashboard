<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\Permission;
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
        $this->canCreate = $user->hasPermission('create-permission');
        $this->canEdit = $user->hasPermission('edit-permission');
        $this->canDelete = $user->hasPermission('delete-permission');

        
    }

    public function createPermission()
    {
        if (!$this->canCreate) {
            $this->error('شما اجازه ایجاد مجوز را ندارید.');
            return;
        }

        $this->validate([
            'name' => 'required|string|max:255|unique:permissions,name,' . $this->editingId,
            'description' => 'nullable|string|max:255',
        ]);

        Permission::create([
            'name' => $this->name,
            'description' => $this->description,
        ]);

        $this->resetForm();
        $this->success('مجوز با موفقیت ایجاد شد.');
    }

    public function editPermission($id)
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش مجوز را ندارید.');
            return;
        }

        $permission = Permission::findOrFail($id);
        $this->editingId = $id;
        $this->name = $permission->name;
        $this->description = $permission->description;
        $this->modal = true;
    }

    public function updatePermission()
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش مجوز را ندارید.');
            return;
        }

        $this->validate([
            'name' => 'required|string|max:255|unique:permissions,name,' . $this->editingId,
            'description' => 'nullable|string|max:255',
        ]);

        $permission = Permission::findOrFail($this->editingId);
        $permission->update([
            'name' => $this->name,
            'description' => $this->description,
        ]);

        $this->resetForm();
        $this->success('مجوز با موفقیت به‌روزرسانی شد.');
        $this->modal = false;
    }

    public function deletePermission($id)
    {
        if (!$this->canDelete) {
            $this->error('شما اجازه حذف مجوز را ندارید.');
            return;
        }

        $permission = Permission::findOrFail($id);
        $permission->delete();
        $this->warning('مجوز با موفقیت حذف شد.');
    }

    public function resetForm()
    {
        $this->reset(['name', 'description', 'editingId']);
    }

    public function openModalForCreate()
    {
        if (!$this->canCreate) {
            $this->error('شما اجازه ایجاد مجوز را ندارید.');
            return;
        }

        $this->resetForm();
        $this->modal = true;
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
            ['key' => 'name', 'label' => 'نام مجوز', 'class' => 'w-20'],
            ['key' => 'description', 'label' => 'توضیحات', 'class' => 'w-32'],
            ['key' => 'actions', 'label' => 'عملیات', 'class' => 'w-20'],
        ];
    }

    public function permissions()
    {
        return Permission::paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'permissions' => $this->permissions(),
            'headers' => $this->headers(),
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت مجوزها" separator progress-indicator>
        <x-slot:actions>
            @if($this->canCreate)
                <x-button class="btn-success btn-sm" label="ثبت جدید" wire:click="openModalForCreate" responsive icon="o-plus" rounded />
            @endif
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-6">
        <x-table :headers="$headers" :rows="$permissions" with-pagination per-page="perPage" :per-page-values="[5, 10, 20]">
            @foreach($permissions as $permission)
                <tr wire:key="{{ $permission->id }}">
                    <td>{{ $permission->id }}</td>
                    <td>{{ $permission->name }}</td>
                    <td>{{ $permission->description ?? '-' }}</td>
                    <td>
                        @scope('actions', $permission)
                            @if($this->canEdit)
                                <x-button icon="o-pencil" wire:click="editPermission({{ $permission->id }})" class="btn-ghost btn-sm text-primary" label="ویرایش" rounded />
                            @endif
                            @if($this->canDelete)
                                <x-button icon="o-trash" wire:click="deletePermission({{ $permission->id }})" wire:confirm="مطمئن هستید؟" class="btn-ghost btn-sm text-error" label="حذف" rounded />
                            @endif
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    @if($this->canCreate || $this->canEdit)
        <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش مجوز' : 'ثبت مجوز جدید' }}" separator>
            <x-form wire:submit.prevent="{{ $editingId ? 'updatePermission' : 'createPermission' }}" class="grid grid-cols-2 gap-4">
                <x-input wire:model="name" label="نام مجوز" placeholder="نام مجوز" required rounded />
                <x-input wire:model="description" label="توضیحات" placeholder="توضیحات" rounded />
                <div class="col-span-2 flex justify-end space-x-2">
                    <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" rounded />
                    <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark" class="btn-outline" rounded />
                </div>
            </x-form>
        </x-modal>
    @endif
</div>