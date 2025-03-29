<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\Role;
use Mary\Traits\Toast;

new class extends Component
{
    use Toast;

    public $name, $description;
    public $roles;
    public $editingId = null;
    public bool $modal = false;

    public function mount()
    {
        $this->roles = Role::all();
    }

    public function createRole()
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $this->editingId,
            'description' => 'nullable|string|max:255',
        ]);

        Role::create([
            'name' => $this->name,
            'description' => $this->description,
        ]);

        $this->resetForm();
        $this->roles = Role::all();
        $this->success('نقش با موفقیت ایجاد شد.');
    }

    public function editRole($id)
    {
        $role = Role::findOrFail($id);
        $this->editingId = $id;
        $this->name = $role->name;
        $this->description = $role->description;
        $this->modal = true;
    }

    public function updateRole()
    {
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
        $this->roles = Role::all();
        $this->success('نقش با موفقیت به‌روزرسانی شد.');
        $this->modal = false;
    }

    public function deleteRole($id)
    {
        $role = Role::findOrFail($id);
        $role->delete();
        $this->roles = Role::all();
        $this->warning('نقش با موفقیت حذف شد.');
    }

    public function resetForm()
    {
        $this->reset(['name', 'description', 'editingId']);
    }

    public function openModalForCreate()
    {
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

    public function with(): array
    {
        return [
            'roles' => $this->roles,
            'headers' => $this->headers(),
        ];
    }
}; ?>
<div>
    <x-header title="مدیریت نقش‌ها" separator progress-indicator>
        <x-slot:actions>
            <x-button class="btn-success btn-sm" label="ثبت جدید" wire:click="openModalForCreate" responsive icon="o-plus" rounded />
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-6">
        <x-table :headers="$headers" :rows="$roles">
            @foreach($roles as $role)
                <tr wire:key="{{ $role->id }}">
                    <td>{{ $role->id }}</td>
                    <td>{{ $role->name }}</td>
                    <td>{{ $role->description ?? '-' }}</td>
                    <td>
                        @scope('actions', $role)
                            <x-button icon="o-pencil" wire:click="editRole({{ $role->id }})" class="btn-ghost btn-sm text-primary" label="ویرایش" rounded />
                            <x-button icon="o-trash" wire:click="deleteRole({{ $role->id }})" wire:confirm="مطمئن هستید؟" class="btn-ghost btn-sm text-error" label="حذف" rounded />
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

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
</div>