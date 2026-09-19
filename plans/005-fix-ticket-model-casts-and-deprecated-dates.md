# Plan 005: Add Ticket $casts + remove deprecated User.$dates

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: correctness
- **Planned at**: 2026-09-19 (v2, verified with CodeGraph)

## Why this matters
Ticket model has datetime columns (`deadline`, `accepted_at`, `completed_at`) without any `$casts` property — date comparisons silently return strings instead of Carbon instances. User model uses deprecated `$dates` property (removed in Laravel 11+).

## Current state (verified)
- `app/Models/Ticket.php`: **NO** `$casts` property at all (full file read, 105 lines). `created_at` works via Eloquent convention but `deadline`, `accepted_at`, `completed_at` are not cast.
- `app/Models/User.php` line 30: `protected $dates = ['deleted_at'];` — deprecated
- `app/Models/User.php` lines 101-108: existing `casts()` method (returns `email_verified_at`, `password`, `settings`)

## Scope
**In scope**: `app/Models/Ticket.php`, `app/Models/User.php`

## Steps

### Step 1: Add $casts to Ticket model
Add to `app/Models/Ticket.php` (after the `$fillable` array, before `canBeCompleted()`):

```php
protected $casts = [
    'deadline' => 'datetime',
    'accepted_at' => 'datetime',
    'completed_at' => 'datetime',
];
```

### Step 2: Fix User model deprecated $dates
Remove `protected $dates = ['deleted_at'];` from User.php (line 30).

Add `'deleted_at' => 'datetime'` to the existing `casts()` method:
```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'settings' => 'array',
        'deleted_at' => 'datetime',
    ];
}
```

### Step 3: Verify
```bash
grep -n "dates" app/Models/User.php
# → should NOT show $dates property
grep -n "casts" app/Models/Ticket.php
# → should show the new casts
```

### Step 4: Test review
Check if Ticket date comparisons exist in tests:
```bash
grep -rn "deadline\|accepted_at\|completed_at" tests/ --include="*.php" | head -10
```
If date comparisons are used, verify they now return Carbon instances.

## Done criteria
- [ ] Ticket has datetime $casts for deadline, accepted_at, completed_at
- [ ] User no longer has deprecated $dates
- [ ] `vendor/bin/pint --dirty --format agent` passes
