# Plan 012: Queue heavy operations

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: performance
- **Planned at**: 2026-09-19

## Why this matters
All scheduled commands run synchronously. Bulk delete, hardware import, and daily report generation can exceed PHP timeout. Redis is configured but unused for queue.

## Current state
- `.env`: `QUEUE_CONNECTION=redis`
- Zero classes implement `ShouldQueue`
- No `app/Jobs/` directory
- Bulk operations in tools.blade.php and import-hardware run synchronously

## Steps

### Step 1: Create Jobs directory and job classes
Create `app/Jobs/` with:
- `ArchiveTicketsJob.php`
- `CleanActivityLogsJob.php`
- `GenerateDailyReportsJob.php`

### Step 2: Convert bulk operations
In `resources/views/livewire/tools/tools.blade.php`, replace direct DB calls with job dispatch:
```php
dispatch(new ArchiveTicketsJob($olderThanDays));
```

### Step 3: Convert scheduled commands
In `app/Console/Kernel.php`, wrap heavy commands with `ShouldQueue`:
```php
$schedule->job(new GenerateDailyReportsJob())->dailyAt('06:00');
```

### Step 4: Verify
**Verify**: `cd /home/runner/h-dashboard && ls app/Jobs/` → should list job files
**Verify**: `grep -rn "ShouldQueue" app/Jobs/` → should show implementations

## Done criteria
- [ ] 3+ job classes created
- [ ] Bulk operations dispatched to queue
- [ ] Scheduled heavy commands use jobs
- [ ] pint passes
