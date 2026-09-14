# Plan 003: API Boundary Hardening (Zabbix proxy, errors, CORS)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- app/Http/Controllers/Api/MultiLatestValueController.php app/Services/ZabbixService.php config/cors.php routes/api.php tests/Feature/TrafficApiTest.php tests/Feature/MultiLatestValueApiTest.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P1 (any authenticated token can proxy unbounded Zabbix reads + leak internals today)
- **Effort**: S-M
- **Risk**: LOW (validation tightening + error masking; watch Flutter client page sizes)
- **Depends on**: none (parallel with 001/002)
- **Category**: security
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

`MultiLatestValueController` accepts an unbounded `item_ids` array, builds a raw `implode('_', ...)` cache key, forwards everything to Zabbix, and returns `$e->getMessage()` on failure. Its route has no role gate while sibling infra routes do. CORS allows all methods/headers with credentials. One plan closes all four.

## Current state

- `app/Http/Controllers/Api/MultiLatestValueController.php:16-24` (verified):
```php
$request->validate([
    'item_ids' => 'required|array',
    'item_ids.*' => 'required|string',
]);
$itemIds = $request->item_ids;
sort($itemIds);
$cacheKey = 'multi_latest_'.implode('_', $itemIds);
```
- `:32-36` (verified): `catch (Throwable $e) { return response()->json(['error' => 'Zabbix connection failed', 'message' => $e->getMessage()], 500); }`
- `app/Services/ZabbixService.php:113-114` (verified): `foreach ($response['result'] as $item)` with no `isset` guard (siblings at :34 and :67 guard).
- `routes/api.php:64-65`: multi/traffic routes without role gate (compare hardware writes with `manage_hardware`).
- `config/cors.php:20,26,32`: `allowed_methods => *`, `allowed_headers => *`, `supports_credentials => true` on `api/*`.
- Tests `TrafficApiTest.php:32`, `MultiLatestValueApiTest.php:64,81` are mock-only (`$mock->shouldReceive`), no wire-format contract.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Tests | `XDEBUG_MODE=off php artisan test tests/Feature/TrafficApiTest.php tests/Feature/MultiLatestValueApiTest.php` | pass |
| Full | `composer test` | pass |

## Scope

**In scope**: `MultiLatestValueController.php`, `ZabbixService.php` (null-guard only), `config/cors.php`, `routes/api.php` (role gate on the two Zabbix routes only), the two Zabbix test files (cap + contract test).
**Out of scope**: Zabbix polling intervals (plan 007), queued imports, dashboard CSP (plan 002), ticket logic (plan 004).

## Steps

### Step 1: Cap + hash item_ids

Validation becomes:
```php
'item_ids' => 'required|array|max:50',
'item_ids.*' => 'required|string|max:64|regex:/^[A-Za-z0-9_.\-]+$/',
```
Cache key becomes: `$cacheKey = 'multi_latest_'.md5(implode(',', $itemIds));` (`$itemIds` already sorted).
**Verify**: post >50 ids → 422; key line contains `md5(`.

### Step 2: Mask errors + null-guard service

Replace `'message' => $e->getMessage()` with a correlation id: `Log::warning('zabbix.multi_latest', ['e' => $e->getMessage()]);` + `'message' => 'Upstream monitoring unavailable'` (+ `request-id` if the app has one, else omit). In `ZabbixService::getLatestValues`, early-return the all-null map when `! isset($response['result']) || ! is_array($response['result'])`.
**Verify**: forced Zabbix failure returns generic message; existing mock tests pass.

### Step 3: Role-gate + tighten CORS

Add the same infra role used by sibling endpoints to the two Zabbix routes (check live `routes/api.php` for the exact middleware string used by hardware/GIS reads — use that, do not invent a new permission). CORS: enumerate methods (`GET, POST`) and headers (`Accept, Authorization, Content-Type, X-Requested-With`) actually used by web+Flutter; set `supports_credentials => false` unless cookie auth on `api/*` is proven (Sanctum Bearer doesn't need it).
**Verify**: unauthorized-role token → 403 on both routes; preflight still passes for web + Flutter.

### Step 4: Contract test

Add one `Http::fake` test with a recorded Zabbix JSON fixture asserting auth header + error mapping (generic message, no leak). Keep existing mock tests.
**Verify**: new test passes; `grep -rn 'getMessage()' app/Http/Controllers/Api/MultiLatestValueController.php` → empty.

## Test plan

- Updated suites + new contract test pass; full `composer test` green.

## Done criteria

- [ ] `max:50` + per-id regex + `md5` cache key
- [ ] No `$e->getMessage()` in API responses on this controller
- [ ] `isset` guard in `getLatestValues`
- [ ] Role gate on both Zabbix routes; explicit CORS lists
- [ ] Contract test present and passing

## STOP conditions

- Flutter client legitimately sends >50 ids per call → stop, report actual batch size before capping.
- No existing infra role fits (all sibling routes use different gates) → stop, report options.
- Verification fails twice → stop, report.
