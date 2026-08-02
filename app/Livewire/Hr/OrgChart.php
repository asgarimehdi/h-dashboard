<?php

namespace App\Livewire\Hr;

use App\Models\Unit;
use App\Services\AccessService;
use Livewire\Component;
use Illuminate\Support\Facades\Cache;

class OrgChart extends Component
{
    public array $tree = [];
    public array $expanded = [];
    public string $search = '';

    public function mount(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $cacheKey = 'hr:orgchart:' . md5(implode(',', $accessibleIds));
        $units = Cache::remember($cacheKey, 300, function () use ($accessibleIds) {
            return Unit::whereIn('id', $accessibleIds)
                ->withCount('person as personnel_count')
                ->get()
                ->toArray();
        });

        // Build tree
        $byId = [];
        foreach ($units as $u) {
            $byId[$u['id']] = $u + ['children' => []];
        }
        foreach ($byId as $id => &$u) {
            if ($u['parent_id'] && isset($byId[$u['parent_id']])) {
                $byId[$u['parent_id']]['children'][] = &$u;
            }
        }
        unset($u);

        $this->tree = array_values(array_filter($byId, fn ($u) => ! $u['parent_id'] || ! isset($byId[$u['parent_id']])));
        $this->expanded = array_keys($byId);
    }

    public function toggle(string $id): void
    {
        if (in_array($id, $this->expanded)) {
            $this->expanded = array_values(array_diff($this->expanded, [$id]));
        } else {
            $this->expanded[] = $id;
        }
    }

    public function expandAll(): void
    {
        $this->expanded = collect($this->flatten($this->tree))->pluck('id')->all();
    }

    public function collapseAll(): void
    {
        $this->expanded = [];
    }

    protected function flatten(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $out[] = $n;
            if (! empty($n['children'])) {
                $out = array_merge($out, $this->flatten($n['children']));
            }
        }
        return $out;
    }

    public function render()
    {
        return view('livewire.hr.org-chart');
    }
}
