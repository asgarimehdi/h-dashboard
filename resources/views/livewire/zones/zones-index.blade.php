<?php

use App\Models\Unit;
use App\Models\Zone;
use App\Services\AccessService;
use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

return new class extends Component {
    use WithPagination;
    use Toast;

    public $name, $description, $color;
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 10;
    public bool $modal = false;
    public bool $showHelpModal = false;
    public array $selectedUnits = [];
    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    public function zones(): LengthAwarePaginator
    {
        $query = Zone::accessible()
            ->withCount('units');

        if (! empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }

        $query->orderBy(...array_values($this->sortBy));

        return $query->paginate($this->perPage);
    }

    public function getAvailableUnitsProperty()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (empty($accessibleIds)) {
            return collect();
        }

        return Unit::whereIn('id', $accessibleIds)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function saveZone(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'selectedUnits' => 'nullable|array',
            'selectedUnits.*' => 'exists:units,id',
        ]);

        // Validate that all selected units are within the user's accessible scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! empty($this->selectedUnits)) {
            $invalidIds = array_diff($this->selectedUnits, $accessibleIds);
            if (! empty($invalidIds)) {
                $this->error('برخی واحدهای انتخاب‌شده خارج از محدوده دسترسی شما هستند.', position: 'toast-bottom');
                return;
            }
        }

        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
        ];

        try {
            if ($this->editingId) {
                $zone = Zone::accessible()->findOrFail($this->editingId);
                $zone->update($data);
                $zone->units()->sync($this->selectedUnits ?? []);
                $this->success("منطقه '{$this->name}' به‌روزرسانی شد");
            } else {
                $zone = Zone::create($data);
                if (! empty($this->selectedUnits)) {
                    $zone->units()->sync($this->selectedUnits);
                }
                $this->success("منطقه '{$this->name}' ایجاد شد");
            }
        } catch (\Exception $e) {
            $this->error("خطا در ذخیره سازی", position: 'toast-bottom');
        }

        $this->resetForm();
        $this->modal = false;
    }

    public function editZone($id): void
    {
        $zone = Zone::accessible()->with('units')->findOrFail($id);
        $this->editingId = $id;
        $this->name = $zone->name;
        $this->description = $zone->description;
        $this->color = $zone->color;
        $this->selectedUnits = $zone->units->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        $this->modal = true;
    }

    public function deleteZone(Zone $zone): void
    {
        // Ensure the zone is within the user's scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $zoneUnitIds = $zone->units()->pluck('zone_unit.unit_id')->toArray();
        if (empty(array_intersect($zoneUnitIds, $accessibleIds))) {
            $this->error("امکان حذف وجود ندارد.", position: 'toast-bottom');
            return;
        }
        try {
            $zone->units()->detach();
            $zone->delete();
            $this->warning("{$zone->name} حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد.", position: 'toast-bottom');
        }
    }

    public function resetForm(): void
    {
        $this->reset(['name', 'description', 'color', 'selectedUnits', 'editingId']);
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
            ['key' => 'name', 'label' => 'نام منطقه', 'class' => 'w-40'],
            ['key' => 'description', 'label' => 'توضیحات', 'class' => 'w-60 hidden sm:table-cell'],
            ['key' => 'color', 'label' => 'رنگ', 'class' => 'w-20'],
            ['key' => 'units_count', 'label' => 'تعداد واحدها', 'class' => 'w-20 text-center'],
        ];
    }

    public function with(): array
    {
        return [
            'zones' => $this->zones(),
            'headers' => $this->headers(),
            'availableUnits' => $this->getAvailableUnitsProperty(),
        ];
    }
}; ?>

<div>
    <!-- HEADER -->
    <x-header title="مدیریت بلاک‌ها (زون‌بندی)" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-help:button section="zones" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <!-- TABLE -->
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
        </div>
        
        <x-table :headers="$headers" :rows="$zones" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[5, 10, 20]">
            @foreach($zones as $zone)
                <tr wire:key="zone-{{ $zone->id }}">
                    @scope('actions', $zone)
                    <div class="flex w-1/12">
                        <x-button icon="o-pencil"
                                  wire:click="editZone({{ $zone->id }})"
                                  class="btn-ghost btn-sm text-primary" />
                        <x-button icon="o-trash"
                                  wire:click="deleteZone({{ $zone->id }})"
                                  wire:confirm="آیا مطمئن هستید"
                                  spinner
                                  class="btn-ghost btn-sm text-error" />
                    </div>
                    @endscope
                    @scope('cell_color', $zone)
                        @if($zone->color)
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded-full inline-block" style="background-color: {{ $zone->color }}"></span>
                                <span>{{ $zone->color }}</span>
                            </div>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    @endscope
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <!-- MODAL -->
    <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش بلاک' : 'ثبت بلاک جدید' }}" separator persistent>
        <x-form wire:submit.prevent="saveZone" class="grid grid-cols-2 gap-4">
            <x-input wire:model="name" label="نام بلاک" placeholder="نام بلاک" required/>
            <x-input wire:model="color" label="رنگ (hex)" placeholder="#FF5733"/>
            <div class="col-span-2">
                <x-input wire:model="description" label="توضیحات" placeholder="توضیحات"/>
            </div>
            <div class="col-span-2">
                <label class="label">
                    <span class="label-text">انتخاب واحدها</span>
                </label>
                <div class="max-h-48 overflow-y-auto border rounded-lg p-2 space-y-1">
                    @forelse($availableUnits as $unit)
                        <label class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded">
                            <input type="checkbox" 
                                   wire:model="selectedUnits" 
                                   value="{{ $unit->id }}"
                                   class="checkbox checkbox-sm checkbox-primary" />
                            <span>{{ $unit->name }}</span>
                        </label>
                    @empty
                        <p class="text-gray-400 text-sm p-2">هیچ واحدی در دسترس نیست</p>
                    @endforelse
                </div>
                @error('selectedUnits')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="col-span-2 flex justify-end space-x-2 space-x-reverse">
                <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check"
                          class="btn-primary"/>
                <x-button label="لغو" wire:click="resetForm" @click="$wire.modal = false" icon="o-x-mark"
                          class="btn-outline"/>
            </div>
        </x-form>
    </x-modal>
</div>