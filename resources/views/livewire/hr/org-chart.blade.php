<?php
use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Mary\Traits\Toast;
return new class extends Component
{
    use Toast;
    public array $expanded = [];

    public string $search = '';

    public $rootUnits;

    public $selectedUnit;

    public $selectedPersonnel;

    public int $selectedPersonnelTotal = 0;

    public int $descendantPersonnelTotal = 0;

    public array $personCounts = [];

    public int $directUserCount = 0;

    public int $descendantUserCount = 0;

    /** Lazily loaded children keyed by parent unit ID. */
    public array $lazyChildren = [];

    /** Units currently loading their children. */
    public array $loadingUnits = [];

    public function mount(): void
    {
        $this->loadData();
    }

    /**
     * Load only root units initially — children loaded lazily on expand.
     */
    public function loadData(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // Root units = accessible units whose parent is NOT accessible (or has no parent).
        // This way a user with access to a child unit (but not its parent) still sees it.
        $this->rootUnits = Unit::whereIn('id', $accessibleIds)
            ->where(function ($q) {
                $q->whereNull('parent_id')
                  ->orWhereNotIn('parent_id', app(AccessService::class)->accessibleUnitIds());
            })
            ->with(['unitType'])
            ->get();

        // Personnel counts for all accessible units
        $personCounts = Person::whereIn('u_id', $accessibleIds)
            ->selectRaw('u_id, count(*) as cnt')
            ->groupBy('u_id')
            ->pluck('cnt', 'u_id');

        $this->personCounts = $personCounts->toArray();

        if (empty($this->expanded)) {
            // Expand first N levels: load children progressively
            $this->expanded = $this->expandFirstNLevels($this->rootUnits, 3);
        }

        // Pre-load children for expanded units
        $this->loadExpandedChildren();
    }

    /**
     * Expand root units and their children up to maxLevel, loading children from DB as needed.
     */
    protected function expandFirstNLevels($nodes, int $maxLevel, int $level = 1): array
    {
        $ids = [];
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        foreach ($nodes as $node) {
            if ($level <= $maxLevel) {
                $ids[] = (string) $node->id;
            }

            // Load children for nodes that should be expanded
            if ($level < $maxLevel) {
                $children = Unit::where('parent_id', $node->id)
                    ->whereIn('id', $accessibleIds)
                    ->get();

                $this->lazyChildren[(int) $node->id] = $children;

                if ($children->isNotEmpty()) {
                    $ids = array_merge($ids, $this->expandFirstNLevels($children, $maxLevel, $level + 1));
                }
            }
        }

        return $ids;
    }

    /**
     * Load children for all currently expanded units that don't have cached children yet.
     */
    public function loadExpandedChildren(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        foreach ($this->expanded as $unitId) {
            $id = (int) $unitId;
            if (! isset($this->lazyChildren[$id])) {
                $children = Unit::where('parent_id', $id)
                    ->whereIn('id', $accessibleIds)
                    ->with(['unitType'])
                    ->get();

                $this->lazyChildren[$id] = $children;
            }
        }
    }

    /**
     * Lazy-load children for a specific unit when expanded.
     */
    public function loadChildren(int $unitId): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($unitId, $accessibleIds)) {
            return;
        }

        if (! isset($this->lazyChildren[$unitId])) {
            $children = Unit::where('parent_id', $unitId)
                ->whereIn('id', $accessibleIds)
                ->with(['unitType'])
                ->get();

            $this->lazyChildren[$unitId] = $children;
        }
    }

    public function updatedSearch(): void
    {
        $this->expanded = [];
        $this->lazyChildren = [];
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (strlen($this->search) > 2) {
            $matchingUnits = Unit::where('name', 'LIKE', "%{$this->search}%")
                ->whereIn('id', $accessibleIds)
                ->with(['parent'])
                ->get();

            foreach ($matchingUnits as $unit) {
                $this->expanded[] = (string) $unit->id;
                $this->expandParents($unit);
            }

            $this->expanded = array_unique($this->expanded);
            $this->loadExpandedChildren();
        }
    }

    /**
     * Walk the full ancestor chain (root → … → direct parent) of a unit and
     * mark every ancestor (plus the unit itself) as expanded, so a deep
     * search match is never hidden behind a collapsed branch.
     *
     * NOTE: We walk the chain in PHP via the `parent` relation rather than
     * Unit::ancestorIds(), because ancestorIds() is intentionally documented
     * and tested as single-level (direct parents only) and is also used by
     * the maps feature.
     */
    protected function expandParents($unit): void
    {
        $this->expanded[] = (string) $unit->id;

        $visited = [(int) $unit->id => true];
        $current = $unit;
        while ($current && $current->parent_id) {
            $parent = $current->parent;
            if (! $parent || isset($visited[(int) $parent->id])) {
                break;
            }
            $this->expanded[] = (string) $parent->id;
            $visited[(int) $parent->id] = true;
            $current = $parent;
        }
    }

    public function toggle($id): void
    {
        if (in_array($id, $this->expanded)) {
            $this->expanded = array_diff($this->expanded, [$id]);
        } else {
            $this->expanded[] = $id;
            $this->loadChildren((int) $id);
        }
    }

    public function selectUnit(int $id): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($id, $accessibleIds)) {
            $this->error('شما مجاز به مشاهده این واحد نیستید.', position: 'toast-bottom');

            return;
        }

        // Query 1: Unit with relations
        $this->selectedUnit = Unit::with(['parent', 'unitType', 'assignedUsers.person'])->find($id);

        // Query 2: Personnel + total count (clone query, no separate count query)
        $personQuery = Person::where('u_id', $id)->with(['semat', 'tahsil', 'estekhdam', 'radif', 'user']);
        $this->selectedPersonnelTotal = (clone $personQuery)->count();
        $this->selectedPersonnel = $personQuery->limit(20)->get();

        // Query 3: Descendant personnel + user counts (descendantIds is cached)
        $descendantIds = Unit::descendantIds($id);
        $this->descendantPersonnelTotal = $descendantIds->isNotEmpty()
            ? Person::whereIn('u_id', $descendantIds)->count()
            : 0;

        // Query 4: Descendant user count via direct JOIN (faster than whereHas)
        $this->descendantUserCount = $descendantIds->isNotEmpty()
            ? DB::table('user_units')
                ->whereIn('unit_id', $descendantIds)
                ->distinct()
                ->count('user_id')
            : 0;

        $this->directUserCount = $this->selectedUnit->assignedUsers->count();
    }

    public function expandAll(): void
    {
        $this->expanded = $this->collectAllIds($this->rootUnits);
        $this->loadExpandedChildren();
    }

    public function collapseAll(): void
    {
        $this->expanded = [];
        $this->lazyChildren = [];
    }

    protected function collectAllIds($nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = (string) $node->id;
        }

        return $ids;
    }

    /**
     * فقط سطوح اول تا N درخت را باز می‌کند (بقیه بسته).
     * سطح ۱ = ریشه، سطح ۲ = فرزندان، سطح ۳ = نوه‌ها و...
     */
    protected function collectFirstNLevels($nodes, int $maxLevel, int $level = 1): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            if ($level <= $maxLevel) {
                $ids[] = (string) $node->id;
            }
        }

        return $ids;
    }
};
?>

<div>
    <x-header title="چارت سازمانی" separator progress-indicator>
        <x-slot:actions>
            <x-button icon="o-arrows-pointing-in" label="جمع کردن" wire:click="collapseAll" class="btn-ghost btn-sm" />
            <x-button icon="o-arrows-pointing-out" label="باز کردن همه" wire:click="expandAll" class="btn-ghost btn-sm" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <div class="mb-4">
        <x-input wire:model.live.debounce.300ms="search" placeholder="جستجوی واحد..." icon="o-magnifying-glass" clearable />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6" dir="rtl">
        <div class="lg:col-span-3">
            <x-card shadow>
                <div class="tree-container text-right" dir="rtl">
                    @foreach ($rootUnits as $unit)
                        @include('livewire.hr.org-node', ['unit' => $unit, 'level' => 0, 'isLast' => $loop->last])
                    @endforeach

                    @if($rootUnits->isEmpty())
                        <div class="text-center p-10 text-gray-400">واحدی یافت نشد.</div>
                    @endif
                </div>
            </x-card>
        </div>

        {{-- جزئیات واحد انتخاب شده --}}
        <div class="lg:col-span-1 sticky top-4">
            @if($selectedUnit)
            <x-card shadow>
                <h3 class="font-bold mb-3">{{ $selectedUnit->name }}</h3>
                <div class="space-y-2 text-sm">
                    <div><span class="font-bold">نوع:</span> {{ $selectedUnit->unitType?->name ?? '---' }}</div>
                    <div><span class="font-bold">والد:</span> {{ $selectedUnit->parent?->name ?? '---' }}</div>
                    <div><span class="font-bold">پرسنل مستقیم:</span> {{ $selectedPersonnelTotal }} نفر <span class="text-xs opacity-60">(زیرمجموعه: {{ $descendantPersonnelTotal }} نفر)</span></div>
                    <div><span class="font-bold">کاربران مستقیم:</span> {{ $directUserCount }} نفر <span class="text-xs opacity-60">(زیرمجموعه: {{ $descendantUserCount }} نفر)</span></div>
                </div>
                <div class="mt-4">
                    <h4 class="font-bold text-xs mb-2">پرسنل این واحد (۲۰ نفر اول):</h4>
                    @forelse($selectedPersonnel as $p)
                    <div class="flex items-center gap-2 p-2 bg-base-200/50 rounded mb-1">
                        <x-icon name="o-user" class="w-4 h-4 {{ $p->user ? 'text-success' : 'text-error' }}" />
                        <span class="text-xs">{{ $p->f_name }} {{ $p->l_name }}</span>
                        <span class="badge badge-xs badge-ghost">{{ $p->semat?->name ?? '---' }}</span>
                    </div>
                    @empty
                    <p class="text-xs opacity-50">پرسنلی ندارد</p>
                    @endforelse
                </div>
            </x-card>
            @else
            <x-card shadow>
                <p class="text-sm opacity-50 text-center py-8">یک واحد را انتخاب کنید</p>
            </x-card>
            @endif
        </div>
    </div>

    <style>
        .tree-line-branch {
            position: absolute;
            right: -20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: #040505;
        }
        .tree-line-leaf {
            position: absolute;
            right: -20px;
            top: 24px;
            width: 20px;
            height: 2px;
            background-color: #040505;
        }
        .tree-node-dot {
            width: 8px;
            height: 8px;
            background-color: #040505;
            border-radius: 50%;
            position: absolute;
            right: -23px;
            top: 21px;
        }
    </style>
</div>
