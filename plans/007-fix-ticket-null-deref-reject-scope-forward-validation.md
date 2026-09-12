# Plan 007: Fix null deref + rejectTicket scope + forward validation + API status transitions

- **Status:** Not started
- **Category:** bug
- **Effort:** M
- **Risk:** MED
- **Priority:** P2
- **Depends on:** none
- **Base SHA:** 5f9c24e

## Why this matters

Four related correctness issues in the ticket system:

1. **Null dereference crash:** `$ticket->task->update()` on line 576 of `⚡inbox.blade.php` can NPE if the linked `Todo` was deleted but `task_id` was never nulled — a 500 error for the user.
2. **rejectTicket scope mismatch:** `rejectTicket()` (line 484) queries `Ticket::where('unit_id', auth()->user()->person?->u_id)` — a single unit scope. But `acceptTicket()` (line 424) uses `whereIn('unit_id', $this->getAccessibleUnitIds())` — the full recursive-access scope. Users with multi-unit access can reject tickets from accessible units but shouldn't, and vice versa: users in a unit hierarchy may not be able to reject tickets they should be able to.
3. **Missing forward validation:** `forward()` (line 385) doesn't check `can_receive_tickets` on the target unit, allowing forwarding to units that shouldn't receive tickets.
4. **No API status validation:** `TicketController::update()` (line 99) accepts any `status` change without validating the transition is legal (e.g., setting a completed ticket back to created).

## Current state (with file:line excerpts)

### Null dereference — `resources/views/livewire/tickets/⚡inbox.blade.php:576-582`

```php
if ($ticket->task_id) {
    $relatedTicket = \App\Models\Ticket::where('task_id', $ticket->task_id)
        ->where('status', '!=', 'completed')
        ->where('id', '!=', $ticket->id)
        ->count();
    if ($relatedTicket === 0) {
        $ticket->task->update(['is_completed' => true]); // <-- line 582: NPE if Todo deleted
        $message .= "وظیفه مرتبط نیز تکمیل شد.";
    }
}
```

`$ticket->task_id` is checked, but `$ticket->task` (the relationship) may return null if the Todo was deleted and `task_id` was not cleaned up.

### rejectTicket scope — `resources/views/livewire/tickets/⚡inbox.blade.php:484`

```php
$ticket = Ticket::where('unit_id', auth()->user()->person?->u_id)->findOrFail($ticketId);
```

Compare with acceptTicket (line 424-426):
```php
$accessibleIds = app(AccessService::class)->accessibleUnitIds();
$ticket = Ticket::whereIn('unit_id', $accessibleIds)->find($ticketId);
```

The scopes differ: `rejectTicket` uses a single unit, `acceptTicket` uses the full recursive-access set.

### Missing forward validation — `resources/views/livewire/tickets/⚡inbox.blade.php:395-398`

```php
$this->validate([
    'targetUnitId' => 'required|exists:units,id',
    'forwardNote' => 'nullable|string|max:500',
]);
```

No check for `Unit::find($this->targetUnitId)->can_receive_tickets`.

### API status transitions — `app/Http/Controllers/Api/TicketController.php:99-106`

```php
$validated = $request->validate([
    'subject' => 'sometimes|required|string|max:255',
    'content' => 'sometimes|required|string',
    'priority' => 'sometimes|required|in:low,normal,urgent',
    'deadline' => 'nullable|date',
]);
$ticket->update($validated);
```

No `status` field is even accepted, which is fine (status is changed via dedicated endpoints). But the dedicated `assign()` endpoint (line 147-149) sets `status => 'forwarded'` without checking the current status is valid for transition. The `accept()` endpoint (line 170-173) doesn't validate status either.

The valid status transitions are: `created→forwarded`, `created→accepted`, `forwarded→accepted`, `accepted→completed`, `accepted→rejected`, `created→rejected`, `forwarded→rejected`.

## Scope

### In scope
- `resources/views/livewire/tickets/⚡inbox.blade.php` — null deref, rejectTicket scope, forward validation
- `app/Http/Controllers/Api/TicketController.php` — `assign()`, `accept()`, status transition validation

### Out of scope
- Bulk actions (`executeBulkAction`) — they already have their own validation
- Ticket model state machine formalization (a larger refactor)
- `submitAction()` method — it already checks status before completion

## Commands

```bash
cd /home/runner/h-dashboard
php artisan test --filter Ticket
git diff --stat
```

## Steps

### Step 1: Fix null dereference in submitAction

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php`

Replace line 582:

```php
// BEFORE:
$ticket->task->update(['is_completed' => true]);

// AFTER:
$ticket->task?->update(['is_completed' => true]);
```

Also add a null guard for the `$ticket->task->title` and `$ticket->task->start_at` references in the Blade template (lines 785-795). These are already inside an `@if($this->showingTicket->task)` block, so they're safe for display. But the completion logic in the PHP block needs the null-safe operator.

**Verify:** Read the changed line. Confirm `?->` is used.

### Step 2: Unify rejectTicket scope

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php`

Replace lines 481-484:

```php
// BEFORE:
public function rejectTicket($ticketId): void
{
    try {
        $ticket = Ticket::where('unit_id', auth()->user()->person?->u_id)->findOrFail($ticketId);

// AFTER:
public function rejectTicket($ticketId): void
{
    try {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $ticket = Ticket::whereIn('unit_id', $accessibleIds)->find($ticketId);
        if (!$ticket) {
            $this->dispatch('swal', ['title' => 'تیکت یافت نشد', 'icon' => 'error']);
            $this->closeDetail();
            return;
        }
```

Note: this replaces `findOrFail` (which throws 404) with `find` + explicit null check + user-friendly message, matching the `acceptTicket` pattern.

**Verify:** Read the method. Confirm `whereIn` + `getAccessibleUnitIds()` pattern is used, matching `acceptTicket`.

### Step 3: Add can_receive_tickets validation to forward()

**File:** `resources/views/livewire/tickets/⚡inbox.blade.php`

After the existing `validate()` call in `forward()` (line 395-398), add:

```php
$this->validate([
    'targetUnitId' => 'required|exists:units,id',
    'forwardNote' => 'nullable|string|max:500',
]);

// Validate target unit can receive tickets
$targetUnit = \App\Models\Unit::find($this->targetUnitId);
if (!$targetUnit || !$targetUnit->can_receive_tickets) {
    $this->dispatch('swal', [
        'title' => 'واحد مقصد قابلیت دریافت تیکت را ندارد.',
        'icon' => 'error',
    ]);
    return;
}
```

**Verify:** Read the forward() method. Confirm `can_receive_tickets` check is present after validation.

### Step 4: Add status transition validation to API assign/accept

**File:** `app/Http/Controllers/Api/TicketController.php`

Add a private helper and use it in `assign()` and `accept()`:

```php
private function validateStatusTransition(Ticket $ticket, string $newStatus): ?JsonResponse
{
    $validTransitions = [
        'created'   => ['forwarded', 'accepted', 'rejected'],
        'forwarded' => ['accepted', 'rejected'],
        'accepted'  => ['completed', 'rejected'],
    ];

    if (!isset($validTransitions[$ticket->status]) || !in_array($newStatus, $validTransitions[$ticket->status])) {
        return response()->json([
            'message' => "Cannot transition from '{$ticket->status}' to '{$newStatus}'.",
        ], 422);
    }

    return null;
}
```

In `assign()` (after accessible check, before update):
```php
$transitionError = $this->validateStatusTransition($ticket, 'forwarded');
if ($transitionError) return $transitionError;
```

In `accept()` (after assignee check, before update):
```php
$transitionError = $this->validateStatusTransition($ticket, 'accepted');
if ($transitionError) return $transitionError;
```

**Verify:** Read both methods. Confirm transition validation is present before update calls.

### Step 5: Run tests

```bash
php artisan test --filter Ticket
```

Expected: All existing tests pass. The new validation may cause some tests to fail if they relied on previously-permissive behavior — adjust test expectations if needed (but per scope, only fix the actual code; note test gaps).

## Test plan

- Existing `Ticket` tests should pass.
- **Gap:** The new status-transition validation may cause `TicketApiTest` or `TicketWorkflowTest` to fail if they test invalid transitions. Check and adjust test expectations.
- Manual verification: Try forwarding a ticket to a unit with `can_receive_tickets=false` → should show error. Try rejecting a ticket from an accessible-but-not-direct unit → should succeed.

## Done criteria

- [ ] `$ticket->task?->update()` uses null-safe operator
- [ ] `rejectTicket()` uses `whereIn` + `getAccessibleUnitIds()` pattern
- [ ] `forward()` validates `can_receive_tickets` on target unit
- [ ] API `assign()` and `accept()` validate status transitions
- [ ] `php artisan test --filter Ticket` passes

## STOP conditions

- If the status transition validation breaks existing bulk actions, STOP — bulk actions may need their own transition logic.
- If `can_receive_tickets` check causes issues with existing forwards in progress, STOP and investigate whether we need a grace period or audit log.
- If tests fail due to new validation constraints, STOP and report which tests need updating rather than silently fixing them.

## Maintenance notes

- The `$validTransitions` map should be the single source of truth for ticket state machine. Consider moving it to the Ticket model as a const or scope in a future refactor.
- The `rejectTicket` scope change may affect users who previously relied on the single-unit behavior. If a user could reject tickets from accessible units they shouldn't have seen, this is a bugfix; if they relied on it, it's a behavior change.
- `can_receive_tickets` defaults to `false` (Unit model line 43), so units must explicitly opt in. The validation enforces this contract.
