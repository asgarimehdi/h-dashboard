# Plan 004: Fix API TicketController Priority Validation Mismatch

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** High  
**Category:** Bug Fix / Data Integrity  

## Problem

The API `TicketController::store()` validation allows priority values `'high'` and `'medium'`, but the database enum/column only stores `'low'`, `'normal'`, and `'urgent'`. This means:
1. Tickets created via API with `priority='high'` or `'medium'` are silently stored with invalid values
2. These invalid values break UI rendering (priority badges, filtering)
3. The web UI uses the correct set: `['low', 'normal', 'urgent']`

## Current State

**File:** `app/Http/Controllers/Api/TicketController.php:72`

```php
'priority' => 'required|in:urgent,high,normal,medium,low',
```

Valid values according to DB (from web UI and model):

```
low, normal, urgent
```

The `high` and `medium` values are NOT valid — they don't match the DB enum or the UI.

## Proposed Fix

Change the validation rule to match the actual valid priorities:

```php
'priority' => 'required|in:low,normal,urgent',
```

This aligns with:
- The Ticket model's `$casts` or validation
- The web UI's priority select options
- The database column's actual stored values

## Files to Modify

| File | Line | Change |
|------|------|--------|
| `app/Http/Controllers/Api/TicketController.php` | 72 | Remove `high,medium` from `in:` rule |

**Out of scope:** The Ticket model itself, the web UI priority select, the database schema (all already correct).

## Verification

```bash
# 1. Test that 'high' is now rejected
curl -X POST http://h-dashboard.test/api/tickets \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"subject":"Test","content":"Test content","priority":"high","unit_id":1}'
# Expected: HTTP 422 with validation error

# 2. Test that 'normal' still works
curl -X POST http://h-dashboard.test/api/tickets \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"subject":"Test subject here","content":"Test content body","priority":"normal","unit_id":1}'
# Expected: HTTP 201 Created

# 3. Run API tests
composer test -- --filter="TicketController"
# Expected: all pass

# 4. Check existing tickets for invalid priority values
php scripts/boost_tool.php query '{"sql": "SELECT DISTINCT priority FROM tickets WHERE priority NOT IN ('\''low'\'', '\''normal'\'', '\''urgent'\'')"}'
# Expected: 0 rows (or document any existing invalid values)
```

## Test Plan

```php
it('rejects invalid priority values via API', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    foreach (['high', 'medium'] as $invalidPriority) {
        $response = $this->postJson('/api/tickets', [
            'subject' => 'Test subject for priority',
            'content' => 'Test content that is long enough to pass validation',
            'priority' => $invalidPriority,
            'unit_id' => Unit::factory()->create()->id,
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('priority');
    }
});

it('accepts valid priority values via API', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    foreach (['low', 'normal', 'urgent'] as $validPriority) {
        $response = $this->postJson('/api/tickets', [
            'subject' => 'Test subject for priority',
            'content' => 'Test content that is long enough to pass validation',
            'priority' => $validPriority,
            'unit_id' => Unit::factory()->create()->id,
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
    }
});
```

## STOP Conditions

- If the DB query finds existing rows with `high` or `medium` priority — must data-migrate first
- If the Flutter app sends `high`/`medium` and needs a coordinated release

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Existing DB rows with invalid priority | Data inconsistency | Run data audit first, bulk-update if needed |
| Flutter app sends old priority values | API 422 errors | Coordinate release with Flutter team |
| Tests use `high`/`medium` | Test failures | Grep test files, update fixtures |

## Maintenance Notes

- Add a database CHECK constraint on the `priority` column in a follow-up migration to prevent future invalid values
- Document the valid priorities in API docs
- Consider a PHP enum: `Priority::LOW`, `Priority::NORMAL`, `Priority::URGENT`
