# Plan 008: Fix CORS Configuration — Restrict Origins

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** High  
**Category:** Security  

## Problem

The CORS configuration allows requests from **any origin** (`'allowed_origins' => ['*']`) while simultaneously enabling credentials (`'supports_credentials' => true`). This is a dangerous combination:
- Any website can make authenticated API requests on behalf of logged-in users
- Enables CSRF-like attacks from any domain
- Violates the CORS specification (browsers reject `Access-Control-Allow-Origin: *` with credentials, but the wildcard pattern is still risky)

## Current State

**File:** `config/cors.php:18-34`

```php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],           // ← WILDCARD — security risk
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,       // ← CREDENTIALS — amplifies risk
];
```

## Proposed Fix

Whitelist specific origins. The app serves:
- **Web UI:** `APP_URL` (e.g., `https://h-dashboard.example.com`)
- **Flutter app:** Mobile apps don't send cookies, but the API might be accessed from a web admin panel
- **Development:** `http://localhost:*`, `http://127.0.0.1:*`

```php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        env('APP_URL', 'http://localhost:8000'),
    ],
    'allowed_origins_patterns' => [
        '/^https?:\/\/localhost(:\d+)?$/',
        '/^https?:\/\/127\.0\.0\.1(:\d+)?$/',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

**Note:** Flutter mobile apps use Bearer tokens (not cookies), so CORS doesn't apply to them. CORS only matters for browser-based requests.

## Files to Modify

| File | Lines | Change |
|------|-------|--------|
| `config/cors.php` | 22,24 | Replace `['*']` with whitelisted origins + patterns |
| `.env.example` | — | Add `CORS_ALLOWED_ORIGINS` documentation |

## Verification

```bash
# 1. Test that evil origin is blocked
curl -v -H "Origin: https://evil.com" http://localhost:8000/api/sanctum/csrf-cookie 2>&1 | grep -i "access-control-allow-origin"
# Expected: NO access-control-allow-origin header for evil.com

# 2. Test that allowed origin works
curl -v -H "Origin: http://localhost:8000" http://localhost:8000/api/sanctum/csrf-cookie 2>&1 | grep -i "access-control-allow-origin"
# Expected: access-control-allow-origin: http://localhost:8000

# 3. Run API tests
composer test -- --filter="Api"
# Expected: all pass
```

## Test Plan

```php
it('rejects CORS from non-whitelisted origin', function () {
    $response = $this->call('OPTIONS', '/api/tickets', [], [], [], [
        'HTTP_ORIGIN' => 'https://evil.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('allows CORS from whitelisted origin', function () {
    $response = $this->call('OPTIONS', '/api/tickets', [], [], [], [
        'HTTP_ORIGIN' => 'http://localhost:8000',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8000');
});
```

## STOP Conditions

- If the Flutter app makes browser-based requests (shouldn't — it's native)
- If there are multiple deployment environments with different URLs
- If the API is consumed by a third-party frontend not yet documented

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Blocking legitimate origins | API broken for consumers | Document all allowed origins; add to .env.example |
| Development origins blocked | Dev workflow broken | Include localhost patterns in production config |
| Pattern regex too broad | Security gap | Use strict patterns with `^` and `$` anchors |

## Maintenance Notes

- Document allowed origins in README/API docs
- When new frontend consumers are added, update the whitelist
- Consider per-route CORS if some endpoints need wider access
