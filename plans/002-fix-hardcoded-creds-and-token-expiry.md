# Plan 002: Remove hardcoded DB creds + reduce Sanctum token expiry

> **Executor instructions**: Follow this plan step by step. Verify each step before proceeding.

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19 (v2, verified)

## Why this matters
Hardcoded database credentials in `config/database.php` violate credential hygiene — if .env is misconfigured, the app connects with committed credentials. Sanctum tokens default to 7-day expiry, which is excessive for healthcare data.

## Current state (verified)
- `config/database.php` line 91: `'username' => env('DB_USERNAME', 'h_user')` — **confirmed**
- `config/database.php` line 92: `'password' => env('DB_PASSWORD', 'h_pass')` — **confirmed**
- `config/sanctum.php` line 61: `'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 10080)` — 7 days, **confirmed**

## Scope
**In scope**: `config/database.php`, `config/sanctum.php`, `.env.example`

## Steps

### Step 1: Remove hardcoded DB fallbacks
In `config/database.php`, change:
- Line 91: `'username' => env('DB_USERNAME', 'h_user')` → `'username' => env('DB_USERNAME', '')`
- Line 92: `'password' => env('DB_PASSWORD', 'h_pass')` → `'password' => env('DB_PASSWORD', '')`

### Step 2: Reduce Sanctum token expiry
In `config/sanctum.php`, change:
- Line 61: `'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 10080)` → `'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 1440)`
- 1440 minutes = 24 hours (configurable via env)

### Step 3: Add SANCTUM_TOKEN_EXPIRATION to .env.example
Add line: `SANCTUM_TOKEN_EXPIRATION=1440`

### Step 4: Verify
```bash
grep -n "h_user\|h_pass" config/database.php
# → should return nothing
grep "1440" config/sanctum.php
# → should show the new default
```

## Done criteria
- [ ] No hardcoded credentials in config/database.php
- [ ] Sanctum token expiry ≤ 24 hours by default
- [ ] .env.example documents the setting
- [ ] `vendor/bin/pint --dirty --format agent` passes
