<?php

use App\Models\Unit;
use App\Models\Region;
use App\Models\UnitType;
use App\Models\UnitTypeRelationship;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;
    use Toast;

    public $name, $description, $unit_type_id, $region_id, $province_id, $parent_id;
    public int|null $editingId = null;
//    public int|null $editingIdMap = null;
//    public int|null $boundaryId = null;
    public string $search = '';
    public int $perPage = 10;
    public bool $modal = false;
    public bool $modal2 = false;
    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    public $unitTypes, $provinces, $counties, $parentUnits;
    public $userUnitLevel; // سطح واحد کاربر (ministry, province, county)
    public $userRegionId; // region_id واحد کاربر
    public $fixedRegionId; // برای کاربران سطح COUNTY (ثابت)
    public $userUnitTypeId; // unit_type_id واحد کاربر
    public $userUnitId; // id واحد کاربر (برای فیلتر کردن)

    public function mount()
    {
        $this->determineUserLevel();
        $this->loadDropdowns();
    }

    // تعیین سطح دسترسی کاربر
    public function determineUserLevel(): void
    {
        $user = auth()->user();
        $person = $user->person;
        $unit = $person?->unit;

        if (!$unit) {
            $this->userUnitLevel = null;
            $this->userUnitId = null;
            return;
        }

        $this->userUnitTypeId = $unit->unit_type_id;
        $this->userUnitId = $unit->id; // ذخیره id واحد کاربر

        if ($unit->id === 1) {
            $this->userUnitLevel = 'ministry'; // سطح وزارت بهداشت
            $this->userRegionId = null;
        } elseif ($unit->region && $unit->region->type === 'province') {
            $this->userUnitLevel = 'province'; // سطح استان
            $this->userRegionId = $unit->region_id;
        } elseif ($unit->region && $unit->region->type === 'county') {
            $this->userUnitLevel = 'county'; // سطح COUNTY
            $this->userRegionId = $unit->region_id;
            $this->fixedRegionId = $unit->region_id; // منطقه ثابت برای ذخیره
        } else {
            $this->userUnitLevel = null;
            $this->userUnitId = null;
        }
    }

    public function units(): LengthAwarePaginator
    {
        $query = Unit::query()
            ->withAggregate('unitType', 'name')
            ->withAggregate('region', 'name')
            ->withAggregate('parent', 'name');

        // حذف واحد کاربر از لیست
        if ($this->userUnitId) {
            $query->where('id', '!=', $this->userUnitId);
        }

        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }

        // محدود کردن واحدها بر اساس سطح کاربر
        if ($this->userUnitLevel === 'county') {
            $query->where('region_id', $this->userRegionId);
        } elseif ($this->userUnitLevel === 'province') {
            $query->whereIn('region_id', Region::where('parent_id', $this->userRegionId)->pluck('id')->push($this->userRegionId));
        }

        $query->orderBy(...array_values($this->sortBy));
        return $query->paginate($this->perPage);
    }

    // بقیه متدها بدون تغییر باقی می‌مانند
    public function loadDropdowns(): void
    {
        $this->unitTypes = $this->getAllowedUnitTypes();
        if ($this->userUnitLevel === 'ministry') {
            $this->provinces = Region::where('type', 'province')->get();
            $this->counties = $this->province_id
                ? Region::where('type', 'county')->where('parent_id', $this->province_id)->get()
                : collect();
        } elseif ($this->userUnitLevel === 'province') {
            $this->provinces = collect();
            $this->counties = Region::where('type', 'county')->where('parent_id', $this->userRegionId)->get();
        } else {
            $this->provinces = collect();
            $this->counties = collect();
        }
        $this->parentUnits = $this->getAllowedParentUnitsProperty();
    }

    public function getAllowedUnitTypes()
    {
        if ($this->userUnitLevel === 'ministry') {
            return UnitType::where('id', '!=', 1)->get();
        }
        $allowedUnitTypeIds = [];
        $childUnitTypeIds = UnitTypeRelationship::where('allowed_parent_unit_type_id', $this->userUnitTypeId)
            ->pluck('child_unit_type_id')
            ->toArray();
        $allowedUnitTypeIds = array_merge($allowedUnitTypeIds, $childUnitTypeIds);
        while (!empty($childUnitTypeIds)) {
            $newChildUnitTypeIds = UnitTypeRelationship::whereIn('allowed_parent_unit_type_id', $childUnitTypeIds)
                ->pluck('child_unit_type_id')
                ->toArray();
            $allowedUnitTypeIds = array_merge($allowedUnitTypeIds, $newChildUnitTypeIds);
            $childUnitTypeIds = $newChildUnitTypeIds;
        }
        $allowedUnitTypeIds = array_unique($allowedUnitTypeIds);
        if (empty($allowedUnitTypeIds)) {
            return collect();
        }
        return UnitType::whereIn('id', $allowedUnitTypeIds)->get();
    }

    public function updatedUnitTypeId($value): void
    {
        $this->reset(['province_id', 'region_id', 'parent_id']);
        $this->loadDropdowns();
    }

    public function updatedProvinceId($value): void
    {
        $this->region_id = null;
        $this->parent_id = null;
        $this->loadDropdowns();
    }

    public function getAllowedParentUnitsProperty()
    {
        if (!$this->unit_type_id) {
            return collect();
        }
        if ($this->unit_type_id == 1) {
            return collect();
        }
        $allowedParentTypeIds = UnitTypeRelationship::where('child_unit_type_id', $this->unit_type_id)
            ->pluck('allowed_parent_unit_type_id');
        $parentUnits = Unit::whereIn('unit_type_id', $allowedParentTypeIds)
            ->when($this->userUnitLevel === 'county', function ($query) {
                return $query->where('region_id', $this->userRegionId);
            })
            ->when($this->userUnitLevel === 'province', function ($query) {
                return $query->whereIn('region_id', Region::where('parent_id', $this->userRegionId)->pluck('id')->push($this->userRegionId));
            })
            ->get();
        if ($parentUnits->isEmpty() && $this->userUnitLevel === 'ministry') {
            $parentUnits = Unit::where('unit_type_id', 1)->get();
        }
        return $parentUnits;
    }

    public function saveUnit(): void
    {
        $rules = [
            'name' => 'required|string|max:255|unique:units,name,' . $this->editingId,
            'unit_type_id' => 'required|exists:unit_types,id',
            'region_id' => 'nullable|exists:regions,id',
            'parent_id' => $this->unit_type_id == 1 ? 'nullable' : 'required|exists:units,id',
        ];
        if ($this->userUnitLevel === 'ministry') {
            $rules['province_id'] = 'required|exists:regions,id';
        }
        $this->validate($rules);
        if ($this->parent_id) {
            $parentUnit = Unit::find($this->parent_id);
            $allowedParentTypeIds = UnitTypeRelationship::where('child_unit_type_id', $this->unit_type_id)
                ->pluck('allowed_parent_unit_type_id')->toArray();
            if (!in_array($parentUnit->unit_type_id, $allowedParentTypeIds)) {
                $this->error('واحد بالادستی انتخاب‌شده مجاز نیست.');
                return;
            }
        }
        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'unit_type_id' => $this->unit_type_id,
            'region_id' => $this->determineRegionId(),
            'parent_id' => $this->parent_id,
        ];
        try {
            if ($this->editingId) {
                Unit::findOrFail($this->editingId)->update($data);
                $this->success("واحد '{$this->name}' به‌روزرسانی شد");
            } else {
                Unit::create($data);
                $this->success("واحد '{$this->name}' ایجاد شد");
            }
        } catch (\Exception $e) {
            $this->error("خطا ", position: 'toast-bottom');
        }
        $this->resetForm();
        $this->modal = false;
    }

    public function determineRegionId()
    {
        if ($this->userUnitLevel === 'county') {
            return $this->fixedRegionId;
        } elseif ($this->userUnitLevel === 'province') {
            return $this->region_id ?: $this->userRegionId;
        } elseif ($this->userUnitLevel === 'ministry') {
            return $this->region_id ?: $this->province_id;
        }
        return null;
    }

    public function editUnit($id): void
    {
        $unit = Unit::findOrFail($id);
        $this->editingId = $id;
        $this->name = $unit->name;
        $this->description = $unit->description;
        $this->unit_type_id = $unit->unit_type_id;
        $this->region_id = $unit->region_id;
        $this->parent_id = $unit->parent_id;
        if ($this->userUnitLevel === 'ministry' && $unit->region) {
            if ($unit->region->type === 'county') {
                $this->province_id = $unit->region->parent_id;
            } elseif ($unit->region->type === 'province') {
                $this->province_id = $unit->region_id;
            }
        }
        $this->loadDropdowns();
        $this->modal = true;
    }

    public function deleteUnit(Unit $unit): void
    {
        try {
            $unit->delete();
            $this->warning("$unit->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.", position: 'toast-bottom');
        }
    }

//    public function mapModal($editingIdMap): void
//    {
//        $this->editingIdMap = $editingIdMap;
//        $this->modal2 = true;
//    }

//    #[On('boundarySaved')]
//    public function saveBoundaryId($boundaryId): void
//    {
//        Unit::find($this->editingIdMap)?->update([
//            'boundary_id' => $boundaryId,
//        ]);
//        $this->success("ایجاد شد", 'با موفقیت', position: 'toast-bottom');
//        $this->modal2 = false;
//    }

    public function resetForm(): void
    {
        $this->reset(['name', 'description', 'unit_type_id', 'region_id', 'province_id', 'parent_id', 'editingId']);
        $this->loadDropdowns();
//        $this->modal2 = false;
    }

    public function openModalForCreate(): void
    {
        $this->resetForm();
        $this->modal = true;
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden 2xl:table-cell'],
            ['key' => 'name', 'label' => 'نام', 'class' => 'w-40'],
            ['key' => 'description', 'label' => 'توضیحات', 'class' => 'w-8 hidden 2xl:table-cell'],
            ['key' => 'unit_type_name', 'label' => 'نوع واحد', 'class' => 'w-50 hidden sm:table-cell'],
            ['key' => 'region_name', 'label' => 'منطقه', 'class' => 'w-8 hidden sm:table-cell'],
            ['key' => 'parent_name', 'label' => 'واحد بالادستی', 'class' => 'w-20 hidden xl:table-cell'],
        ];
    }

    public function with(): array
    {
        return [
            'units' => $this->units(),
            'headers' => $this->headers(),
            'unitTypes' => $this->unitTypes,
            'provinces' => $this->provinces,
            'counties' => $this->counties,
            'parentUnits' => $this->parentUnits,
            'userUnitLevel' => $this->userUnitLevel,
        ];
    }
}; ?>

<div>
    <!-- هدر -->
    <x-header title="مدیریت واحدهای زیر مجموعه" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <!-- جدول -->
    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <x-button class="btn-success" wire:click="openModalForCreate" responsive icon="o-plus"/>
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
        <x-table :headers="$headers" :rows="$units" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[5, 10, 20]">
            @foreach($units as $unit)
                <tr>
                    <td>
                        @scope('actions', $unit)
                        <div class="flex w-1/12">
{{--                            <x-button icon="o-map"--}}
{{--                                      class="btn-ghost btn-sm text-primary"--}}
{{--                                      wire:click="mapModal({{ $unit->id }})">--}}
{{--                                <span class="hidden 2xl:inline">نقشه</span>--}}
{{--                            </x-button>--}}
                            <x-button icon="o-pencil"
                                      wire:click="editUnit({{ $unit->id }})"
                                      class="btn-ghost btn-sm text-primary"
                                      @click="$wire.modal = true">
                                <span class="hidden 2xl:inline">ویرایش</span>
                            </x-button>
                            <x-button icon="o-trash"
                                      wire:click="deleteUnit({{ $unit->id }})"
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

    <!-- مدال -->
    <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش واحد' : 'ثبت واحد جدید' }}" separator persistent>
        <x-form wire:submit.prevent="saveUnit">
            <div class="grid grid-cols-2 gap-4">
                <!-- نام -->
                <x-input wire:model="name" label="نام واحد" placeholder="نام واحد" required/>

                <!-- توضیحات -->
                <x-input wire:model="description" label="توضیحات" placeholder="توضیحات"/>

                <!-- نوع واحد -->
                <x-select wire:model.live="unit_type_id" label="نوع واحد" :options="$unitTypes" option-value="id"
                          option-label="name" required placeholder="انتخاب کنید"/>

                <!-- استان (فقط برای کاربران سطح وزارت) -->
                @if($userUnitLevel === 'ministry')
                    <x-select wire:model.live="province_id" label="استان" :options="$provinces" option-value="id"
                              option-label="name" placeholder="انتخاب کنید" required/>
                @endif

                <!-- COUNTY (برای کاربران سطح وزارت یا استان) -->
                @if($userUnitLevel === 'ministry' || $userUnitLevel === 'province')
                    <x-select wire:model="region_id" label="شهرستان" :options="$counties" option-value="id"
                              option-label="name" placeholder="انتخاب کنید"/>
                @endif

                <!-- واحد بالادستی -->
                <x-select wire:model="parent_id" label="واحد بالادستی" :options="$parentUnits" option-value="id"
                          option-label="name" required placeholder="انتخاب کنید"/>

                <!-- دکمه‌ها -->
                <div class="col-span-2 flex justify-end space-x-2">
                    <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check"
                              class="btn-primary"/>
                    <x-button label="لغو" wire:click="resetForm" @click="$wire.modal = false" icon="o-x-mark"
                              class="btn-outline"/>
                </div>
            </div>
        </x-form>
    </x-modal>
    <x-modal wire:model="modal2" title="ثبت مرز" separator persistent>
        <livewire:maps.polygon />
    </x-modal>
</div>
