# Plan 019: Optimize Dashboard from 14 Queries to 2-3

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

The dashboard Livewire component executes 14 separate queries on a cold cache: 9 count queries across 3 models + 5 detail queries in separate `Cache::remember()` callbacks. Even when cached, the first load is slow; cache invalidation forces a full re-fetch.

### Current Code (Cold Cache Path)

**File:** `resources/views/livewire/dashboard.blade.php`

**Lines 42-43** (global stats — 2 queries):
```php
$this->totalUsers = Cache::remember("dashboard:total_users:v{$v}", 300, fn() => User::count());
$this->totalRoles = Cache::remember("dashboard:total_roles:v{$v}", 300, fn() => Role::count());
```

**Lines 46-63** (scoped stats — 9 queries inside one callback):
```php
$stats = Cache::remember("dashboard:stats:v{$v}:{$scopeKey}", 300, function () use ($accessibleIds) {
    return [
        'totalPersons' => Person::whereIn('u_id', $accessibleIds)->count(),
        'totalUnits' => Unit::whereIn('id', $accessibleIds)->count(),
        'totalTickets' => Ticket::whereIn('unit_id', $accessibleIds)->count(),
        'openTickets' => Ticket::whereIn('unit_id', $accessibleIds)
            ->whereIn('status', ['created', 'forwarded'])->count(),
        'completedTickets' => Ticket::whereIn('unit_id', $accessibleIds)
            ->where('status', 'completed')->count(),
        'totalTodos' => Todo::whereIn('unit_id', $accessibleIds)->count(),
        'pendingTodos' => Todo::whereIn('unit_id', $accessibleIds)
            ->where('is_completed', false)->count(),
        'completedTodos' => Todo::whereIn('unit_id', $accessibleIds)
            ->where('is_completed', true)->count(),
        'linkedTodos' => Todo::whereIn('unit_id', $accessibleIds)
            ->has('tickets')->count(),
    ];
});
```

**Lines 82-106** (ticket details — 5 queries inside one callback):
```php
$details = Cache::remember("dashboard:ticket_details:v{$v}:{$scopeKey}", 180, function () use ($accessibleIds) {
    return [
        'urgentTickets' => Ticket::whereIn('unit_id', $accessibleIds)
            ->where('priority', 'urgent')
            ->whereIn('status', ['created', 'forwarded'])->count(),
        'normalTickets' => Ticket::whereIn('unit_id', $accessibleIds)
            ->where('priority', 'normal')
            ->whereIn('status', ['created', 'forwarded'])->count(),
        'lowTickets' => Ticket::whereIn('unit_id', $accessibleIds)
            ->where('priority', 'low')
            ->whereIn('status', ['created', 'forwarded'])->count(),
        'overdueTickets' => Ticket::whereIn('unit_id', $accessibleIds)
            ->whereIn('status', ['created', 'forwarded'])
            ->where('deadline', '<', now())->count(),
        'avgResolutionDays' => Ticket::whereIn('unit_id', $accessibleIds)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->avg(DB::raw($diffExpr)) ?? 0,
    ];
});
```

**Total cold-cache queries:** 2 (global) + 9 (scoped) + 5 (details) = **16 queries**

---

## Solution

Consolidate with conditional `CASE`/`SUM` aggregation queries. Each model's counts can be computed in a single query.

### Changes

**File:** `resources/views/livewire/dashboard.blade.php`

Replace lines 42-43 (global stats):
```php
$globalStats = Cache::remember("dashboard:global:v{$v}", 300, fn() => [
    'totalUsers' => User::count(),
    'totalRoles' => Role::count(),
]);
$this->totalUsers = $globalStats['totalUsers'];
$this->totalRoles = $globalStats['totalRoles'];
```
→ **1 query instead of 2.**

Replace lines 46-63 (scoped stats) with a single aggregation query:
```php
$stats = Cache::remember("dashboard:stats:v{$v}:{$scopeKey}", 300, function () use ($accessibleIds) {
    $row = DB::table('persons')
        ->selectRaw('COUNT(*) as total_persons')
        ->where('u_id', $accessibleIds)
        ->addSelect(
            DB::raw('(SELECT COUNT(*) FROM units WHERE id IN (' . implode(',', $accessibleIds) . ')) as total_units'),
        )
        ->first();

    $ticketRow = DB::table('tickets')
        ->selectRaw('COUNT(*) as total_tickets')
        ->where('unit_id', $accessibleIds)
        ->addSelect(
            DB::raw("COUNT(*) FILTER (WHERE status IN ('created','forwarded')) as open_tickets"),
            DB::raw("COUNT(*) FILTER (WHERE status = 'completed') as completed_tickets"),
        )
        ->first();

    $todoRow = DB::table('todos')
        ->selectRaw('COUNT(*) as total_todos')
        ->where('unit_id', $accessibleIds)
        ->addSelect(
            DB::raw("COUNT(*) FILTER (WHERE is_completed = false) as pending_todos"),
            DB::raw("COUNT(*) FILTER (WHERE is_completed = true) as completed_todos"),
        )
        ->first();

    $linkedTodos = DB::table('todos')
        ->join('ticket_todo', 'todos.id', '=', 'ticket_todo.todo_id')
        ->whereIn('todos.unit_id', $accessibleIds)
        ->distinct('todos.id')
        ->count();

    return [
        'totalPersons'   => $row->total_persons,
        'totalUnits'     => $row->total_units,
        'totalTickets'   => $ticketRow->total_tickets,
        'openTickets'    => $ticketRow->open_tickets,
        'completedTickets' => $ticketRow->completed_tickets,
        'totalTodos'     => $todoRow->total_todos,
        'pendingTodos'   => $todoRow->pending_todos,
        'completedTodos' => $todoRow->completed_todos,
        'linkedTodos'    => $linkedTodos,
    ];
});
```
→ **3 queries instead of 9.**

Replace lines 82-106 (ticket details) with a single aggregation:
```php
$details = Cache::remember("dashboard:ticket_details:v{$v}:{$scopeKey}", 180, function () use ($accessibleIds) {
    $diffExpr = match (DB::getDriverName()) {
        'pgsql' => "EXTRACT(EPOCH FROM (completed_at - created_at)) / 86400",
        'sqlite' => "julianday(completed_at) - julianday(created_at)",
        default => "DATEDIFF(completed_at, created_at)",
    };

    return DB::table('tickets')
        ->where('unit_id', $accessibleIds)
        ->selectRaw("COUNT(*) FILTER (WHERE priority = 'urgent' AND status IN ('created','forwarded')) as urgent_tickets")
        ->addSelect(
            DB::raw("COUNT(*) FILTER (WHERE priority = 'normal' AND status IN ('created','forwarded')) as normal_tickets"),
            DB::raw("COUNT(*) FILTER (WHERE priority = 'low' AND status IN ('created','forwarded')) as low_tickets"),
            DB::raw("COUNT(*) FILTER (WHERE status IN ('created','forwarded') AND deadline < NOW()) as overdue_tickets"),
            DB::raw("AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL THEN {$diffExpr} END) as avg_resolution_days"),
        )
        ->first();
});
```
→ **1 query instead of 5.**

### Total Queries

| Phase | Before | After |
|-------|--------|-------|
| Global stats | 2 | 1 |
| Scoped stats | 9 | 3 |
| Ticket details | 5 | 1 |
| **Total** | **16** | **5** |

After consolidation, the cache callbacks share the same `$accessibleIds` array. We can merge the scoped stats and ticket details into a single callback if desired, bringing it to **3 queries** total.

---

## Verification

1. **Enable Debugbar and cold-cache the dashboard:**
   ```bash
   php artisan cache:clear
   ```
   Navigate to dashboard → check Debugbar query count.

2. **Run dashboard tests:**
   ```bash
   composer test -- --filter=Dashboard
   ```
   Expected: all tests pass with identical stat values.

3. **Correctness check:** Compare each stat value before/after to ensure the SQL aggregations return identical counts.

---

## STOP Conditions

- If `FILTER` clause syntax doesn't work on the PostgreSQL version, use `SUM(CASE WHEN ... THEN 1 ELSE 0 END)` instead.
- If any dashboard test asserts exact query counts, update the assertion.
- If the `ticket_todo` pivot table doesn't exist, verify via `php scripts/boost_tool.php query '{"sql": "SELECT table_name FROM information_schema.tables WHERE table_name LIKE '\''%ticket_todo%'\''"}' `

---

## Out of Scope

- Moving dashboard queries to a dedicated `DashboardService`.
- Adding dashboard-level API endpoint.
- Caching with Redis tags for fine-grained invalidation.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer test -- --filter=Dashboard` | All pass |
| 2 | Cold-cache dashboard query count (Debugbar) | ≤5 queries |
| 3 | Stat values match before/after refactor | Exact match |
| 4 | `vendor/bin/pint --dirty --format agent` | Clean |

---

## Maintenance Notes

- **PostgreSQL `FILTER` clause:** Available since PG 9.4. Safe to use with PG 16.
- **`$accessibleIds` injection:** The current code builds a `whereIn(...)` with an array. For the subselect, we need to inline IDs or use a temporary table. The `implode()` approach is safe for ≤500 IDs (typical). For larger sets, consider `DB::raw('(SELECT unnest(ARRAY[' . implode(',', $accessibleIds) . ']))')`.
- **Merge opportunity:** The scoped stats and ticket details callbacks could share a single cache key since they both depend on `$accessibleIds` and the same version counter. This would reduce queries to 3 total (1 global + 2 scoped).
