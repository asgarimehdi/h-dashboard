<?php

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    public $n_code;

    public $f_name;

    public $l_name;

    public $t_id;

    public $e_id;

    public $s_id;

    public $r_id;

    public $u_id;

    public ?int $editingId = null;

    public string $search = '';

    public int $perPage = 5;

    public bool $formOpen = false;

    public bool $unitModal = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    public function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['n_code', 'f_name', 'l_name', 't_id', 'e_id', 's_id', 'r_id', 'u_id', 'editingId', 'formOpen', 'unitModal']);
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->formOpen = true;
    }

    public function delete(Person $person): void
    {
        try {
            $person->delete();
            $this->warning("$person->f_name $person->l_name حذف شد", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.', position: 'toast-bottom');
        }
    }

    public function savePerson(): void
    {
        $this->validate([
            'n_code' => 'required|string|size:10|unique:persons,n_code,'.$this->editingId,
            'f_name' => 'required|string|max:255',
            'l_name' => 'required|string|max:255',
            't_id' => 'required|exists:tahsils,id',
            'e_id' => 'required|exists:estekhdams,id',
            's_id' => 'required|exists:semats,id',
            'r_id' => 'required|exists:radifs,id',
            'u_id' => 'required|exists:units,id',
        ]);

        if ($this->editingId) {
            $person = Person::findOrFail($this->editingId);
            $person->update([
                'n_code' => $this->n_code,
                'f_name' => $this->f_name,
                'l_name' => $this->l_name,
                't_id' => $this->t_id,
                'e_id' => $this->e_id,
                's_id' => $this->s_id,
                'r_id' => $this->r_id,
                'u_id' => $this->u_id,
            ]);

            if ($user = $person->user) {
                app(AccessService::class)->clearCache($user);
                $user->units()->syncWithoutDetaching([$this->u_id => ['role' => 'staff', 'is_primary' => true]]);
            }

            $this->success('شخص به‌روزرسانی شد');
        } else {
            $person = Person::create([
                'n_code' => $this->n_code,
                'f_name' => $this->f_name,
                'l_name' => $this->l_name,
                't_id' => $this->t_id,
                'e_id' => $this->e_id,
                's_id' => $this->s_id,
                'r_id' => $this->r_id,
                'u_id' => $this->u_id,
            ]);

            if ($user = $person->user) {
                app(AccessService::class)->clearCache($user);
                $user->units()->syncWithoutDetaching([$this->u_id => ['role' => 'staff', 'is_primary' => true]]);
            }

            $this->success('شخص جدید ثبت شد');
        }

        $this->resetForm();
    }

    public function editPerson($id): void
    {
        $this->resetValidation();
        $person = Person::findOrFail($id);
        $this->editingId = (int) $id;
        $this->n_code = $person->n_code;
        $this->f_name = $person->f_name;
        $this->l_name = $person->l_name;
        $this->t_id = $person->t_id;
        $this->e_id = $person->e_id;
        $this->s_id = $person->s_id;
        $this->r_id = $person->r_id;
        $this->u_id = $person->u_id;
        $this->formOpen = true;
        $this->unitModal = false;
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden 2xl:table-cell'],
            ['key' => 'n_code', 'label' => 'کد ملی', 'class' => 'w-10 hidden sm:table-cell'],
            ['key' => 'f_name', 'label' => 'نام', 'class' => 'w-10'],
            ['key' => 'l_name', 'label' => 'نام خانوادگی', 'class' => 'w-10'],
            ['key' => 'tahsil_name', 'label' => 'تحصیلات', 'class' => 'w-10 hidden xl:table-cell'],
            ['key' => 'estekhdam_name', 'label' => 'استخدام', 'class' => 'w-10 hidden xl:table-cell'],
            ['key' => 'semat_name', 'label' => 'سمت', 'class' => 'w-10 hidden xl:table-cell'],
            ['key' => 'radif_name', 'label' => 'ردیف سازمانی', 'class' => 'w-10 hidden xl:table-cell'],
            ['key' => 'unit_name', 'label' => 'واحد', 'class' => 'w-10 hidden xl:table-cell'],
        ];
    }

    public function persons(): LengthAwarePaginator
    {
        $query = Person::query()
            ->accessible('u_id')
            ->withAggregate('tahsil', 'name')
            ->withAggregate('estekhdam', 'name')
            ->withAggregate('semat', 'name')
            ->withAggregate('radif', 'name')
            ->withAggregate('unit', 'name');

        if (! empty($this->search)) {
            $query->where(function ($q) {
                $q->where('n_code', 'LIKE', '%'.$this->search.'%')
                    ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$this->search}%"]);
            });
        }

        $query->orderBy(...array_values($this->sortBy));

        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();
        $units = Unit::with('unitType')
            ->whereIn('id', $accessibleUnitIds)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'parent_id' => $u->parent_id,
                'unit_type_name' => $u->unitType?->name,
            ])
            ->all();

        $selectedUnitName = null;
        if ($this->u_id) {
            $selectedUnitName = collect($units)->firstWhere('id', (int) $this->u_id)['name'] ?? Unit::find($this->u_id)?->name;
        }

        return [
            'persons' => $this->persons(),
            'headers' => $this->headers(),
            'tahsils' => Tahsil::all(),
            'estekhdams' => Estekhdam::all(),
            'semats' => Semat::all(),
            'radifs' => Radif::all(),
            'units' => $units,
            'selectedUnitName' => $selectedUnitName,
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت پرسنل" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <x-button class="btn-success" wire:click="startCreate" icon="o-plus"/>
            <div class="flex-1">
                <x-input
                    placeholder="جستجو..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
        </div>

        @if($formOpen)
            <div class="mb-6 p-4 bg-base-200 rounded-xl border border-base-300">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-sm">
                        {{ $editingId ? 'ویرایش پرسنل' : 'ثبت پرسنل جدید' }}
                    </h3>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm" wire:click="resetForm" />
                </div>

                <x-form wire:submit.prevent="savePerson" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input wire:model="n_code" label="کد ملی" placeholder="کد ملی" required/>
                    <x-input wire:model="f_name" label="نام" placeholder="نام" required/>
                    <x-input wire:model="l_name" label="نام خانوادگی" placeholder="نام خانوادگی" required/>
                    <x-select wire:model="t_id" label="تحصیلات" :options="$tahsils" required placeholder="انتخاب سطح تحصیلات"/>
                    <x-select wire:model="e_id" label="استخدام" :options="$estekhdams" required placeholder="انتخاب نوع استخدام"/>
                    <x-select wire:model="s_id" label="سمت" :options="$semats" required placeholder="انتخاب سمت"/>
                    <x-select wire:model="r_id" label="ردیف سازمانی" :options="$radifs" required placeholder="انتخاب ردیف سازمانی"/>

                    <div class="sm:col-span-2">
                        <label class="text-sm font-medium block mb-1">واحد</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex-1 min-w-[12rem] input input-bordered flex items-center">
                                <span class="{{ $selectedUnitName ? '' : 'text-base-content/40' }} text-sm">
                                    {{ $selectedUnitName ?: 'واحدی انتخاب نشده' }}
                                </span>
                            </div>
                            <x-button
                                type="button"
                                label="انتخاب واحد"
                                icon="o-building-office-2"
                                class="btn-outline btn-sm"
                                wire:click="$set('unitModal', true)"
                            />
                        </div>
                        @error('u_id') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2 flex justify-end gap-2">
                        <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" spinner />
                        <x-button type="button" label="لغو" wire:click="resetForm" icon="o-x-mark" class="btn-ghost" />
                    </div>
                </x-form>
            </div>
        @endif

        <x-table :headers="$headers" :rows="$persons" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[5, 10, 20]">
            @scope('actions', $person)
                <div class="flex w-1/12">
                    <x-button icon="o-pencil"
                              wire:click="editPerson({{ $person->id }})"
                              class="btn-ghost btn-sm text-primary" />
                    <x-button icon="o-trash"
                              wire:click="delete({{ $person->id }})"
                              wire:confirm="آیا مطمئن هستید"
                              spinner
                              class="btn-ghost btn-sm text-error" />
                </div>
            @endscope
        </x-table>
    </x-card>

    <x-modal wire:model="unitModal" title="انتخاب واحد" persistent separator>
        @include('livewire.partials.unit-tree-picker', [
            'units' => $units,
            'model' => 'u_id',
            'multiple' => false,
            'alwaysOpen' => true,
            'label' => 'واحد سازمانی',
        ])
        <x-slot:actions>
            <x-button label="تأیید" icon="o-check" class="btn-primary" wire:click="$set('unitModal', false)" />
            <x-button label="بستن" icon="o-x-mark" class="btn-ghost" wire:click="$set('unitModal', false)" />
        </x-slot:actions>
    </x-modal>
</div>
