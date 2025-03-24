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

new class extends Component
{
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
        $person->delete();
        $this->warning("$person->f_name $person->l_name حذف شد", 'با موفقیت', position: 'toast-bottom');
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

    // تعریف سرستون‌های جدول
    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
            ['key' => 'n_code', 'label' => 'کد ملی', 'class' => 'w-20'],
            ['key' => 'f_name', 'label' => 'نام', 'class' => 'w-20'],
            ['key' => 'l_name', 'label' => 'نام خانوادگی', 'class' => 'w-20'],
            ['key' => 'tahsil_name', 'label' => 'تحصیلات', 'class' => 'w-20'],
            ['key' => 'estekhdam_name', 'label' => 'استخدام', 'class' => 'w-20'],
            ['key' => 'semat_name', 'label' => 'سمت', 'class' => 'w-20'],
            ['key' => 'radif_name', 'label' => 'ردیف سازمانی', 'class' => 'w-20'],
            ['key' => 'unit_name', 'label' => 'واحد', 'class' => 'w-20'],
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
                  ->orWhere('f_name', 'LIKE', '%' . $this->search . '%')
                  ->orWhere('l_name', 'LIKE', '%' . $this->search . '%');
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
            'tahsils' => Tahsil::all()->pluck('name', 'id'),
            'estekhdams' => Estekhdam::all()->pluck('name', 'id'),
            'semats' => Semat::all()->pluck('name', 'id'),
            'radifs' => Radif::all()->pluck('name', 'id'),
            'units' => Unit::all()->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <!-- هدر -->
    <x-header title="مدیریت اشخاص" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="جستجو..." wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-success btn-sm" label="ثبت جدید" @click="$wire.modal = true" responsive icon="o-plus" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <!-- جدول -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$persons" :sort-by="$sortBy" with-pagination per-page="perPage" :per-page-values="[5, 10, 20]">
            @foreach($persons as $person)
                <tr wire:key="{{ $person->id }}">
                    {{-- <td>{{ $person->id }}</td>
                    <td>{{ $person->n_code }}</td>
                    <td>{{ $person->f_name }}</td>
                    <td>{{ $person->l_name }}</td>
                    <td>{{ $person->tahsil_name ?? 'نامشخص' }}</td>
                    <td>{{ $person->estekhdam_name ?? 'نامشخص' }}</td>
                    <td>{{ $person->semat_name ?? 'نامشخص' }}</td>
                    <td>{{ $person->radif_name ?? 'نامشخص' }}</td>
                    <td>{{ $person->unit_name ?? 'نامشخص' }}</td> --}}
                    <td>
                        @scope('actions', $person)
                        <!-- دکمه ویرایش -->
                        <x-button icon="o-pencil" wire:click="editPerson({{ $person->id }})" class="btn-ghost btn-sm text-primary" label="Edit" />
                        <!-- دکمه حذف -->
                        <x-button icon="o-trash" wire:click="delete({{ $person->id }})" wire:confirm="مطمئن هستید؟" spinner class="btn-ghost btn-sm text-error" label="Delete" />
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <!-- مدال برای ایجاد و ویرایش -->
    <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش کاربر' : 'ثبت کاربر جدید' }}" separator>
        {{-- <form wire:submit.prevent="savePerson" class="grid grid-cols-2 gap-4">
            <x-input wire:model="n_code" label="کد ملی" placeholder="کد ملی" required />
            <x-input wire:model="f_name" label="نام" placeholder="نام" required />
            <x-input wire:model="l_name" label="نام خانوادگی" placeholder="نام خانوادگی" required />
            <x-select wire:model="t_id" label="تحصیلات" :options="$tahsils" required />
            <x-select wire:model="e_id" label="استخدام" :options="$estekhdams" required />
            <x-select wire:model="s_id" label="سمت" :options="$semats" required />
            <x-select wire:model="r_id" label="ردیف سازمانی" :options="$radifs" required />
            <x-select wire:model="u_id" label="واحد" :options="$units" required />
            <div class="col-span-2 flex justify-end space-x-2">
                <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" />
                <x-button label="لغو" @click="$wire.modal = false" icon="o-x-mark" />
            </div>
        </form> --}}
        <form wire:submit.prevent="savePerson" class="grid grid-cols-2 gap-4">
    <!-- کد ملی -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">کد ملی</label>
        <input wire:model="n_code" type="text" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="کد ملی" required />
    </div>

    <!-- نام -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">نام</label>
        <input wire:model="f_name" type="text" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="نام" required />
    </div>

    <!-- نام خانوادگی -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">نام خانوادگی</label>
        <input wire:model="l_name" type="text" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="نام خانوادگی" required />
    </div>

    <!-- تحصیلات -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">تحصیلات</label>
        <select wire:model="t_id" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none" required>
            <option value="">انتخاب کنید</option>
            @foreach($tahsils as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <!-- استخدام -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">استخدام</label>
        <select wire:model="e_id" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none" required>
            <option value="">انتخاب کنید</option>
            @foreach($estekhdams as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <!-- سمت -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">سمت</label>
        <select wire:model="s_id" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none" required>
            <option value="">انتخاب کنید</option>
            @foreach($semats as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <!-- ردیف سازمانی -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">ردیف سازمانی</label>
        <select wire:model="r_id" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none" required>
            <option value="">انتخاب کنید</option>
            @foreach($radifs as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <!-- واحد -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">واحد</label>
        <select wire:model="u_id" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none" required>
            <option value="">انتخاب کنید</option>
            @foreach($units as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <!-- دکمه‌ها -->
    <div class="col-span-2 flex justify-end space-x-2">
        <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 flex items-center">
            <i class="o-check mr-2"></i> {{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}
        </button>
        <button type="button" @click="$wire.modal = false" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 flex items-center">
            <i class="o-x-mark mr-2"></i> لغو
        </button>
    </div>
</form>
    </x-modal>
</div>