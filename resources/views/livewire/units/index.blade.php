<?php

use App\Models\Unit;
use App\Models\Region;
use App\Models\UnitType;
use App\Models\UnitTypeRelationship;
use App\Services\AccessService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

return new class extends Component {
    use WithPagination;
    use Toast;

    public $name, $description, $unit_type_id, $region_id, $province_id, $parent_id;
    public bool $can_receive_tickets = false;
    #[Locked]
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 20;
    public bool $modal = false;
    public bool $showHelpModal = false;
    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // #914: `sortBy` is client-settable, so only real columns are sortable.
    // The `*_name` entries are the `withAggregate` aliases loaded in units().
    private const SORTABLE_COLUMNS = ['id', 'name', 'description', 'unit_type_name', 'region_name', 'parent_name', 'can_receive_tickets'];

    public $unitTypes, $provinces, $counties, $parentUnits;
    public $userUnitLevel;
    public $userRegionId;
    public $fixedRegionId;
    public $userUnitTypeId;
    public $userUnitId;

    public function mount(): void
    {
        $this->determineUserLevel();
        $this->loadDropdowns();
    }

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
        $this->userUnitId = $unit->id;

        if ($unit->id === 1) {
            $this->userUnitLevel = 'ministry';
            $this->userRegionId = null;
        } elseif ($unit->region && $unit->region->type === 'province') {
            $this->userUnitLevel = 'province';
            $this->userRegionId = $unit->region_id;
        } elseif ($unit->region && $unit->region->type === 'county') {
            $this->userUnitLevel = 'county';
            $this->userRegionId = $unit->region_id;
            $this->fixedRegionId = $unit->region_id;
        } else {
            $this->userUnitLevel = null;
            $this->userUnitId = null;
        }
    }

    public function units(): LengthAwarePaginator
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = Unit::query()
            ->withAggregate('unitType', 'name')
            ->withAggregate('region', 'name')
            ->withAggregate('parent', 'name')
            ->addSelect('can_receive_tickets', 'is_active');

        $query->whereIn('id', $accessibleIds);

        if ($this->userUnitId) {
            $query->where('id', '!=', $this->userUnitId);
        }

        if (! empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }

        $column = in_array($this->sortBy['column'] ?? '', self::SORTABLE_COLUMNS, true)
            ? $this->sortBy['column'] : 'id';
        $direction = ($this->sortBy['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction);

        return $query->paginate($this->perPage);
    }

    /**
     * Issue #817: the org tree IS the authorization model — every write
     * must prove the target (and the new parent) sit inside the caller's
     * reachable set. Returns true when in scope, flashes an error toast
     * and returns false otherwise.
     */
    private function assertUnitInScope(int $unitId): bool
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($unitId, $accessibleIds, true)) {
            $this->error('شما مجاز به انجام این عملیات روی این واحد نیستید.', position: 'toast-bottom');

            return false;
        }

        return true;
    }

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

    /**
     * How deep the unit-type walk may descend before it is truncated.
     *
     * The relationship table is shallow — the deepest real walk is 7 rounds
     * from type 1 — so this is a belt-and-braces guard, not a design limit:
     * it turns a future bad import into a short dropdown instead of a hung
     * request. The visited set is what actually makes the walk terminate.
     */
    private const int ALLOWED_UNIT_TYPES_MAX_DEPTH = 10;

    public function getAllowedUnitTypes()
    {
        if ($this->userUnitLevel === 'ministry') {
            return UnitType::where('id', '!=', 1)->get();
        }

        return $this->walkAllowedUnitTypes();
    }

    /**
     * Breadth-first walk over unit_type_relationships.
     *
     * Issue #875: the previous loop replaced the frontier with its own
     * children and had no visited set, so the two seeded self-referencing
     * rows ([22,22] ستادی under ستادی and [17,17] فوریت under فوریت) kept the
     * frontier non-empty forever — one SELECT per round until the request
     * timed out. This mirrors the reasoning behind the UNION (not UNION ALL)
     * in Unit::recursiveDescendantQuery(): the set operator dedupes so a
     * cycle terminates; here the visited set does the same job in PHP.
     *
     * The self-edges stay in the seeder — they encode real intent, so a
     * ستادی manager must still be offered ستادی. That is why every child is
     * *collected* into the result while only the not-yet-visited ones are
     * *queued* for the next round: skipping already-seen ids before collecting
     * would return an empty dropdown for exactly the accounts the rows exist
     * for.
     *
     * @return \Illuminate\Support\Collection<int, UnitType>
     */
    private function walkAllowedUnitTypes(): \Illuminate\Support\Collection
    {
        $visited = [$this->userUnitTypeId => true];
        $frontier = [$this->userUnitTypeId];
        $allowedUnitTypeIds = [];

        for ($depth = 0; $depth < self::ALLOWED_UNIT_TYPES_MAX_DEPTH && $frontier !== []; $depth++) {
            // distinct() bounds the round's own result even if a bad import
            // ever inserts the same edge twice.
            $childUnitTypeIds = UnitTypeRelationship::whereIn('allowed_parent_unit_type_id', $frontier)
                ->distinct()
                ->pluck('child_unit_type_id')
                ->all();

            $allowedUnitTypeIds = array_merge($allowedUnitTypeIds, $childUnitTypeIds);

            $frontier = [];
            foreach ($childUnitTypeIds as $childUnitTypeId) {
                if (! isset($visited[$childUnitTypeId])) {
                    $visited[$childUnitTypeId] = true;
                    $frontier[] = $childUnitTypeId;
                }
            }
        }

        $allowedUnitTypeIds = array_values(array_unique($allowedUnitTypeIds));

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

    public function updatedRegionId($value): void
    {
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
            'name' => 'required|string|max:255',
            'unit_type_id' => 'required|exists:unit_types,id',
            'region_id' => 'nullable|exists:regions,id',
            'parent_id' => $this->unit_type_id == 1 ? 'nullable' : 'required|exists:units,id',
            'can_receive_tickets' => 'boolean',
        ];
        
        if ($this->userUnitLevel === 'ministry') {
            $rules['province_id'] = 'required|exists:regions,id';
        }
        
        $this->validate($rules);

        // #817: scope-check the unit being edited AND the new parent — the
        // parent check below only validates type compatibility, never scope.
        if ($this->editingId && ! $this->assertUnitInScope((int) $this->editingId)) {
            return;
        }

        if ($this->parent_id && ! $this->assertUnitInScope((int) $this->parent_id)) {
            return;
        }

        if ($this->parent_id) {
            $parentUnit = Unit::find($this->parent_id);
            $allowedParentTypeIds = UnitTypeRelationship::where('child_unit_type_id', $this->unit_type_id)
                ->pluck('allowed_parent_unit_type_id')->toArray();
            if (!in_array($parentUnit->unit_type_id, $allowedParentTypeIds)) {
                $this->error('واحد بالادستی انتخاب‌شده مجاز نیست.');
                return;
            }

            // #817: descendant-cycle guard, parity with UnitController::update.
            // editingId is now #[Locked], but the check is still load-bearing:
            // a client can re-parent a unit under its own descendant.
            if ($this->editingId) {
                $forbiddenIds = Unit::descendantIds($this->editingId)->push($this->editingId)->all();
                if (in_array((int) $this->parent_id, array_map('intval', $forbiddenIds), true)) {
                    $this->error('نمی‌توان واحد را زیرمجموعه خودش یا یکی از زیرمجموعه‌هایش قرار داد.');
                    return;
                }
            }
        }
        
        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'unit_type_id' => $this->unit_type_id,
            'region_id' => $this->determineRegionId(),
            'parent_id' => $this->parent_id,
            'can_receive_tickets' => auth()->user()->can('manage_unit_tickets')
                ? $this->can_receive_tickets
                : false,
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

        app(\App\Services\AccessService::class)->clearAllCaches();
        
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
        // #817: no scope check here used to leak any unit's data into the form.
        if (! $this->assertUnitInScope((int) $id)) {
            return;
        }

        $unit = Unit::findOrFail($id);
        $this->editingId = $id;
        $this->name = $unit->name;
        $this->description = $unit->description;
        $this->unit_type_id = $unit->unit_type_id;
        $this->region_id = $unit->region_id;
        $this->parent_id = $unit->parent_id;
        $this->can_receive_tickets = (bool) $unit->can_receive_tickets;
        
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
        // #817: Livewire model hydration applies no scope — check it here.
        if (! $this->assertUnitInScope($unit->id)) {
            return;
        }

        // #817: explicit children guard (parity with UnitController::destroy)
        // instead of relying on the swallowed FK exception below.
        if ($unit->children()->exists()) {
            $this->error('امکان حذف واحدی که زیرمجموعه دارد وجود ندارد.', position: 'toast-bottom');
            return;
        }

        try {
            $unit->delete();
            $this->warning("$unit->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.", position: 'toast-bottom');
        }

        app(\App\Services\AccessService::class)->clearAllCaches();
    }

    public function resetForm(): void
    {
        $this->reset(['name', 'description', 'unit_type_id', 'region_id', 'province_id', 'parent_id', 'editingId', 'can_receive_tickets']);
        $this->loadDropdowns();
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
            ['key' => 'can_receive_tickets', 'label' => 'پذیرش تیکت', 'class' => 'w-5 hidden lg:table-cell'],
        ];
    }

    public function toggleTicketCapability($unitId): void
    {
        if (! auth()->user()->can('manage_unit_tickets')) {
            $this->error('شما مجوز مدیریت تیکت‌ها را ندارید.', position: 'toast-bottom');
            return;
        }

        $unit = Unit::find($unitId);
        if (! $unit) {
            $this->error('واحد یافت نشد.', position: 'toast-bottom');
            return;
        }

        // #817: the permission check above is not a scope check.
        if (! $this->assertUnitInScope($unit->id)) {
            return;
        }

        $unit->can_receive_tickets = ! $unit->can_receive_tickets;
        $unit->saveQuietly();

        $status = $unit->can_receive_tickets ? 'فعال شد' : 'غیرفعال شد';
        $this->success("واحد «{$unit->name}» برای پذیرش تیکت {$status}.", position: 'toast-bottom');

        app(\App\Services\AccessService::class)->clearAllCaches();
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
    <!-- HEADER -->
    <x-header title="مدیریت واحدهای زیر مجموعه" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-help:button section="units" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <!-- TABLE -->
    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <x-ui.icon-button name="واحد جدید" label="واحد جدید" class="btn-success" wire:click="openModalForCreate" responsive icon="o-plus"/>
            <a href="{{ route('units.export') }}"
               class="btn btn-outline btn-sm"
               title="خروجی اکسل واحدهای در دسترس">
                <x-icon name="o-arrow-down-tray" class="w-5 h-5"/>
                <span class="hidden 2xl:inline">خروجی اکسل</span>
            </a>
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
        
        <x-table :headers="$headers" :rows="$units" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[10, 20, 50]">
            @scope('actions', $unit)
                <div class="flex w-1/12">
                    <a href="/units/{{ $unit->id }}/map"
                       aria-label="نقشهٔ واحد"
                       class="btn btn-ghost btn-sm text-primary">
                        <x-icon name="o-map" class="w-5 h-5"/>
                        <span class="hidden 2xl:inline">نقشه</span>
                    </a>
                    <x-ui.icon-button name="ویرایش واحد" icon="o-pencil"
                              wire:click="editUnit({{ $unit->id }})"
                              class="btn-ghost btn-sm text-primary"
                              @click="$wire.modal = true" />
                    <x-ui.icon-button name="حذف واحد" icon="o-trash"
                              wire:click="deleteUnit({{ $unit->id }})"
                              wire:confirm="آیا مطمئن هستید"
                              spinner
                              class="btn-ghost btn-sm text-error" />
                </div>
            @endscope

            @scope('cell_can_receive_tickets', $unit)
                <button wire:click="toggleTicketCapability({{ $unit->id }})" class="btn btn-ghost btn-sm" title="تغییر وضعیت پذیرش تیکت">
                    @if($unit->can_receive_tickets)
                        <x-icon name="o-check-circle" class="w-6 h-6 text-success" />
                        <span class="text-xs text-success hidden lg:inline">فعال</span>
                    @else
                        <x-icon name="o-x-circle" class="w-6 h-6 text-base-content/40" />
                        <span class="text-xs text-base-content/40 hidden lg:inline">غیرفعال</span>
                    @endif
                </button>
            @endscope
        </x-table>
    </x-card>

    <!-- MODAL -->
    <x-modal wire:model="modal" title="{{ $editingId ? 'ویرایش واحد' : 'ثبت واحد جدید' }}" separator persistent>
        <x-form wire:submit.prevent="saveUnit" class="grid grid-cols-2 gap-4">
            <x-input wire:model="name" label="نام واحد" placeholder="نام واحد" required/>
            <x-input wire:model="description" label="توضیحات" placeholder="توضیحات"/>
            
            <x-select wire:model.live="unit_type_id" label="نوع واحد" :options="$unitTypes" option-value="id"
                      option-label="name" required placeholder="انتخاب کنید"/>

            @if($userUnitLevel === 'ministry')
                <x-select wire:model.live="province_id" label="استان" :options="$provinces" option-value="id"
                          option-label="name" placeholder="انتخاب کنید" required/>
            @endif

            @if($userUnitLevel === 'ministry' || $userUnitLevel === 'province')
                <x-select wire:model.live="region_id" label="شهرستان" :options="$counties" option-value="id"
                          option-label="name" placeholder="انتخاب کنید"/>
            @endif

            <x-select wire:model.live="parent_id" label="واحد بالادستی" :options="$parentUnits" option-value="id"
                      option-label="name" :required="$unit_type_id != 1" placeholder="انتخاب کنید"/>

            <div class="col-span-2">
                <x-toggle wire:model="can_receive_tickets" label="پذیرش تیکت"
                          hint="اگر فعال باشد، این واحد می‌تواند تیکت دریافت کند." />
            </div>

            <div class="col-span-2 flex justify-end space-x-2">
                <x-button type="submit" label="{{ $editingId ? 'به‌روزرسانی' : 'ذخیره' }}" icon="o-check"
                          class="btn-primary"/>
                <x-button label="لغو" wire:click="resetForm" @click="$wire.modal = false" icon="o-x-mark"
                          class="btn-outline"/>
            </div>
        </x-form>
    </x-modal>
</div>