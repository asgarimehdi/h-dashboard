# Plan 006: Fix Zabbix error leakage + CORS max_age

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19

## Why this matters
ZabbixService exceptions leak internal infrastructure details (API errors, HTTP status codes) to API clients. CORS max_age=0 forces unnecessary preflight requests for every API call.

## Current state
- `app/Http/Controllers/Api/MultiLatestValueController.php` line 33-36: catches Throwable, returns `$e->getMessage()` in JSON
- `config/cors.php` line 30: `'max_age' => 0`

## Steps

### Step 1: Hide Zabbix errors from clients
In MultiLatestValueController, replace `$e->getMessage()` with a generic message:
```php
\Log::error('Zabbix API error', ['exception' => $e]);
return response()->json(['error' => 'Service temporarily unavailable'], 503);
```

### Step 2: Fix CORS max_age
In `config/cors.php`, change `'max_age' => 0` to `'max_age' => 86400`.

### Step 3: Verify
**Verify**: `cd /home/runner/h-dashboard && grep -n "getMessage" app/Http/Controllers/Api/MultiLatestValueController.php` → should not show in error response
**Verify**: `grep "max_age" config/cors.php` → should show 86400

## Done criteria
- [ ] Zabbix errors not leaked to API clients
- [ ] CORS max_age ≥ 86400
- [ ] pint passes
