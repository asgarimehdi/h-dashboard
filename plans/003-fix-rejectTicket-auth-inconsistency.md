# Plan 003: Fix rejectTicket() auth inconsistency

> **Executor instructions**: Follow this plan step by step. Verify each step.

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19 (v2, verified with CodeGraph)

## Why this matters
`rejectTicket()` uses `auth()->user()->person?->u_id` to scope the query, while every other ticket method uses `AccessService::accessibleUnitIds()`. Users with access to sub-units can view/accept/forward/complete tickets from sub-units but CANNOT reject them. This authorization inconsistency could force tickets into unrecoverable states.

## Current state (verified via CodeGraph)
- **File**: `resources/views/livewire/tickets/⚡inbox.blade.php` (single-file Livewire component)
- **Broken pattern** at line 484:
  ```php
  $ticket = Ticket::where('unit_id', auth()->user()->person?->u_id)->findOrFail($ticketId);
  ```
- **Correct pattern** used in `acceptTicket()` (line 422-426), `forward()` (line 387), `submitAction()` (line 525):
  ```php
  $accessibleIds = app(AccessService::class)->accessibleUnitIds();
  $ticket = Ticket::whereIn('unit_id', $accessibleIds)->find($ticketId);
  ```

## Scope
**In scope**: `resources/views/livewire/tickets/⚡inbox.blade.php` (rejectTicket method only)

## Steps

### Step 1: Fix the query in rejectTicket()
Replace lines 483-484 with:
```php
$accessibleIds = app(AccessService::class)->accessibleUnitIds();
$ticket = Ticket::whereIn('unit_id', $accessibleIds)->find($ticketId);

if (! $ticket) {
    $this->dispatch('swal', ['title' => 'تیکت یافت نشد.', 'icon' => 'error']);

    return;
}
```

**Why `find()` not `findOrFail()`**: The original code wraps in try/catch and dispatches a generic error. Using `find()` + explicit null check gives a user-friendly Persian message instead of a 404 exception.

### Step 2: Verify
```bash
grep -n "accessibleUnitIds" resources/views/livewire/tickets/⚡inbox.blade.php
# → should show rejectTicket now uses it (in addition to acceptTicket, forward, submitAction)
```

### Step 3: Test review (AGENTS.md Test Review Rule)
Check if existing tests cover rejectTicket:
```bash
grep -rn "rejectTicket\|reject" tests/ --include="*.php" | head -10
```
If no test exists, add one to `tests/Feature/TicketRejectTest.php`:
- test user with sub-unit access can reject ticket from sub-unit
- test user without access cannot reject ticket
- test rejectTicket updates status to 'rejected'

## Done criteria
- [ ] rejectTicket() uses AccessService::accessibleUnitIds()
- [ ] User-friendly error message in Persian
- [ ] Test coverage verified/added
- [ ] `vendor/bin/pint --dirty --format agent` passes
