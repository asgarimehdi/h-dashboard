# Plan 006: Fix Zabbix error leakage + CORS max_age

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: 2026-09-19 (v2, verified with CodeGraph)

## Why this matters
ZabbixService exceptions leak internal infrastructure details (API errors, HTTP status codes) to API clients. CORS max_age=0 forces unnecessary preflight requests for every API call from the Flutter app.

## Current state (verified)
- `app/Http/Controllers/Api/MultiLatestValueController.php` lines 33-36:
  ```php
  return response()->json([
      'error' => 'Zabbix connection failed',
      'message' => $e->getMessage(),
  ], 500);
  ```
- `config/cors.php` line 30: `'max_age' => 0`

## Scope
**In scope**: `app/Http/Controllers/Api/MultiLatestValueController.php`, `config/cors.php`

## Steps

### Step 1: Hide Zabbix errors from clients
In `app/Http/Controllers/Api/MultiLatestValueController.php`, replace lines 33-36:

```php
\Log::error('Zabbix API error', ['exception' => $e]);

return response()->json(['error' => 'Service temporarily unavailable'], 503);
```

**Why 503 not 500**: Zabbix being down is a service dependency issue, not an application error. 503 correctly communicates "try again later."

### Step 2: Fix CORS max_age
In `config/cors.php`, change line 30:
```php
'max_age' => 86400,
```
This caches the preflight response for 24 hours, reducing Flutter app API latency.

### Step 3: Verify
```bash
grep -n "getMessage" app/Http/Controllers/Api/MultiLatestValueController.php
# → should NOT show in error response
grep "max_age" config/cors.php
# → should show 86400
```

## Done criteria
- [ ] Zabbix errors not leaked to API clients
- [ ] CORS max_age >= 86400
- [ ] `vendor/bin/pint --dirty --format agent` passes
