# Plan 007: Fix restoreAuditValue Type Mismatch (ram/vlan/port Cast to int)

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** Medium  
**Category:** Bug Fix  

## Problem

In the hardware audit restore function, fields `ram`, `vlan`, and `port` are cast to `(int)` when restoring from a display value. However, the Hardware model stores these fields as strings in PostgreSQL. The `(int)` cast causes type mismatches when restoring, potentially:
- Converting `"8"` to integer `8` instead of string `"8"`
- Breaking PostgreSQL column type expectations
- Losing leading zeros or specific formatting

## Current State

**File:** `resources/views/livewire/hardware/index.blade.php:647-649`

```php
private function restoreAuditValue(string $displayValue, string $field): mixed
{
    if ($displayValue === '—') {
        return null;
    }
    if ($displayValue === 'بله') {
        return true;
    }
    if ($displayValue === 'خیر') {
        return false;
    }
    if (in_array($field, ['ram', 'vlan', 'port'], true) && is_numeric($displayValue)) {
        return (int) $displayValue;
    }
    // ...
}
```

The `(int)` cast is incorrect because the Hardware model stores these as string columns. PostgreSQL will accept the integer, but the type consistency within the application is broken.

## Proposed Fix

Remove the `(int)` cast — return the string value since it's already a string input:

```php
if (in_array($field, ['ram', 'vlan', 'port'], true) && is_numeric($displayValue)) {
    return $displayValue;  // Keep as string — matches the DB column type
}
```

## Files to Modify

| File | Line | Change |
|------|------|--------|
| `resources/views/livewire/hardware/index.blade.php` | 648 | Remove `(int)` cast, return `$displayValue` directly |

**Out of scope:** The Hardware model columns, the `formatValueForDisplay()` method (which formats strings for display), other restore methods.

## Verification

```bash
# 1. Check Hardware model for column types
php scripts/boost_tool.php query '{"sql": "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = '\''hardware'\'' AND column_name IN ('\''ram'\'', '\''vlan'\'', '\''port'\'')"}'
# Expected: all show 'character varying' or 'text'

# 2. Run hardware tests
composer test -- --filter="hardware"
# Expected: all pass

# 3. Manual test:
# - Edit a hardware record, change ram to "8"
# - View audit log, restore the old value
# - Verify ram column still contains string "8", not integer 8
```

## Test Plan

```php
it('restores ram value as string, not integer', function () {
    $hardware = Hardware::factory()->create(['ram' => '8']);

    // Simulate audit restore: pass display value "8" for field "ram"
    // The restoreAuditValue should return string "8", not int 8
    
    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('restoreAuditValue', '8', 'ram');

    $hardware->refresh();
    expect($hardware->ram)->toBeString()
        ->and($hardware->ram)->toBe('8');
});
```

## STOP Conditions

- If the Hardware model casts `ram`, `vlan`, or `port` to integer in `$casts` array
- If any other code depends on these being integers after restore

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Downstream code expects integer | Type error | Audit `intval()` or arithmetic on these fields |
| PostgreSQL strict typing rejects int | Query error | Check column types first |

## Maintenance Notes

- Consider adding `$casts` to the Hardware model to enforce string types explicitly
- The `formatValueForDisplay()` method (reverse direction) likely already handles strings correctly
