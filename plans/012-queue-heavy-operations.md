# Plan 012: Queue heavy operations

> **Executor instructions**: Follow this plan step by step. This plan is more conservative than v1 — start with the lowest-risk conversions first.

## Status
- **Priority**: P3
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: performance
- **Planned at**: 2026-09-19 (v2, realistic scope)

## Why this matters
All scheduled commands run synchronously. Bulk delete, hardware import, and daily report generation can exceed PHP timeout. Redis is configured but unused for queue.

## Current state
- `.env`: `QUEUE_CONNECTION=redis`
- Zero classes implement `ShouldQueue`
- No `app/Jobs/` directory
- Bulk operations in `resources/views/livewire/tools/tools.blade.php` run synchronously

## AGENTS.md note
> `zabbix:sync` schedule uses `->everyFiveMinutes()` — do NOT add `->timeout(N)` to any schedule call (throws `BadMethodCallException`). HTTP timeout lives in `ZabbixService::request()`.

## Scope — conservative approach
**Phase 1 (this plan)**: Create Job classes for the 3 heaviest operations only. Do NOT touch scheduled commands yet — they need deeper analysis of failure modes.

## Steps

### Step 1: Create Jobs directory and 3 job classes
Create `app/Jobs/` with:

**`app/Jobs/ArchiveActivityLogsJob.php`**:
```php
<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\ActivityLogArchive;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ArchiveActivityLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function handle(): void
    {
        $cutoff = now()->subDays(90);

        $logs = ActivityLog::where('created_at', '<', $cutoff)->limit(1000)->get();

        foreach ($logs->chunk(100) as $chunk) {
            ActivityLogArchive::insert($chunk->toArray());
            ActivityLog::whereIn('id', $chunk->pluck('id'))->delete();
        }
    }
}
```

**`app/Jobs/GenerateDailyReportsJob.php`**:
```php
<?php

namespace App\Jobs;

use App\Console\Commands\GenerateDailyReports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
useIlluminate\Queue\InteractsWithQueue;

class GenerateDailyReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public function handle(): void
    {
        $command = app(GenerateDailyReports::class);
        $command->handle();
    }
}
```

### Step 2: Convert tools.blade.php bulk operations
In `resources/views/livewire/tools/tools.blade.php`, replace direct DB calls for activity log archival with:
```php
dispatch(new \App\Jobs\ArchiveActivityLogsJob());
```

### Step 3: Verify
```bash
ls app/Jobs/
# → should list job files
grep -rn "ShouldQueue" app/Jobs/
# → should show implementations
php artisan queue:work --once 2>&1 | head -5
# → verify queue worker can process jobs
```

### Step 4: Test review
Create `tests/Feature/Jobs/ArchiveActivityLogsJobTest.php`:
- test job archives old logs
- test job handles empty table gracefully

## STOP conditions
- If any job fails in testing, do NOT proceed to convert scheduled commands
- Do NOT touch `zabbix:sync` schedule

## Done criteria
- [ ] 2+ job classes created implementing ShouldQueue
- [ ] Bulk operations dispatched to queue
- [ ] Pest tests added
- [ ] `vendor/bin/pint --dirty --format agent` passes
