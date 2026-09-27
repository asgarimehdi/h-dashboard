# Plan 5: Synchronous notification sends in TicketCommentController — queue instead

> Written against commit: `4ef9a0f` (beta/sydney)
> Category: Performance | Effort: M | Impact: HIGH

## Problem

`TicketCommentController` calls `NotificationService::send()` synchronously in three code paths: `store()` for @mentions and reply notifications, and `react()` for reaction notifications. Each `NotificationService::send()` call performs a database INSERT and a `Cache::forget()` — both synchronous operations that block the HTTP response.

In high-traffic scenarios (a comment with many @mentions, or rapid reactions), these synchronous calls compound:
- A comment mentioning 5 users triggers 5 sequential DB writes + 5 cache invalidations inside the request.
- The `react()` endpoint — a lightweight UX action — also blocks on a DB write + cache invalidation before returning.

The project already has a job infrastructure (`app/Jobs/`) with Redis queue in production (`QUEUE_CONNECTION=redis`), but notification sending bypasses it entirely.

**Compare** with `app/Jobs/CleanNotificationsJob.php` which follows the established `ShouldQueue` pattern.

### Evidence

- `app/Http/Controllers/Api/TicketCommentController.php:97-99` — `store()` calls `notifyMentions()` synchronously after creating the comment
- `app/Http/Controllers/Api/TicketCommentController.php:102-107` — `store()` calls `notifyReply()` synchronously
- `app/Http/Controllers/Api/TicketCommentController.php:202-205` — `react()` calls `notifyReaction()` synchronously
- `app/Services/NotificationService.php:13-38` — `send()` does DB INSERT (line 23) + `Cache::forget()` (line 35) inline
- `app/Http/Controllers/Api/TicketCommentController.php:352-416` — three private methods (`notifyMentions`, `notifyReply`, `notifyReaction`) all call `NotificationService::send()` directly

## Solution

Create a `SendNotificationJob` that wraps `NotificationService::send()` as a queued job. Replace direct `NotificationService::send()` calls in `TicketCommentController` with `SendNotificationJob::dispatch()`.

### Before (TicketCommentController.php:352-368)

```php
private function notifyMentions(array $mentions, TicketComment $comment, User $author): void
{
    foreach ($mentions as $username => $userId) {
        if ($userId === $author->id) {
            continue;
        }

        NotificationService::send(
            $userId,
            'mention',
            "شما در یک نظر به تیکت {$comment->ticket->ticket_code} منشن شدید",
            'منشن در نظر',
            'at-sign',
            'text-blue-500',
            route('tickets.inbox', $comment->ticket_id)
        );
    }
}
```

### After

```php
private function notifyMentions(array $mentions, TicketComment $comment, User $author): void
{
    foreach ($mentions as $username => $userId) {
        if ($userId === $author->id) {
            continue;
        }

        SendNotificationJob::dispatch(
            $userId,
            'mention',
            "شما در یک نظر به تیکت {$comment->ticket->ticket_code} منشن شدید",
            'منشن در نظر',
            'at-sign',
            'text-blue-500',
            route('tickets.inbox', $comment->ticket_id)
        );
    }
}
```

### SendNotificationJob (new file: app/Jobs/SendNotificationJob.php)

```php
<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries = 3;

    public function __construct(
        public int $userId,
        public string $type,
        public string $title,
        public ?string $body = null,
        public string $icon = 'o-bell',
        public string $color = 'text-info',
        public ?string $url = null,
        public ?array $data = null,
    ) {}

    public function handle(): void
    {
        NotificationService::send(
            $this->userId,
            $this->type,
            $this->title,
            $this->body,
            $this->icon,
            $this->color,
            $this->url,
            $this->data,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendNotificationJob failed for user {$this->userId}: {$exception->getMessage()}");
    }
}
```

## Files in Scope

- `app/Http/Controllers/Api/TicketCommentController.php` — replace `NotificationService::send()` with `SendNotificationJob::dispatch()`
- `app/Jobs/SendNotificationJob.php` — **new file** wrapping NotificationService::send()

## Files Out of Scope

- `app/Services/NotificationService.php` — no changes needed; the service remains available for other callers
- `app/Jobs/CleanNotificationsJob.php`, `app/Jobs/ArchiveActivityLogsJob.php`, `app/Jobs/GenerateDailyReportsJob.php` — existing jobs, unchanged
- `app/Http/Controllers/Api/NotificationController.php` — API endpoints, no notification sending
- No other controllers currently call `NotificationService::send()` (confirmed via grep)

## Steps

### Step 1: Create SendNotificationJob

1. Create `app/Jobs/SendNotificationJob.php` with the job class shown above
2. Run `vendor/bin/pint --dirty --format agent` to format

### Step 2: Update TicketCommentController — notifyMentions

1. Add `use App\Jobs\SendNotificationJob;` import
2. In `notifyMentions()` (line 352-368): replace `NotificationService::send(...)` with `SendNotificationJob::dispatch(...)`
3. Remove `use App\Services\NotificationService;` import if no longer used directly (it's still referenced indirectly through the job)

### Step 3: Update TicketCommentController — notifyReply

1. In `notifyReply()` (line 374-385): replace `NotificationService::send(...)` with `SendNotificationJob::dispatch(...)`

### Step 4: Update TicketCommentController — notifyReaction

1. In `notifyReaction()` (line 390-416): replace `NotificationService::send(...)` with `SendNotificationJob::dispatch(...)`

### Step 5: Verify existing tests

```bash
composer test       # All 1352 tests should pass (sync queue in tests)
```

Tests use `QUEUE_CONNECTION=sync` (phpunit.xml), so dispatched jobs execute inline. The existing notification tests in `TicketCommentApiComprehensiveTest.php` (lines 373-448) assert `assertDatabaseHas('notifications', ...)` and will continue to pass because:
- Sync queue executes `SendNotificationJob::handle()` immediately
- `handle()` calls `NotificationService::send()` which creates the DB record
- The DB assertion sees the record

### Step 6: Add job-specific tests

1. Create `tests/Feature/Jobs/SendNotificationJobTest.php`:
   - Test that `SendNotificationJob::dispatch()` creates a notification in the database
   - Test that `failed()` logs an error without throwing
   - Test with `QUEUE_CONNECTION=sync` (default in tests)

### Step 7: Format and finalize

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
composer test
```

## Test Plan

1. **Existing tests pass unchanged** — `TicketCommentApiComprehensiveTest` tests at lines 373-448 (`test_reply_notifies_parent_author`, `test_mention_creates_notification`, `test_reaction_notifies_comment_author`) use sync queue, so they exercise the job's `handle()` method inline
2. **New unit test** — `SendNotificationJobTest.php`:
   - `test_job_creates_notification`: dispatch with known params, assert `notifications` table has the row
   - `test_job_logs_on_failure`: mock `NotificationService::send()` to throw, assert `Log::error` called
3. **Regression** — run `composer test` to verify all 1352 tests pass

## Maintenance Note

- When `QUEUE_CONNECTION=redis` in production, notifications are truly async. If Redis goes down, jobs will retry 3 times (matching existing job conventions) then land in `failed_jobs`. Monitor `failed_jobs` table after deployment.
- `NotificationService::send()` remains a public static method. Other controllers or services can still call it directly if they need synchronous notification delivery (e.g., critical security alerts).
- `route()` calls in `TicketCommentController` (e.g., `route('tickets.inbox', $comment->ticket_id)`) execute in the controller before dispatch — the job receives the resolved URL string, so route model binding is not an issue.
- If the project adds `Bus::batch()` for grouped notification dispatch in the future, `SendNotificationJob` already implements `ShouldQueue` and uses `SerializesModels`, so it's compatible.

## Done Criteria

- [ ] `app/Jobs/SendNotificationJob.php` exists and implements `ShouldQueue`
- [ ] `TicketCommentController` no longer imports `NotificationService` directly (or only if still needed for other reasons)
- [ ] `notifyMentions`, `notifyReply`, `notifyReaction` dispatch `SendNotificationJob` instead of calling `NotificationService::send()`
- [ ] All existing tests pass (`composer test`)
- [ ] New `SendNotificationJobTest` covers dispatch and failure logging
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
- [ ] `grep -rn "NotificationService::send" app/Http/Controllers/` returns 0 results
