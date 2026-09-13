# Plan 009: Fix org-chart N+1 queries

- **Category:** perf
- **Effort:** M
- **Risk:** MED
- **Priority:** P2
- **Depends on:** none
- **Status:** proposed

## ⚠️ TL;DR فارسی

**مشکل:** org-chart ۵۰-۱۰۰+ کوئری با ۳ سطح باز.

**⚠️ تأیید شد:** هیچ orderBy‌ای وجود نداره! ترتیب بر اساس insertion order پستگره.
**راه‌حل:** اضافه کردن `->orderBy('name')` به `Unit::children()` relation (canonical) + هر ۴ کوئری مستقیم در org-chart.

**ریسک:** 🟡 متوسط


## Problem

The org-chart page fires individual DB queries for every visible node at every level, causing O(n) queries where n = total expanded nodes. Three distinct N+1 hotspots exist:

### 1. `expandFirstNLevels` — per-node child queries (org-chart.blade.php:79-104)

```php
foreach ($nodes as $node) {
    // ...
    if ($level < $maxLevel) {
        $children = Unit::where('parent_id', $node->id)  // ← 1 query per node
            ->whereIn('id', $accessibleIds)
            ->get();
        // ...
    }
}
```

With 3 levels expanded and ~10 nodes per level, this fires ~110 queries.

### 2. `org-node.blade.php` — per-node `exists()` check (lines 8-9)

```php
if (!$hasChildren && !isset($this->lazyChildren[$unit->id])) {
    $hasChildren = \App\Models\Unit::where('parent_id', $unit->id)->exists();
}
```

Every visible node without pre-loaded children fires a separate `exists()` query to determine if the expand button should show.

### 3. `expandParents` — sequential parent traversal (org-chart.blade.php:179-194)

```php
while ($current && $current->parent_id) {
    $parent = $current->parent;  // ← 1 query per ancestor level
    // ...
}
```

On search, each matching unit triggers a chain of parent queries (depth of tree queries per result).

### 4. `loadExpandedChildren` — per-unit child queries (lines 109-124)

```php
foreach ($this->expanded as $unitId) {
    if (! isset($this->lazyChildren[$id])) {
        $children = Unit::where('parent_id', $id)  // ← 1 query per expanded unit
            ->whereIn('id', $accessibleIds)
            ->get();
    }
}
```

## Proposed Fix

### Step 1: Batch-load children for `expandFirstNLevels`

Replace the per-node loop with a single batch query using `whereIn('parent_id', ...)`:

```php
protected function expandFirstNLevels($nodes, int $maxLevel, int $level = 1): array
{
    $ids = [];
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();

    // Collect all parent IDs that need children loaded at this level
    $parentIds = [];
    foreach ($nodes as $node) {
        if ($level <= $maxLevel) {
            $ids[] = (string) $node->id;
        }
        if ($level < $maxLevel) {
            $parentIds[] = $node->id;
        }
    }

    // Single batch query for all children at this level
    if (!empty($parentIds)) {
        $allChildren = Unit::whereIn('parent_id', $parentIds)
            ->whereIn('id', $accessibleIds)
            ->with(['unitType'])
            ->get()
            ->groupBy('parent_id');

        foreach ($nodes as $node) {
            if ($level < $maxLevel) {
                $children = $allChildren->get($node->id, collect());
                $this->lazyChildren[(int) $node->id] = $children;

                if ($children->isNotEmpty()) {
                    $ids = array_merge(
                        $ids,
                        $this->expandFirstNLevels($children, $maxLevel, $level + 1)
                    );
                }
            }
        }
    }

    return $ids;
}
```

**Queries at level 1:** 1 batch query instead of N individual queries.

### Step 2: Batch-load children for `loadExpandedChildren`

Same pattern — single `whereIn` instead of per-unit loop:

```php
public function loadExpandedChildren(): void
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();

    // Find which expanded units don't have cached children
    $missingIds = [];
    foreach ($this->expanded as $unitId) {
        $id = (int) $unitId;
        if (!isset($this->lazyChildren[$id])) {
            $missingIds[] = $id;
        }
    }

    if (empty($missingIds)) {
        return;
    }

    // Single batch query
    $allChildren = Unit::whereIn('parent_id', $missingIds)
        ->whereIn('id', $accessibleIds)
        ->with(['unitType'])
        ->get()
        ->groupBy('parent_id');

    foreach ($missingIds as $parentId) {
        $this->lazyChildren[$parentId] = $allChildren->get($parentId, collect());
    }
}
```

### Step 3: Pre-compute `has_children` in batch (eliminate org-node.exists())

Add a public property `public array $hasChildren = [];` to the component. After loading all children, set it:

```php
// After loadExpandedChildren() or loadChildren(), set has_children
foreach ($this->lazyChildren as $parentId => $children) {
    if ($children->isNotEmpty()) {
        $this->hasChildren[$parentId] = true;
    }
}
```

For nodes NOT in `$lazyChildren` (not yet loaded), pre-fetch in bulk:

```php
// Batch-check which unloaded units have children
$unloadedIds = array_diff($allUnitIds, array_keys($this->lazyChildren));
if (!empty($unloadedIds)) {
    $unitsWithChildren = Unit::whereIn('parent_id', $unloadedIds)
        ->whereIn('id', $accessibleIds)
        ->pluck('parent_id')
        ->unique();
    foreach ($unitsWithChildren as $pid) {
        $this->hasChildren[$pid] = true;
    }
}
```

Update `org-node.blade.php` to use `$this->hasChildren[$unit->id] ?? false` instead of the `exists()` query.

### Step 4: Fix `expandParents` to use batch ancestor lookup

Replace the sequential `$current->parent` loop with a batch query. Since `Unit::ancestorIds()` only returns direct parents (single JOIN, not recursive), implement a recursive ancestor CTE or use iterative batching:

```php
protected function expandParents($unit): void
{
    $this->expanded[] = (string) $unit->id;

    // Collect all ancestors using iterative batch queries
    $currentIds = [$unit->id];
    $visited = [$unit->id => true];
    $maxDepth = 20; // safety limit

    while (!empty($currentIds) && $maxDepth-- > 0) {
        $parents = Unit::whereIn('id', $currentIds)
            ->whereNotNull('parent_id')
            ->pluck('parent_id', 'id');

        $nextIds = [];
        foreach ($parents as $parentId) {
            if (!isset($visited[$parentId])) {
                $visited[$parentId] = true;
                $this->expanded[] = (string) $parentId;
                $nextIds[] = $parentId;
            }
        }
        $currentIds = $nextIds;
    }
}
```

This reduces ancestor traversal from O(depth) queries per search result to O(depth / batchSize) queries.

### Step 5: Same fix for `loadChildren` on toggle

The `loadChildren` method (line 129) already handles a single unit — this is fine for interactive toggle (user clicks one node). No change needed.

## Files to Modify

1. **`resources/views/livewire/hr/org-chart.blade.php`**
   - Add `public array $hasChildren = [];` property
   - Rewrite `expandFirstNLevels()` to use batch query
   - Rewrite `loadExpandedChildren()` to use batch query
   - Rewrite `expandParents()` to use iterative batch queries
   - Set `$this->hasChildren` after each children load
   - Pre-compute `hasChildren` for unloaded units

2. **`resources/views/livewire/hr/org-node.blade.php`**
   - Replace lines 8-9 (`Unit::where('parent_id', ...)->exists()`) with `$this->hasChildren[$unit->id] ?? false`

## Verification

1. **Query count test:** Enable `DB::enableQueryLog()`, navigate to org-chart, expand 3 levels. Assert `count(DB::getQueryLog()) < 10`.
2. **Functional test:** Tree renders correctly, expand/collapse toggle works, search finds deep nodes, detail panel shows correct personnel counts.
3. **Regression test:** All existing org-chart E2E tests (`tests/e2e/`) pass.
4. **Performance baseline:** Before/after query count comparison with a 100-unit test dataset.

## Risks

- **Risk:** Batch queries may load more data than needed if many units share a parent. **Mitigation:** The `whereIn('id', $accessibleIds)` filter already limits scope. Batch size is bounded by accessible units.
- **Risk:** `$hasChildren` cache may go stale if units are created/deleted while page is open. **Mitigation:** Livewire `mount()` / `loadData()` recomputes on each request. This is a view-scoped cache, not persistent.
- **Risk:** `expandParents` batch approach may miss units not accessible to the user. **Mitigation:** Ancestors for a matched search result must exist in the database even if not in the user's accessible set. We only add them to `$expanded` (array of IDs), not to the tree rendering. The tree only renders `$rootUnits` and their loaded children.
