<?php

use App\Services\AccessService;
use App\Services\UnitTreeService;
use Livewire\Component;

/**
 * Reusable unit tree (issue #704 — Plan 30).
 *
 * ============================ REUSE CONTRACT ============================
 * Owns ONLY tree mechanics: scope-rooted roots, lazy children, expanded
 * state, search, and expand/collapse-all. It knows nothing about any
 * particular kind of data.
 *
 * IN  (props):
 *   badgeView        string|null  Blade view rendered per node; receives
 *                               $unit and $personCounts. Omit for a bare tree.
 *   personCounts     array        unit-id => count, forwarded to badgeView.
 *   title            string       Page header text.
 *   searchPlaceholder string      Search input placeholder.
 *
 * OUT (events):
 *   unit-selected    int          Fired on node click. The parent page listens
 *                               and fills its own detail panel — the tree
 *                               deliberately does not know what "selected"
 *                               means for the page embedding it.
 *
 * A new feature (e.g. covered population per unit) needs ZERO tree code:
 *
 *     <livewire:unit.tree badge-view="livewire.population.coverage-badge"
 *                         title="جمعیت تحت پوشش" />
 *
 * plus a badge view and a `#[On('unit-selected')]` listener. Resist adding
 * further knobs until a second real consumer needs one.
 * =======================================================================
 */
return new class extends Component
{
    /** Blade view rendered per node; receives $unit + $personCounts. */
    public ?string $badgeView = null;

    /**
     * unit-id => count, forwarded verbatim to the badge view.
     *
     * @var array<int, int>
     */
    public array $personCounts = [];

    /** Page header text. */
    public string $title = 'درخت واحدها';

    /** Search input placeholder. */
    public string $searchPlaceholder = 'جستجوی واحد...';

    /**
     * Unit IDs expanded in the tree, as strings (wire:click payloads are strings).
     *
     * @var array<int, string>
     */
    public array $expanded = [];

    public string $search = '';

    /**
     * Scope-rooted top of the tree.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\Unit>
     */
    public $rootUnits;

    /**
     * Lazily loaded children, keyed by parent unit ID.
     *
     * @var array<int, \Illuminate\Support\Collection<int, \App\Models\Unit>>
     */
    public array $lazyChildren = [];

    /** How many levels are open on first paint. */
    public int $initialExpandLevels = 3;

    public function mount(): void
    {
        $this->loadData();
    }

    /**
     * Load the scope-rooted top of the tree, then open the first N levels.
     */
    public function loadData(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $this->rootUnits = app(UnitTreeService::class)->roots($accessibleIds);

        if ($this->expanded === []) {
            $this->expanded = $this->expandFirstNLevels($this->rootUnits, $this->initialExpandLevels);
        }

        $this->loadExpandedChildren();
    }

    /**
     * Expand up to maxLevel, loading children from the DB as needed.
     *
     * @param  iterable<mixed, \App\Models\Unit>  $nodes
     * @return array<int, string>
     */
    protected function expandFirstNLevels($nodes, int $maxLevel, int $level = 1): array
    {
        $ids = [];
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        foreach ($nodes as $node) {
            if ($level <= $maxLevel) {
                $ids[] = (string) $node->id;
            }

            if ($level < $maxLevel) {
                $children = app(UnitTreeService::class)->childrenOf((int) $node->id, $accessibleIds);

                $this->lazyChildren[(int) $node->id] = $children;

                if ($children->isNotEmpty()) {
                    $ids = array_merge($ids, $this->expandFirstNLevels($children, $maxLevel, $level + 1));
                }
            }
        }

        return $ids;
    }

    /**
     * Load children for every expanded unit not yet in the lazy cache.
     */
    public function loadExpandedChildren(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        foreach ($this->expanded as $unitId) {
            $id = (int) $unitId;

            if (! isset($this->lazyChildren[$id])) {
                $this->lazyChildren[$id] = app(UnitTreeService::class)->childrenOf($id, $accessibleIds);
            }
        }
    }

    /**
     * Lazy-load the children of one unit. Out-of-scope units are ignored.
     */
    public function loadChildren(int $unitId): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($unitId, $accessibleIds, true)) {
            return;
        }

        if (! isset($this->lazyChildren[$unitId])) {
            $this->lazyChildren[$unitId] = app(UnitTreeService::class)->childrenOf($unitId, $accessibleIds);
        }
    }

    /**
     * A node was clicked. Hand the id to the embedding page and let it decide
     * what to show — the tree has no opinion about the payload.
     */
    public function selectNode(int $id): void
    {
        $this->dispatch('unit-selected', unitId: $id);
    }

    /**
     * @param  int|string  $id  Unit id; wire:click delivers it unquoted, so both shapes arrive.
     */
    public function toggle($id): void
    {
        if (in_array($id, $this->expanded)) {
            $this->expanded = array_values(array_diff($this->expanded, [$id]));
        } else {
            $this->expanded[] = $id;
            $this->loadChildren((int) $id);
        }
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
     * Reset the tree, then reveal every match together with its ancestor chain
     * so a deep hit is never hidden behind a collapsed branch.
     */
    public function updatedSearch(): void
    {
        $this->expanded = [];
        $this->lazyChildren = [];

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $matches = app(UnitTreeService::class)->search($this->search, $accessibleIds);

        if ($matches->isEmpty()) {
            return;
        }

        $tree = app(UnitTreeService::class);

        foreach ($matches as $unit) {
            $this->expanded[] = (string) $unit->id;

            foreach ($tree->ancestorChain($unit, $accessibleIds) as $ancestor) {
                $this->expanded[] = (string) $ancestor->id;
            }
        }

        $this->expanded = array_values(array_unique($this->expanded));
        $this->loadExpandedChildren();
    }

    /**
     * @param  iterable<mixed, \App\Models\Unit>  $nodes
     * @return array<int, string>
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
    {{-- Search and expand/collapse drive the TREE's state, so they live inside
         the tree component rather than on the embedding page — a parent cannot
         call into a child's state without a ref. --}}
    <div class="mb-4 flex items-center gap-2">
        <x-input
            wire:model.live.debounce.300ms="search"
            :placeholder="$searchPlaceholder"
            icon="o-magnifying-glass"
            clearable
        />
        <x-button
            wire:click="collapseAll"
            icon="o-arrows-pointing-in"
            label="جمع کردن"
            class="btn-ghost btn-sm"
        />
        <x-button
            wire:click="expandAll"
            icon="o-arrows-pointing-out"
            label="باز کردن همه"
            class="btn-ghost btn-sm"
        />
    </div>

    <x-card shadow>
        <div class="tree-container text-right" dir="rtl">
            @foreach ($rootUnits as $unit)
                @include('livewire.unit.tree-node', [
                    'unit' => $unit,
                    'level' => 0,
                    'isLast' => $loop->last,
                    'badgeView' => $badgeView,
                    'personCounts' => $personCounts,
                ])
            @endforeach

            @if ($rootUnits->isEmpty())
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
