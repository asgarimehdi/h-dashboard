# Plan 003: Fix Hardcoded Old Status in acceptTicket Activity Log

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** Medium  
**Category:** Bug Fix  

## Problem

In `acceptTicket()`, the `ActivityLogService::updated()` call hardcodes the old status as `'created'`:

```php
\App\Services\ActivityLogService::updated(
    $ticket,
    ['status' => 'created'],   // ← HARDCODED
    ['status' => 'accepted'],
    "پذیرش تیکت {$ticket->ticket_code}"
);
```

This is incorrect because a ticket can be accepted from statuses other than `'created'` (e.g., `'forwarded'`). The activity log will always show `'created' → 'accepted'` regardless of the actual previous status.

The bulk action methods (lines 244-245) already handle this correctly:

```php
$oldStatuses = Ticket::whereIn('id', $ticketIds)
    ->pluck('status', 'id');
```

## Current State

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php:414-418`

```php
\App\Services\ActivityLogService::updated(
    $ticket,
    ['status' => 'created'],
    ['status' => 'accepted'],
    "پذیرش تیکت {$ticket->ticket_code}"
);
```

Compare with bulk complete (line 244-245):

```php
$oldStatuses = Ticket::whereIn('id', $ticketIds)
    ->pluck('status', 'id');
```

And bulk complete activity log (line 272-273):

```php
$oldStatus = $oldStatuses->get($ticket->id, $ticket->status);
\App\Services\ActivityLogService::updated($ticket, ['status' => $oldStatus], ['status' => 'completed'], "...");
```

## Proposed Fix

Capture the old status before the update, pass it to the activity log:

```php
public function acceptTicket($ticketId): void
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    
    $ticket = Ticket::whereIn('unit_id', $accessibleIds)->findOrFail($ticketId);
    
    // Capture old status before update
    $oldStatus = $ticket->status;

    DB::transaction(function () use ($ticket, $oldStatus) {
        $ticket->update([
            'status' => 'accepted',
            'current_assignee_id' => auth()->id(),
            'accepted_at' => now(),
        ]);

        $ticket->activities()->create([
            'user_id' => auth()->id(),
            'action' => 'accepted',
            'description' => 'تیکت توسط کارشناس تایید شد و مسئولیت آن پذیرفته شد.'
        ]);
    });

    \App\Services\ActivityLogService::updated(
        $ticket,
        ['status' => $oldStatus],        // ← DYNAMIC, not hardcoded
        ['status' => 'accepted'],
        "پذیرش تیکت {$ticket->ticket_code}"
    );

    $this->dispatch('swal', ['title' => 'تیکت پذیرفته شد', 'icon' => 'success']);
    $this->closeDetail();
}
```

**Note:** This fix is closely related to Plan 002 (race condition). They should be implemented together since both modify `acceptTicket()`. The `FOR UPDATE` lock in Plan 002 ensures the `$oldStatus` captured here doesn't change between the read and the write.

## Files to Modify

| File | Line | Change |
|------|------|--------|
| `resources/views/livewire/tickets/⚡inbox.blade.php` | 393-423 | Capture `$oldStatus` before transaction, use in ActivityLogService |

**Out of scope:** `rejectTicket()` (line 425+), `forwardTicket()` — similar issue but separate scope.

## Verification

```bash
# 1. Verify the fix in code
grep -n "ActivityLogService::updated" resources/views/livewire/tickets/⚡inbox.blade.php
# Expected: lines show dynamic $oldStatus, not hardcoded 'created'

# 2. Run tests
composer test -- --filter="ticket"
# Expected: all pass

# 3. Manual test: forward a ticket, then accept it
# Check activity_logs table — old status should be 'forwarded', not 'created'
php scripts/boost_tool.php query '{"sql": "SELECT old_values, new_values FROM activity_logs WHERE subject_type = '\''App\\\\Models\\\\Ticket'\'' ORDER BY id DESC LIMIT 5"}'
```

## Test Plan

```php
it('logs correct old status when accepting a forwarded ticket', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->create([
        'status' => 'forwarded',
        'unit_id' => $user->person?->u_id ?? Unit::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('tickets.⚡inbox')
        ->call('acceptTicket', $ticket->id);

    $log = ActivityLog::where('subject_type', Ticket::class)
        ->where('subject_id', $ticket->id)
        ->where('type', 'updated')
        ->latest()
        ->first();

    expect($log->old_values['status'])->toBe('forwarded')
        ->and($log->new_values['status'])->toBe('accepted');
});
```

## STOP Conditions

- If `ActivityLogService::updated()` signature differs from expected
- If test database lacks forwarded tickets in fixture data

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| NULL old status (first-ever update) | Misleading log entry | Fallback: `$oldStatus ?? $ticket->getOriginal('status')` |
| Race condition with concurrent update | Stale oldStatus read | Handled by Plan 002's FOR UPDATE lock |

## Maintenance Notes

- Apply same pattern to `rejectTicket()`, `forwardTicket()`, and any future status-transition methods
- Consider a `TicketTransition` value object if more fields need old/new tracking
