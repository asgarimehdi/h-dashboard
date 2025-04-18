<?php

namespace App\Livewire\Users;

use App\Models\User;
use App\Models\Person;
use App\Models\Unit;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;
use Illuminate\Validation\ValidationException;

new class extends Component {
    use WithPagination;
    use Toast;

    public array $expanded = [];
    public string $search = '';
    public string $filterStatus = 'active'; // فیلتر پیش‌فرض: فقط کاربران فعال

    public bool $modal = false;
    public array $sortBy = ['column' => 'n_code', 'direction' => 'asc'];

    public $n_code;
    public $password;
    public array $role_ids = [];
    public $person_search = '';
    public $editing_user_id = null;

    public function clear(): void
    {
        $this->reset(['search', 'filterStatus']);
        $this->filterStatus = 'active'; // برگشت به پیش‌فرض
        $this->success('فیلترها پاک شدند.', position: 'toast-bottom');
    }

    public function delete(User $user): void
    {
         // لود کردن تمام روابط مرتبط با کاربر
        // $user->load('roles'); // اینجا می‌تونی روابط دیگه رو هم اضافه کنی

        // چک کردن اینکه آیا کاربر توی روابط استفاده شده یا نه
        // $isInUse = $user->roles()->exists(); // برای رابطه roles
        // $isInUse = $user->roles()->exists() || $user->orders()->exists() || $user->comments()->exists();


        // if ($isInUse) {
        //     $this->error("نمی‌توانید $user->name را غیرفعال کنید چون در بخش‌های دیگر سیستم (مثل نقش‌ها) استفاده شده است.", position: 'toast-bottom');
        //     return;
        // }
        $user->delete(); // Soft delete
        $this->warning("$user->name غیرفعال شد", 'غیرفعال شد!', position: 'toast-bottom');
    }

    public function restore($userId): void
    {
        $user = User::withTrashed()->findOrFail($userId);
        $user->restore();
        $this->success("$user->name فعال شد", 'کاربر برگشت!', position: 'toast-bottom');
    }

    public function openModalForCreate(): void
    {
        $this->reset(['n_code', 'password', 'role_ids', 'person_search', 'editing_user_id']);
        $this->modal = true;
    }

    public function edit($userId): void
    {
        $user = User::withTrashed()->findOrFail($userId); // برای ویرایش کاربران غیرفعال
        $this->editing_user_id = $user->id;
        $this->n_code = $user->n_code;
        $person = Person::where('n_code', $user->n_code)->first();
        $this->person_search = "{$person->f_name} {$person->l_name} ({$person->n_code})";
        $this->password = null;
        $this->role_ids = $user->roles->pluck('id')->toArray();
        $this->modal = true;
    }

    public function selectPerson($n_code)
    {
        $this->n_code = $n_code;
        $person = Person::where('n_code', $n_code)->first();
        $this->person_search = "{$person->f_name} {$person->l_name} ({$person->n_code})";
    }

    public function createUser(): void
    {
        $this->validate([
            'n_code' => 'required|exists:persons,n_code|unique:users,n_code',
            'password' => 'required|string|min:6',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
        ], [
            'n_code.unique' => 'این کد ملی قبلاً ثبت شده است.',
            'n_code.required' => 'کد ملی الزامی است.',
            'n_code.exists' => 'این کد ملی در سیستم موجود نیست.',
            'password.required' => 'رمز عبور الزامی است.',
            'password.min' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
            'role_ids.required' => 'حداقل یک نقش باید انتخاب شود.',
        ]);
        try {
            $person = Person::where('n_code', $this->n_code)->first();

            $user = User::create([
                'n_code' => $this->n_code,
                'password' => bcrypt($this->password),
            ]);

            $user->roles()->sync($this->role_ids);

            $this->reset(['n_code', 'password', 'role_ids', 'person_search', 'editing_user_id']);
            $this->success('کاربر با موفقیت ایجاد شد.');
            $this->modal = false;
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error, position: 'toast-bottom');
            }
        }
    }

    public function updateUser(): void
    {
        $this->validate([
            'n_code' => 'required|exists:persons,n_code|unique:users,n_code,' . $this->editing_user_id,
            'password' => 'nullable|string|min:6',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
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
            $user->roles()->sync($this->role_ids);

            $this->reset(['n_code', 'password', 'role_ids', 'person_search', 'editing_user_id']);
            $this->success('کاربر با موفقیت ویرایش شد.');
            $this->modal = false;
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
            ->with(['person.unit', 'roles'])
            ->withAggregate('person', 'f_name')
            ->withAggregate('person', 'l_name')
            ->when($this->search, function (Builder $q) {
                $q->whereHas('person', function ($query) {
                    $query->whereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$this->search}%"]);
                });
            })
            ->whereNot('id', auth()->id()); // حذف کاربر لاگین‌شده از نتایج

        if ($this->filterStatus === 'active') {
            $query->whereNull('deleted_at');
        } elseif ($this->filterStatus === 'inactive') {
            $query->onlyTrashed();
        } else {
            $query->withTrashed();
        }

        return $query->orderBy('n_code', $this->sortBy['direction'])
            ->paginate(5);
    }

    public function getFilteredPersonsProperty()
    {
        return Person::query()
            ->when($this->person_search, function ($query) {
                $query->whereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$this->person_search}%"])
                    ->orWhere('n_code', 'like', "%{$this->person_search}%");
            })
            ->get()
            ->map(fn($person) => [
                'value' => $person->n_code,
                'label' => "{$person->f_name} {$person->l_name} ({$person->n_code})"
            ])->toArray();
    }

    public function with(): array
    {
        return [
            'users' => $this->users(),
            'headers' => $this->headers(),
            'persons' => $this->getFilteredPersonsProperty(),
            'roles' => Role::all()
        ];
    }
}; ?>

<div>
    <x-header title="کاربران" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <x-button class="btn-success" wire:click="openModalForCreate" responsive icon="o-plus"/>
            <div class="flex-1">
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
        <x-table :headers="$headers" :rows="$users" :sort-by="$sortBy" wire:model="expanded" expandable>

            @scope('cell_status', $user)
                <x-badge
                    :value="$user->trashed() ? 'غیرفعال' : 'فعال'"
                    :class="$user->trashed() ? 'badge-error' : 'badge-success'"
                    rounded
                />
            @endscope
            @scope('actions', $user)
                <div class="flex w-1/12">
                    <x-button icon="o-pencil"
                              wire:click="edit({{ $user->id }})"
                              class="btn-ghost btn-sm text-primary">
                        <span class="hidden 2xl:inline">ویرایش</span>
                    </x-button>
                    @if($user->trashed())
                        <x-button icon="o-arrow-path"
                                  wire:click="restore({{ $user->id }})"
                                  wire:confirm="آیا مطمئن هستید که می‌خواهید این کاربر را فعال کنید؟"
                                  spinner
                                  class="btn-ghost btn-sm text-success">
                            <span class="hidden 2xl:inline">فعال‌سازی</span>
                        </x-button>
                    @else
                        <x-button icon="o-trash"
                                  wire:click="delete({{ $user->id }})"
                                  wire:confirm="آیا مطمئن هستید که می‌خواهید این کاربر را غیرفعال کنید؟"
                                  spinner
                                  class="btn-ghost btn-sm text-error">
                            <span class="hidden 2xl:inline">غیرفعال</span>
                        </x-button>
                    @endif
                </div>
            @endscope
            @scope('expansion', $user)
                <div class="bg-base-200 p-8 font-bold">
                    اطلاعات بیشتر درباره کاربر، {{ $user->name }}!
                </div>
            @endscope
        </x-table>
    </x-card>

    <x-modal wire:model="modal" :title="$editing_user_id ? 'ویرایش کاربر' : 'ثبت کاربر جدید'" persistent separator>
        <x-form wire:submit.prevent="{{ $editing_user_id ? 'updateUser' : 'createUser' }}"
                class="grid grid-cols-2 gap-4">
            <div class="relative">
                <x-input wire:model.live="person_search" type="text" class="input input-bordered w-full" label="کد ملی"
                         placeholder="جستجوی نام یا کد ملی"/>
                @error('n_code') <span class="text-error text-sm">{{ $message }}</span> @enderror
                @if($person_search)
                    <div>
                        @forelse($persons as $person)
                            <div wire:click="selectPerson('{{ $person['value'] }}')"
                                 class="p-2 hover:bg-base-200 cursor-pointer">
                                {{ $person['label'] }}
                            </div>
                        @empty
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
                <x-choices wire:model="role_ids" label="نقش‌ها" :options="$roles" multiple
                           placeholder="نقش‌ها را انتخاب کنید" required rounded searchable/>
                @error('role_ids') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="col-span-2 flex justify-end space-x-2">
                <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary" rounded />
                <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark" class="btn-outline" rounded />
            </div>
        </x-form>
    </x-modal>
</div>
