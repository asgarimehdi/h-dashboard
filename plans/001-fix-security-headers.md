# Plan 001: Fix security headers (CSP, HSTS, remove XSS-Protection)

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in "STOP conditions" occurs, stop and report.

## Status
- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `rebecca`, 2026-09-19

## Why this matters
The SecurityHeaders middleware is missing Content-Security-Policy and HSTS headers, and still sets the deprecated X-XSS-Protection header. For a healthcare application handling sensitive data, CSP is critical as a second layer of XSS defense — especially since ticket comments render `{!! $comment->body_html !!}`. Without CSP, any XSS vector gives an attacker full execution capability.

## Current state
- File: `app/Http/Middleware/SecurityHeaders.php`
- Currently sets: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection
- Missing: Content-Security-Policy, Strict-Transport-Security
- Deprecated: X-XSS-Protection (line 18)
- Registered in: `bootstrap/app.php` line 35-37 (web middleware group)

## Commands you will need
| Purpose | Command | Expected |
|---------|---------|----------|
| Verify middleware | `grep -n 'SecurityHeaders' bootstrap/app.php` | shows middleware registration |
| Run tests | `cd /home/runner/h-dashboard && composer test -- --filter=SecurityHeaders 2>&1 \| tail -5` | passes or no tests |

## Scope
**In scope**: `app/Http/Middleware/SecurityHeaders.php`
**Out of scope**: No changes to routes, views, or other middleware

## Steps

### Step 1: Remove X-XSS-Protection header
Remove the line `$response->headers->set('X-XSS-Protection', '1; mode=block');` from the handle method.

### Step 2: Add Content-Security-Policy header
Add after existing headers:
```php
$response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
```
Note: `unsafe-inline` and `unsafe-eval` are required for Livewire/Alpine.js. Start with CSP-Report-Only if unsure about breakage.

### Step 3: Add Strict-Transport-Security header
Add:
```php
$response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
```

### Step 4: Verify
**Verify**: `cd /home/runner/h-dashboard && grep -c 'Content-Security-Policy\|Strict-Transport-Security' app/Http/Middleware/SecurityHeaders.php` → should return `2`

## Test plan
- Existing SecurityHeaders tests should still pass (if any)
- Manual: check response headers with `curl -I http://localhost:8000/`

## Done criteria
ALL must hold:
- [ ] X-XSS-Protection header removed
- [ ] Content-Security-Policy header present with appropriate directives
- [ ] Strict-Transport-Security header present with max-age >= 31536000
- [ ] `vendor/bin/pint --dirty --format agent` passes

## STOP conditions
- If CSP breaks Livewire or Alpine.js functionality (test in browser)
- If pint fails on the modified file
