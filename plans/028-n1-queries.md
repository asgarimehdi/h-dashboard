# 028 — Fix N+1 Queries in Tree Views

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | MEDIUM (Performance) |
| **Effort** | M |
| **Risk** | Low — query optimization only |
| **Base commit** | `a106d38` |
| **Files** | `resources/views/livewire/units/chart.blade.php`, `resources/views/livewire/hr/org-chart.blade.php`, `resources/views/livewire/tickets/⚡inbox.blade.php` |

## Problem

Three locations perform N+1 database queries when loading tree structures or paginated data.

### Evidence (file:line)

#### (a) `units/chart.blade.php:82-89` — Recursive `Unit::find()` per parent

```php
protected function expandParents($unit): void
{
    if ($unit->parent_id) {
        $this->expanded[] = (string) $unit->parent_id;
        $parent = Unit::find($unit->parent_id);  // N+1: one query per parent
        if ($parent) {
            $this->expandParents($parent);
        }
    }
}
```

This fires `SELECT * FROM units WHERE id = ?` for every ancestor of every search match. With deep trees and many search results, this multiplies rapidly.

#### (b) `hr/org-chart.blade.php:90-93` — Children loaded one-by-one in `expandFirstNLevels()`

```php
if ($level < $maxLevel) {
    $children = Unit::where('parent_id', $node->id)  // N+1: one query per node
        ->whereIn('id', $accessibleIds)
        ->get();
    $this->lazyChildren[(int) $node->id] = $children;
```

At level 3 with branching, this fires a separate query for every node to load its children, instead of batching.

#### (c) `tickets/⚡inbox.blade.php:89` — Person not eager-loaded with ticket user

```php
$query = Ticket::with(['user:id,n_code', 'unit:id,name']);
```

Line 721 in the Blade template accesses `$ticket->user->person?->f_name` but `person` is not in the `with()` chain. Each ticket renders cause a lazy-loaded `Person` query.

## Decision

### (a) Replace recursive `Unit::find()` with `Unit::whereIn()` batch lookup

Collect all `parent_id` values from search results, fetch them in one query, then walk the in-memory tree.

### (b) Replace per-node children queries with a batch `whereIn('parent_id', $nodeIds)`

Collect all parent IDs at each level, fetch all children in one query, then partition into `$lazyChildren`.

### (c) Add `person` to the eager-load chain

Change `'user:id,n_code'` to `'user:id,n_code,person'` — but since `person` is on `User`, add `'user.person'` to the with clause.

## Commands

```bash
cd /home/runner/h-dashboard
XDEBUG_MODE=off php artisan test tests/Feature/UnitsTreeItemLivewireTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/TicketsInboxLivewireTest.php -v
```

## Steps

### Phase 1 — Fix units/chart.blade.php expandParents (lines 82-91)

Replace the recursive `Unit::find()` approach with a batch ancestor lookup:

```php
protected function expandParents($unit): void
{
    $this->expanded[] = (string) $unit->id;
    $currentId = $unit->parent_id;
    $visited = [];

    while ($currentId && !isset($visited[$currentId])) {
        $visited[$currentId] = true;
        $this->expanded[] = (string) $currentId;
        $parent = Unit::find($currentId);  // Still one-at-a-time but avoids deep recursion
        $currentId = $parent?->parent_id;
    }
}
```

Actually, the best fix is to collect all parent_ids and do a single `whereIn`. But the unit already has `->with(['parent'])` on the matching query in `updatedSearch()` (line 156 of org-chart). For units/chart, the parent is already loaded via `childrenRecursive`. We can walk the in-memory tree:

```php
protected function expandParents($unit): void
{
    $this->expanded[] = (string) $unit->id;
    // parent is already loaded via childrenRecursive eager loading
    $parent = $unit->parent;
    if ($parent) {
        $this->expanded[] = (string) $parent->id;
        // Walk up: since parent was loaded with childrenRecursive, 
        // we still need to walk up the tree. Batch the parent IDs.
    }
}
```

**Better approach for units/chart:** Since `updatedSearch()` (line 64-80) calls `expandParents` on each matching unit, and the matching units are loaded via `Unit::where('name', ...)` without eager-loading ancestors, the cleanest fix is to batch-fetch all ancestors at once:

```php
public function updatedSearch(): void
{
    $this->expanded = [];
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    if (strlen($this->search) > 2) {
        $matchingUnits = Unit::where('name', 'LIKE', "%{$this->search}%")
            ->whereIn('id', $accessibleIds)
            ->get();

        // Collect all ancestor parent_ids via recursive CTE or iterative batch
        $allParentIds = [];
        foreach ($matchingUnits as $unit) {
            $this->expanded[] = (string) $unit->id;
            $pid = $unit->parent_id;
            while ($pid && !isset($allParentIds[$pid])) {
                $allParentIds[$pid] = true;
                $this->expanded[] = (string) $pid;
            }
        }
        
        // Batch-fetch all ancestors we don't already have
        $missingIds = array_diff(array_keys($allParentIds), array_map('strval', $matchingUnits->pluck('id')->toArray()));
        if (!empty($missingIds)) {
            $ancestors = Unit::whereIn('id', $missingIds)->get();
            foreach ($ancestors as $ancestor) {
                if ($ancestor->parent_id && !isset($allParentIds[$ancestor->parent_id])) {
                    $this->expanded[] = (string) $ancestor->parent_id;
                    // Continue walking up for newly discovered parents
                    $pid = $ancestor->parent_id;
                    while ($pid && !isset($allParentIds[$pid])) {
                        $allParentIds[$pid] = true;
                        $this->expanded[] = (string) $pid;
                        $ancestorParent = Unit::find($pid); // may need recursive fetch
                        $pid = $ancestorParent?->parent_id;
                    }
                }
            }
        }
        $this->expanded = array_unique($this->expanded);
    }
}
```

**Simplest correct approach:** Replace the per-parent `Unit::find()` with a single `Unit::whereIn()` batch fetch, then walk in-memory:

```php
protected function expandParents($unit): void
{
    $this->expanded[] = (string) $unit->id;
    $parentIds = [];
    $current = $unit->parent;
    while ($current) {
        if (isset($parentIds[$current->id])) break;
        $parentIds[$current->id] = true;
        $this->expanded[] = (string) $current->id;
        $current = $current->parent;
    }
}
```

The problem is `parent` may not be eager-loaded. **Final approach:** Eager-load `parent` in the search query, then walk in-memory. Add `->with('parent')` to the search query at line 156:

```php
$matchingUnits = Unit::where('name', 'LIKE', "%{$this->search}%")
    ->whereIn('id', $accessibleIds)
    ->with('parent')  // <-- ADD
    ->get();
```

Then simplify `expandParents` to walk the eager-loaded chain:

```php
protected function expandParents($unit): void
{
    $this->expanded[] = (string) $unit->id;
    $current = $unit;
    while ($current->parent_id) {
        if (!isset($this->expanded[(string) $current->parent_id])) {
            $this->expanded[] = (string) $current->parent_id;
        }
        $current = $current->parent;
        if (!$current) break;
    }
}
```

### Phase 2 — Fix hr/org-chart.blade.php expandFirstNLevels (lines 79-103)

Replace per-node `Unit::where('parent_id', $node->id)` with a batch query:

```php
protected function expandFirstNLevels($nodes, int $maxLevel, int $level = 1): array
{
    $ids = [];
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();

    foreach ($nodes as $node) {
        if ($level <= $maxLevel) {
            $ids[] = (string) $node->id;
        }
    }

    // Batch-load children for all nodes at this level
    if ($level < $maxLevel) {
        $nodeIds = $nodes->pluck('id')->toArray();
        $allChildren = Unit::whereIn('parent_id', $nodeIds)
            ->whereIn('id', $accessibleIds)
            ->with(['unitType'])
            ->get()
            ->groupBy('parent_id');

        foreach ($nodes as $node) {
            $children = $allChildren->get($node->id, collect());
            if ($children->isNotEmpty()) {
                $this->lazyChildren[(int) $node->id] = $children;
                $ids = array_merge($ids, $this->expandFirstNLevels($children, $maxLevel, $level + 1));
            }
        }
    }

    return $ids;
}
```

### Phase 3 — Fix tickets/inbox.blade.php eager loading (line 89)

Change:
```php
$query = Ticket::with(['user:id,n_code', 'unit:id,name']);
```
To:
```php
$query = Ticket::with(['user:id,n_code', 'unit:id,name']);
```

Wait — the `person` is accessed on `$ticket->user->person?->f_name` (line 721). The `person` relationship is on `User`, not `Ticket`. So we need:

```php
$query = Ticket::with(['user:id,n_code', 'user.person:id,n_code,f_name,l_name', 'unit:id,name']);
```

### Phase 4 — Verify

```bash
XDEBUG_MODE=off php artisan test tests/Feature/UnitsTreeItemLivewireTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/TicketsInboxLivewireTest.php -v
```

## Test plan

- Existing `UnitsTreeItemLivewireTest` and `TicketsInboxLivewireTest` confirm functionality.
- Manual: enable query logging and confirm fewer queries on tree expansion and ticket list rendering.
- Optional: add a `DB::enableQueryLog()` assertion to confirm N+1 count reduction.

## Done criteria

- [ ] `expandParents()` in units/chart walks in-memory after eager-loading parents
- [ ] `expandFirstNLevels()` in hr/org-chart uses batch `whereIn` instead of per-node queries
- [ ] `user.person` is eager-loaded in tickets/inbox computed property
- [ ] Tree expansion and ticket list tests pass
- [ ] `vendor/bin/pint --dirty` clean

## STOP conditions

- If eager-loading `parent` on search results causes memory issues (unlikely with typical org trees), STOP and benchmark.
- If the batch query in org-chart causes circular relationships (self-referencing parent_id), STOP and add a guard.
