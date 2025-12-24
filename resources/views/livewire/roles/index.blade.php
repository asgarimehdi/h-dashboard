<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

new class extends Component {
    use WithPagination;
    use Toast;

    // تعریف صریح پروپرتی‌ها با مقدار اولیه
    public string $name = '';
    public string $label = '';
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 5;
    public bool $modal = false;
    public array $allPermissions = [];
    public array $permissions = [];
    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];
    
    // تعریف headers به عنوان پروپرتی برای جلوگیری از خطای Method Not Found
    public array $headers = [];

public function mount(): void
{
    // اضافه کردن label به فیلدهای انتخابی
    $this->allPermissions = Permission::all(['id', 'name', 'label'])->toArray();
    
    $this->headers = [
        ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
        ['key' => 'label', 'label' => 'عنوان', 'class' => 'flex-1'],
        ['key' => 'name', 'label' => 'نام', 'class' => 'flex-1'],
    ];
}

    public function clear(): void
    {
        $this->resetValidation();
        // فقط فیلدهای مربوط به فرم را ریست کنید
        $this->reset(['name', 'label', 'permissions', 'editingId', 'modal']);
       // $this->info('فیلدها خالی شدند', position: 'toast-bottom');
    }

    public function delete(Role $role): void
    {
        try {
            $role->delete();
            $this->warning("$role->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد.", position: 'toast-bottom');
        }
    }

    public function createRole(): void
    {
        $this->validate([
            'name' => 'required|string|alpha_num:ascii|max:255|unique:roles,name',
            'label' => 'required|string|max:255|unique:roles,label',
            'permissions' => 'required'
        ]);

        $newRole = Role::create(['name' => $this->name, 'label' => $this->label]);
        $newRole->syncPermissions($this->permissions);
        
        $this->success("$this->label ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->clear();
    }

    public function editRole($id): void
    {
        $role = Role::with('permissions')->findOrFail($id);
        $this->editingId = $id;
        $this->name = $role->name;
        $this->label = $role->label;
        
        // تبدیل IDها به رشته برای سازگاری با x-choices
        $this->permissions = $role->permissions->pluck('name')->map(fn($name) => (string) $name)->toArray();
        
        $this->modal = true;
    }

    public function updateRole(): void
    {
        $this->validate([
            'name' => 'required|string|alpha_num:ascii|max:255|unique:roles,name,' . $this->editingId,
            'label' => 'required|string|max:255|unique:roles,label,' . $this->editingId,
            'permissions' => 'required',
        ]);

        $role = Role::findOrFail($this->editingId);
        $role->update(['name' => $this->name, 'label' => $this->label]);
        $role->syncPermissions($this->permissions);
        
        $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
        $this->clear();
    }

    public function roles(): LengthAwarePaginator
    {
        $query = Role::query();
        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%')
                  ->orWhere('label', 'LIKE', '%' . $this->search . '%');
        }
        $query->orderBy(...array_values($this->sortBy));
        
        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'roles' => $this->roles(),
            // نیازی به پاس دادن headers و editingId نیست چون public هستند
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

        <x-table :headers="$headers" :rows="$roles" :sort-by="$sortBy" with-pagination>
            {{-- بخش Actions به صورت خودکار برای هر ردیف رندر می‌شود --}}
            @scope('actions', $role)
            <div class="flex gap-2">
                <x-button icon="o-pencil"
                        wire:click="editRole({{ $role->id }})"
                        class="btn-ghost btn-sm text-primary" />

                <x-button icon="o-trash"
                        wire:click="delete({{ $role->id }})"
                        wire:confirm="آیا مطمئن هستید؟"
                        spinner
                        class="btn-ghost btn-sm text-error" />
            </div>
            @endscope
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
{{--            <x-choices label="دسترسی" wire:model="permissions" :options="$allPermissions"  clearable />--}}
<x-choices-offline
    label="دسترسی ها"
    wire:model="permissions"
    :options="$allPermissions"
    option-label="label"    {{-- نمایش عنوان فارسی به کاربر --}}
    option-value="name"     {{-- ارسال نام انگلیسی به سمت سرور برای syncPermissions --}}
    placeholder="جستجو..."
    clearable
    searchable
/>

            <div class="flex gap-4">
                <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary pl-6" spinner/>
                <x-button label="بستن" icon="o-x-mark" wire:click="clear" class="btn-default pl-6" spinner/>
            </div>
        </x-form>
    </x-modal>
</div>
