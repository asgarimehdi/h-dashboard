<?php

namespace App\Livewire\Hr;

use App\Models\Unit;
use App\Services\AccessService;
use Livewire\Component;

class OrgChart extends Component
{
    public array $expanded = [];
    public string $search = '';
    public $rootUnits;

    public function mount(): void
    {
        $this->loadData();
    }

    /**
     * الهام‌گرفته از درختواره واحدها (/units/chart) که خیلی سریع‌تر کار می‌کند:
     * - eager loading با childrenRecursive (یک کوئری واحد + رابطه recursive)
     * - بدون ساخت دستی tree از flat array
     */
    public function loadData(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $this->rootUnits = Unit::whereNull('parent_id')
            ->whereIn('id', $accessibleIds)
            ->with(['childrenRecursive', 'unitType'])
            ->withCount('person as personnel_count')
            ->get();

        if (empty($this->expanded)) {
            $this->expanded = $this->collectAllIds($this->rootUnits);
        }
    }

    public function updatedSearch(): void
    {
        $this->expanded = [];
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (strlen($this->search) > 2) {
            $matchingUnits = Unit::where('name', 'LIKE', "%{$this->search}%")
                ->whereIn('id', $accessibleIds)
                ->get();

            foreach ($matchingUnits as $unit) {
                $this->expandParents($unit);
            }

            $this->expanded = array_unique($this->expanded);
        }
    }

    protected function expandParents($unit): void
    {
        if ($unit->parent_id) {
            $this->expanded[] = (string) $unit->parent_id;
            $parent = Unit::find($unit->parent_id);
            if ($parent) {
                $this->expandParents($parent);
            }
        }
    }

    public function toggle($id): void
    {
        if (in_array($id, $this->expanded)) {
            $this->expanded = array_diff($this->expanded, [$id]);
        } else {
            $this->expanded[] = $id;
        }
    }

    public function expandAll(): void
    {
        $this->expanded = $this->collectAllIds($this->rootUnits);
    }

    public function collapseAll(): void
    {
        $this->expanded = [];
    }

    protected function collectAllIds($nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = (string) $node->id;
            if ($node->childrenRecursive->count()) {
                $ids = array_merge($ids, $this->collectAllIds($node->childrenRecursive));
            }
        }
        return $ids;
    }

    public function render()
    {
        return view('livewire.hr.org-chart');
    }
}
