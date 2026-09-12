# Plan 010: Extract DashboardService from dashboard Livewire god object

- **Category:** tech-debt
- **Effort:** L
- **Risk:** MED
- **Priority:** P2
- **Depends on:** none
- **Status:** proposed

## Problem

`resources/views/livewire/dashboard.blade.php` is a 580-line anonymous-class Livewire component that owns all data fetching, caching, and query logic directly inline. This makes it hard to test, reuse, or reason about independently.

### Concrete issues

1. **18 public properties** (lines 11-31) for stats that could be a single DTO.
2. **5 raw `DB::selectOne()` calls** (lines 60-64, 67-73, 76-82, 85-90, 133-141) with inline SQL for stats aggregation. These contain PostgreSQL-specific `FILTER` syntax and `EXTRACT(EPOCH...)` expressions.
3. **7 separate `Cache::remember()` blocks** across `mount()` and computed properties (lines 46, 56, 106, 125, 179, 201, 217) — each with its own cache key construction and TTL.
4. **No testability:** The component can't be unit-tested without booting Livewire; raw SQL is untestable in isolation.
5. **Duplicated scope key logic:** `$scopeKey = md5(implode(',', $accessibleIds))` is repeated 7 times across mount() and computed properties.

### What the component does (data flow)

```
mount() → 
  1. Read user settings (dashboard_refresh)
  2. Get accessible unit IDs
  3. Cache::remember("dashboard:global:...") → User::count(), Role::count()
  4. Cache::remember("dashboard:stats:...") → 5 raw SQL queries for persons/units/tickets/todos/linked
  5. Cache::remember("dashboard:today:...") → Today's tickets/todos/activities
  6. Cache::remember("dashboard:ticket_details:...") → Priority breakdown, overdue, avg resolution

Computed properties (lazy, per-request):
  7. ticketChartData → Ticket trend for 30-day chart
  8. ticketStatusData → Ticket status pie chart
  9. recentActivities → Last 10 activity logs
```

## Proposed Fix

### Step 1: Create `DashboardStats` DTO

```php
// app/DTOs/DashboardStats.php
namespace App\DTOs;

final readonly class DashboardStats
{
    public function __construct(
        public int $totalUsers,
        public int $totalPersons,
        public int $totalUnits,
        public int $totalTickets,
        public int $openTickets,
        public int $completedTickets,
        public int $totalTodos,
        public int $pendingTodos,
        public int $completedTodos,
        public int $linkedTodos,
        public int $totalRoles,
        public int $todayTickets,
        public int $todayTodos,
        public int $todayActivities,
        public int $urgentTickets,
        public int $normalTickets,
        public int $lowTickets,
        public int $overdueTickets,
        public float $avgResolutionDays,
    ) {}
}
```

### Step 2: Create `DashboardService`

```php
// app/Services/DashboardService.php
namespace App\Services;

use App\DTOs\DashboardStats;
use App\Models\{User, Person, Unit, Ticket, Todo, ActivityLog};
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\{Cache, DB};

class DashboardService
{
    private CacheInvalidationServiceInterface $cache;

    public function __construct(CacheInvalidationServiceInterface $cache)
    {
        $this->cache = $cache;
    }

    public function getStats(array $accessibleIds): DashboardStats
    {
        $v = $this->cache->getVersion('dashboard');
        $scopeKey = md5(implode(',', $accessibleIds));

        // Global stats (not scoped)
        $globalStats = $this->cache->remember(
            'dashboard', $scopeKey, fn () => [
                'totalUsers' => User::count(),
                'totalRoles' => Role::count(),
            ], 5, ['type' => 'global']
        );

        // Scoped stats — consolidated PostgreSQL FILTER queries
        $stats = $this->cache->remember(
            'dashboard', $scopeKey, fn () => $this->queryScopedStats($accessibleIds), 5, ['type' => 'scoped']
        );

        $todayStats = $this->cache->remember(
            'dashboard', $scopeKey, fn () => $this->queryTodayStats($accessibleIds), 2, ['type' => 'today']
        );

        $details = $this->cache->remember(
            'dashboard', $scopeKey, fn () => $this->queryTicketDetails($accessibleIds), 3, ['type' => 'details']
        );

        return new DashboardStats(
            totalUsers: $globalStats['totalUsers'],
            totalRoles: $globalStats['totalRoles'],
            totalPersons: $stats['totalPersons'],
            // ... map all fields
        );
    }

    private function queryScopedStats(array $accessibleIds): array
    {
        $ids = implode(',', $accessibleIds);
        // Move the 5 DB::selectOne() calls here
        // ...
    }

    private function queryTodayStats(array $accessibleIds): array
    {
        // Move today stats query here
        // ...
    }

    private function queryTicketDetails(array $accessibleIds): array
    {
        // Move priority/overdue/avg resolution query here
        // ...
    }

    public function getTicketChartData(array $accessibleIds): array { /* ... */ }
    public function getTicketStatusData(array $accessibleIds): array { /* ... */ }
    public function getRecentActivities(array $accessibleIds): \Illuminate\Database\Eloquent\Collection { /* ... */ }
}
```

### Step 3: Refactor Livewire component to thin pass-through

```php
// In dashboard.blade.php anonymous class:
return new class extends Component {
    public DashboardStats $stats;   // Single property replaces 18
    public bool $showHelpModal = false;
    public int $refreshInterval = 0;

    public function mount(): void
    {
        $settings = auth()->user()->settings ?? [];
        $this->refreshInterval = $settings['dashboard_refresh'] ?? 0;

        $service = app(DashboardService::class);
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $this->stats = $service->getStats($accessibleIds);
    }

    // Computed properties delegate to service
    public function getTicketChartDataProperty(): array
    {
        return app(DashboardService::class)
            ->getTicketChartData(app(AccessService::class)->accessibleUnitIds());
    }

    // ticketStatusData, recentActivities follow same pattern
};
```

### Step 4: Update Blade template

Replace `$totalUsers`, `$totalPersons`, etc. with `$stats->totalUsers`, `$stats->totalPersons`, etc. throughout the view. This is a mechanical find-and-replace.

### Step 5: Register in service provider

```php
// In AppServiceProvider::register()
$this->app->singleton(DashboardService::class, function ($app) {
    return new DashboardService($app->make(CacheInvalidationServiceInterface::class));
});
```

## Files to Create

1. **`app/DTOs/DashboardStats.php`** — readonly DTO with 18 fields
2. **`app/Services/DashboardService.php`** — encapsulates all 7 cache+query blocks

## Files to Modify

1. **`resources/views/livewire/dashboard.blade.php`** — replace 18 public properties with single `$stats` DTO; move SQL to service; update Blade references (`$totalX` → `$stats->totalX`)
2. **`app/Providers/AppServiceProvider.php`** — register DashboardService singleton

## Verification

1. **Visual regression:** Dashboard renders identically — same stat cards, same charts, same activity feed.
2. **Test pass:** `composer test --filter Dashboard` passes (if tests exist) or write new Pest test for DashboardService.
3. **Cache behavior:** Verify cache keys are still versioned and invalidated correctly.
4. **All 18 properties populated:** Template accesses every stat via DTO; nothing is missing.
5. **Raw SQL preserved:** The PostgreSQL-specific `FILTER` and `EXTRACT` syntax must be preserved exactly (works in pgsql, sqlite fallback for tests).

## Risks

- **Risk:** Changing 18 property names to `$stats->x` is a large Blade diff that could break. **Mitigation:** Mechanical rename, no logic changes. Use search-and-replace with verification.
- **Risk:** Cache key structure changes (extra `type` parameter in `remember()`). **Mitigation:** The `CacheInvalidationService::remember()` already hashes extra params into the key. This just adds categorization.
- **Risk:** The `read_file` output shows dashboard is 580 lines; the Blade portion (lines 229-580) references the property names heavily. **Mitigation:** Touch only lines 1-228 (PHP class) and the specific `$totalX` references in Blade; the Highcharts JS code references `$this->ticketChartData` which stays the same.
