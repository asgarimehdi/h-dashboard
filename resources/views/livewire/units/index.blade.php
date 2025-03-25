<?php

use App\Models\Unit;
use App\Models\Province;
use App\Models\County;
use App\Models\UnitType;
use App\Models\UnitTypeRelationship;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
    use Toast;

    public $name, $description, $unit_type_id, $province_id, $county_id, $parent_id;
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 5;
    public bool $modal = false;
    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    public $unitTypes, $provinces, $counties, $parentUnits;

    public function mount()
    {
        $this->loadDropdowns();
    }

    public function loadDropdowns()
    {
        $this->unitTypes = UnitType::where('id', '!=', 1)->get();
        $this->provinces = Province::all();
        $this->counties = $this->province_id ? County::where('province_id', $this->province_id)->get() : collect();
        $this->parentUnits = $this->getAllowedParentUnitsProperty();
    }

    public function updatedUnitTypeId($value)
    {
        $this->reset(['province_id', 'county_id', 'parent_id']);
        $this->loadDropdowns();
    }

    public function updatedProvinceId($value)
    {
        $this->county_id = null;
        $this->parent_id = null;
        $this->counties = County::where('province_id', $value)->get();
        $this->parentUnits = $this->getAllowedParentUnitsProperty();
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
            ->where('province_id', $this->province_id)
            ->get();

        if ($parentUnits->isEmpty()) {
            $parentUnits = Unit::where('unit_type_id', 1)->get();
        }

        return $parentUnits;
    }

    public function saveUnit()
    {
        $rules = [
            'name' => 'required|string|max:255|unique:units,name,' . $this->editingId,
            'unit_type_id' => 'required|exists:unit_types,id',
            'province_id' => 'nullable|exists:provinces,id',
            'county_id' => 'nullable|exists:counties,id',
            'parent_id' => $this->unit_type_id == 1 ? 'nullable' : 'required|exists:units,id',
        ];

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
            'province_id' => $this->province_id,
            'county_id' => $this->county_id ?: null,
            'parent_id' => $this->parent_id,
        ];

        if ($this->editingId) {
            Unit::findOrFail($this->editingId)->update($data);
            $this->success("واحد '{$this->name}' به‌روزرسانی شد");
        } else {
            Unit::create($data);
            $this->success("واحد '{$this->name}' ایجاد شد");
        }

        $this->resetForm();
        $this->modal = false;
    }

    public function editUnit($id)
    {
        $unit = Unit::findOrFail($id);
        $this->editingId = $id;
        $this->name = $unit->name;
        $this->description = $unit->description;
        $this->unit_type_id = $unit->unit_type_id;
        $this->province_id = $unit->province_id;
        $this->county_id = $unit->county_id;
        $this->parent_id = $unit->parent_id;
        $this->loadDropdowns();
        $this->modal = true;
    }

    public function deleteUnit(Unit $unit)
    {
        $unit->delete();
        $this->warning("واحد '{$unit->name}' حذف شد");
    }

    public function resetForm()
    {
        $this->reset(['name', 'description', 'unit_type_id', 'province_id', 'county_id', 'parent_id', 'editingId']);
        $this->loadDropdowns();
    }

    public function openModalForCreate()
    {
        $this->resetForm();
        $this->modal = true;
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
            ['key' => 'name', 'label' => 'نام', 'class' => 'w-20'],
            ['key' => 'description', 'label' => 'توضیحات', 'class' => 'w-32'],
            ['key' => 'unit_type_name', 'label' => 'نوع واحد', 'class' => 'w-20'],
            ['key' => 'province_name', 'label' => 'استان', 'class' => 'w-20'],
            ['key' => 'county_name', 'label' => 'شهرستان', 'class' => 'w-20'],
            ['key' => 'parent_name', 'label' => 'واحد بالادستی', 'class' => 'w-20'],
        ];
    }

    public function units(): LengthAwarePaginator
    {
        $query = Unit::query()
            ->withAggregate('unitType', 'name')
            ->withAggregate('province', 'name')
            ->withAggregate('county', 'name')
            ->withAggregate('parent', 'name');

        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }

        $query->orderBy(...array_values($this->sortBy));
        return $query->paginate($this->perPage);
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
        ];
    }
}; ?>

<div>
    <!-- هدر -->
    <x-header title="مدیریت واحدها" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="جستجو..." wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
            <x-button class="btn-success btn-sm" label="ثبت جدید" wire:click="openModalForCreate" responsive icon="o-plus" />
           
             
        </x-slot:actions>
    </x-header>

    <!-- جدول -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$units" :sort-by="$sortBy" with-pagination per-page="perPage" :per-page-values="[5, 10, 20]">
            @foreach($units as $unit)
                <tr >
                   
                    <td>
                        @scope('actions', $unit)
                        <x-button icon="o-pencil" wire:click="editUnit({{ $unit->id }})" class="btn-ghost btn-sm text-primary" label="Edit" />
                        <x-button icon="o-trash" wire:click="deleteUnit({{ $unit->id }})" wire:confirm="مطمئن هستید؟" spinner class="btn-ghost btn-sm text-error" label="Delete" />
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <!-- مدال -->
    <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش واحد' : 'ثبت واحد جدید' }}" separator>
        <x-form wire:submit.prevent="saveUnit">
            <div class="grid grid-cols-2 gap-4">
                <!-- نام -->
                <x-input wire:model="name" label="نام واحد" placeholder="نام واحد" required />

                <!-- توضیحات -->
                <x-input wire:model="description" label="توضیحات" placeholder="توضیحات" />

                <!-- نوع واحد -->
                <x-select wire:model.live="unit_type_id" label="نوع واحد" :options="$unitTypes" option-value="id" option-label="name" required placeholder="انتخاب کنید" />

                <!-- استان -->
                <x-select wire:model.live="province_id" label="استان" :options="$provinces" option-value="id" option-label="name" placeholder="انتخاب کنید" />

                <!-- شهرستان -->
                <x-select wire:model="county_id" label="شهرستان" :options="$counties" option-value="id" option-label="name" placeholder="انتخاب کنید" />

                <!-- واحد بالادستی -->
                <x-select wire:model="parent_id" label="واحد بالادستی" :options="$parentUnits" option-value="id" option-label="name" required placeholder="انتخاب کنید" />

                <!-- دکمه‌ها -->
                <div class="col-span-2 flex justify-end space-x-2">
                    <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check" class="btn-primary" />
                    <x-button label="لغو" wire:click="resetForm" @click="$wire.modal = false" icon="o-x-mark" class="btn-outline" />
                </div>
            </div>
        </x-form>
    </x-modal>
</div>