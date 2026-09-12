# Plan 022: Parameterize Dashboard SQL + Cache Lookups + Fix Token Creation + Fix Advanced Report N+1

**Category:** perf | **Effort:** M | **Risk:** MED | **Priority:** P2 | **Depends on:** none

---
## ⚠️ TL;DR فارسی

**مشکل:** SQL string concat. Lookup ثابت هر render. توکن هر بار. N+1.

**⚠️ نکته:**  با implode(array_fill) نه ? ساده.

**ریسک:** 🟡 متوسط

---

## Problem

Four performance issues cause unnecessary DB load and prevent PostgreSQL query plan caching:

1. **Dashboard raw SQL interpolates IDs via string concat** (`dashboard.blade.php:57-141`). The `$ids` variable is embedded directly into SQL strings like `WHERE unit_id IN ({$ids})`, which:
   - Prevents PostgreSQL from caching the query plan (every different ID set creates a new prepared statement)
   - Is technically a SQL injection vector if `$accessibleIds` ever contained non-integer values (currently safe due to AccessService returning ints, but fragile)

2. **Kargozini person page loads all reference data every render** (`person.blade.php:276-312`). The `with()` method calls `Tahsil::all()`, `Estekhdam::all()`, `Semat::all()`, `Radif::all()` on every Livewire component render — these are static/semi-static lookup tables.

3. **Map dashboard creates new API token on every mount** (`map-dashboard.blade.php:43-45`). The `mount()` method deletes old tokens then creates a new one every time, executing 2 DB writes per page visit (DELETE + INSERT on `personal_access_tokens`).

4. **Advanced report N+1 on grouped unit query** (`reports/advanced.blade.php:133-136`). The `$byUnit` query uses `->with('unit:id,name')` on a grouped result. Since the query is grouped by `$unitColumn`, each result row is an aggregate — calling `->with()` on an aggregate causes N+1 queries because Eloquent eagerly loads the `unit` relationship for every grouped row individually.

---

## Change 1: Parameterize Dashboard SQL

### Files to modify
- `resources/views/livewire/dashboard.blade.php` — lines 56-150

### Current code (lines 56-103)
```php
$ids = implode(',', $accessibleIds);

// Used in:
$row = DB::selectOne("... WHERE u_id IN ({$ids}) ...");
$ticketRow = DB::selectOne("... WHERE unit_id IN ({$ids}) ...");
$todoRow = DB::selectOne("... WHERE unit_id IN ({$ids}) ...");
$linkedTodos = DB::selectOne("... WHERE t.unit_id IN ({$ids}) ...");
```

Also on lines 126-141:
```php
$ids = implode(',', $accessibleIds);
$row = DB::selectOne("... WHERE unit_id IN ({$ids}) ...");
```

### Fix
Replace string interpolation with parameter binding. Use PostgreSQL's `ANY()` array syntax or build `IN (?, ?, ...)` with bound parameters.

```php
// Option A: PostgreSQL ANY with array binding (preferred for PostGIS project)
$placeholder = ':' . implode(',:', array_keys($accessibleIds));
// Build named params: ['p0' => 1, 'p1' => 2, ...]
$params = array_combine(
    array_map(fn($i) => "p$i", array_keys($accessibleIds)),
    $accessibleIds
);

// Option B: Build IN clause with ? placeholders (cross-DB)
$placeholders = implode(',', array_fill(0, count($accessibleIds), '?'));

$row = DB::selectOne(
    "SELECT
        (SELECT COUNT(*) FROM persons WHERE u_id IN ({$placeholders})) AS total_persons,
        (SELECT COUNT(*) FROM units WHERE id IN ({$placeholders})) AS total_units",
    array_merge($accessibleIds, $accessibleIds)
);
```

**Recommended approach:** Use the `IN (?, ?, ...)` pattern with `?` placeholders since the code already handles both pgsql and sqlite drivers. Each `DB::selectOne` call needs its own `$accessibleIds` array repeated for each `IN` clause in that query.

Create a helper at the top of the cache closure to avoid repetition:
```php
$cache = function () use ($accessibleIds) {
    $ph = implode(',', array_fill(0, count($accessibleIds), '?'));

    $row = DB::selectOne(
        "SELECT
            (SELECT COUNT(*) FROM persons WHERE u_id IN ({$ph})) AS total_persons,
            (SELECT COUNT(*) FROM units WHERE id IN ({$ph})) AS total_units",
        array_merge($accessibleIds, $accessibleIds)
    );
    // ... same pattern for $ticketRow, $todoRow, $linkedTodos, $details
};
```

Apply the same fix to the `details` cache closure (lines 125-150) and the `todayStats` closure (lines 106-122) which also uses `$ids` and `$userIdStr`.

### Risk mitigation
- The ID arrays come from `AccessService::accessibleUnitIds()` which returns validated integers — low risk of type mismatch.
- Test all three cache closures still return correct values after parameterization.
- Verify both PostgreSQL and SQLite paths work (CI may use SQLite).

### Verification
- `grep -rn 'IN (\$ids' resources/views/` returns 0 matches (no more string interpolation)
- `grep -rn 'IN ({$ids}' resources/views/` returns 0 matches
- Dashboard renders correctly with populated data

---

## Change 2: Cache Static Reference Lookups

### Files to modify
- `resources/views/livewire/kargozini/person.blade.php` — lines 276-312 (the `with()` method)

### Current code (lines 301-311)
```php
return [
    'persons' => $this->persons(),
    'headers' => $this->headers(),
    'tahsils' => Tahsil::all(),        // ← DB query every render
    'estekhdams' => Estekhdam::all(),  // ← DB query every render
    'semats' => Semat::all(),          // ← DB query every render
    'radifs' => Radif::all(),          // ← DB query every render
    'units' => $units,
    'selectedUnitName' => $selectedUnitName,
    'filterUnitName' => $filterUnitName,
];
```

### Fix
Cache these with `Cache::remember()` using the existing version-based invalidation pattern. The reference tables (Tahsil, Estekhdam, Semat, Radif) change rarely and are already invalidated by the global cache versioning system.

```php
$v = Cache::get('reference_data_version', 0);

$tahsils = Cache::remember("kargozini:tahsils:v{$v}", 3600, fn() => Tahsil::all());
$estekhdams = Cache::remember("kargozini:estekhdams:v{$v}", 3600, fn() => Estekhdam::all());
$semats = Cache::remember("kargozini:semats:v{$v}", 3600, fn() => Semat::all());
$radifs = Cache::remember("kargozini:radifs:v{$v}", 3600, fn() => Radif::all());

return [
    'persons' => $this->persons(),
    'headers' => $this->headers(),
    'tahsils' => $tahsils,
    'estekhdams' => $estekhdams,
    'semats' => $semats,
    'radifs' => $radifs,
    'units' => $units,
    'selectedUnitName' => $selectedUnitName,
    'filterUnitName' => $filterUnitName,
];
```

**Cache invalidation:** The `Person` model already bumps `hr_stats`, `dashboard`, `maps`, `gis` version namespaces on save/delete (lines 45-52 of `Person.php`). Add a `reference_data_version` bump to the same observer callback, or add a separate `CacheInvalidationServiceInterface::increment('reference_data')` call in the Person boot.

For Tahsil/Estekhdam/Semat/Radif models, add similar version bumps to their boot methods:
```php
// In each model's boot():
static::saved(fn() => Cache::increment('reference_data_version'));
static::deleted(fn() => Cache::increment('reference_data_version'));
```

### Verification
- First render: 4 DB queries (tahsils, estekhdams, semats, radifs)
- Second render: 0 DB queries for reference data (cache hits)
- After editing a Tahsil record, next render fetches fresh data

---

## Change 3: Reuse Existing Map Token

### Files to modify
- `resources/views/livewire/map/map-dashboard.blade.php` — `mount()` method, lines 38-48

### Current code (lines 40-46)
```php
$user = auth()->user();
// Always create a new token — Sanctum stores only the hash,
// so plainTextToken is null on tokens loaded from DB.
// Delete old map-dashboard tokens first to avoid accumulation.
$user->tokens()->where('name', 'map-dashboard')->delete();
$this->mapToken = $user->createToken('map-dashboard')->plainTextToken;
$this->mapTileTemplate = config('map.tile_url_template', ...);
$this->loadStats();
```

### Fix
Check if a valid token already exists. Since Sanctum stores only the hash and `plainTextToken` is only available on creation, store the plain text token in the user's settings (or a dedicated cache key) after first creation. On subsequent mounts, retrieve from cache instead of creating a new token.

```php
$user = auth()->user();
$cacheKey = "map-token:user:{$user->id}";
$this->mapToken = Cache::get($cacheKey);

if (! $this->mapToken) {
    // Clean up any stale tokens first
    $user->tokens()->where('name', 'map-dashboard')->delete();
    $this->mapToken = $user->createToken('map-dashboard')->plainTextToken;
    // Cache the plain text token for 24 hours (tokens don't expire by default)
    Cache::put($cacheKey, $this->mapToken, now()->addHours(24));
}

$this->mapTileTemplate = config('map.tile_url_template', ...);
$this->loadStats();
```

**Alternative (simpler):** Store the token in the user's settings column (encrypted):
```php
if (! empty($user->settings['map_token'])) {
    $this->mapToken = $user->settings['map_token'];
} else {
    $user->tokens()->where('name', 'map-dashboard')->delete();
    $this->mapToken = $user->createToken('map-dashboard')->plainTextToken;
    $user->update(['settings' => array_merge($user->settings ?? [], ['map_token' => $this->mapToken])]);
}
```

**Trade-off:** The cache approach is faster but means all devices share the same token. The settings approach gives per-user persistence. Either eliminates the 2 DB writes per mount. The cache approach is recommended for simplicity.

### Verification
- First mount: 1 token created, cached
- Subsequent mounts: 0 DB writes for token creation
- `personal_access_tokens` row count stays stable (no accumulation)

---

## Change 4: Fix Advanced Report N+1

### Files to modify
- `resources/views/livewire/reports/advanced.blade.php` — `reportData()` computed property, lines 133-139

### Current code (lines 133-139)
```php
$byUnit = $query->clone()
    ->selectRaw("$unitColumn, count(*) as count")
    ->groupBy($unitColumn)
    ->with('unit:id,name')        // ← N+1: eager loads on grouped rows
    ->get()
    ->mapWithKeys(fn($item) => [$item->unit?->name ?? 'نامشخص' => $item->count])
    ->toArray();
```

### Fix
Replace `->with('unit:id,name')` with a `->join()` so the unit name is resolved in the same grouped query:

```php
$byUnit = $query->clone()
    ->selectRaw("{$unitColumn}, units.name as unit_name, count(*) as count")
    ->leftJoin('units', 'units.id', '=', $unitColumn)
    ->groupBy($unitColumn, 'units.name')
    ->pluck('count', 'unit_name')
    ->toArray();
```

This reduces the N+1 to a single query with a LEFT JOIN. The `->mapWithKeys` transformation is no longer needed since `pluck('count', 'unit_name')` returns the exact structure required.

**Note:** When `$unitColumn` is `u_id` (for `persons` report type), the LEFT JOIN on `units.id = persons.u_id` is correct. When `$unitColumn` is `unit_id` (for tickets/todos), `units.id = tickets.unit_id` is also correct. The shared column name `units.id` works for both cases.

### Verification
- Enable Laravel Debugbar or listen to `DB::listening` — confirm the `byUnit` query is a single SQL statement with JOIN, not N+1
- Unit names display correctly in the pie chart
- Null unit_ids show as "نامشخص"

---

## Verification Checklist

- [ ] `grep -rn 'IN (\$ids\|IN ({$ids' resources/views/` returns 0 matches
- [ ] Dashboard renders correctly with parameterized SQL
- [ ] Kargozini person page: first render = 4 queries, second render = 0 queries for reference data
- [ ] Map dashboard: second mount does not create a new token
- [ ] Advanced report: `byUnit` query is a single JOIN, not N+1
- [ ] `composer test` / `php artisan test` passes
- [ ] No visual regressions in dashboard, kargozini, map, or reports
