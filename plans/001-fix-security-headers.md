# Plan 001: Fix security headers (CSP-Report-Only, HSTS, remove XSS-Protection)

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in "STOP conditions" occurs, stop and report.

## Status
- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19 (v2, corrected)

## Why this matters
The SecurityHeaders middleware is missing Content-Security-Policy and HSTS headers, and still sets the deprecated X-XSS-Protection header. For a healthcare application handling sensitive data, CSP is critical as a second layer of XSS defense — especially since ticket comments render `{!! $comment->body_html !!}`. Without CSP, any XSS vector gives an attacker full execution capability.

## Current state
- File: `app/Http/Middleware/SecurityHeaders.php` (registered in `bootstrap/app.php`)
- Currently sets: X-Content-Type-Options (L15), X-Frame-Options (L16), Referrer-Policy (L17), X-XSS-Protection (L18)
- Missing: Content-Security-Policy, Strict-Transport-Security
- Deprecated: X-XSS-Protection (line 18)

## Scope
**In scope**: `app/Http/Middleware/SecurityHeaders.php`
**Out of scope**: No changes to routes, views, or other middleware

## Commands you will need
| Purpose | Command | Expected |
|---------|---------|----------|
| Verify middleware | `grep -n 'SecurityHeaders' bootstrap/app.php` | shows middleware registration |
| Run tests | `composer test -- --filter=SecurityHeaders 2>&1 \| tail -5` | passes or no tests |

## Steps

### Step 1: Remove X-XSS-Protection header
Remove line 18: `$response->headers->set('X-XSS-Protection', '1; mode=block');`

### Step 2: Add Content-Security-Policy (Report-Only first)
Add CSP in **Report-Only** mode initially. This lets you validate without breaking Livewire/Alpine.js:

```php
// Start with report-only to catch violations without breaking the app.
// Once violations are zero for a week, switch to enforcement by renaming
// the header to Content-Security-Policy.
$response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; report-uri /csp-report");
```

**Key differences from original PR:**
- Removed `'unsafe-eval'` — Alpine.js 3.x and Livewire 4.x do NOT require `unsafe-eval` (they use `eval()` replacement via `Alpine.evaluate()`). Test without it first.
- Used `Content-Security-Policy-Report-Only` instead of enforcement — prevents breaking the app on first deploy.
- Added `report-uri` for violation logging.

### Step 3: Add Strict-Transport-Security header
```php
$response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
```

### Step 4: Add CSP report route (optional but recommended)
Create `app/Http/Controllers/CspReportController.php`:
```php
class CspReportController extends Controller
{
    public function store(Request $request)
    {
        \Log::warning('CSP Violation', $request->input('csp-report', []));
        return response()->noContent();
    }
}
```
Add route in `routes/web.php` (outside auth middleware, as browsers POST from outside):
```php
Route::post('/csp-report', [\App\Http\Controllers\CspReportController::class, 'store']);
```

### Step 5: Verify
```bash
grep -c 'Content-Security-Policy\|Strict-Transport-Security' app/Http/Middleware/SecurityHeaders.php
# → should return 2 (one for Report-Only, one for HSTS)
grep 'X-XSS-Protection' app/Http/Middleware/SecurityHeaders.php
# → should return nothing
```

## Test plan
- Existing SecurityHeaders tests should still pass (if any)
- Manual: `curl -I http://localhost:8000/` — verify CSP-Report-Only and HSTS headers present
- Check browser console for CSP violations over 1-2 days of normal use

## Done criteria
ALL must hold:
- [ ] X-XSS-Protection header removed
- [ ] Content-Security-Policy-Report-Only header present (NOT enforcement yet)
- [ ] Strict-Transport-Security header present with max-age >= 31536000
- [ ] `vendor/bin/pint --dirty --format agent` passes
- [ ] No CSP violations in browser console during normal use

## STOP conditions
- If CSP-Report-Only shows violations for core functionality (Livewire updates, Alpine interactions) — tune directives before switching to enforcement
