# Plan 001: Fix Ticket Code Collision (uniqid → Str::random)

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** Medium  
**Category:** Bug Fix  

## Problem

Ticket codes are generated using `uniqid()`, which is time-based and has only 6 hex characters of entropy. Under load (e.g., bulk ticket creation or automated maintenance ticket generation), two tickets created within the same microsecond will receive identical codes, causing a unique-constraint violation or duplicate codes.

## Current State

**File:** `resources/views/livewire/tickets/⚡create.blade.php:116`

```php
$ticketCode = 'TK-' . strtoupper(substr(uniqid(), -6));
```

- `uniqid()` returns a 23-character hex string based on the current time in microseconds
- `substr(uniqid(), -6)` extracts only the last 6 hex characters (~2.4 bytes of entropy)
- Collision probability: non-trivial under concurrent ticket creation (same microsecond → same code)

## Proposed Fix

Replace `uniqid()` with Laravel's `Str::random()`, which uses a cryptographically secure random generator:

**File:** `resources/views/livewire/tickets/⚡create.blade.php:116`

```php
$ticketCode = 'TK-' . strtoupper(Str::random(8));
```

- `Str::random(8)` returns 8 random alphanumeric characters → 48 bits of entropy
- Collision probability is astronomically low for the expected ticket volume
- `Str` is already imported via Laravel's global helpers (no new `use` statement needed)

## Migration Concern

**Existing codes are safe.** New codes use `[A-Z0-9]{8}` (36-char alphabet), while old codes from `uniqid()` use `[0-9A-F]{6}` (16-char alphabet). There is zero overlap because:
- Old codes are exactly 6 characters; new codes are 8 characters
- Old codes contain only hex digits `[0-9A-F]`; new codes contain full alphanumeric `[A-Z0-9]`
- The `ticket_code` column already has a unique constraint; any collision will fail at DB level

No data migration is needed.

## Files to Modify

| File | Line | Change |
|------|------|--------|
| `resources/views/livewire/tickets/⚡create.blade.php` | 116 | `uniqid()` → `Str::random(8)` |

**Out of scope:** No other files generate ticket codes. The `ticket_code` unique index already exists.

## Verification

```bash
# 1. Ensure no remaining uniqid() calls in blade files
grep -r "uniqid" resources/views/livewire/tickets/
# Expected: 0 matches

# 2. Verify the ticket_code column has a unique constraint
php scripts/boost_tool.php query '{"sql": "SELECT conname FROM pg_constraint WHERE conrelid = '\''tickets'\''::regclass AND contype = '\''u'\'' AND conname LIKE '\''%ticket_code%'\''"}'
# Expected: returns the unique constraint name

# 3. Run existing ticket creation tests
composer test -- --filter="ticket"
# Expected: all pass

# 4. Manual verification: create two tickets rapidly
# Both should have different 8-char suffixes
```

## Test Plan

Add a Pest test that creates multiple tickets in rapid succession and asserts unique `ticket_code` values:

```php
it('generates unique ticket codes for rapid concurrent creation', function () {
    $user = User::factory()->create();
    $unit = Unit::factory()->create();
    $codes = [];

    for ($i = 0; $i < 50; $i++) {
        Livewire::actingAs($user)
            ->test('tickets.⚡create')
            ->set('unit_id', $unit->id)
            ->set('subject', "Ticket subject number {$i} here")
            ->set('content', "This is the content for ticket number {$i} enough to pass validation")
            ->set('priority', 'normal')
            ->call('submit');

        $ticket = Ticket::latest()->first();
        $codes[] = $ticket->ticket_code;
    }

    expect($codes)->toHaveCount(50)
        ->and(collect($codes)->unique()->count())->toBe(50);
});
```

## STOP Conditions

- If `Str` class is not available in this context (check for `use Illuminate\Support\Str` or auto-load)
- If `ticket_code` column has no unique constraint (must add one first)
- If the existing uniqueness constraint would break current test data (check factories)

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| No `Str` import in scope | Build fails | Verify `Illuminate\Support\Str` is auto-loaded or add `use` |
| Existing test data collision | Tests break | Old codes are 6 chars, new are 8 chars — no overlap |
| Performance difference | Negligible | `Str::random()` is microsecond-level |

## Maintenance Notes

- The `ticket_code` generation is now consistent with Laravel best practices
- If the app ever needs shorter codes, increase from 8 to reduce collision further
- Consider adding a unique index check in the `create` method as a safety net (catch `QueryException`)
