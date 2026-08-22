<?php

namespace App\Livewire\Hr;

use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Mary\Traits\Toast;

class OrgChart extends Component
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

    public function render()
    {
        return view('livewire.hr.org-chart');
    }
}
