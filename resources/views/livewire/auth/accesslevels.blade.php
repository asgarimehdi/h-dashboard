<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\AccessLevel;
use Mary\Traits\Toast;
use Livewire\WithPagination;

new class extends Component
{
    use Toast, WithPagination;

    public $name, $description;
    public int $perPage = 5;
    public $editingId = null;
    public bool $modal = false;

    // پرچم‌های دسترسی
    public bool $canCreate = false;
    public bool $canEdit = false;
    public bool $canDelete = false;

    public function mount()
    {
        $user = auth()->user();
        $this->canCreate = $user->hasPermission('create-access-level');
        $this->canEdit   = $user->hasPermission('edit-access-level');
        $this->canDelete = $user->hasPermission('delete-access-level');
    }

    public function openModalForCreate()
    {
        if (! $this->canCreate) {
            $this->error('شما اجازه ایجاد سطح دسترسی را ندارید.');
            return;
        }
        $this->resetForm();
        $this->modal = true;
    }

    public function createAccessLevel()
    {
        if (! $this->canCreate) {
            $this->error('شما اجازه ایجاد سطح دسترسی را ندارید.');
            return;
        }

        $this->validate([
            'name'        => 'required|string|max:255|unique:access_levels,name',
            'description' => 'nullable|string|max:255',
        ]);

        AccessLevel::create([
            'name'        => $this->name,
            'description' => $this->description,
        ]);

        $this->resetForm();
        $this->success('سطح دسترسی با موفقیت ایجاد شد.');
    }

    public function editAccessLevel($id)
    {
        if (! $this->canEdit) {
            $this->error('شما اجازه ویرایش سطح دسترسی را ندارید.');
            return;
        }

        $level = AccessLevel::findOrFail($id);
        $this->editingId  = $level->id;
        $this->name       = $level->name;
        $this->description = $level->description;
        $this->modal      = true;
    }

    public function updateAccessLevel()
    {
        if (! $this->canEdit) {
            $this->error('شما اجازه ویرایش سطح دسترسی را ندارید.');
            return;
        }

        $this->validate([
            'name'        => 'required|string|max:255|unique:access_levels,name,' . $this->editingId,
            'description' => 'nullable|string|max:255',
        ]);

        $level = AccessLevel::findOrFail($this->editingId);
        $level->update([
            'name'        => $this->name,
            'description' => $this->description,
        ]);

        $this->resetForm();
        $this->success('سطح دسترسی با موفقیت به‌روزرسانی شد.');
        $this->modal = false;
    }

    public function deleteAccessLevel($id)
    {
        if (! $this->canDelete) {
            $this->error('شما اجازه حذف سطح دسترسی را ندارید.');
            return;
        }

        $level = AccessLevel::findOrFail($id);
        $level->delete();
        $this->warning('سطح دسترسی با موفقیت حذف شد.');
    }

    public function resetForm()
    {
        $this->reset(['name', 'description', 'editingId']);
    }

    public function headers(): array
    {
        return [
            ['key' => 'id',          'label' => '#',           'class' => 'w-1'],
            ['key' => 'name',        'label' => 'نام سطح دسترسی', 'class' => 'w-32'],
            ['key' => 'description', 'label' => 'توضیحات',     'class' => 'w-48'],
            ['key' => 'actions',     'label' => 'عملیات',      'class' => 'w-24'],
        ];
    }

    public function accessLevels()
    {
        return AccessLevel::paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'accessLevels' => $this->accessLevels(),
            'headers'      => $this->headers(),
        ];
    }
};?>
<div>
    <x-header title="مدیریت سطوح دسترسی" separator progress-indicator>
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
            :rows="$accessLevels"
            with-pagination
            per-page="perPage"
            :per-page-values="[5, 10, 20]"
        >
            @foreach($accessLevels as $level)
                <tr wire:key="{{ $level->id }}">
                    <td>{{ $level->id }}</td>
                    <td>{{ $level->name }}</td>
                    <td>{{ $level->description ?? '-' }}</td>
                    <td>
                        @scope('actions', $level)
                            @if($this->canEdit)
                                <x-button
                                    icon="o-pencil"
                                    wire:click="editAccessLevel({{ $level->id }})"
                                    class="btn-ghost btn-sm text-primary"
                                    label="ویرایش"
                                    rounded
                                />
                            @endif
                            @if($this->canDelete)
                                <x-button
                                    icon="o-trash"
                                    wire:click="deleteAccessLevel({{ $level->id }})"
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
            title="{{ $editingId ? 'ویرایش سطح دسترسی' : 'ثبت سطح دسترسی جدید' }}"
            separator
        >
            <x-form
                wire:submit.prevent="{{ $editingId ? 'updateAccessLevel' : 'createAccessLevel' }}"
                class="grid grid-cols-2 gap-4"
            >
                <x-input
                    wire:model="name"
                    label="نام سطح دسترسی"
                    placeholder="نام سطح دسترسی"
                    required
                    rounded
                />
                <x-input
                    wire:model="description"
                    label="توضیحات"
                    placeholder="توضیحات (اختیاری)"
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
