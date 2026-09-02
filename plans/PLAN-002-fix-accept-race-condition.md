# Plan 002: Fix Accept Race Condition in Ticket Inbox

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** High  
**Category:** Bug Fix  

## Problem

When two agents simultaneously accept the same ticket, both succeed because `acceptTicket()` does not check the current ticket status before updating. This causes:
1. Duplicate acceptance activity log entries
2. `accepted_at` overwritten by second agent
3. `current_assignee_id` silently changed
4. Potential confusion about who actually "owns" the ticket

## Current State

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php:393-423`

```php
public function acceptTicket($ticketId): void
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    
    $ticket = Ticket::whereIn('unit_id', $accessibleIds)->findOrFail($ticketId);
    
    DB::transaction(function () use ($ticket) {
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

    // ثبت فعالیت
    \App\Services\ActivityLogService::updated(
        $ticket,
        ['status' => 'created'],
        ['status' => 'accepted'],
        "پذیرش تیکت {$ticket->ticket_code}"
    );
    // ...
}
```

**Issues:**
1. No status check before accepting — any status (including 'accepted', 'completed') will be overwritten
2. No `FOR UPDATE` lock — two concurrent transactions can both read status='created' and both proceed
3. The `ActivityLogService::updated()` call is **outside** the transaction, risking inconsistent state

## Proposed Fix

```php
public function acceptTicket($ticketId): void
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    
    $ticket = Ticket::whereIn('unit_id', $accessibleIds)->findOrFail($ticketId);

    // Capture old status BEFORE any changes (for activity log)
    $oldStatus = $ticket->status;

    // Check if ticket is in a state that can be accepted
    if ($oldStatus !== 'created' && $oldStatus !== 'forwarded') {
        $this->dispatch('swal', [
            'title' => 'تیکت قبلاً پذیرفته شده است',
            'icon' => 'warning'
        ]);
        return;
    }

    DB::transaction(function () use ($ticket, $oldStatus) {
        // Pessimistic lock: select for update to prevent concurrent accept
        $locked = DB::select('SELECT id, status FROM tickets WHERE id = ? FOR UPDATE', [$ticket->id]);
        
        if ($locked[0]->status !== $oldStatus) {
            throw new \App\Exceptions\TicketAlreadyAcceptedException($ticket->id);
        }

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

        // Activity log INSIDE the transaction for consistency
        \App\Services\ActivityLogService::updated(
            $ticket,
            ['status' => $oldStatus],
            ['status' => 'accepted'],
            "پذیرش تیکت {$ticket->ticket_code}"
        );
    });

    $this->dispatch('swal', ['title' => 'تیکت پذیرفته شد', 'icon' => 'success']);
    $this->closeDetail();
}
```

## Files to Modify

| File | Change |
|------|--------|
| `resources/views/livewire/tickets/⚡inbox.blade.php` (lines 393-423) | Add status check, FOR UPDATE lock, move activity log inside transaction |
| `app/Exceptions/TicketAlreadyAcceptedException.php` (new) | Custom exception for the race condition case |

**Out of scope:** rejectTicket(), forwardTicket() — similar pattern but separate plans.

## Verification

```bash
# 1. Run existing ticket tests
composer test -- --filter="ticket"
# Expected: all pass

# 2. Run full test suite
composer test
# Expected: 928+ pass, 0 fail

# 3. Manual concurrent test:
# Open two browser tabs with different users, both viewing same ticket
# Click "Accept" in both tabs simultaneously
# Second tab should show warning "تیکت قبلاً پذیرفته شد"
```

## Test Plan

```php
it('prevents two users from accepting the same ticket simultaneously', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $unit = Unit::factory()->create();
    $ticket = Ticket::factory()->create([
        'unit_id' => $unit->id,
        'status' => 'created',
    ]);

    // User 1 accepts
    Livewire::actingAs($user1)
        ->test('tickets.⚡inbox')
        ->call('acceptTicket', $ticket->id);

    $ticket->refresh();
    expect($ticket->status)->toBe('accepted');
    expect($ticket->current_assignee_id)->toBe($user1->id);

    // User 2 tries to accept the same ticket
    Livewire::actingAs($user2)
        ->test('tickets.⚡inbox')
        ->call('acceptTicket', $ticket->id)
        ->assertSee('تیکت قبلاً پذیرفته شد');
});
```

## STOP Conditions

- If `FOR UPDATE` syntax differs on current PostgreSQL version (check `pg_version()`)
- If `TicketAlreadyAcceptedException` is not caught by Livewire's exception handler
- If existing tests fail due to the new status check (some tests may accept tickets from non-'created' status)

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| FOR UPDATE deadlock | Requests hang | Short transaction scope, lock only one row |
| Livewire exception handling | 500 error | Use `try/catch` in the Livewire method |
| Existing tests assume any-status accept | Test failures | Audit test data states before implementation |

## Maintenance Notes

- The same pattern should be applied to `rejectTicket()` and `forwardTicket()` in follow-up plans
- Consider extracting a `TicketTransitionService` for all status changes if more methods accumulate
