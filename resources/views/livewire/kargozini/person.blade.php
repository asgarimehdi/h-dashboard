<?php

use App\Models\Person;
use App\Models\Tahsil;
use App\Models\Estekhdam;
use App\Models\Semat;
use App\Models\Radif;
use App\Models\Unit;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;
    use Toast;

    // ویژگی‌های فرم
    public $n_code, $f_name, $l_name, $t_id, $e_id, $s_id, $r_id, $u_id;
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 5;
    public bool $modal = false; // متغیر برای مدال
    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // پاک کردن فیلترها
    public function clear(): void
    {
        $this->reset();
        $this->success('فیلترها پاک شدند.', position: 'toast-bottom');
    }

    // حذف رکورد

    public function delete(Person $person): void
    {
        try {
            $person->delete();
            $this->warning("$person->f_name $person->l_name حذف شد", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.", position: 'toast-bottom');
        }
    }

    // ذخیره یا به‌روزرسانی رکورد
    public function savePerson(): void
    {
        $this->validate([
            'n_code' => 'required|string|size:10|unique:persons,n_code,' . $this->editingId,
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
            $this->success("شخص به‌روزرسانی شد");
        } else {
            Person::create([
                'n_code' => $this->n_code,
                'f_name' => $this->f_name,
                'l_name' => $this->l_name,
                't_id' => $this->t_id,
                'e_id' => $this->e_id,
                's_id' => $this->s_id,
                'r_id' => $this->r_id,
                'u_id' => $this->u_id,
            ]);
            $this->success("شخص جدید ثبت شد");
        }

        $this->reset(['n_code', 'f_name', 'l_name', 't_id', 'e_id', 's_id', 'r_id', 'u_id', 'editingId']);
        $this->modal = false; // بستن مدال
    }

    // بارگذاری اطلاعات برای ویرایش
    public function editPerson($id): void
    {
        $person = Person::findOrFail($id);
        $this->editingId = $id;
        $this->n_code = $person->n_code;
        $this->f_name = $person->f_name;
        $this->l_name = $person->l_name;
        $this->t_id = $person->t_id;
        $this->e_id = $person->e_id;
        $this->s_id = $person->s_id;
        $this->r_id = $person->r_id;
        $this->u_id = $person->u_id;
        $this->modal = true; // باز کردن مدال برای ویرایش
    }

    public function resetModal(): void
    {
        $this->reset(['n_code', 'f_name', 'l_name', 't_id', 'e_id', 's_id', 'r_id', 'u_id', 'editingId']);
    }

    // تعریف سرستون‌های جدول
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

    // دریافت لیست افراد با جستجو و مرتب‌سازی
    public function persons(): LengthAwarePaginator
    {
        $query = Person::query()
            ->withAggregate('tahsil', 'name')
            ->withAggregate('estekhdam', 'name')
            ->withAggregate('semat', 'name')
            ->withAggregate('radif', 'name')
            ->withAggregate('unit', 'name');

        if (!empty($this->search)) {
            $query->where('n_code', 'LIKE', '%' . $this->search . '%')
                ->orwhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$this->search}%"]);
        }

        $query->orderBy(...array_values($this->sortBy));
        return $query->paginate($this->perPage);
    }

    // ارسال داده‌ها به View
    public function with(): array
    {
        return [
            'persons' => $this->persons(),
            'headers' => $this->headers(),
            'tahsils' => Tahsil::all(),
            'estekhdams' => Estekhdam::all(),
            'semats' => Semat::all(),
            'radifs' => Radif::all(),
            'units' => Unit::all(),
        ];
    }
}; ?>

<div>
    <!-- هدر -->
    <x-header title="مدیریت اشخاص" separator progress-indicator>
        <x-slot:middle class="!justify-end">

        </x-slot:middle>
        <x-slot:actions>

            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <!-- جدول -->
    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <x-button class="btn-success" @click="$wire.modal = true" icon="o-plus"/>
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
        <x-table :headers="$headers" :rows="$persons" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[5, 10, 20]">
            @foreach($persons as $person)
                <tr wire:key="{{ $person->id }}">
                    <td>
                        @scope('actions', $person)
                        <div class="flex w-1/12">
                            <x-button icon="o-pencil"
                                      wire:click="editPerson({{ $person->id }})"
                                      class="btn-ghost btn-sm text-primary"
                                      @click="$wire.modal = true">
                                <span class="hidden 2xl:inline">ویرایش</span>
                            </x-button>

                            <x-button icon="o-trash"
                                      wire:click="delete({{ $person->id }})"
                                      wire:confirm="آیا مطمئن هستید"
                                      spinner
                                      class="btn-ghost btn-sm text-error">
                                <span class="hidden 2xl:inline">حذف</span>
                            </x-button>
                        </div>
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <!-- مدال برای ایجاد و ویرایش -->
    <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش کاربر' : 'ثبت کاربر جدید' }}" separator>
        <x-form wire:submit.prevent="savePerson" class="grid grid-cols-2 gap-4">
            <x-input wire:model="n_code" label="کد ملی" placeholder="کد ملی" required/>
            <x-input wire:model="f_name" label="نام" placeholder="نام" required/>
            <x-input wire:model="l_name" label="نام خانوادگی" placeholder="نام خانوادگی" required/>
            <x-select wire:model="t_id" label="تحصیلات" :options="$tahsils" required placeholder="انتخاب سطح تحصیلات"/>
            <x-select wire:model="e_id" label="استخدام" :options="$estekhdams" required
                      placeholder="انتخاب نوع استخدام"/>
            <x-select wire:model="s_id" label="سمت" :options="$semats" required placeholder="انتخاب سمت"/>
            <x-select wire:model="r_id" label="ردیف سازمانی" :options="$radifs" required
                      placeholder="انتخاب ردیف سازمانی"/>
            <x-select wire:model="u_id" label="واحد" :options="$units" required placeholder="انتخاب واحد"/>
            <div class="col-span-2 flex justify-end space-x-2">
                <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check"
                          class="btn-primary"/>
                <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark"/>
            </div>
        </x-form>
    </x-modal>
</div>
