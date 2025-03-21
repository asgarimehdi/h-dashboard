<?php

use Livewire\Volt\Component;
use App\Models\Unit;
use App\Models\Province;
use App\Models\County;
use App\Models\UnitType;
use App\Models\UnitTypeRelationship;

new class extends Component {
     public $units;         // لیست واحدهای سازمانی
    public $name;
    public $description;
    public $unit_type_id;  // شناسه نوع واحد انتخاب شده
    public $province_id;
    public $county_id;
    public $parent_id;

    // داده‌های کمکی برای dropdown ها
    public $unitTypes;   // لیست انواع واحدها
    public $provinces;   // لیست استان‌ها
    public $counties;    // لیست شهرستان‌ها
    public $parentUnits; // تمام واحدهای سازمانی (برای انتخاب والد)
    public function updatedProvinceId($value)
    {
        $this->county_id = null; // ریست کردن انتخاب شهرستان
        $this->counties = County::where('province_id', $value)->get();
        //  $this->parentUnits= OrganizationalUnit::where('province_id', $value)->get();
    }
    public function updatedUnitTypeId()
    {
        $this->reset(['province_id', 'county_id', 'parent_id']);
    }


    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        // دریافت داده‌های اولیه جهت نمایش لیست و dropdown ها
        $this->units = Unit::with(['province', 'county', 'parent', 'unitType'])->get();
        $this->provinces = Province::all();
        $this->counties = County::all();
        $this->parentUnits = Unit::all();
        // $this->unitTypes = UnitType::all();
        $this->unitTypes = UnitType::where('id', '!=', 1)->get();
    }
 
public function getAllowedParentUnitsProperty()
{
    if (!$this->unit_type_id) {
        return collect();
    }

    // دریافت شناسه‌های نوع واحدهای والد مجاز
    $allowedParentTypeIds = UnitTypeRelationship::where('child_unit_type_id', $this->unit_type_id)
        ->pluck('allowed_parent_unit_type_id');

    // فیلتر واحدها بر اساس نوع واحدهای والد مجاز و استان انتخاب‌شده
    $parentUnits = Unit::whereIn('unit_type_id', $allowedParentTypeIds)
        ->where('province_id', $this->province_id)
        ->get();

    // اگر هیچ واحد والدی در استان انتخاب‌شده وجود نداشت
    if ($parentUnits->isEmpty()) {
        // اضافه کردن وزارت‌خانه به عنوان گزینه پیش‌فرض
        $parentUnits = Unit::where('unit_type_id', 1)->get();
    }

    return $parentUnits;
}

    public function createUnit()
    {
        $this->validate([
            'name'         => 'required|string|max:255|unique:units,name',
            'unit_type_id' => 'required|exists:unit_types,id',
            'province_id'  => 'nullable|exists:provinces,id',
            'county_id'    => 'nullable|exists:counties,id',
            'parent_id'    => 'nullable|exists:units,id',
        ]);
        if ($this->county_id == "") {
            $this->county_id = null;
        }
        if ($this->parent_id == null) {

            $this->addError('parent_id', 'هیچ واحد بالادستی انتخاب یا ایجاد نشده است');
            return;
        }
         if ($this->parent_id) {
        $parentUnit = Unit::find($this->parent_id);
        // دریافت شناسه‌های نوع والد مجاز برای نوع فرزند انتخاب‌شده
        $allowedParentTypeIds = UnitTypeRelationship::where('child_unit_type_id', $this->unit_type_id)
            ->pluck('allowed_parent_unit_type_id')->toArray();
        
        if (! in_array($parentUnit->unit_type_id, $allowedParentTypeIds)) {
            $this->addError('parent_id', 'واحد بالادستی انتخاب شده مجاز نیست.');
            return;
        }
    }

        Unit::create([
            'name'          => $this->name,
            'description'   => $this->description,
            'unit_type_id'  => $this->unit_type_id,
            'province_id'   => $this->province_id,
            'county_id'     => $this->county_id,
            'parent_id'     => $this->parent_id,
        ]);

        $this->loadData();
        // session()->flash('message', ' Unit created successfully.');
        session()->flash('message', 'واحد ' . $this->name . ' با موفقیت ایجاد شد.');
        $this->reset(['name', 'description', 'unit_type_id', 'province_id', 'county_id', 'parent_id']);

    }
}; ?>

<div>
  <x-header title="ایجاد واحد های سازمانی" separator progress-indicator>
     
        <x-slot:actions>
        
            <x-theme-toggle darkTheme="dark"  lightTheme="light"  />

        </x-slot:actions>
    </x-header>
   <div class="p-6">

    <!-- نمایش پیام موفقیت -->
    @if (session()->has('message'))
        <div class="mb-4 p-2 bg-green-100 text-green-800 rounded">
            {{ session('message') }}
        </div>
    @endif

    <!-- فرم ایجاد واحد جدید -->
    <form wire:submit.prevent="createUnit" class="space-y-4">
        <!-- نام واحد -->
        <div class=" flex items-center space-x-4">
  <label class="text-gray-700 whitespace-nowrap w-32">نام واحد</label>
            <input 
                type="text" 
                wire:model="name" 
                placeholder="نام واحد را وارد کنید" 
                required
                class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring focus:border-blue-300"
            />
        </div>

        <!-- توضیحات -->
        <div class="flex items-center space-x-4">
            <label class="text-gray-700 whitespace-nowrap w-32">توضیحات</label>
            <input 
                type="text" 
                wire:model="description" 
                placeholder="توضیحات " 
                class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring focus:border-blue-300"
            />
        </div>

        <!-- انتخاب نوع واحد -->
        <div class="flex items-center space-x-4">
            <label class="text-gray-700 whitespace-nowrap w-32">نوع واحد </label>
            <select 
                wire:model.live="unit_type_id" 
                class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring focus:border-blue-300"
            >
                <option value="">انتخاب کنید</option>
                @foreach($unitTypes as $type)
                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                @endforeach
            </select>
        </div>

        <!-- انتخاب استان -->
        <div class="flex items-center space-x-4">
            <label class="text-gray-700 whitespace-nowrap w-32">استان</label>
            <select 
                wire:model.live="province_id" 
                class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring focus:border-blue-300"
            >
                <option value="">انتخاب کنید</option>
                @foreach($provinces as $province)
                    <option value="{{ $province->id }}">{{ $province->name }}</option>
                @endforeach
            </select>
        </div>

        <!-- انتخاب شهرستان -->
        <div class="flex items-center space-x-4">
            <label class="text-gray-700 whitespace-nowrap w-32">شهرستان</label>
            <select 
                wire:model.live="county_id" 
                class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring focus:border-blue-300"
            >
                @if($province_id)
                    <option value="">انتخاب کنید</option>
                    @foreach($this->counties as $county)
                        <option value="{{ $county->id }}">{{ $county->name }}</option>
                    @endforeach
                @else
                    <option value="">ابتدا یک استان انتخاب کنید</option>
                @endif
            </select>
        </div>

        <!-- انتخاب واحد بالادستی -->
        <div class="flex items-center space-x-4">
            <label class="text-gray-700 whitespace-nowrap w-32">واحد بالادستی </label>
            <select 
                wire:model="parent_id" 
                class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:outline-none focus:ring focus:border-blue-300"
            >
                @if($province_id && $unit_type_id)
                    <option value="">بدون والد</option>
                    @foreach($this->allowedParentUnits as $unit)
                        <option value="{{ $unit->id }}">
                            {{ $unit->name }} ({{ optional($unit->unitType)->name ?? '-' }})
                        </option>
                    @endforeach
                @else
                    <option value="">ابتدا باید یک نوع واحد و یک استان انتخاب کنید</option>
                @endif
            </select>
        </div>
@error('parent_id')
    <div class="text-red-500 text-sm mt-1">{{ $message }}</div>
@enderror
        <!-- دکمه ایجاد -->
        <button 
            type="submit" 
            class="w-full bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700 transition-colors"
        >
            ایجاد واحد
        </button>
    </form>

  
</div>

</div>