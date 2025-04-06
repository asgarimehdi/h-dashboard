<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\Role;
use App\Models\AccessLevel;
use Mary\Traits\Toast;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator; // اضافه کردن این خط

new class extends Component
{
    use Toast, WithPagination;

    public $role_id;
    public array $access_level_ids = [];
    public int $perPage = 5;
    public $editingId = null;
    public bool $modal = false;

    public bool $canCreate = false;
    public bool $canEdit   = false;
    public bool $canDelete = false;

    public function mount()
    {
        $user = auth()->user();
        $this->canCreate = $user->hasPermission('create-role-access-level');
        $this->canEdit   = $user->hasPermission('edit-role-access-level');
        $this->canDelete = $user->hasPermission('delete-role-access-level');
    }

    public function openModalForCreate()
    {
        if (! $this->canCreate) {
            $this->error('شما اجازه ایجاد اتصال نقش به سطح دسترسی را ندارید.');
            return;
        }
        $this->resetForm();
        $this->modal = true;
    }

    public function createRoleAccessLevel()
    {
        if (! $this->canCreate) {
            $this->error('شما اجازه ایجاد اتصال نقش به سطح دسترسی را ندارید.');
            return;
        }

        $this->validate([
            'role_id'          => 'required|exists:roles,id',
            'access_level_ids' => 'required|array|min:1',
            'access_level_ids.*' => 'exists:access_levels,id',
        ]);

        // چک کردن تکراری بودن
        $existing = DB::table('role_access_level')
            ->where('role_id', $this->role_id)
            ->whereIn('access_level_id', $this->access_level_ids)
            ->pluck('access_level_id')
            ->toArray();

        if (!empty($existing)) {
            $duplicateNames = AccessLevel::whereIn('id', $existing)->pluck('name')->toArray();
            $this->error('سطوح دسترسی زیر تکراری هستند: ' . implode(', ', $duplicateNames));
            return;
        }

        // ثبت سطوح دسترسی جدید
        foreach ($this->access_level_ids as $accessLevelId) {
            DB::table('role_access_level')->insert([
                'role_id' => $this->role_id,
                'access_level_id' => $accessLevelId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->resetForm();
        $this->success('اتصال نقش به سطوح دسترسی با موفقیت ایجاد شد.');
        $this->modal = false;
    }

    public function editRoleAccessLevel($roleId)
    {
        if (! $this->canEdit) {
            $this->error('شما اجازه ویرایش اتصال نقش به سطح دسترسی را ندارید.');
            return;
        }

        $role = Role::find($roleId);
        if (! $role) {
            $this->error('نقش یافت نشد.');
            return;
        }

        $this->editingId = $roleId;
        $this->role_id = $role->id;
        $this->access_level_ids = DB::table('role_access_level')
            ->where('role_id', $roleId)
            ->pluck('access_level_id')
            ->toArray();
        $this->modal = true;
    }

    public function updateRoleAccessLevel()
    {
        if (! $this->canEdit) {
            $this->error('شما اجازه ویرایش اتصال نقش به سطح دسترسی را ندارید.');
            return;
        }

        $this->validate([
            'role_id'          => 'required|exists:roles,id',
            'access_level_ids' => 'required|array|min:1',
            'access_level_ids.*' => 'exists:access_levels,id',
        ]);

        // حذف همه سطوح دسترسی قبلی
        DB::table('role_access_level')->where('role_id', $this->role_id)->delete();

        // ثبت سطوح دسترسی جدید
        foreach ($this->access_level_ids as $accessLevelId) {
            DB::table('role_access_level')->insert([
                'role_id' => $this->role_id,
                'access_level_id' => $accessLevelId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->resetForm();
        $this->success('اتصال نقش به سطوح دسترسی با موفقیت به‌روزرسانی شد.');
        $this->modal = false;
    }

    public function deleteRoleAccessLevel($roleId)
    {
        if (! $this->canDelete) {
            $this->error('شما اجازه حذف اتصال نقش به سطح دسترسی را ندارید.');
            return;
        }

        DB::table('role_access_level')->where('role_id', $roleId)->delete();
        $this->warning('اتصال نقش به سطوح دسترسی با موفقیت حذف شد.');
    }

    public function resetForm()
    {
        $this->reset(['role_id', 'access_level_ids', 'editingId']);
    }

    public function headers(): array
    {
        return [
            ['key' => 'id',               'label' => '#',                       'class' => 'w-1'],
            ['key' => 'role_name',        'label' => 'نام نقش',               'class' => 'w-32'],
            ['key' => 'access_levels',    'label' => 'سطوح دسترسی',           'class' => 'w-48'],
            ['key' => 'actions',          'label' => 'عملیات',                'class' => 'w-24'],
        ];
    }

    public function roleAccessLevels()
    {
        $rawData = DB::table('role_access_level')
            ->join('roles', 'role_access_level.role_id', '=', 'roles.id')
            ->join('access_levels', 'role_access_level.access_level_id', '=', 'access_levels.id')
            ->select(
                'role_access_level.role_id',
                'roles.name as role_name',
                DB::raw('GROUP_CONCAT(access_levels.name SEPARATOR ", ") as access_levels')
            )
            ->groupBy('role_access_level.role_id', 'roles.name')
            ->get();

        // تبدیل به Paginator
        $items = $rawData->map(function ($item, $index) {
            return (object) [
                'id' => $index + 1, // شماره‌گذاری دستی
                'role_id' => $item->role_id,
                'role_name' => $item->role_name,
                'access_levels' => $item->access_levels,
            ];
        });

        $total = $items->count();
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $perPage = $this->perPage;

        $paginatedItems = $items->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $paginatedItems,
            $total,
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    public function with(): array
    {
        return [
            'rows'          => $this->roleAccessLevels(),
            'headers'       => $this->headers(),
            'roles'         => Role::all(),
            'access_levels' => AccessLevel::all(),
        ];
    }
}
; ?>

<div>
    <x-header title="مدیریت اتصال نقش‌ها به سطوح دسترسی" separator progress-indicator>
        <x-slot:actions>
            @if($this->canCreate)
                <x-button
                    class="btn-success btn-sm"
                    label="ثبت جدید"
                    wire:click="openModalForCreate"
                    responsive
                    icon="o-plus"
                    rounded
                />
            @endif
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-6">
        <x-table
            :headers="$headers"
            :rows="$rows"
            with-pagination
            per-page="perPage"
            :per-page-values="[5, 10, 20]"
        >
            @foreach($rows as $row)
                <tr wire:key="{{ $row->role_id }}">
                    <td>{{ $row->id }}</td>
                    <td>{{ $row->role_name }}</td>
                    <td>{{ $row->access_levels }}</td>
                    <td>
                        @scope('actions', $row)
                            @if($this->canEdit)
                                <x-button
                                    icon="o-pencil"
                                    wire:click="editRoleAccessLevel({{ $row->role_id }})"
                                    class="btn-ghost btn-sm text-primary"
                                    label="ویرایش"
                                    rounded
                                />
                            @endif
                            @if($this->canDelete)
                                <x-button
                                    icon="o-trash"
                                    wire:click="deleteRoleAccessLevel({{ $row->role_id }})"
                                    wire:confirm="مطمئن هستید؟"
                                    class="btn-ghost btn-sm text-error"
                                    label="حذف"
                                    rounded
                                />
                            @endif
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    @if($this->canCreate || $this->canEdit)
        <x-modal
            wire:model="modal"
            title="{{ $editingId ? 'ویرایش اتصال' : 'ثبت اتصال جدید' }}"
            separator
        >
            <x-form
                wire:submit.prevent="{{ $editingId ? 'updateRoleAccessLevel' : 'createRoleAccessLevel' }}"
                class="grid grid-cols-2 gap-4"
            >
                <x-select
                    wire:model="role_id"
                    label="نقش"
                    :options="$roles"
                    placeholder="یک نقش انتخاب کنید"
                    required
                    rounded
                />
                <x-choices
                    wire:model="access_level_ids"
                    label="سطوح دسترسی"
                    :options="$access_levels"
                    placeholder="چند سطح دسترسی انتخاب کنید"
                    multiple
                    searchable
                    required
                    rounded
                />
                <div class="col-span-2 flex justify-end space-x-2">
                    <x-button
                        type="submit"
                        label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}"
                        icon="o-check"
                        class="btn-primary"
                        rounded
                    />
                    <x-button
                        label="لغو"
                        @click="$wire.modal = false"
                        icon="o-x-mark"
                        class="btn-outline"
                        rounded
                    />
                </div>
            </x-form>
        </x-modal>
    @endif
</div>