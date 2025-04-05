<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\Permission;
use App\Models\AccessLevel;
use App\Models\Role;
use Mary\Traits\Toast;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    use Toast, WithPagination;

    public $permission_id, $access_level_id;
    public int $perPage = 5;
    public $editingId = null;
    public bool $modal = false;
    public bool $canCreate = false;
    public bool $canEdit = false;
    public bool $canDelete = false;

    public function mount()
    {
        $user = auth()->user();
        $this->canCreate = $user->hasPermission('create-permission-access-level');
        $this->canEdit = $user->hasPermission('edit-permission-access-level');
        $this->canDelete = $user->hasPermission('delete-permission-access-level');


    }

    public function createPermissionAccessLevel()
    {
        if (!$this->canCreate) {
            $this->error('شما اجازه ایجاد اتصال مجوز به سطح دسترسی را ندارید.');
            return;
        }

        $this->validate([
            'permission_id' => 'required|exists:permissions,id',
            'access_level_id' => 'required|exists:access_levels,id',
        ]);

        DB::table('permission_access_level')->updateOrInsert(
            ['permission_id' => $this->permission_id, 'access_level_id' => $this->access_level_id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $this->resetForm();
        $this->success('اتصال مجوز به سطح دسترسی با موفقیت ایجاد شد.');
    }

    public function editPermissionAccessLevel($id)
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش اتصال مجوز به سطح دسترسی را ندارید.');
            return;
        }

        $permissionAccessLevel = DB::table('permission_access_level')->where('id', $id)->first();
        if (!$permissionAccessLevel) {
            $this->error('اتصال موردنظر یافت نشد.');
            return;
        }

        $this->editingId = $id;
        $this->permission_id = $permissionAccessLevel->permission_id;
        $this->access_level_id = $permissionAccessLevel->access_level_id;
        $this->modal = true;
    }

    public function updatePermissionAccessLevel()
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش اتصال مجوز به سطح دسترسی را ندارید.');
            return;
        }

        $this->validate([
            'permission_id' => 'required|exists:permissions,id',
            'access_level_id' => 'required|exists:access_levels,id',
        ]);

        DB::table('permission_access_level')
            ->where('id', $this->editingId)
            ->update([
                'permission_id' => $this->permission_id,
                'access_level_id' => $this->access_level_id,
                'updated_at' => now(),
            ]);

        $this->resetForm();
        $this->success('اتصال مجوز به سطح دسترسی با موفقیت به‌روزرسانی شد.');
        $this->modal = false;
    }

    public function deletePermissionAccessLevel($id)
    {
        if (!$this->canDelete) {
            $this->error('شما اجازه حذف اتصال مجوز به سطح دسترسی را ندارید.');
            return;
        }

        DB::table('permission_access_level')->where('id', $id)->delete();
        $this->warning('اتصال مجوز به سطح دسترسی با موفقیت حذف شد.');
    }

    public function resetForm()
    {
        $this->reset(['permission_id', 'access_level_id', 'editingId']);
    }

    public function openModalForCreate()
    {
        if (!$this->canCreate) {
            $this->error('شما اجازه ایجاد اتصال مجوز به سطح دسترسی را ندارید.');
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
            ['key' => 'access_level_name', 'label' => 'نام سطح دسترسی', 'class' => 'w-20'],
            ['key' => 'actions', 'label' => 'عملیات', 'class' => 'w-20'],
        ];
    }

    public function permissionAccessLevels()
    {
        return DB::table('permission_access_level')
            ->join('permissions', 'permission_access_level.permission_id', '=', 'permissions.id')
            ->join('access_levels', 'permission_access_level.access_level_id', '=', 'access_levels.id')
            ->select(
                'permission_access_level.id',
                'permissions.name as permission_name',
                'access_levels.name as access_level_name'
            )
            ->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'permissionAccessLevels' => $this->permissionAccessLevels(),
            'headers' => $this->headers(),
            'permissions' => Permission::all(),
            'access_levels' => AccessLevel::all(),
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت اتصال مجوزها به سطوح دسترسی" separator progress-indicator>
        <x-slot:actions>
            @if($this->canCreate)
                <x-button class="btn-success btn-sm" label="ثبت جدید" wire:click="openModalForCreate" responsive icon="o-plus" rounded />
            @endif
                <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-6">
        <x-table :headers="$headers" :rows="$permissionAccessLevels" with-pagination per-page="perPage" :per-page-values="[5, 10, 20]">
            @foreach($permissionAccessLevels as $permissionAccessLevel)
                <tr wire:key="{{ $permissionAccessLevel->id }}">
                    <td>{{ $permissionAccessLevel->id }}</td>
                    <td>{{ $permissionAccessLevel->permission_name }}</td>
                    <td>{{ $permissionAccessLevel->access_level_name }}</td>
                    <td>
                        @scope('actions', $permissionAccessLevel)
                            @if($this->canEdit)
                                <x-button icon="o-pencil" wire:click="editPermissionAccessLevel({{ $permissionAccessLevel->id }})" class="btn-ghost btn-sm text-primary" label="ویرایش" rounded />
                            @endif
                            @if($this->canDelete)
                                <x-button icon="o-trash" wire:click="deletePermissionAccessLevel({{ $permissionAccessLevel->id }})" wire:confirm="مطمئن هستید؟" class="btn-ghost btn-sm text-error" label="حذف" rounded />
                            @endif
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    @if($this->canCreate || $this->canEdit)
        <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش اتصال' : 'ثبت اتصال جدید' }}" separator>
            <x-form wire:submit.prevent="{{ $editingId ? 'updatePermissionAccessLevel' : 'createPermissionAccessLevel' }}" class="grid grid-cols-2 gap-4">
                <x-select wire:model="permission_id" label="مجوز" :options="$permissions" placeholder="یک مجوز انتخاب کنید" required rounded />
                <x-select wire:model="access_level_id" label="دسترسی" :options="$access_levels" placeholder="یک نقش انتخاب کنید" required rounded />
                <div class="col-span-2 flex justify-end space-x-2">
                    <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" rounded />
                    <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark" class="btn-outline" rounded />
                </div>
            </x-form>
        </x-modal>
    @endif
</div>
