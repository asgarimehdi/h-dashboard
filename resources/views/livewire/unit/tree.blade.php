<?php

use App\Models\Unit;
use App\Services\AccessService;
use App\Services\UnitTreeService;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Generic reusable unit tree.
 *
 * Plug-in contract (issue #704):
 *   Inputs:  badgeView (string) — Blade view rendered per node, receives $unit.
 *                      `$unit->personnel_count` is preloaded (see UnitTreeService::withPersonnelCount).
 *            title, searchPlaceholder — page chrome strings.
 *   Outputs: dispatches `unit-selected` (int unit id) on node click.
 *            The parent page listens with #[On('unit-selected')] and fills its own detail panel.
 *
 * A future feature (e.g. covered population per unit) needs zero tree code:
 *   <livewire:unit.tree badge-view="livewire.population.coverage-badge" title="جمعیت تحت پوشش" />
 */
return new class extends Component
{
    /**
     * Blade view rendered per node, receives $unit. This is the plug-in
     * point: a new feature supplies its own badge view and reuses the tree.
     */
    public ?string $badgeView = null;

    public string $title = 'چارت سازمانی';

    public string $searchPlaceholder = 'جستجوی واحد...';

    /** @var array<int, string> */
    public array $expanded = [];

    public string $search = '';

    /** @var Collection<int, Unit> */
    public $rootUnits;

    /** @var array<int, Collection<int, Unit>> Lazily loaded children keyed by parent unit ID. */
    public array $lazyChildren = [];

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

        $this->rootUnits = app(UnitTreeService::class)->roots($accessibleIds);

        if (empty($this->expanded)) {
            // Expand first N levels: load children progressively
            $this->expanded = $this->expandFirstNLevels($this->rootUnits, 3);
        }

        $this->loadExpandedChildren();
    }

    /**
     * Expand root units and their children up to maxLevel, loading children from DB as needed.
     */
    /**
     * @param  Collection<int, Unit>  $nodes
     * @return array<int, string>
     */
    protected function expandFirstNLevels(Collection $nodes, int $maxLevel, int $level = 1): array
    {
        $ids = [];
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $service = app(UnitTreeService::class);

        foreach ($nodes as $node) {
            if ($level <= $maxLevel) {
                $ids[] = (string) $node->id;
            }

            // Load children for nodes that should be expanded
            if ($level < $maxLevel) {
                $children = $service->childrenOf((int) $node->id, $accessibleIds);

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
        $service = app(UnitTreeService::class);

        foreach ($this->expanded as $unitId) {
            $id = (int) $unitId;
            if (! isset($this->lazyChildren[$id])) {
                $this->lazyChildren[$id] = $service->childrenOf($id, $accessibleIds);
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
            $this->lazyChildren[$unitId] = app(UnitTreeService::class)->childrenOf($unitId, $accessibleIds);
        }
    }

    public function updatedSearch(): void
    {
        $this->expanded = [];
        $this->lazyChildren = [];

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $result = app(UnitTreeService::class)->search($this->search, $accessibleIds);

        if ($result['matches']->isNotEmpty()) {
            $this->expanded = array_map('strval', $result['ancestorsToExpand']);
            $this->loadExpandedChildren();
        }
    }

    public function toggle(int|string $id): void
    {
        if (in_array($id, $this->expanded)) {
            $this->expanded = array_values(array_diff($this->expanded, [$id]));
        } else {
            $this->expanded[] = $id;
            $this->loadChildren((int) $id);
        }
    }

    /**
     * Notify the parent page that a unit was picked; it owns the detail panel.
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
     * @param  Collection<int, Unit>  $nodes
     * @return array<int, string>
     */
    protected function collectAllIds(Collection $nodes): array
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
    <x-header title="{{ $title }}" separator progress-indicator>
        <x-slot:actions>
            <x-button icon="o-arrows-pointing-in" label="جمع کردن" wire:click="collapseAll" class="btn-ghost btn-sm" />
            <x-button icon="o-arrows-pointing-out" label="باز کردن همه" wire:click="expandAll" class="btn-ghost btn-sm" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <div class="mb-4">
        <x-input wire:model.live.debounce.300ms="search" placeholder="{{ $searchPlaceholder }}" icon="o-magnifying-glass" clearable />
    </div>

    <x-card shadow>
        <div class="tree-container text-right" dir="rtl">
            @foreach ($rootUnits as $unit)
                @include('livewire.unit.tree-node', [
                    'unit' => $unit,
                    'level' => 0,
                    'isLast' => $loop->last,
                    'badgeView' => $badgeView,
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
