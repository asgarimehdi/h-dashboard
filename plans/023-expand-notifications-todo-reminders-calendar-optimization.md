# Plan 023: Expand Notification Scope + Todo Reminders + Todo Calendar Optimization

**Category:** direction | **Effort:** M | **Risk:** LOW | **Priority:** P3 | **Depends on:** none

---
## ⚠️ TL;DR فارسی

**مشکل:** اعلان فقط کامنت/منشن. واگذاری بی‌اعلان. Todo بدون یادآوری.

**⚠️ نکته:** فیلتر تقویم نباید overdue حذف کنه.

**ریسک:** 🟢 کم

---

## Problem

Three gaps in the notification and calendar systems:

1. **Notification scope is too narrow.** `NotificationService::send()` is currently called only from `TicketCommentController` for: comment creation (indirectly via mentions), mention, reply, and reaction. Several important events that affect users are silent:
   - **Ticket assignment** — `TicketController::assign()` (line 147) updates `current_assignee_id` but sends no notification to the assignee
   - **Hardware status changes** — `HardwareAuditObserver` records audit entries for status changes (shutdown/active transitions) but notifies nobody
   - **Maintenance ticket generation** — `GenerateDueMaintenance` creates tickets but doesn't notify the assigned unit
   - **Todo assignment** — no concept of assigning todos to specific users yet, but `Todo.user_id` exists and is unused in notifications

2. **Todo deadlines have no reminder system.** Todos with `end_at` dates that are approaching or overdue are never surfaced to users. There is no scheduled command to check for upcoming deadlines and send proactive notifications.

3. **Todo calendar loads all tickets without date filtering** (`todo/todo.blade.php:60-63`). The ticket query uses `->whereHas('task')` but the date range filter on the `Ticket` query is applied after `->whereHas('task')` — specifically, lines 60-73 show that the ticket query does apply date filtering when `$calendarStart`/`$calendarEnd` are set, but only on `created_at`. Tickets linked to tasks may have been created long ago but still have active tasks — the filter should also consider the task's `start_at`/`end_at` for more accurate scoping.

---

## Change 1: Expand NotificationService Calls

### Files to modify
- `app/Http/Controllers/Api/TicketController.php` — `assign()` method
- `app/Observers/HardwareAuditObserver.php` — `updating()` method
- `app/Console/Commands/GenerateDueMaintenance.php` — ticket creation block
- `app/Services/NotificationService.php` — add helper method (optional)

### 1a: Ticket Assignment Notification

**File:** `app/Http/Controllers/Api/TicketController.php`, `assign()` method (line 126-156)

After the `$ticket->update()` call (line 147-150), add:

```php
use App\Services\NotificationService;

// After ticket update:
NotificationService::send(
    $validated['assignee_id'],
    'ticket_assigned',
    "تیکت {$ticket->ticket_code} به شما ارجاع شد",
    $ticket->subject,
    'o-ticket',
    'text-blue-500',
    route('tickets.inbox') . "#ticket-{$ticket->id}",
    ['ticket_id' => $ticket->id, 'ticket_code' => $ticket->ticket_code]
);
```

Also notify the ticket creator (if different from the assigner and assignee):
```php
if ($ticket->creator_id && $ticket->creator_id !== auth()->id() && $ticket->creator_id !== $validated['assignee_id']) {
    NotificationService::send(
        $ticket->creator_id,
        'ticket_assigned_to_other',
        "تیکت {$ticket->ticket_code} به کاربر دیگری ارجاع شد",
        $ticket->subject,
        'o-arrow-right',
        'text-info',
        route('tickets.inbox') . "#ticket-{$ticket->id}"
    );
}
```

### 1b: Hardware Status Change Notification

**File:** `app/Observers/HardwareAuditObserver.php`, `updating()` method (line 57-68)

The observer already detects changed fields. Add notification when `shutdown` status changes:

```php
use App\Services\NotificationService;

public function updating(Hardware $hardware): void
{
    if ($this->shouldSuppress()) return;

    $changes = $this->getChangedFields($hardware);
    if (!empty($changes)) {
        $this->recordAudit($hardware, 'updated', $changes, $this->detectSource());
    }

    // Notify when shutdown status changes
    if ($hardware->isDirty('shutdown')) {
        $newStatus = $hardware->getDirty()['shutdown'] ? 'خاموش' : 'روشن';
        $unitId = $hardware->person?->u_id;
        if ($unitId) {
            NotificationService::notifyUnit(
                $unitId,
                'hardware_status_change',
                "وضعیت دستگاه {$hardware->pc_name} تغییر کرد: {$newStatus}",
                route('api.gis.hardware')
            );
        }
    }
}
```

### 1c: Maintenance Ticket Generation Notification

**File:** `app/Console/Commands/GenerateDueMaintenance.php`, after `Ticket::create()` (line 65-72)

```php
use App\Services\NotificationService;

// After ticket creation (line 72):
NotificationService::notifyUnit(
    $schedule->unit_id,
    'maintenance_ticket_created',
    "تیکت نگهداری «{$schedule->title}» ایجاد شد",
    'تیکت خودکار نگهداری',
    'o-wrench',
    'text-warning',
    route('tickets.inbox') . "#ticket-{$ticket->id}"
);
```

### Verification
- Assign a ticket via API → assignee receives notification
- Toggle hardware shutdown → unit receives notification
- Run `maintenance:generate-due` → unit receives notification

---

## Change 2: Todo Deadline Reminders

### Files to create
- `app/Console/Commands/SendTodoReminders.php` — new Artisan command

### Files to modify
- `app/Console/Kernel.php` — schedule the command

### Implementation

**Command** (`app/Console/Commands/SendTodoReminders.php`):
```php
namespace App\Console\Commands;

use App\Models\Todo;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendTodoReminders extends Command
{
    protected $signature = 'todos:send-reminders';
    protected $description = 'Send notifications for upcoming and overdue todo deadlines';

    public function handle(): int
    {
        $now = now();

        // 1. Todos due within the next 24 hours (and not yet completed)
        $upcoming = Todo::where('is_completed', false)
            ->whereNotNull('end_at')
            ->where('end_at', '>=', $now)
            ->where('end_at', '<=', $now->copy()->addDay())
            ->get();

        foreach ($upcoming as $todo) {
            $this->notifyTodoOwner($todo, 'upcoming', $todo->end_at->diffForHumans());
        }

        // 2. Overdue todos (past end_at, not completed)
        $overdue = Todo::where('is_completed', false)
            ->whereNotNull('end_at')
            ->where('end_at', '<', $now)
            ->where('created_at', '>=', $now->copy()->subDays(30)) // Don't notify on ancient todos
            ->get();

        foreach ($overdue as $todo) {
            $this->notifyTodoOwner($todo, 'overdue', $todo->end_at->diffForHumans());
        }

        $this->info("Sent reminders for {$upcoming->count()} upcoming and {$overdue->count()} overdue todos.");
        return 0;
    }

    private function notifyTodoOwner(Todo $todo, string $urgency, string $timeLabel): void
    {
        $userId = $todo->user_id;
        if (! $userId) return;

        // Avoid duplicate notifications: check if one was already sent today
        $todayKey = "todo_reminder:{$todo->id}:{$urgency}:" . now()->format('Y-m-d');
        if (\Illuminate\Support\Facades\Cache::has($todayKey)) return;
        Cache::put($todayKey, true, now()->endOfDay());

        $title = $urgency === 'upcoming'
            ? "وظیفه «{$todo->title}» {$timeLabel} دیگر سررسید می‌شود"
            : "وظیفه «{$todo->title}» {$timeLabel} از سررسید گذشته";

        $icon = $urgency === 'upcoming' ? 'o-clock' : 'o-exclamation-triangle';
        $color = $urgency === 'upcoming' ? 'text-warning' : 'text-error';

        NotificationService::send(
            $userId,
            "todo_{$urgency}",
            $title,
            $todo->title,
            $icon,
            $color,
            url('/todo')
        );
    }
}
```

**Schedule** (add to `app/Console/Kernel.php`):
```php
// Todo deadline reminders — every 4 hours
$schedule->command('todos:send-reminders')->everyFourHours();
```

### Verification
- Create a todo with `end_at` = 20 hours from now → runs in ~4 hours, user gets reminder
- Create a todo with `end_at` = yesterday → user gets overdue notification
- Run `todos:send-reminders` manually → see output
- Same todo doesn't get duplicate notifications within a day

---

## Change 3: Optimize Todo Calendar Ticket Query

### Files to modify
- `resources/views/livewire/todo/todo.blade.php` — `getEvents()` method, lines 42-117

### Current code (lines 60-73)
```php
$ticketQuery = Ticket::accessible()
    ->whereHas('task')
    ->with('task')
    ->whereIn('status', ['created', 'forwarded', 'accepted']);

// Apply date range filter when available
if ($this->calendarStart) {
    $todoQuery->where('start_at', '>=', $this->calendarStart);
    $ticketQuery->where('created_at', '>=', $this->calendarStart);
}
if ($this->calendarEnd) {
    $todoQuery->where('start_at', '<=', $this->calendarEnd);
    $ticketQuery->where('created_at', '<=', $this->calendarEnd);
}
```

### Issues
1. The date filter on tickets is on `created_at`, not on the task's dates. A ticket created months ago that has a task due next month won't appear until it enters the calendar viewport's date range on `created_at` — but it should be filtered by the task's `start_at`/`end_at`.
2. `->with('task')` is eager-loaded on ALL accessible tickets with tasks, even when only a narrow date range is visible. This fetches far more data than needed.

### Fix
Filter tickets by the linked task's date range instead of (or in addition to) `created_at`. Also, scope the ticket query more aggressively:

```php
$ticketQuery = Ticket::accessible()
    ->whereHas('task', function ($tq) use ($accessibleIds) {
        $tq->whereIn('unit_id', $accessibleIds);

        // Apply date range filter to the TASK, not the ticket
        if ($this->calendarStart) {
            $tq->where(function ($q) {
                // Show tasks that overlap with the calendar range:
                // task.start_at <= calendarEnd AND (task.end_at >= calendarStart OR task.end_at IS NULL)
                $q->where('start_at', '<=', $this->calendarEnd ?? now()->addYear())
                  ->where(function ($q2) {
                      $q2->whereNull('end_at')
                         ->orWhere('end_at', '>=', $this->calendarStart ?? now()->subYear());
                  });
            });
        }
    })
    ->whereIn('status', ['created', 'forwarded', 'accepted'])
    ->with('task:id,title,start_at,end_at');  // Only load needed columns
```

This ensures:
- Only tickets whose **task** overlaps with the calendar view are fetched
- The `with()` eager load selects only needed columns from `todos`
- The `->whereHas('task')` and date filter are combined in a single subquery instead of being separate operations

### Verification
- Navigate to todo calendar with a narrow date range → fewer tickets loaded
- A ticket created 3 months ago with a task due this month → appears when viewing this month
- A ticket created this month with a task due in 3 months → does NOT appear when viewing this month
- No visual regressions in calendar event display

---

## Verification Checklist

- [ ] Ticket assignment via API sends notification to assignee
- [ ] Hardware shutdown toggle sends notification to unit members
- [ ] `maintenance:generate-due` command creates notifications for the assigned unit
- [ ] `todos:send-reminders` command runs on schedule and sends reminders for upcoming/overdue todos
- [ ] Duplicate reminders within the same day are suppressed
- [ ] Todo calendar only shows tickets whose tasks overlap with the visible date range
- [ ] `composer test` / `php artisan test` passes
- [ ] No N+1 queries in todo calendar (inspect with Debugbar)
