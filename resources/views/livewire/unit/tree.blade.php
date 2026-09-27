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
 *                               $unit and $badgeData. Omit for a bare tree.
 *   badgeData        array        Opaque per-unit payload (unit-id => data)
 *                               forwarded verbatim to badgeView. The tree
 *                               never interprets it.
 *   searchPlaceholder string      Search input placeholder.
 *
 * OUT (events):
 *   unit-selected    int          Fired on node click. The parent page listens
 *                               and fills its own detail panel — the tree
 *                               deliberately does not know what "selected"
 *                               means for the page embedding it. The listener
 *                               MUST re-check the id against its own
 *                               accessibleUnitIds(): selectNode is a public
 *                               Livewire method and forwards ANY id (pinned
 *                               by UnitTreeLivewireTest).
 *
 * A new feature (e.g. covered population per unit) needs ZERO tree code:
 *
 *     <livewire:unit.tree badge-view="livewire.population.coverage-badge" />
 *
 * plus a badge view and a `#[On('unit-selected')]` listener. Resist adding
 * further knobs until a second real consumer needs one.
 * =======================================================================
 */
return new class extends Component
{
    /** Levels open on first paint; deeper levels lazy-load on expand. */
    private const INITIAL_EXPAND_LEVELS = 3;

    /** Blade view rendered per node; receives $unit + $badgeData. */
    public ?string $badgeView = null;

    /**
     * Opaque per-unit payload forwarded verbatim to the badge view — the tree
     * does not know or care what it holds (counts, flags, anything keyed by
     * unit id).
     *
     * @var array<int, mixed>
     */
    public array $badgeData = [];

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
     * Units matched by the current search (id => true). Nodes highlight from
     * THIS set instead of re-deriving a match against the raw term, so the
     * highlight and the search can never disagree about what matched.
     *
     * @var array<int, bool>
     */
    public array $matchIds = [];

    /**
     * Scope ids for the current request — resolved once, then memoized.
     *
     * @var array<int>|null
     */
    protected ?array $accessibleIds = null;

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

    public function mount(): void
    {
        $this->loadData();
    }

    /**
     * The caller's accessible unit ids, resolved once per request so node
     * templates and recursive helpers never re-enter the container.
     *
     * @return array<int>
     */
    public function accessibleIds(): array
    {
        if ($this->accessibleIds === null) {
            $this->accessibleIds = app(AccessService::class)->accessibleUnitIds();
        }

        return $this->accessibleIds;
    }

    /**
     * Load the scope-rooted top of the tree, then open the first N levels.
     */
    public function loadData(): void
    {
        $accessibleIds = $this->accessibleIds();

        $this->rootUnits = app(UnitTreeService::class)->roots($accessibleIds);

        if ($this->expanded === []) {
            $this->expanded = $this->expandFirstNLevels($this->rootUnits, self::INITIAL_EXPAND_LEVELS);
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
        $accessibleIds = $this->accessibleIds();

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
        $accessibleIds = $this->accessibleIds();

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
        $accessibleIds = $this->accessibleIds();

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
        $id = (string) $id;

        if (in_array($id, $this->expanded, true)) {
            $this->expanded = array_values(array_diff($this->expanded, [$id]));
        } else {
            $this->expanded[] = $id;
            $this->loadChildren((int) $id);
        }
    }

    /**
     * Open every unit in scope: walk the hierarchy from the roots, loading
     * children as we go so the very next paint is already complete. (The old
     * org chart collected only the root ids here — "باز کردن همه" restored a
     * single level, which is not what the label promises.)
     */
    public function expandAll(): void
    {
        $accessibleIds = $this->accessibleIds();
        $this->expanded = array_values(array_unique(
            $this->collectAllIds($this->rootUnits, $accessibleIds, [])
        ));
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
        $this->matchIds = [];

        $accessibleIds = $this->accessibleIds();
        $matches = app(UnitTreeService::class)->search($this->search, $accessibleIds);

        if ($matches->isEmpty()) {
            return;
        }

        foreach ($matches as $matched) {
            $this->matchIds[(int) $matched->id] = true;
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
     * Depth-first id walk that caches every children query it makes. The
     * visited set is carried down the path, so a parent_id cycle stops at
     * its own repeat instead of walking forever (same guard as
     * UnitTreeService::ancestorChain).
     *
     * @param  iterable<mixed, \App\Models\Unit>  $nodes
     * @param  array<int>  $accessibleIds
     * @param  array<int, bool>  $visited
     * @return array<int, string>
     */
    protected function collectAllIds($nodes, array $accessibleIds, array $visited): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            if (isset($visited[(int) $node->id])) {
                continue;
            }

            $visited[(int) $node->id] = true;
            $ids[] = (string) $node->id;

            $children = app(UnitTreeService::class)->childrenOf((int) $node->id, $accessibleIds);
            $this->lazyChildren[(int) $node->id] = $children;

            if ($children->isNotEmpty()) {
                $ids = array_merge($ids, $this->collectAllIds($children, $accessibleIds, $visited));
            }
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
                    'badgeData' => $badgeData,
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
