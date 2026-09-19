# Plan 007: Fix N+1 queries (ticket inbox, monitoring, comment notification)

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: performance
- **Planned at**: 2026-09-19 (v2, verified with CodeGraph)

## Why this matters
Multiple N+1 query patterns degrade page load times. The ticket inbox and monitoring pages load 20+ tickets but don't eager-load `user.person`, causing 20+ extra queries per page.

## Current state (verified)
- `resources/views/livewire/tickets/⚡inbox.blade.php` line 89:
  ```php
  $query = Ticket::with(['user:id,n_code', 'unit:id,name']);
  ```
  Missing `person` relation on user.

- `resources/views/livewire/tickets/⚡monitoring.blade.php` line 69:
  ```php
  $query = Ticket::with(['user:id,n_code', 'unit:id,name'])->accessible();
  ```
  Same missing eager load.

- `resources/views/livewire/tickets/ticket-comments.blade.php` lines 53-56:
  ```php
  $this->ticket = Ticket::where('id', $this->ticketId)
      ->whereIn('unit_id', $accessibleIds)
      ->with(['comments' => fn ($q) => $q->with('user.person')->with('children.user.person')->orderByDesc('created_at')])
      ->first();
  ```
  Comments already eager-load `user.person` — this one is fine.

## Scope
**In scope**: `⚡inbox.blade.php`, `⚡monitoring.blade.php` (both single-file Livewire components)

## Steps

### Step 1: Fix ticket inbox eager loading
In `resources/views/livewire/tickets/⚡inbox.blade.php` line 89, change:
```php
Ticket::with(['user:id,n_code', 'unit:id,name'])
```
to:
```php
Ticket::with(['user:id,n_code,person:f_name,l_name', 'unit:id,name'])
```

### Step 2: Fix monitoring blade (same pattern)
Apply same change to `resources/views/livewire/tickets/⚡monitoring.blade.php` line 69.

### Step 3: Verify
```bash
grep -n "person:f_name" resources/views/livewire/tickets/⚡inbox.blade.php
# → should show eager load
grep -n "person:f_name" resources/views/livewire/tickets/⚡monitoring.blade.php
# → should show eager load
```

### Step 4: Test review
Check if ticket inbox tests exist:
```bash
grep -rn "inbox\|monitoring" tests/ --include="*.php" | head -10
```
The N+1 fix is a performance improvement — verify existing tests still pass (no behavioral change).

## Done criteria
- [ ] Ticket inbox eager-loads user.person
- [ ] Monitoring page eager-loads user.person
- [ ] `composer test` passes
- [ ] `vendor/bin/pint --dirty --format agent` passes
