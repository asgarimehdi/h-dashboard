# Plan 009: Fix CORS localhost patterns + .env.example APP_DEBUG

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19 (v2, verified with CodeGraph)

## Why this matters
CORS allows localhost patterns with credentials in ALL environments including production. `.env.example` ships `APP_DEBUG=true`, which would expose stack traces if deployed without overriding.

## Current state (verified)
- `config/cors.php` line 24: `allowed_origins_patterns => ['/^https?:\/\/localhost(:\d+)?$/', '/^https?:\/\/127\.0\.0\.1(:\d+)?$/']` — always active
- `config/cors.php` line 32: `'supports_credentials' => true`
- `.env.example` line 7: `APP_DEBUG=true`

## Scope
**In scope**: `config/cors.php`, `.env.example`

## Steps

### Step 1: Conditionally apply CORS localhost patterns
In `config/cors.php`, replace line 24:

```php
'allowed_origins_patterns' => app()->environment('production')
    ? []
    : [
        '/^https?:\/\/localhost(:\d+)?$/',
        '/^https?:\/\/127\.0\.0\.1(:\d+)?$/',
    ],
```

**Why**: In production, the Flutter app should connect via the configured `APP_URL`, not localhost. Localhost patterns are only needed for local development.

### Step 2: Fix .env.example
Change line 7 in `.env.example`:
```
APP_DEBUG=false
```

**Why**: `APP_DEBUG=true` in `.env.example` means new deployments that forget to set it will expose stack traces with sensitive data. `false` is the safe default.

### Step 3: Verify
```bash
grep "APP_DEBUG" .env.example
# → should show false
grep -A2 "allowed_origins_patterns" config/cors.php
# → should show the environment check
```

## Done criteria
- [ ] CORS localhost patterns only in non-production
- [ ] .env.example has APP_DEBUG=false
- [ ] `vendor/bin/pint --dirty --format agent` passes
