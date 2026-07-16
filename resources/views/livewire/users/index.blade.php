<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Component
{
    use Toast;
    use WithPagination;

    public array $expanded = [];

    public string $search = '';

    public string $filterStatus = 'active';

    public bool $formOpen = false;

    public bool $unitModal = false;

    public int $perPage = 5;

    public array $sortBy = ['column' => 'n_code', 'direction' => 'asc'];

    public $n_code;

    public $password;

    public $person_search = '';

    public $editing_user_id = null;

    public array $allRoles = [];

    public array $role_ids = [];

    public array $allPermissions = [];

    public array $user_permissions = [];

    public array $allUnits = [];

    public array $allUnitsTree = [];

    public array $unit_ids = [];

    public function mount(): void
    {
        $this->authorize('manage_users');

        $this->allRoles = Role::all(['id', 'name', 'label'])->toArray();
        $this->allPermissions = Permission::all(['id', 'name', 'label'])->toArray();
        $units = Unit::with('unitType')->where('is_active', true)->orderBy('name')->get();
        $this->allUnits = $units->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
        $this->allUnitsTree = $units->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'parent_id' => $u->parent_id,
            'unit_type_name' => $u->unitType?->name,
        ])->all();
    }

    public function clear(): void
    {
        $this->reset(['search', 'filterStatus']);
        $this->filterStatus = 'active';
        $this->success('فیلترها پاک شدند.', position: 'toast-bottom');
    }

    public function delete(User $user): void
    {
        $user->delete();
        $this->warning("$user->name غیرفعال شد", 'غیرفعال شد!', position: 'toast-bottom');
    }

    public function restore($userId): void
    {
        $user = User::withTrashed()->findOrFail($userId);
        $user->restore();
        $this->success("$user->name فعال شد", 'کاربر برگشت!', position: 'toast-bottom');
    }

    public function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['n_code', 'password', 'person_search', 'editing_user_id', 'role_ids', 'user_permissions', 'unit_ids', 'formOpen', 'unitModal']);
    }

    public function openFormForCreate(): void
    {
        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit($userId): void
    {
        $this->resetValidation();
        $user = User::withTrashed()->findOrFail($userId);
        $this->editing_user_id = $user->id;
        $this->n_code = $user->n_code;
        $person = Person::where('n_code', $user->n_code)->first();
        $this->person_search = $person ? "{$person->f_name} {$person->l_name} ({$person->n_code})" : '';
        $this->password = null;
        $this->role_ids = $user->roles->pluck('id')->toArray();
        $this->user_permissions = $user->getDirectPermissions()->pluck('name')->map(fn ($n) => (string) $n)->toArray();
        $this->unit_ids = $user->units()->pluck('units.id')->map(fn ($id) => (int) $id)->toArray();
        $this->formOpen = true;
        $this->unitModal = false;
    }

    public function selectPerson($n_code): void
    {
        $this->n_code = $n_code;
        $person = Person::where('n_code', $n_code)->first();
        if ($person) {
            $this->person_search = "{$person->f_name} {$person->l_name} ({$person->n_code})";
        }
    }

    public function createUser(): void
    {
        $this->validate([
            'n_code' => 'required|exists:persons,n_code|unique:users,n_code',
            'password' => 'required|string|min:6',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
            'user_permissions' => 'nullable|array',
            'user_permissions.*' => 'exists:permissions,name',
        ], [
            'n_code.unique' => 'این کد ملی قبلاً ثبت شده است.',
            'n_code.required' => 'کد ملی الزامی است.',
            'n_code.exists' => 'این کد ملی در سیستم موجود نیست.',
            'password.required' => 'رمز عبور الزامی است.',
            'password.min' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
        ]);

        try {
            User::create([
                'n_code' => $this->n_code,
                'password' => bcrypt($this->password),
            ]);

            $user = User::where('n_code', $this->n_code)->first();
            $user->roles()->sync($this->role_ids ?? []);
            $user->syncPermissions($this->user_permissions ?? []);
            $user->units()->sync($this->unit_ids ?? []);

            $this->resetForm();
            $this->success('کاربر با موفقیت ایجاد شد.');
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error, position: 'toast-bottom');
            }
        }
    }

    public function updateUser(): void
    {
        $this->validate([
            'n_code' => 'required|exists:persons,n_code|unique:users,n_code,'.$this->editing_user_id,
            'password' => 'nullable|string|min:6',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
            'user_permissions' => 'nullable|array',
            'user_permissions.*' => 'exists:permissions,name',
        ], [
            'n_code.unique' => 'این کد ملی قبلاً ثبت شده است.',
            'n_code.required' => 'کد ملی الزامی است.',
            'n_code.exists' => 'این کد ملی در سیستم موجود نیست.',
            'password.min' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
            'role_ids.required' => 'حداقل یک نقش باید انتخاب شود.',
        ]);

        try {
            $user = User::withTrashed()->findOrFail($this->editing_user_id);
            $data = ['n_code' => $this->n_code];

            if ($this->password) {
                $data['password'] = bcrypt($this->password);
            }

            $user->update($data);
            $user->roles()->sync($this->role_ids ?? []);
            $user->syncPermissions($this->user_permissions ?? []);
            $user->units()->sync($this->unit_ids ?? []);
            app(AccessService::class)->clearCache($user);

            $this->resetForm();
            $this->success('کاربر با موفقیت ویرایش شد.');
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error, position: 'toast-bottom');
            }
        }
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden xl:table-cell'],
            ['key' => 'name', 'label' => 'نام', 'class' => 'w-40', 'sortable' => false],
            ['key' => 'n_code', 'label' => 'کد ملی', 'class' => 'w-30 hidden sm:table-cell'],
            ['key' => 'unit_name', 'label' => 'واحد اصلی', 'class' => 'w-40 hidden sm:table-cell'],
            ['key' => 'roles_name', 'label' => 'نقش‌ها', 'class' => 'w-70 hidden sm:table-cell'],
            ['key' => 'status', 'label' => 'وضعیت', 'class' => 'w-20'],
        ];
    }

    public function users(): LengthAwarePaginator
    {
        $query = User::query()
            ->with('roles')
            ->withAggregate('person', 'f_name')
            ->withAggregate('person', 'l_name')
            ->when($this->search, function (Builder $q) {
                $q->whereHas('person', function ($query) {
                    $query->whereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$this->search}%"]);
                });
            })
            ->whereNot('id', auth()->id());

        if ($this->filterStatus === 'active') {
            $query->whereNull('deleted_at');
        } elseif ($this->filterStatus === 'inactive') {
            $query->onlyTrashed();
        } else {
            $query->withTrashed();
        }

        return $query->orderBy('n_code', $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    public function getFilteredPersonsProperty()
    {
        return Person::query()
            ->when($this->person_search, function ($query) {
                $query->whereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$this->person_search}%"])
                    ->orWhere('n_code', 'like', "%{$this->person_search}%");
            })
            ->get()
            ->map(fn ($person) => [
                'value' => $person->n_code,
                'label' => "{$person->f_name} {$person->l_name} ({$person->n_code})",
            ])->toArray();
    }

    public function with(): array
    {
        $selectedUnitNames = collect($this->allUnits)
            ->whereIn('id', $this->unit_ids ?? [])
            ->pluck('name')
            ->values()
            ->all();

        return [
            'users' => $this->users(),
            'headers' => $this->headers(),
            'persons' => $this->getFilteredPersonsProperty(),
            'selectedUnitNames' => $selectedUnitNames,
        ];
    }
}; ?>

<div>
    <x-header title="کاربران" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4 flex-wrap">
            <x-button class="btn-success" wire:click="openFormForCreate" responsive icon="o-plus"/>
            <div class="flex-1 min-w-[12rem]">
                <x-input
                    placeholder="جستجو..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
            <div>
                <select wire:model.live="filterStatus" class="select select-bordered w-40">
                    <option value="all">همه</option>
                    <option value="active">فعال</option>
                    <option value="inactive">غیرفعال</option>
                </select>
            </div>
        </div>

        @if($formOpen)
            <div class="mb-6 p-4 bg-base-200 rounded-xl border border-base-300">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-sm">
                        {{ $editing_user_id ? 'ویرایش کاربر' : 'ثبت کاربر جدید' }}
                    </h3>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm" wire:click="resetForm" />
                </div>

                <x-form wire:submit.prevent="{{ $editing_user_id ? 'updateUser' : 'createUser' }}"
                        class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="relative">
                        <x-input wire:model.live="person_search" type="text" class="input input-bordered w-full" label="کد ملی"
                                 placeholder="جستجوی نام یا کد ملی"/>
                        @error('n_code') <span class="text-error text-sm">{{ $message }}</span> @enderror
                        @if($person_search)
                            <div class="max-h-40 overflow-auto border border-base-300 rounded-lg mt-1 bg-base-100">
                                @forelse($persons as $person)
                                    <div wire:click="selectPerson('{{ $person['value'] }}')"
                                         class="p-2 hover:bg-base-200 cursor-pointer text-sm">
                                        {{ $person['label'] }}
                                    </div>
                                @empty
                                    <div class="p-2 text-sm text-base-content/50">موردی یافت نشد</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                    <div>
                        <x-input wire:model="password" label="رمز عبور" type="password"
                                 :placeholder="$editing_user_id ? 'در صورت نیاز وارد کنید' : 'رمز عبور'"
                                 :required="!$editing_user_id" rounded/>
                        @error('password') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-choices-offline
                            label="نقش‌ها"
                            wire:model="role_ids"
                            :options="$allRoles"
                            option-label="label"
                            option-value="id"
                            placeholder="انتخاب نقش..."
                            clearable
                            searchable
                        />
                        @error('role_ids') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-choices-offline
                            label="دسترسی‌های مستقیم"
                            wire:model="user_permissions"
                            :options="$allPermissions"
                            option-label="label"
                            option-value="name"
                            placeholder="جستجو در دسترسی‌ها..."
                            clearable
                            searchable
                        />
                        @error('user_permissions') <span class="text-error text-sm">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="text-sm font-medium block mb-1">واحدها</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex-1 min-w-[12rem] input input-bordered flex flex-wrap items-center gap-1 min-h-[2.75rem] py-1">
                                @forelse($selectedUnitNames as $unitName)
                                    <span class="badge badge-primary">{{ $unitName }}</span>
                                @empty
                                    <span class="text-base-content/40 text-sm">واحدی انتخاب نشده</span>
                                @endforelse
                            </div>
                            <x-button
                                type="button"
                                label="انتخاب واحد"
                                icon="o-building-office-2"
                                class="btn-outline btn-sm"
                                wire:click="$set('unitModal', true)"
                            />
                        </div>
                    </div>

                    <div class="sm:col-span-2 flex justify-end gap-2">
                        <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                        <x-button type="button" label="لغو" wire:click="resetForm" icon="o-x-mark" class="btn-ghost" />
                    </div>
                </x-form>
            </div>
        @endif

        <x-table :headers="$headers" :rows="$users" :sort-by="$sortBy" wire:model="expanded" expandable with-pagination per-page="perPage" :per-page-values="[5, 10, 20, 50]">

            @scope('cell_status', $user)
                <x-badge
                    :value="$user->trashed() ? 'غیرفعال' : 'فعال'"
                    :class="$user->trashed() ? 'badge-error' : 'badge-success'"
                    rounded
                />
            @endscope

            @scope('cell_roles_name', $user)
                @forelse($user->roles as $role)
                    <x-badge :value="$role->label ?? $role->name" class="badge-primary" rounded />
                @empty
                    <span class="text-muted text-sm">—</span>
                @endforelse
            @endscope

            @scope('actions', $user)
                <div class="flex w-1/12">
                    <x-button icon="o-pencil"
                              wire:click="edit({{ $user->id }})"
                              class="btn-ghost btn-sm text-primary" />
                    @if($user->trashed())
                        <x-button icon="o-arrow-path"
                                  wire:click="restore({{ $user->id }})"
                                  wire:confirm="آیا مطمئن هستید که می‌خواهید این کاربر را فعال کنید؟"
                                  spinner
                                  class="btn-ghost btn-sm text-success" />
                    @else
                        <x-button icon="o-trash"
                                  wire:click="delete({{ $user->id }})"
                                  wire:confirm="آیا مطمئن هستید که می‌خواهید این کاربر را غیرفعال کنید؟"
                                  spinner
                                  class="btn-ghost btn-sm text-error" />
                    @endif
                </div>
            @endscope

            @scope('expansion', $user)
                <div class="bg-base-200 p-6">
                    <div class="mb-3">
                        <span class="font-bold">دسترسی‌ها برای</span>
                        <span class="font-medium">{{ $user->name }}</span>
                    </div>
                    @php
                        $permissions = $user->getAllPermissions();
                    @endphp
                    @if($permissions->isEmpty())
                        <div class="text-sm text-muted">هیچ دسترسی ثبت نشده است.</div>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach($permissions as $perm)
                                <x-badge :value="$perm->label ?? $perm->name" class="badge-info" rounded/>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>

    <x-modal wire:model="unitModal" title="انتخاب واحدها" persistent separator>
        @include('livewire.partials.unit-tree-picker', [
            'units' => $allUnitsTree,
            'model' => 'unit_ids',
            'multiple' => true,
            'alwaysOpen' => true,
            'label' => 'واحدهای سازمانی',
        ])
        <x-slot:actions>
            <x-button label="تأیید" icon="o-check" class="btn-primary" wire:click="$set('unitModal', false)" />
            <x-button label="بستن" icon="o-x-mark" class="btn-ghost" wire:click="$set('unitModal', false)" />
        </x-slot:actions>
    </x-modal>
</div>