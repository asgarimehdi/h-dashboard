<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\Permission;
use App\Models\AccessLevel;
use Mary\Traits\Toast;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    use Toast, WithPagination;

    public $permission_ids = [];
    public $access_level_id = null;
    public int $perPage = 5;
    public $editingId = null;
    public bool $modal = false;
    public bool $showPermissionsModal = false;
    public $selectedAccessLevelPermissions = [];
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
            'permission_ids' => 'required|array|min:1',
            'permission_ids.*' => 'exists:permissions,id',
            'access_level_id' => 'required|exists:access_levels,id',
        ]);

        foreach ($this->permission_ids as $permission_id) {
            DB::table('permission_access_level')->updateOrInsert(
                ['permission_id' => $permission_id, 'access_level_id' => $this->access_level_id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->resetForm();
        $this->success('اتصال مجوزها به سطح دسترسی با موفقیت ایجاد شد.');
        $this->modal = false;
    }

    public function editPermissionAccessLevel($accessLevelId)
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش اتصال مجوز به سطح دسترسی را ندارید.');
            return;
        }

        $this->permission_ids = DB::table('permission_access_level')
            ->where('access_level_id', $accessLevelId)
            ->pluck('permission_id')
            ->toArray();

        $this->editingId = $accessLevelId;
        $this->access_level_id = $accessLevelId;
        $this->modal = true;
    }

    public function updatePermissionAccessLevel()
    {
        if (!$this->canEdit) {
            $this->error('شما اجازه ویرایش اتصال مجوز به سطح دسترسی را ندارید.');
            return;
        }

        $this->validate([
            'permission_ids' => 'required|array|min:1',
            'permission_ids.*' => 'exists:permissions,id',
            'access_level_id' => 'required|exists:access_levels,id',
        ]);

        DB::table('permission_access_level')
            ->where('access_level_id', $this->access_level_id)
            ->delete();

        foreach ($this->permission_ids as $permission_id) {
            DB::table('permission_access_level')->insert([
                'permission_id' => $permission_id,
                'access_level_id' => $this->access_level_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->resetForm();
        $this->success('اتصال مجوزها به سطح دسترسی با موفقیت به‌روزرسانی شد.');
        $this->modal = false;
    }

    public function deletePermissionAccessLevel($accessLevelId)
    {
        if (!$this->canDelete) {
            $this->error('شما اجازه حذف اتصال مجوز به سطح دسترسی را ندارید.');
            return;
        }

        DB::table('permission_access_level')
            ->where('access_level_id', $accessLevelId)
            ->delete();

        $this->warning('همه اتصالات مجوز به سطح دسترسی با موفقیت حذف شدند.');
    }

    public function showPermissions($accessLevelId)
    {
        $this->selectedAccessLevelPermissions = DB::table('permission_access_level')
            ->join('permissions', 'permission_access_level.permission_id', '=', 'permissions.id')
            ->where('permission_access_level.access_level_id', $accessLevelId)
            ->pluck('permissions.description')
            ->toArray();

        $this->showPermissionsModal = true;
    }

    public function resetForm()
    {
        $this->reset(['permission_ids', 'access_level_id', 'editingId']);
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
            ['key' => 'description', 'label' => 'نام سطح دسترسی', 'class' => 'w-20'],
            ['key' => 'permissions', 'label' => 'نام مجوز', 'class' => 'w-20'],
            ['key' => 'actions', 'label' => 'عملیات', 'class' => 'w-20'],
        ];
    }

    public function accessLevels()
    {
        return AccessLevel::whereHas('permissions')
            ->withCount('permissions')
            ->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'accessLevels' => $this->accessLevels(),
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
        <x-table :headers="$headers" :rows="$accessLevels" with-pagination per-page="perPage" :per-page-values="[5, 10, 20]">
            @scope('cell_permissions', $accessLevel)
                <x-button 
                    label="نمایش مجوزها" 
                    wire:click="showPermissions({{ $accessLevel->id }})" 
                    class="btn-ghost btn-sm text-info" 
                    icon="o-eye" 
                    rounded
                />
            @endscope

            @scope('cell_actions', $accessLevel)
                @if($this->canEdit)
                    <x-button icon="o-pencil" wire:click="editPermissionAccessLevel({{ $accessLevel->id }})" class="btn-ghost btn-sm text-primary" label="ویرایش" rounded />
                @endif
                @if($this->canDelete)
                    <x-button icon="o-trash" wire:click="deletePermissionAccessLevel({{ $accessLevel->id }})" wire:confirm="مطمئن هستید که می‌خواهید همه مجوزهای این سطح دسترسی را حذف کنید؟" class="btn-ghost btn-sm text-error" label="حذف" rounded />
                @endif
            @endscope
        </x-table>
    </x-card>

    @if($this->canCreate || $this->canEdit)
        <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش اتصال' : 'ثبت اتصال جدید' }}" separator>
            <x-form wire:submit="createPermissionAccessLevel" class="grid grid-cols-2 gap-4">
                <x-choices 
                    wire:model.live="permission_ids" 
                    label="مجوزها" 
                    :options="$permissions" 
                    option-value="id"
                    option-label="description"
                    placeholder="چند مجوز انتخاب کنید" 
                    multiple 
                    required 
                    rounded 
                />
                <x-select 
                    wire:model.live="access_level_id" 
                    label="سطح دسترسی" 
                    :options="$access_levels" 
                    option-value="id"
                    option-label="description"
                    placeholder="یک سطح دسترسی انتخاب کنید" 
                    required 
                    rounded 
                />
                <div class="col-span-2 flex justify-end space-x-2">
                    <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" rounded />
                    <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark" class="btn-outline" rounded />
                </div>
            </x-form>
        </x-modal>
    @endif

    <x-modal wire:model="showPermissionsModal" title="مجوزهای متصل" separator>
        <div class="p-4">
            @if(!empty($selectedAccessLevelPermissions))
                <ul class="list-disc list-inside text-gray-700">
                    @foreach($selectedAccessLevelPermissions as $permission)
                        <li>{{ $permission }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-gray-500">هیچ مجوزی به این سطح دسترسی متصل نیست.</p>
            @endif
        </div>
        <x-slot:footer>
            <x-button label="بستن" @click="$wire.showPermissionsModal = false" class="btn-outline" rounded />
        </x-slot:footer>
    </x-modal>
</div>