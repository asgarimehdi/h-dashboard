# Plan 005: Fix Bulk Complete Missing Task Auto-Complete

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** Medium  
**Category:** Bug Fix  

## Problem

When a single ticket is completed, the code checks if all sibling tickets under the same task are completed, and if so, auto-completes the parent task. However, the bulk complete action uses `Ticket::whereIn()->update()` which bypasses Eloquent events, so this task auto-complete logic is never executed.

## Current State

### Single complete (correct behavior)

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php:520-528`

```php
// اگر وظیفه مرتبطی دارد و تمام تیکت‌های آن وظیفه بسته شده‌اند، وظیفه را تکمیل کن
if ($ticket->task_id) {
    $relatedTicket = \App\Models\Ticket::where('task_id', $ticket->task_id)
        ->where('status', '!=', 'completed')
        ->where('id', '!=', $ticket->id)
        ->count();
    if ($relatedTicket === 0) {
        $ticket->task->update(['is_completed' => true]);
        $message .= " وظیفه مرتبط نیز تکمیل شد.";
    }
}
```

### Bulk complete (missing logic)

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php:247-275`

```php
if ($this->bulkAction === 'complete') {
    Ticket::whereIn('id', $ticketIds)->update([
        'status' => 'completed',
        'completed_at' => $now,
    ]);
    Cache::increment('report_tickets_version');
    Cache::increment('gis_version');
    Cache::increment('calendar_version');

    $activityRows = $ticketIds->map(fn($id) => [
        'ticket_id' => $id,
        'user_id' => $userId,
        'action' => 'completed',
        'description' => 'تکمیل دسته‌ای تیکت' . ($bulkNote ? ": {$bulkNote}" : ''),
        'created_at' => $now,
        'updated_at' => $now,
    ])->toArray();
    TaskActivity::insert($activityRows);

    // ActivityLogService ...
    $tickets = Ticket::whereIn('id', $ticketIds)->get(['id', 'ticket_code', 'status']);
    foreach ($tickets as $ticket) {
        $oldStatus = $oldStatuses->get($ticket->id, $ticket->status);
        \App\Services\ActivityLogService::updated($ticket, ['status' => $oldStatus], ['status' => 'completed'], "...");
    }
    // ← NO task auto-complete logic here!
}
```

## Proposed Fix

After the bulk update and activity logging, add task auto-complete logic:

```php
// After the activity log loop, still inside the DB::transaction:
// Collect unique task IDs from the completed tickets
$completedTaskIds = Ticket::whereIn('id', $ticketIds)
    ->whereNotNull('task_id')
    ->pluck('task_id')
    ->unique();

foreach ($completedTaskIds as $taskId) {
    // Check if ALL tickets under this task are now completed
    $incompleteCount = Ticket::where('task_id', $taskId)
        ->where('status', '!=', 'completed')
        ->count();

    if ($incompleteCount === 0) {
        \App\Models\Todo::where('id', $taskId)->update(['is_completed' => true]);
        // Optional: log the task auto-completion
    }
}
```

**Note:** We need to read the task_id BEFORE the bulk update (since we have the tickets loaded for activity logging). Adjust to capture `$taskIds` during the existing `Ticket::whereIn('id', $ticketIds)->get(...)` call:

```php
$tickets = Ticket::whereIn('id', $ticketIds)->get(['id', 'ticket_code', 'status', 'task_id']);
$taskIds = $tickets->pluck('task_id')->filter()->unique();

foreach ($tickets as $ticket) {
    $oldStatus = $oldStatuses->get($ticket->id, $ticket->status);
    \App\Services\ActivityLogService::updated($ticket, ['status' => $oldStatus], ['status' => 'completed'], "...");
}

// Auto-complete parent tasks where all child tickets are completed
foreach ($taskIds as $taskId) {
    $incompleteCount = Ticket::where('task_id', $taskId)
        ->where('status', '!=', 'completed')
        ->count();
    if ($incompleteCount === 0) {
        \App\Models\Todo::where('id', $taskId)->update(['is_completed' => true]);
    }
}
```

## Files to Modify

| File | Lines | Change |
|------|-------|--------|
| `resources/views/livewire/tickets/⚡inbox.blade.php` | 270-275 | Add task auto-complete logic after bulk activity logging |

**Out of scope:** The `Ticket::whereIn()->update()` raw query (needed for performance); only the post-update logic changes.

## Verification

```bash
# 1. Run ticket tests
composer test -- --filter="ticket"
# Expected: all pass

# 2. Manual test:
# - Create a Todo/Task with 2 tickets
# - Bulk-complete both tickets
# - Verify the Todo is_auto_completed

# 3. Verify no regression on single complete
composer test -- --filter="single"
# Expected: all pass
```

## Test Plan

```php
it('auto-completes parent task when last ticket is bulk-completed', function () {
    $user = User::factory()->create();
    $task = Todo::factory()->create(['is_completed' => false]);
    
    $ticket1 = Ticket::factory()->create(['task_id' => $task->id, 'status' => 'created']);
    $ticket2 = Ticket::factory()->create(['task_id' => $task->id, 'status' => 'created']);

    Livewire::actingAs($user)
        ->test('tickets.⚡inbox')
        ->set('selectedTickets', [$ticket1->id, $ticket2->id])
        ->set('bulkAction', 'complete')
        ->call('processBulkAction');

    $task->refresh();
    expect($task->is_completed)->toBeTrue();
});

it('does not auto-complete parent task when other tickets remain', function () {
    $user = User::factory()->create();
    $task = Todo::factory()->create(['is_completed' => false]);
    
    $ticket1 = Ticket::factory()->create(['task_id' => $task->id, 'status' => 'created']);
    $ticket2 = Ticket::factory()->create(['task_id' => $task->id, 'status' => 'created']);

    Livewire::actingAs($user)
        ->test('tickets.⚡inbox')
        ->set('selectedTickets', [$ticket1->id])
        ->set('bulkAction', 'complete')
        ->call('processBulkAction');

    $task->refresh();
    expect($task->is_completed)->toBeFalse();
});
```

## STOP Conditions

- If `Todo` model uses different method than `update(['is_completed' => true])` for completion
- If `task_id` in tickets table references a different model than `Todo`

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Task referenced by tickets across different bulk actions | False auto-complete | Only check after 'complete' action |
| Todo model relationship name differs | Wrong model queried | Verify `$ticket->task` relationship definition |
| Performance with many tasks | Slow bulk operation | Only query tasks that have non-null task_id (already filtered) |

## Maintenance Notes

- Consider extracting `autoCompleteTaskIfNeeded($taskIds)` as a reusable service method
- The same logic should be added to forward/reject bulk actions if applicable
