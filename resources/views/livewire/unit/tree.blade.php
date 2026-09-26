<?php

use App\Services\AccessService;
use App\Services\UnitTreeService;
use Livewire\Component;

/**
 * Reusable, access-scoped unit tree (#704).
 *
 * Reuse contract — the ONLY surface a parent page may depend on:
 *   inputs  : badgeView    — Blade view rendered per node; receives $unit + $badgeData
 *             badgeData    — array keyed by unit id, data the badge view needs
 *             searchPlaceholder — placeholder for the search input
 *   outputs : `unit-selected` event (named arg `id`, int) — fired on node click;
 *             the parent listens with #[On('unit-selected')] and fills its own panel.
 * Everything else ($rootUnits, $expanded, $lazyChildren, $search) is internal
 * and may change without notice.
 *
 * Usage: <livewire:unit.tree badge-view="livewire.x.coverage-badge" :badge-data="$counts" />
 * The tree owns ONLY tree mechanics: roots, lazy children, expanded state,
 * search, toggle / expand-all / collapse-all. It never renders page chrome.
 */
return new class extends Component
{
    /** @var array<string> Unit ids (as strings) that are currently open. */
    public array $expanded = [];

    public string $search = '';

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Unit> Root units (set in mount()). */
    public $rootUnits;

    /** @var array<int, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Unit>> Lazily loaded children keyed by parent unit ID. */
    public array $lazyChildren = [];

    /** Blade view rendered inside every node, receives $unit + $badgeData. */
    public string $badgeView = '';

    /** @var array<int, int> Data for $badgeView keyed by unit id (e.g. personnel counts). */
    public array $badgeData = [];

    public string $searchPlaceholder = 'جستجوی واحد...';

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
        $tree = app(UnitTreeService::class);

        // Root units = accessible units whose parent is NOT accessible (or has no parent).
        // This way a user with access to a child unit (but not its parent) still sees it.
        $this->rootUnits = $tree->roots($accessibleIds)->get();

        if (empty($this->expanded)) {
            // Expand first N levels: load children progressively
            $this->expanded = $this->expandFirstNLevels($this->rootUnits, 3);
        }

        // Pre-load children for expanded units
        $this->loadExpandedChildren();
    }

    /**
     * Expand root units and their children up to maxLevel, loading children from DB as needed.
     *
     * Levels are preloaded ONE QUERY PER LEVEL, never one per node — the
     * original per-node loop was an N+1 over every unit on the first three
     * levels. The resulting `$expanded` / `$lazyChildren` state is identical:
     * ids for levels 1..maxLevel, children cached for levels 1..maxLevel-1.
     *
     * @param  iterable<int, \App\Models\Unit>  $nodes
     * @return array<string>
     */
    protected function expandFirstNLevels($nodes, int $maxLevel, int $level = 1): array
    {
        $ids = [];
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $tree = app(UnitTreeService::class);

        $levelNodes = collect($nodes);

        while ($level <= $maxLevel && $levelNodes->isNotEmpty()) {
            foreach ($levelNodes as $node) {
                $ids[] = (string) $node->id;
            }

            if ($level >= $maxLevel) {
                break;
            }

            $children = $tree->childrenOfMany(
                $levelNodes->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $accessibleIds
            )->get();

            // Every node of this level gets its children entry — including the
            // empty ones, so the node template never has to re-query for them.
            foreach ($levelNodes as $node) {
                $this->lazyChildren[(int) $node->id] = $children
                    ->where('parent_id', $node->id)
                    ->values();
            }

            $levelNodes = $children;
            $level++;
        }

        return $ids;
    }

    /**
     * Load children for all currently expanded units that don't have cached children yet.
     *
     * One query for the whole batch — a per-unit query here scales with the
     * number of expanded nodes (the deepest level is always the widest).
     */
    public function loadExpandedChildren(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $missing = [];
        foreach ($this->expanded as $unitId) {
            $id = (int) $unitId;
            if (! isset($this->lazyChildren[$id])) {
                $missing[$id] = $id;
            }
        }

        if ($missing === []) {
            return;
        }

        $children = app(UnitTreeService::class)
            ->childrenOfMany(array_values($missing), $accessibleIds)
            ->get();

        foreach ($missing as $id) {
            $this->lazyChildren[$id] = $children->where('parent_id', $id)->values();
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
            $this->lazyChildren[$unitId] = app(UnitTreeService::class)->childrenOf($unitId, $accessibleIds)->get();
        }
    }

    public function updatedSearch(): void
    {
        $this->expanded = [];
        $this->lazyChildren = [];
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (strlen($this->search) > 2) {
            $matchingUnits = app(UnitTreeService::class)->search($this->search, $accessibleIds)->get();

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
     *
     * @param  \App\Models\Unit  $unit
     */
    protected function expandParents($unit): void
    {
        $this->expanded[] = (string) $unit->id;

        $visited = [(int) $unit->id => true];
        $current = $unit;
        // $current can never be null here: the guard below breaks before it
        // would ever be assigned a null parent.
        while ($current->parent_id) {
            $parent = $current->parent;
            if (! $parent || isset($visited[(int) $parent->id])) {
                break;
            }
            $this->expanded[] = (string) $parent->id;
            $visited[(int) $parent->id] = true;
            $current = $parent;
        }
    }

    /**
     * @param  int|string  $id  unit id (Livewire passes whatever the template gave it)
     */
    public function toggle($id): void
    {
        if (in_array($id, $this->expanded)) {
            $this->expanded = array_diff($this->expanded, [$id]);
        } else {
            $this->expanded[] = $id;
            $this->loadChildren((int) $id);
        }
    }

    /**
     * A node was clicked — tell the parent which unit it was. The tree has no
     * idea what "selected" means; the parent owns access checks + its panel.
     */
    public function selectUnit(int $id): void
    {
        $this->dispatch('unit-selected', id: $id);
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

    /**
     * @param  iterable<int, \App\Models\Unit>  $nodes
     * @return array<string>
     */
    protected function collectAllIds($nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = (string) $node->id;
        }

        return $ids;
    }
};
?>

<div>
    {{-- Tree controls live with the tree so every page that reuses it gets them (#704) --}}
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <div class="min-w-56 flex-1">
            <x-input wire:model.live.debounce.300ms="search" :placeholder="$searchPlaceholder" icon="o-magnifying-glass" clearable />
        </div>
        <x-button icon="o-arrows-pointing-in" label="جمع کردن" wire:click="collapseAll" class="btn-ghost btn-sm" />
        <x-button icon="o-arrows-pointing-out" label="باز کردن همه" wire:click="expandAll" class="btn-ghost btn-sm" />
    </div>

    <x-card shadow>
        <div class="tree-container text-right" dir="rtl">
            @foreach ($rootUnits as $unit)
                @include('livewire.unit.tree-node', ['unit' => $unit, 'level' => 0, 'isLast' => $loop->last])
            @endforeach

            @if($rootUnits->isEmpty())
                <div class="text-center p-10 text-gray-400">واحدی یافت نشد.</div>
            @endif
        </div>
    </x-card>

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
