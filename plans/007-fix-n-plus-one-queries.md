# Plan 007: Fix N+1 queries (ticket inbox, org-node, comment notification)

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: performance
- **Planned at**: 2026-09-19

## Why this matters
Multiple N+1 query patterns degrade page load times. The ticket inbox loads 20+ tickets but doesn't eager-load user.person, causing 20+ extra queries per page. HR org-node fires a query per node. Comment notification triggers lazy loads.

## Current state
- `resources/views/livewire/tickets/⚡inbox.blade.php` line 89: `Ticket::with(['user:id,n_code', 'unit:id,name'])` missing `person`
- `resources/views/livewire/hr/org-node.blade.php` line 9: `Unit::where('parent_id', $unit->id)->exists()` per node
- `app/Http/Controllers/Api/TicketCommentController.php` lines 96-106: `$comment->ticket->ticket_code` lazy-loads after creation

## Steps

### Step 1: Fix ticket inbox eager loading
In ⚡inbox.blade.php `tickets()` method, change:
```php
Ticket::with(['user:id,n_code', 'unit:id,name'])
```
to:
```php
Ticket::with(['user:id,n_code,person:f_name,l_name', 'unit:id,name'])
```

### Step 2: Fix monitoring blade (same pattern)
Apply same change to ⚡monitoring.blade.php line 69.

### Step 3: Fix comment notification lazy load
In TicketCommentController after creating comment, add:
```php
$comment->load('ticket');
```
before calling notifyMentions() and notifyReply().

### Step 4: Verify
**Verify**: `cd /home/runner/h-dashboard && grep -n "person:f_name" resources/views/livewire/tickets/⚡inbox.blade.php` → should show eager load
**Verify**: `grep -n "load.*ticket" app/Http/Controllers/Api/TicketCommentController.php` → should show eager load

## Done criteria
- [ ] Ticket inbox eager-loads user.person
- [ ] Comment notification doesn't lazy-load ticket
- [ ] pint passes
