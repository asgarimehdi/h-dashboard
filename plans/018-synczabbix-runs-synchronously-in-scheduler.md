# Plan 18: SyncZabbix runs synchronously in scheduler — blocks for up to 20s

> Written against commit: `b027ea4` (beta/sydney)
> Category: Performance | Effort: S | Impact: Medium

## Problem

`SyncZabbix` command runs every 5 minutes via the scheduler (`app/Console/Kernel.php:31-34`) and makes 2 sequential HTTP calls to the Zabbix API — each with a `->timeout(10)` in `ZabbixService::request()` (line 79). When Zabbix is slow or unreachable, the command blocks the scheduler process for up to 20 seconds.

Compounding this: **the command fetches data but never stores it** — no cache, no database write. The results are simply printed to stdout and discarded. Meanwhile, `TrafficController` independently makes the same 2 HTTP calls on every API request, with only a 30-second `Cache::remember()` TTL (`TrafficController.php:27-36`). This means:

1. The scheduler process is blocked for up to 20s every 5 minutes doing work that is thrown away.
2. `TrafficController` hits the Zabbix API fresh every 30 seconds, duplicating the same calls the scheduler already made.
3. If Zabbix is unreachable, the scheduler silently fails (`SyncZabbix.php:33-35`) while `TrafficController` independently hits the same failing endpoint.

### Evidence

- `app/Console/Commands/SyncZabbix.php:14-41` — command runs synchronously, fetches data, discards it (no `Cache::put`, no DB write)
- `app/Console/Kernel.php:31-34` — scheduler registers `zabbix:sync` with `->everyFiveMinutes()->withoutOverlapping()->runInBackground()`
- `app/Services/ZabbixService.php:79` — `->timeout(10)` per HTTP call (2 calls = up to 20s total)
- `app/Http/Controllers/Api/TrafficController.php:27-36` — `Cache::remember()` with 30s TTL, independent of scheduler
- `references/api-endpoints.md:29` — docs note: "Do not add `->timeout(N)` to the schedule — method doesn't exist, throws `BadMethodCallException`"

## Solution

**Make `SyncZabbix` a queued job that caches traffic data**, and have `TrafficController` read from that cache instead of making direct Zabbix API calls.

### Step 1: Create `SyncZabbixJob`

Create `app/Jobs/SyncZabbixJob.php` following the pattern of existing jobs (`CleanNotificationsJob`, `GenerateDailyReportsJob`):

```php
<?php

namespace App\Jobs;

use App\Services\ZabbixService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncZabbixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30; // covers 2 HTTP calls × 10s timeout
    public int $tries = 2;

    public function handle(ZabbixService $zabbix): void
    {
        $outItemId = config('services.zabbix.out_item_id');
        $inItemId = config('services.zabbix.in_item_id');

        if (empty($outItemId) || empty($inItemId)) {
            Log::warning('SyncZabbixJob: Zabbix item IDs not configured, skipping.');
            return;
        }

        try {
            $out = $zabbix->getInterfaceTraffic($outItemId);
            $in = $zabbix->getInterfaceTraffic($inItemId);

            $data = ['out' => $out, 'in' => $in];

            // Cache for 5 minutes (matching scheduler interval) + 30s buffer
            Cache::put('zabbix:traffic:synced', $data, now()->addMinutes(5));
            Cache::put("traffic_{$outItemId}_{$inItemId}_3600", $data, now()->addMinutes(5));

            Log::info('SyncZabbixJob: synced '.count($out).' out + '.count($in).' in records.');
        } catch (\Throwable $e) {
            Log::error('SyncZabbixJob failed: '.$e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncZabbixJob failed after retries: '.$exception->getMessage());
    }
}
```

### Step 2: Update scheduler to dispatch the job

Replace the synchronous command with a job dispatch in `app/Console/Kernel.php`:

```php
// Before
$schedule->command('zabbix:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// After
$schedule->job(new \App\Jobs\SyncZabbixJob)
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

Note: `->runInBackground()` is removed because queued jobs are inherently non-blocking — they dispatch to the queue worker. `->withoutOverlapping()` remains to prevent duplicate dispatches.

### Step 3: Update `TrafficController` to prefer pre-fetched cache

```php
// Before (TrafficController.php:25-36)
$data = Cache::remember(
    "traffic_{$outItemId}_{$inItemId}_{$duration}",
    30,
    function () use ($zabbix, $outItemId, $inItemId, $duration) {
        return [
            'out' => $zabbix->getInterfaceTraffic($outItemId, $duration),
            'in' => $zabbix->getInterfaceTraffic($inItemId, $duration),
        ];
    }
);

// After
$data = Cache::get("traffic_{$outItemId}_{$inItemId}_3600");

if (is_null($data)) {
    // Fallback: no pre-fetched data available (scheduler missed or first run)
    $data = Cache::remember(
        "traffic_{$outItemId}_{$inItemId}_{$duration}",
        30,
        function () use ($zabbix, $outItemId, $inItemId, $duration) {
            return [
                'out' => $zabbix->getInterfaceTraffic($outItemId, $duration),
                'in' => $zabbix->getInterfaceTraffic($inItemId, $duration),
            ];
        }
    );
}
```

### Step 4: Keep `SyncZabbix` command as-is (for manual runs)

The command stays in place for `php artisan zabbix:sync` manual execution. It already does the fetch — optionally add a `Cache::put()` call so manual runs also warm the cache:

```php
// SyncZabbix.php — add before the final success message
Cache::put('zabbix:traffic:synced', $data, now()->addMinutes(5));
Cache::put("traffic_{$outItemId}_{$inItemId}_3600", $data, now()->addMinutes(5));
```

## Files in Scope

- `app/Jobs/SyncZabbixJob.php` (new)
- `app/Console/Kernel.php:31-34`
- `app/Console/Commands/SyncZabbix.php:27-41`
- `app/Http/Controllers/Api/TrafficController.php:25-36`

## Files Out of Scope

- `app/Services/ZabbixService.php` (timeout config is fine, no change needed)
- `app/Http/Controllers/Api/MultiLatestValueController.php` (separate endpoint, separate concern)
- `config/services.php` (no config changes needed)

## Steps

### Step 1: Create `SyncZabbixJob`
1. Create `app/Jobs/SyncZabbixJob.php` with `ShouldQueue`, `$timeout = 30`, `$tries = 2`
2. `handle()` fetches traffic data and writes to 2 cache keys with 5-minute TTL
3. `failed()` logs the error
4. Verify: `php artisan tinker --execute 'new App\Jobs\SyncZabbixJob'` (instantiates without error)

### Step 2: Update scheduler
1. Replace `$schedule->command('zabbix:sync')` with `$schedule->job(new \App\Jobs\SyncZabbixJob)`
2. Keep `->everyFiveMinutes()->withoutOverlapping()`
3. Remove `->runInBackground()` (jobs are inherently async)
4. Verify: `php artisan schedule:list` shows the job

### Step 3: Update `TrafficController`
1. Check `Cache::get()` for the pre-fetched key before falling back to `Cache::remember()`
2. Verify: `grep -n 'traffic_' app/Http/Controllers/Api/TrafficController.php` shows both cache keys

### Step 4: Update `SyncZabbix` command (optional)
1. Add `Cache::put()` calls before the success message so manual runs also warm the cache
2. Verify: `grep -n 'Cache::put' app/Console/Commands/SyncZabbix.php`

### Step 5: Verify
```bash
composer phpstan    # Ensure no new errors
composer pint       # Format
composer test       # All tests pass
```

## Test Plan

1. **Existing `SyncZabbixCommandTest.php`** — update mock expectations to verify `Cache::put()` is called on success
2. **New `SyncZabbixJobTest.php`** — test all 3 paths:
   - Unconfigured item IDs → logs warning, returns early
   - Zabbix fetch failure → logs error, returns early
   - Success → verifies `Cache::get('zabbix:traffic:synced')` returns data
3. **Existing `TrafficApiTest.php`** — update `test_traffic_caches_results` to verify the pre-fetched cache key is read first
4. **Existing `ScheduledJobInfrastructureTest.php`** — verify schedule still contains a zabbix-related event
5. Verify no raw `getInterfaceTraffic` calls remain in TrafficController when pre-fetched data exists

## Maintenance Note

- If the Zabbix item IDs in config change, the scheduler will cache the new IDs but stale cache keys with old IDs will linger until TTL expires. Consider clearing old keys on config change (low priority — TTL handles it).
- The `SyncZabbixJob` writes to 2 cache keys (`zabbix:traffic:synced` and `traffic_{out}_{in}_3600`). The second key matches the existing `TrafficController` cache key format so the controller's fallback path still works if someone adds a new duration parameter.
- Do NOT add `->timeout(N)` to the scheduler — it throws `BadMethodCallException` on this Laravel version. The job's own `$timeout` property handles this.

## Done Criteria

- [ ] `SyncZabbixJob.php` exists and implements `ShouldQueue`
- [ ] Scheduler dispatches the job instead of running the command synchronously
- [ ] `TrafficController` reads pre-fetched cache before falling back to live API call
- [ ] `SyncZabbix` command optionally warms cache on manual runs
- [ ] All existing tests pass (`composer test`)
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
- [ ] `grep -rn 'runInBackground' app/Console/Kernel.php` returns 0 results
- [ ] `SyncZabbixJobTest.php` covers success, failure, and unconfigured paths
