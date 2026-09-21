# Plan 004: Unbounded Recursive Eager Loading in TicketComment::descendants() and Unit::childrenRecursive()

> Written against commit: `22a97b5` (beta/sydney)
> Category: Performance | Effort: M | Impact: HIGH

## Problem

`Unit::childrenRecursive()` and `TicketComment::descendants()` use Eloquent's recursive eager loading pattern — `$this->children()->with('childrenRecursive')` — which causes **N+1 queries** (one per tree depth level) with **no depth limit**. For a hierarchy of depth D, this generates D separate SQL queries, each fetching all nodes at that level.

On a unit tree with 5 levels of depth, this produces **5 queries**. On a comment thread with 10 nested replies, it produces **10 queries**. With no depth cap, a circular reference or a malicious data import could cause unbounded recursion until PHP memory exhaustion.

The project already has a superior pattern: `Unit::descendantIds()` (Unit.php:140-178) uses a **single recursive CTE** to fetch all descendants in one query. The recursive eager loading is an older, inferior pattern that should be replaced.

### Evidence

**Unit::childrenRecursive() — actively used in production:**
- `app/Models/Unit.php:80-82` — defines the recursive eager loading
- `resources/views/livewire/units/chart.blade.php:60` — `Unit::with(['childrenRecursive', 'unitType'])->get()` — loads ALL levels for every root unit
- `resources/views/livewire/units/tree-item.blade.php:4` — accesses `$unit->childrenRecursive->count()`
- `resources/views/livewire/units/tree-item.blade.php:68` — `@foreach($unit->childrenRecursive as $child)` — iterates loaded children

**TicketComment::descendants() — dead code, no production callers:**
- `app/Models/TicketComment.php:63-66` — defines the recursive eager loading
- Only called in `tests/Feature/TicketCommentModelTest.php:443` — no Blade views, controllers, or Livewire components use it
- `TicketCommentController.php:267-282` already uses a recursive CTE (`getThreadDepth()`) for depth calculation — proving CTE is the preferred approach in this codebase

## Solution

### For Unit::childrenRecursive() — Replace with a single CTE query + in-memory tree building

The chart component (`chart.blade.php:58-61`) already filters root units via `whereIn('id', $accessibleIds)`. Replace the eager-loaded recursive tree with:

1. A single CTE query that fetches ALL accessible units with their `parent_id` (already available via `Unit::descendantIds()`)
2. Build the tree structure in memory using a single pass
3. Attach `childrenRecursive` as an attribute so the Blade templates remain unchanged

### Before (Unit.php:80-82)

```php
public function childrenRecursive(): HasMany
{
    return $this->children()->with('childrenRecursive');
}
```

### After (Unit.php)

```php
/**
 * Build full tree structure from a flat collection using a single CTE query.
 * Replaces the N+1 recursive eager loading pattern.
 *
 * @param  array<int>  $rootIds
 * @param  array<int>|null  $accessibleIds  If null, no scope filter applied
 * @return Collection<int, Unit>
 */
public static function buildTree(array $rootIds, ?array $accessibleIds = null): Collection
{
    if (empty($rootIds)) {
        return collect();
    }

    // Single CTE: fetch all descendants of root units (inclusive)
    $allIds = self::descendantIds($rootIds)->all();

    if (! empty($accessibleIds)) {
        $allIds = array_values(array_intersect($allIds, $accessibleIds));
    }

    if (empty($allIds)) {
        return collect();
    }

    // Single query: load all relevant units with their types
    $placeholders = implode(',', array_fill(0, count($allIds), '?'));
    $allUnits = self::query()
        ->with('unitType')
        ->whereIn('units.id', $allIds)
        ->get()
        ->keyBy('id');

    // Build adjacency list
    $childrenMap = [];
    foreach ($allUnits as $unit) {
        $parentId = $unit->parent_id;
        if ($parentId !== null && isset($allUnits[$parentId])) {
            $childrenMap[$parentId][] = $unit;
        }
    }

    // Recursive closure to attach children
    $attachChildren = function (Unit $unit) use (&$attachChildren, $childrenMap) {
        $unit->childrenRecursive = collect($childrenMap[$unit->id] ?? []);
        foreach ($unit->childrenRecursive as $child) {
            $attachChildren($child);
        }
    };

    // Build root collection
    $roots = collect();
    foreach ($rootIds as $rootId) {
        if (isset($allUnits[$rootId])) {
            $roots[] = $allUnits[$rootId];
        }
    }

    foreach ($roots as $root) {
        $attachChildren($root);
    }

    return $roots;
}
```

### Before (chart.blade.php:54-61)

```php
public function loadData(): void
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();

    $this->rootUnits = Unit::whereNull('parent_id')
        ->whereIn('id', $accessibleIds)
        ->with(['childrenRecursive', 'unitType'])
        ->get();
}
```

### After (chart.blade.php)

```php
public function loadData(): void
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();

    $rootIds = Unit::whereNull('parent_id')
        ->whereIn('id', $accessibleIds)
        ->pluck('id')
        ->all();

    $this->rootUnits = Unit::buildTree($rootIds, $accessibleIds);
}
```

### For TicketComment::descendants() — Remove dead code

Since `TicketComment::descendants()` has no production callers (only a test), remove it entirely. The `TicketCommentController` already uses a CTE for depth calculation. If thread loading is needed in the future, implement it as a CTE-based method following the `Unit::descendantIds()` pattern.

```php
// DELETE lines 60-66 in TicketComment.php:
/**
 * Get all descendants (recursive).
 */
public function descendants(): HasMany
{
    return $this->children()->with('descendants');
}
```

Also update the test at `tests/Feature/TicketCommentModelTest.php:420-448` to verify the removal (or convert it to a CTE-based test if a replacement method is added later).

## Files in Scope

- `app/Models/Unit.php` — add `buildTree()`, remove/deprecate `childrenRecursive()`
- `resources/views/livewire/units/chart.blade.php` — switch to `Unit::buildTree()`
- `app/Models/TicketComment.php` — remove `descendants()` method
- `tests/Feature/TicketCommentModelTest.php` — update/remove descendants test
- `tests/Feature/UnitModelTest.php` — update `childrenRecursive` test

## Files Out of Scope

- `resources/views/livewire/units/tree-item.blade.php` — no changes needed; it accesses `$unit->childrenRecursive` which will be set as a dynamic attribute by `buildTree()`
- `app/Http/Controllers/Api/TicketCommentController.php` — already uses CTE
- `app/Models/Unit.php:140-178` (`descendantIds()`) — already correct CTE pattern, used by `buildTree()`

## Steps

### Step 1: Add `Unit::buildTree()` static method
1. Add the `buildTree()` static method to `app/Models/Unit.php` after the `childrenRecursive()` method
2. Verify the method compiles: `php artisan tinker --execute 'dd(\App\Models\Unit::buildTree([]));'`

### Step 2: Update `units/chart.blade.php` to use `buildTree()`
1. Replace the `loadData()` method in `resources/views/livewire/units/chart.blade.php`
2. Change `Unit::with(['childrenRecursive', 'unitType'])->get()` to `Unit::buildTree($rootIds, $accessibleIds)`
3. Verify: `php artisan tinker --execute 'dd(\App\Models\Unit::buildTree([1]));'`

### Step 3: Remove `TicketComment::descendants()`
1. Delete lines 60-66 in `app/Models/TicketComment.php` (the `descendants()` method and its docblock)
2. Update `tests/Feature/TicketCommentModelTest.php:420-448` — remove the `test_comment_descendants_are_recursive` test, or replace with a test that verifies the method no longer exists
3. Verify: `grep -rn 'descendants' app/Models/TicketComment.php` returns no results (except possibly the `children()` eager load)

### Step 4: Remove `Unit::childrenRecursive()` (deprecated)
1. Remove the `childrenRecursive()` method from `app/Models/Unit.php:80-83`
2. Update `tests/Feature/UnitModelTest.php:234-244` — replace with a test for `buildTree()`

### Step 5: Verify
```bash
composer phpstan    # Ensure no new errors
composer pint       # Format
composer test       # All 1352 tests pass
```

## Test Plan

### New tests to add in `tests/Feature/UnitModelTest.php`:

1. **`test_build_tree_returns_nested_hierarchy`** — Create parent → child → grandchild, call `Unit::buildTree([$parentId])`, verify nested structure
2. **`test_build_tree_respects_accessible_ids_scope`** — Create two branches, pass only one branch's IDs as accessible, verify the other branch is excluded
3. **`test_build_tree_with_empty_root_ids_returns_empty`** — Edge case
4. **`test_build_tree_single_level_has_empty_children_recursive`** — Leaf nodes should have empty `childrenRecursive` collections

### Tests to modify:

1. `tests/Feature/UnitModelTest.php:234-244` — `test_children_recursive_eager_loads_hierarchy` → rewrite to test `buildTree()`
2. `tests/Feature/TicketCommentModelTest.php:420-448` — `test_comment_descendants_are_recursive` → remove (dead code removal)

### Verification commands:

```bash
composer test       # All tests pass
composer phpstan    # No new errors
grep -rn "childrenRecursive\|descendants" app/Models/   # Confirm removal from models
grep -rn "with('childrenRecursive')" resources/         # Confirm no remaining eager load calls
```

## Maintenance Note

- The `buildTree()` method uses the existing `descendantIds()` CTE — no new SQL complexity
- The `childrenRecursive` attribute is set dynamically (not via an Eloquent relation) — static analysis tools like PHPStan may flag it. Use `@property` annotation or `@method` on the model to document it
- If the unit hierarchy grows beyond ~1000 nodes per accessible scope, consider lazy-loading (only fetch children when a node is expanded) instead of loading the entire tree upfront
- Future developers should not re-introduce recursive eager loading. Add a comment on `Unit::childrenRecursive` explaining why it was removed
- The tree-item Blade template still references `$unit->childrenRecursive` — this works because `buildTree()` sets it as a dynamic property. If Eloquent model strict mode is enabled, this will break. Use `#[AllowDynamicProperties]` or define it in `$appends`

## Done Criteria

- [ ] `Unit::buildTree()` exists and works: `php artisan tinker --execute 'dd(\App\Models\Unit::buildTree([1]));'`
- [ ] `chart.blade.php` uses `Unit::buildTree()` instead of `with(['childrenRecursive'])`
- [ ] `TicketComment::descendants()` removed: `grep -rn 'function descendants' app/Models/TicketComment.php` returns 0 results
- [ ] `Unit::childrenRecursive()` removed: `grep -rn 'function childrenRecursive' app/Models/Unit.php` returns 0 results
- [ ] No remaining eager-load calls: `grep -rn "with('childrenRecursive')" resources/` returns 0 results
- [ ] All existing tests pass (`composer test`)
- [ ] New `buildTree()` tests added and pass
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
