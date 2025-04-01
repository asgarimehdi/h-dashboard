<?php

namespace App\Livewire\permissionsroles;

use Livewire\Volt\Component;
use App\Models\Permission;
use App\Models\Role;
use Mary\Traits\Toast;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    use Toast, WithPagination;

    public $permission_id, $role_id;
    public int $perPage = 5;
    public $editingId = null;
    public bool $modal = false;
    public bool $canCreate = false;
    public bool $canEdit = false;
    public bool $canDelete = false;

    public function mount()
    {
        $user = auth()->user();
        $this->canCreate = $user->hasPermission('create-permission-role');
        $this->canEdit = $user->hasPermission('edit-permission-role');
        $this->canDelete = $user->hasPermission('delete-permission-role');

      
    }

    public function createPermissionRole()
    {
        if (!$this->canCreate) {
            $this->error('شما اجازه ایجاد اتصال مجوز به نقش را ندارید.');
            return;
        }

        $this->validate([
            'permission_id' => 'required|exists:permissions,id',
            'role_id' => 'required|exists:roles,id',
        ]);

        DB::table('permission_role')->updateOrInsert(
            ['permission_id' => $this->permission_id, 'role_id' => $this->role_id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $this->resetForm();
        $this->success('اتصال مجوز به نقش با موفقیت ایجاد شد.');
    }

    public function editPermissionRole($id)
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش اتصال مجوز به نقش را ندارید.');
            return;
        }

        $permissionRole = DB::table('permission_role')->where('id', $id)->first();
        if (!$permissionRole) {
            $this->error('اتصال موردنظر یافت نشد.');
            return;
        }

        $this->editingId = $id;
        $this->permission_id = $permissionRole->permission_id;
        $this->role_id = $permissionRole->role_id;
        $this->modal = true;
    }

    public function updatePermissionRole()
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش اتصال مجوز به نقش را ندارید.');
            return;
        }

        $this->validate([
            'permission_id' => 'required|exists:permissions,id',
            'role_id' => 'required|exists:roles,id',
        ]);

        DB::table('permission_role')
            ->where('id', $this->editingId)
            ->update([
                'permission_id' => $this->permission_id,
                'role_id' => $this->role_id,
                'updated_at' => now(),
            ]);

        $this->resetForm();
        $this->success('اتصال مجوز به نقش با موفقیت به‌روزرسانی شد.');
        $this->modal = false;
    }

    public function deletePermissionRole($id)
    {
        if (!$this->canDelete) {
            $this->error('شما اجازه حذف اتصال مجوز به نقش را ندارید.');
            return;
        }

        DB::table('permission_role')->where('id', $id)->delete();
        $this->warning('اتصال مجوز به نقش با موفقیت حذف شد.');
    }

    public function resetForm()
    {
        $this->reset(['permission_id', 'role_id', 'editingId']);
    }

    public function openModalForCreate()
    {
        if (!$this->canCreate) {
            $this->error('شما اجازه ایجاد اتصال مجوز به نقش را ندارید.');
            return;
        }

        $this->resetForm();
        $this->modal = true;
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
            ['key' => 'permission_name', 'label' => 'نام مجوز', 'class' => 'w-20'],
            ['key' => 'role_name', 'label' => 'نام نقش', 'class' => 'w-20'],
            ['key' => 'actions', 'label' => 'عملیات', 'class' => 'w-20'],
        ];
    }

    public function permissionRoles()
    {
        return DB::table('permission_role')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->select(
                'permission_role.id',
                'permissions.name as permission_name',
                'roles.name as role_name'
            )
            ->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'permissionRoles' => $this->permissionRoles(),
            'headers' => $this->headers(),
            'permissions' => Permission::all(),
            'roles' => Role::all(),
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت اتصال مجوزها به نقش‌ها" separator progress-indicator>
        <x-slot:actions>
            @if($this->canCreate)
                <x-button class="btn-success btn-sm" label="ثبت جدید" wire:click="openModalForCreate" responsive icon="o-plus" rounded />
            @endif
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-6">
        <x-table :headers="$headers" :rows="$permissionRoles" with-pagination per-page="perPage" :per-page-values="[5, 10, 20]">
            @foreach($permissionRoles as $permissionRole)
                <tr wire:key="{{ $permissionRole->id }}">
                    <td>{{ $permissionRole->id }}</td>
                    <td>{{ $permissionRole->permission_name }}</td>
                    <td>{{ $permissionRole->role_name }}</td>
                    <td>
                        @scope('actions', $permissionRole)
                            @if($this->canEdit)
                                <x-button icon="o-pencil" wire:click="editPermissionRole({{ $permissionRole->id }})" class="btn-ghost btn-sm text-primary" label="ویرایش" rounded />
                            @endif
                            @if($this->canDelete)
                                <x-button icon="o-trash" wire:click="deletePermissionRole({{ $permissionRole->id }})" wire:confirm="مطمئن هستید؟" class="btn-ghost btn-sm text-error" label="حذف" rounded />
                            @endif
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    @if($this->canCreate || $this->canEdit)
        <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش اتصال' : 'ثبت اتصال جدید' }}" separator>
            <x-form wire:submit.prevent="{{ $editingId ? 'updatePermissionRole' : 'createPermissionRole' }}" class="grid grid-cols-2 gap-4">
                <x-select wire:model="permission_id" label="مجوز" :options="$permissions" placeholder="یک مجوز انتخاب کنید" required rounded />
                <x-select wire:model="role_id" label="نقش" :options="$roles" placeholder="یک نقش انتخاب کنید" required rounded />
                <div class="col-span-2 flex justify-end space-x-2">
                    <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" rounded />
                    <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark" class="btn-outline" rounded />
                </div>
            </x-form>
        </x-modal>
    @endif
</div>