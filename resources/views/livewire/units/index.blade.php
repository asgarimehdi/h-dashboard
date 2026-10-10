<?php

use App\Models\Region;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\UnitTypeRelationship;
use App\Services\AccessService;
use App\Services\UnitDeletionImpact;
use App\Services\UnitDeletionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    public $name;

    public $description;

    public $unit_type_id;

    public $region_id;

    public $province_id;

    public $parent_id;

    public bool $can_receive_tickets = false;

    #[Locked]
    public ?int $editingId = null;

    public string $search = '';

    public int $perPage = 20;

    public bool $modal = false;

    public bool $showHelpModal = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // #914: `sortBy` is client-settable, so only real columns are sortable.
    // The `*_name` entries are the `withAggregate` aliases loaded in units().
    private const SORTABLE_COLUMNS = ['id', 'name', 'description', 'unit_type_name', 'region_name', 'parent_name', 'can_receive_tickets', 'is_active'];

    public $unitTypes;

    public $provinces;

    public $counties;

    public $parentUnits;

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

        if (! $unit) {
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
            $query->where('name', 'LIKE', '%'.$this->search.'%');
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
        if (! $this->unit_type_id) {
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
            if (! in_array($parentUnit->unit_type_id, $allowedParentTypeIds)) {
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
            $this->error('خطا ', position: 'toast-bottom');
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

        // #949 steps 2–4: refuse, and say what is actually in the way.
        // Six of the eight inbound FKs on `units` are not RESTRICT, so a delete
        // here used to silently NULL `persons.u_id`, NULL `todos.unit_id` and
        // CASCADE away `user_units` rows — orphaning 155 of 318 accounts from
        // the org tree while reporting a green toast. The counts, not this
        // component, decide what blocks: `UnitDeletionService` is shared with
        // `UnitController::destroy()` so the two surfaces cannot disagree.
        $impact = app(UnitDeletionService::class)->impact($unit);

        if (! $impact->isClear()) {
            $this->error($this->describeImpact($impact), position: 'toast-bottom');

            return;
        }

        try {
            $unit->delete();
            $this->warning("$unit->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            // Honest scope of this catch: it covers the two RESTRICT FKs only —
            // `units_parent_fk` and `tickets_unit_fk` — in the window between
            // the count above and this delete. The four non-RESTRICT tables do
            // NOT raise here: a `persons` / `todos` / `user_units` row landing
            // in that same window still produces a successful delete with the
            // green toast above. READ COMMITTED does not close that race, and
            // this issue does not claim it does. Closing it needs a decision
            // about serialising unit deletes; until then the counts narrow the
            // window, they do not remove it.
            $this->error('حذف واحد انجام نشد؛ هم‌زمان تغییری در اطلاعات آن رخ داد. دوباره تلاش کنید.', position: 'toast-bottom');
        }

        app(\App\Services\AccessService::class)->clearAllCaches();
    }

    /**
     * #949 step 4 — the refusal names each blocking table and its count, in the
     * Persian the rest of this page is written in. The old text
     * («…زیرا در جدول دیگری استفاده شده است») described only the two RESTRICT
     * rows and implied the six cascade tables were protected — which is exactly
     * the belief that made the delete destructive.
     */
    private function describeImpact(UnitDeletionImpact $impact): string
    {
        $labels = [
            'children' => 'زیرمجموعه',
            'tickets' => 'تیکت',
            'persons' => 'پرسنل',
            'hardware' => 'سخت‌افزار',
            'todos' => 'وظیفه',
            'accounts' => 'حساب کاربری',
        ];

        $blocking = [];

        foreach ($impact->blockers() as $key => $count) {
            // `?? $key` because the keys live on `UnitDeletionImpact` and the
            // labels here; nothing links the two at compile time, and an
            // undefined index would render «» 12 «» instead of a table name.
            $blocking[] = $count.' '.($labels[$key] ?? $key);
        }

        return 'امکان حذف این واحد وجود ندارد؛ این واحد هنوز دارد: '
            .implode('، ', $blocking)
            .'. ابتدا آن‌ها را به واحد دیگری منتقل کنید یا واحد را غیرفعال کنید.';
    }

    /**
     * #949 step 1 — deactivation is the primary way to retire a unit.
     *
     * `units.is_active` already existed, already gated ticket routing
     * (`App\Rules\TicketTargetUnit`) and already had a `غیرفعال` label in the
     * export, but nothing in `app/` or `resources/` ever wrote `false`. The
     * only writer was `ZabbixDevice`. So the destructive option was the only
     * option on offer, and deleting a unit is a six-table cascade.
     *
     * This is the missing half of that feature: retire a unit and **no row is
     * touched**. `persons.u_id` stays set (a delete sets it to NULL),
     * `user_units` stays (a delete cascades it), and the unit itself stays
     * queryable. That is the half of #949's harm a delete causes and
     * deactivation does not.
     *
     * What deactivation DOES change — stated plainly, because it is easy to
     * mistake for the above — is the caller's *ancestors'* view.
     * `Unit::recursiveDescendantQuery()` filters the recursive step with
     * `WHERE u.is_active = true`, so a retired unit leaves the subtree of
     * every operator above it, and so do the still-active descendants below
     * it: retiring C in P→C→D→E moves a P-level operator's scope from
     * {P,C,D,E} to {P}. Nothing is destroyed, but from above the unit is
     * invisible — including in this list, which is how the operator would
     * otherwise have re-activated it.
     *
     * That asymmetry is deliberate and pre-existing: that filter is pinned by
     * `UnitModelTest::test_descendant_ids_still_terminates_on_an_inactive_node_in_a_cycle`
     * ("the inactive unit is EXCLUDED from the result, not included"), and
     * `TicketTargetUnit`, the users unit picker and the ticket pickers all
     * agree with it. Before this method existed nothing ever wrote `false`, so
     * the filter was inert. Whether retiring a unit should also hide it from
     * its ancestors is a product decision, not an implementation detail — see
     * the `wire:confirm` text for the decision this implementation makes
     * visible to the operator, and issue #949 for the open question.
     */
    public function setInactive(Unit $unit, bool $active): void
    {
        // #817 parity with `deleteUnit`: a public Livewire method hydrates its
        // model from a client-supplied id, so the scope check is not optional.
        if (! $this->assertUnitInScope($unit->id)) {
            return;
        }

        $unit->is_active = $active;
        $unit->saveQuietly();

        $status = $active ? 'فعال' : 'غیرفعال';
        $this->success("واحد «{$unit->name}» {$status} شد.", position: 'toast-bottom');

        // `is_active` is a predicate of `recursiveDescendantQuery()`, so a
        // retired unit leaves its parent's subtree — a stale `unit_hierarchy`
        // would keep serving the old tree to every caller.
        //
        // `saveQuietly()` skips the `Unit::updated` hook that invalidates
        // `report_units` and `hr_stats` as well. That is safe today because
        // every one of those keys hashes `accessibleUnitIds()` into its own
        // key, so a scope change self-invalidates; it matches
        // `toggleTicketCapability()` below. Do not "fix" this by dropping
        // `saveQuietly()` without re-checking those keys.
        app(AccessService::class)->clearAllCaches();
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
            ['key' => 'is_active', 'label' => 'وضعیت', 'class' => 'w-5 hidden lg:table-cell'],
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
                              wire:confirm="حذف واحد تنها وقتی انجام می‌شود که هیچ پرسنل، سخت‌افزار، وظیفه، تیکت یا حساب کاربری‌ای به آن وابسته نباشد؛ در غیر این صورت حذف رد می‌شود و دلیل آن اعلام می‌گردد. اگر قصد بازنشستگی واحد را دارید، به‌جای حذف آن را غیرفعال کنید. مطمئن هستید؟"
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

            {{-- #949 step 1: the non-destructive way to retire a unit. --}}
            @scope('cell_is_active', $unit)
                <button wire:click="setInactive({{ $unit->id }}, {{ $unit->is_active ? 'false' : 'true' }})"
                        wire:confirm="{{ $unit->is_active
                            ? 'این واحد و تمام زیرمجموعه‌های فعال آن از دید مدیران بالادستی حذف می‌شود. اطلاعات آن حفظ می‌شود و هر زمان می‌توانید دوباره آن را فعال کنید. مطمئن هستید؟'
                            : 'این واحد دوباره در دسترس مدیران بالادستی و در مسیرهای تیکت قرار می‌گیرد. مطمئن هستید؟' }}"
                        class="btn btn-ghost btn-sm"
                        title="{{ $unit->is_active ? 'غیرفعال کردن واحد' : 'فعال کردن واحد' }}">
                    @if($unit->is_active)
                        <x-icon name="o-check-circle" class="w-6 h-6 text-success" />
                        <span class="text-xs text-success hidden lg:inline">فعال</span>
                    @else
                        <x-icon name="o-x-circle" class="w-6 h-6 text-warning" />
                        <span class="text-xs text-warning hidden lg:inline">غیرفعال</span>
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