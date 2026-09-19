# Plan 009: Fix CORS localhost patterns + .env.example APP_DEBUG

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19

## Why this matters
CORS allows localhost patterns with credentials in all environments. .env.example ships APP_DEBUG=true, which would expose stack traces if deployed without overriding.

## Current state
- `config/cors.php` line 24: localhost regex patterns with supports_credentials=true
- `.env.example` line 7: `APP_DEBUG=true`

## Steps

### Step 1: Conditionally apply CORS localhost patterns
In `config/cors.php`, wrap localhost patterns:
```php
'allowed_origins_patterns' => app()->environment('production')
    ? []
    : [
        '/^https?:\/\/localhost(:\d+)?$/',
        '/^https?:\/\/127\.0\.0\.1(:\d+)?$/',
    ],
```

### Step 2: Fix .env.example
Change `APP_DEBUG=true` to `APP_DEBUG=false` in `.env.example`.

### Step 3: Verify
**Verify**: `cd /home/runner/h-dashboard && grep "APP_DEBUG" .env.example` → should show false

## Done criteria
- [ ] CORS localhost patterns only in non-production
- [ ] .env.example has APP_DEBUG=false
- [ ] pint passes
