# Plan 003: Fix rejectTicket() auth inconsistency

> **Executor instructions**: Follow this plan step by step. Verify each step.

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19

## Why this matters
`rejectTicket()` uses `auth()->user()->person?->u_id` to scope the query, while every other ticket method uses `AccessService::accessibleUnitIds()`. Users with access to sub-units can view/accept/forward/complete tickets from sub-units but CANNOT reject them. This authorization inconsistency could force tickets into unrecoverable states.

## Current state
- File: `resources/views/livewire/tickets/⚡inbox.blade.php` line 484
- Broken pattern: `Ticket::where('unit_id', auth()->user()->person?->u_id)->findOrFail($ticketId)`
- Correct pattern (used elsewhere): `$accessibleIds = app(AccessService::class)->accessibleUnitIds(); Ticket::whereIn('unit_id', $accessibleIds)->find($ticketId)`

## Scope
**In scope**: `resources/views/livewire/tickets/⚡inbox.blade.php` (rejectTicket method only)

## Steps

### Step 1: Fix the query in rejectTicket()
Replace line 484 with:
```php
$accessibleIds = app(AccessService::class)->accessibleUnitIds();
$ticket = Ticket::whereIn('unit_id', $accessibleIds)->find($ticketId);
if (!$ticket) { abort(404); }
```

### Step 2: Verify
**Verify**: `cd /home/runner/h-dashboard && grep -n "accessibleUnitIds" resources/views/livewire/tickets/⚡inbox.blade.php` → should show rejectTicket now uses it

## Done criteria
- [ ] rejectTicket() uses AccessService::accessibleUnitIds()
- [ ] pint passes

## STOP conditions
- If AccessService class is not importable in the Livewire component
