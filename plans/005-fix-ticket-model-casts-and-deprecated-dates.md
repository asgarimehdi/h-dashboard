# Plan 005: Add Ticket $casts + remove deprecated User.$dates

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: correctness
- **Planned at**: 2026-09-19

## Why this matters
Ticket model has datetime columns (deadline, accepted_at, completed_at) without $casts, so date comparisons silently fail. User model uses deprecated $dates property.

## Current state
- `app/Models/Ticket.php`: no $casts for datetime fields
- `app/Models/User.php` line 30: `protected $dates = ['deleted_at']` (deprecated in Laravel 10+)

## Steps

### Step 1: Add $casts to Ticket model
Add to Ticket.php:
```php
protected $casts = [
    'deadline' => 'datetime',
    'accepted_at' => 'datetime',
    'completed_at' => 'datetime',
];
```

### Step 2: Fix User model deprecated $dates
Remove `protected $dates = ['deleted_at'];` from User.php. Add `'deleted_at' => 'datetime'` to existing $casts array.

### Step 3: Verify
**Verify**: `cd /home/runner/h-dashboard && grep -n "dates" app/Models/User.php` → should not show $dates property
**Verify**: `grep -n "casts" app/Models/Ticket.php` → should show the new casts

## Done criteria
- [ ] Ticket has datetime $casts
- [ ] User no longer has deprecated $dates
- [ ] pint passes
