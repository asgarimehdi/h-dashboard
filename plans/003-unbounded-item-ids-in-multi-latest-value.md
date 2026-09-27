# Plan 003: Unbounded item_ids array in MultiLatestValueController — add max limit

> Written against commit: `b9dc5b0` (beta/sydney)
> Category: Performance | Effort: S | Impact: HIGH

## Problem

`MultiLatestValueController::index()` accepts `item_ids` as a required array with string entries, but imposes **no upper bound on the array size**. An attacker (or misconfigured client) can send thousands of item IDs in a single request, causing:

1. **Cache key bloat** — Line 25 builds `multi_latest_` + `implode('_', $itemIds)`. With 10,000 IDs this creates a multi-KB Redis key, wasting memory and degrading key lookup performance.
2. **Zabbix API overload** — `ZabbixService::getLatestValues()` (line 108–111) passes the entire array directly to `item.get`. Zabbix has practical limits on request body size; oversized payloads cause 502/504 timeouts or OOM on the Zabbix server.
3. **No chunking** — The controller does not chunk large arrays, so the entire request hits Zabbix as one call with no fallback.

**Compare** with `TrafficController` which takes only 2 fixed IDs — no unbounded input risk there.

### Evidence

- `app/Http/Controllers/Api/MultiLatestValueController.php:17–19` — validation is `required|array` / `required|string` with **no `max` rule**
- `app/Http/Controllers/Api/MultiLatestValueController.php:25` — `implode('_', $itemIds)` builds unbounded cache key
- `app/Services/ZabbixService.php:108–111` — `getLatestValues()` passes full array to Zabbix `item.get` without size check
- No `max:` constraint exists anywhere in the project's validation rules for arrays

## Solution

Add `max:100` to the `item_ids` validation rule, limiting the array to 100 entries. This is generous for a monitoring dashboard (typical use: 5–50 items per dashboard panel) while preventing abuse.

### Before

```php
$request->validate([
    'item_ids' => 'required|array',
    'item_ids.*' => 'required|string',
]);
```

### After

```php
$request->validate([
    'item_ids' => 'required|array|max:100',
    'item_ids.*' => 'required|string|max:64',
]);
```

The `max:64` on individual entries limits each item ID string to 64 characters, preventing excessively long strings from being used as cache keys or Zabbix parameters. Zabbix item IDs are numeric strings typically ≤20 chars.

## Files in Scope

- `app/Http/Controllers/Api/MultiLatestValueController.php`
- `tests/Feature/MultiLatestValueControllerTest.php`
- `tests/Feature/MultiLatestValueApiTest.php`

## Files Out of Scope

- `app/Services/ZabbixService.php` — the service already handles empty arrays gracefully; the controller guard is sufficient
- `routes/api.php` — no route changes needed
- `TrafficController` — only accepts 2 fixed IDs, not affected

## Steps

### Step 1: Add max validation rules to the controller

1. Edit `app/Http/Controllers/Api/MultiLatestValueController.php:17–19`
2. Change `'item_ids' => 'required|array'` to `'item_ids' => 'required|array|max:100'`
3. Change `'item_ids.*' => 'required|string'` to `'item_ids.*' => 'required|string|max:64'`

### Step 2: Add test for array size limit

In `tests/Feature/MultiLatestValueControllerTest.php`, add:

```php
public function test_rejects_item_ids_exceeding_max_limit(): void
{
    $ids = array_map('strval', range(1, 101));

    $response = $this->authUser()
        ->getJson('/api/zabbix/multi-latest?' . http_build_query(['item_ids' => $ids]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['item_ids']);
}
```

### Step 3: Add test for individual item ID length limit

In `tests/Feature/MultiLatestValueControllerTest.php`, add:

```php
public function test_rejects_item_id_exceeding_max_length(): void
{
    $longId = str_repeat('a', 65);

    $response = $this->authUser()
        ->getJson('/api/zabbix/multi-latest?item_ids[]=' . $longId);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['item_ids.0']);
}
```

### Step 4: Verify existing tests still pass

1. `composer pint` — format code
2. `composer phpstan` — no new errors
3. `composer test` — all 1352+ tests pass

## Test Plan

1. Existing tests in `MultiLatestValueControllerTest.php` (4 tests) and `MultiLatestValueApiTest.php` (5 tests) must pass unchanged — they use 1–2 IDs which are within the new limit
2. New test: 101 IDs → 422 validation error
3. New test: 65-char item ID → 422 validation error
4. New test: exactly 100 IDs → 200 success (boundary test)
5. Verify no regression: `composer test`

## Maintenance Note

The limit of 100 is chosen conservatively. If a legitimate use case requires more (e.g., a "full hospital overview" dashboard), increase to 200 or add chunking in the controller. Monitor Zabbix API response times with the `api-user` rate limiter (60 req/min) — a user sending 100-ID requests at rate limit will still be bounded.

If the Flutter app needs more items, consider batching client-side (send multiple 100-ID requests) rather than raising the limit further.

## Done Criteria

- [ ] `MultiLatestValueController` validation includes `max:100` on `item_ids` and `max:64` on `item_ids.*`
- [ ] Test exists for exceeding array size limit → 422
- [ ] Test exists for exceeding individual ID length → 422
- [ ] Boundary test: 100 IDs → 200
- [ ] All existing tests pass (`composer test`)
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
