<?php

namespace App\Http\Controllers\Api;

use App\Exports\UnitsExport;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Unit;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UnitsExportController extends Controller
{
    /**
     * @return BinaryFileResponse
     */
    public function export(UnitScopedRequest $request)
    {
        $accessibleIds = $request->accessibleIds();

        $units = Unit::query()
            ->with('unitType')
            ->when($accessibleIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($accessibleIds !== [], fn ($query) => $query->whereIn('id', $accessibleIds))
            ->get();

        // Ancestors above the caller's scope contribute names only to the
        // breadcrumb — the tree page already renders those, so nothing new
        // leaks — which is why this map covers every unit, not just the scope.
        $map = $this->hierarchyMap();

        $hierarchy = $this->buildHierarchy($units, $map);
        $ordered = $this->orderDepthFirst($units);

        $filename = 'units-'.now()->format('Ymd-His');

        return Excel::download(new UnitsExport($ordered, $hierarchy), "{$filename}.xlsx");
    }

    /**
     * Every unit's id → name/parent, loaded in one query so no row mapping ever
     * walks back to the database.
     *
     * @return array<int, array{name: string, parent_id: int|null}>
     */
    protected function hierarchyMap(): array
    {
        $map = [];

        foreach (Unit::query()->get(['id', 'name', 'parent_id']) as $unit) {
            $map[$unit->id] = [
                'name' => $unit->name,
                'parent_id' => $unit->parent_id,
            ];
        }

        return $map;
    }

    /**
     * Breadcrumb, depth and parent name for every exported unit.
     *
     * `parent_id` is user-editable, so a bad edit can form a cycle. The visited
     * set stops the upward walk instead of looping forever.
     *
     * @param  Collection<int, Unit>  $units
     * @param  array<int, array{name: string, parent_id: int|null}>  $map
     * @return array<int, array{path: string, depth: int, parent_name: string}>
     */
    protected function buildHierarchy(Collection $units, array $map): array
    {
        $hierarchy = [];

        foreach ($units as $unit) {
            $chain = [];
            $visited = [];
            $parentId = $unit->parent_id;

            while ($parentId !== null && isset($map[$parentId]) && ! isset($visited[$parentId])) {
                $visited[$parentId] = true;
                array_unshift($chain, $map[$parentId]['name']);
                $parentId = $map[$parentId]['parent_id'];
            }

            $hierarchy[$unit->id] = [
                'path' => implode(' > ', array_merge($chain, [$unit->name])),
                'depth' => count($chain),
                'parent_name' => $chain === [] ? '' : $chain[count($chain) - 1],
            ];
        }

        return $hierarchy;
    }

    /**
     * Sort parents before children so the sheet reads top-down. A unit whose
     * parent sits outside the exported set is a root, which also anchors a
     * scoped subtree at the caller's own unit.
     *
     * @param  Collection<int, Unit>  $units
     * @return Collection<int, Unit>
     */
    protected function orderDepthFirst(Collection $units): Collection
    {
        $byId = $units->keyBy('id');

        /** @var array<int, list<Unit>> $children */
        $children = [];
        $roots = [];

        foreach ($units as $unit) {
            $parentId = $unit->parent_id;
            if ($parentId !== null && $byId->has($parentId)) {
                $children[$parentId][] = $unit;
            } else {
                $roots[] = $unit;
            }
        }

        // Siblings alphabetically, so a unit's row sits next to its same-named
        // neighbours rather than in arbitrary id order.
        $byName = fn (Unit $a, Unit $b) => strcmp((string) $a->name, (string) $b->name);

        usort($roots, $byName);
        foreach ($children as &$siblings) {
            usort($siblings, $byName);
        }
        unset($siblings);

        // A cycle leaves units with no reachable root. Append them by ascending
        // id so nothing is dropped from the file.
        $seen = [];
        foreach ($this->walk($roots, $children) as $unit) {
            $seen[$unit->id] = true;
        }
        $orphans = $units
            ->reject(fn (Unit $unit) => isset($seen[$unit->id]))
            ->sortBy('id')
            ->all();

        $ordered = $this->walk($roots, $children);

        return $ordered->concat(collect($orphans))->values();
    }

    /**
     * @param  array<int, Unit>  $roots
     * @param  array<int, list<Unit>>  $children
     * @return Collection<int, Unit>
     */
    protected function walk(array $roots, array $children): Collection
    {
        $ordered = collect();
        $stack = $roots;

        while ($stack !== []) {
            $unit = array_shift($stack);
            $ordered->push($unit);

            foreach (array_reverse($children[$unit->id] ?? []) as $child) {
                array_unshift($stack, $child);
            }
        }

        return $ordered;
    }
}
