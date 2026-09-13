# 032 — Add Content-Security-Policy Header

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | MEDIUM (Security hardening) |
| **Effort** | M |
| **Risk** | Medium — overly restrictive CSP breaks the app |
| **Base commit** | `a106d38` |
| **Files** | `app/Http/Middleware/SecurityHeaders.php` |

## Problem

`app/Http/Middleware/SecurityHeaders.php` sets several security headers but is missing `Content-Security-Policy` (CSP). CSP is a critical defense-in-depth layer against XSS attacks.

### Evidence (file:line)

**`app/Http/Middleware/SecurityHeaders.php:11-21`** — current middleware:

```php
public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);

    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('X-XSS-Protection', '1; mode=block');

    return $response;
}
```

Headers set: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `X-XSS-Protection`.
Missing: `Content-Security-Policy`, `Permissions-Policy`, `Strict-Transport-Security`.

### Application assets and inline scripts

The app uses:
- **Inline scripts** (`<script>` blocks in Livewire Blade views) — requires `'unsafe-inline'` for `script-src` OR nonces
- **Alpine.js** — inline event handlers need `'unsafe-inline'` in `script-src`
- **Highcharts** — loaded from `asset('js/chart/...')` (same origin)
- **Leaflet** — loaded from `https://unpkg.com/leaflet@1.9.4/` (external CDN)
- **External font/style CDN** — depends on MaryUI/DaisyUI setup
- **Livewire** — makes XHR requests to the app itself
- **Sanctum tokens** — sent as Bearer tokens to `/api/*`

## Decision

Add a **report-only** CSP first (`Content-Security-Policy-Report-Only`) to avoid breaking the app in production. This allows collecting violations before enforcing.

Build the CSP directive from the app's actual asset sources:

```
default-src 'self';
script-src 'self' 'unsafe-inline' https://unpkg.com;
style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com;
img-src 'self' data: blob:;
font-src 'self' https://fonts.gstatic.com;
connect-src 'self';
frame-ancestors 'none';
base-uri 'self';
form-action 'self';
```

**Why `unsafe-inline` for scripts:** Livewire 4, Alpine.js, and the app's Blade views all rely heavily on inline `<script>` blocks and Alpine `x-on:click` handlers. Converting to nonces would require modifying every Blade template — that's a separate, larger effort.

## Commands

```bash
cd /home/runner/h-dashboard
# Verify middleware is registered
grep -rn 'SecurityHeaders' bootstrap/ app/Http/Kernel.php
# Check asset sources
grep -rn 'unpkg.com\|cdn\.\|googleapis' resources/views/
```

## Steps

### Phase 1 — Audit all external asset sources

```bash
# Find all external URLs loaded by the app
grep -rn 'https\?://[^"'"'"' ]*\.\(js\|css\|woff\|ttf\)' resources/views/ --include='*.blade.php'
grep -rn 'https\?://[^"'"'"' ]*\.\(js\|css\|woff\|ttf\)' public/ --include='*.html' 2>/dev/null
```

### Phase 2 — Add CSP to SecurityHeaders middleware

Update `app/Http/Middleware/SecurityHeaders.php`:

```php
public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);

    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('X-XSS-Protection', '1; mode=block');
    $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

    // CSP — start with report-only to collect violations before enforcing
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://unpkg.com",
        "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com",
        "img-src 'self' data: blob:",
        "font-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com",
        "connect-src 'self'",
        "frame-ancestors 'none'",
        "base-uri 'self'",
        "form-action 'self'",
    ]);

    $response->headers->set('Content-Security-Policy', $csp);

    return $response;
}
```

### Phase 3 — Test CSP doesn't break the app

1. Load the dashboard in browser, check for CSP violations in console.
2. Open the map page, verify Leaflet loads correctly.
3. Open the hardware page, verify Highcharts renders.
4. Check Livewire updates work (form submissions, real-time search).

### Phase 4 — Add HSTS for production

```php
if ($request->isSecure()) {
    $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
}
```

### Phase 5 — Verify

```bash
# Check headers in test
XDEBUG_MODE=off php artisan test --filter=SecurityHeaders
# Or manually via curl
curl -I http://localhost:8000/ 2>/dev/null | grep -i 'content-security'
```

## Test plan

- Add a test in a new or existing test file that asserts the CSP header is present and contains expected directives:

```php
test('security headers include content-security-policy', function () {
    $response = $this->get('/');
    $response->assertHeader('Content-Security-Policy');
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'");
    expect($csp)->toContain("frame-ancestors 'none'");
});
```

## Done criteria

- [ ] `Content-Security-Policy` header present on all responses
- [ ] `Permissions-Policy` header present
- [ ] CSP doesn't break dashboard, map, hardware, or ticket pages
- [ ] CSP violations logged (consider adding `report-uri` endpoint later)
- [ ] Tests pass
- [ ] `vendor/bin/pint --dirty` clean

## STOP conditions

- If the CSP blocks a critical feature (Livewire, Highcharts, Leaflet), STOP and adjust the specific directive.
- If using nonces instead of `unsafe-inline` requires rewriting >5 Blade templates, STOP and defer to a separate plan.
- If `HSTS` breaks local HTTP development, only set it for production (`config('app.env') === 'production'`).
