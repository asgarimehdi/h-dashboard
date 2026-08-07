<?php

namespace App\Livewire\Hr;

use App\Models\Unit;
use App\Models\Person;
use App\Services\AccessService;
use Livewire\Component;

class OrgChart extends Component
{
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
            ->get();

        // محاسبه تعداد پرسنل برای تمام واحدها (شامل فرزندان)
        $personCounts = Person::whereIn('u_id', $accessibleIds)
            ->selectRaw('u_id, count(*) as cnt')
            ->groupBy('u_id')
            ->pluck('cnt', 'u_id');

        $this->personCounts = $personCounts->toArray();

        if (empty($this->expanded)) {
            // پیش‌فرض: فقط تا سطح معاونت‌های بهداشت باز باشد
            // وزارت(1) → دانشگاه(2) → معاونت(3) → [شبکه‌ها و مراکز بسته]
            $this->expanded = $this->collectFirstNLevels($this->rootUnits, 3);
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
            $ancestorIds = Unit::ancestorIds([$unit->id]);
            foreach ($ancestorIds as $id) {
                $this->expanded[] = (string) $id;
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

    public function selectUnit(int $id): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($id, $accessibleIds)) {
            $this->error('شما مجاز به مشاهده این واحد نیستید.', position: 'toast-bottom');
            return;
        }

        $this->selectedUnit = Unit::with(['parent', 'unitType', 'assignedUsers.person'])->find($id);
        $this->selectedPersonnel = Person::where('u_id', $id)->with(['semat', 'tahsil', 'estekhdam', 'radif', 'user'])->limit(20)->get();
        $this->selectedPersonnelTotal = Person::where('u_id', $id)->count();
        $descendantIds = Unit::descendantIds($id);
        $this->descendantPersonnelTotal = Person::whereIn('u_id', $descendantIds)->count();
        $this->directUserCount = $this->selectedUnit->assignedUsers->count();
        $this->descendantUserCount = \App\Models\User::whereHas('units', fn($q) => $q->whereIn('unit_id', $descendantIds))->count();
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
            if ($node->childrenRecursive->count() && $level < $maxLevel) {
                $ids = array_merge($ids, $this->collectFirstNLevels($node->childrenRecursive, $maxLevel, $level + 1));
            }
        }
        return $ids;
    }

    public function render()
    {
        return view('livewire.hr.org-chart');
    }
}
