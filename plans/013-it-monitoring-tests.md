# Plan 013: Characterize IT monitoring API endpoints with tests

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat ac23e15..HEAD -- app/Http/Controllers/Api/TrafficController.php app/Http/Controllers/Api/MultiLatestValueController.php app/Services/ZabbixService.php`
> If any in-scope file changed, compare "Current state" excerpts before
> proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — tests only, no production behavior change
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `ac23e15`, 2025-07-20
- **Issue**: —

## Why this matters

`TrafficController` and `MultiLatestValueController` drive live IT dashboards
(ISP/network monitoring page, wireless gauges). They call a remote Zabbix API
but have zero tests. If the Zabbix API shape changes or the network is
unreachable, dashboards fail silently. Characterization tests lock down the
current behavior.

## Current state

**`app/Http/Controllers/Api/TrafficController.php`** — takes `out_item_id`,
`in_item_id`, `duration`; delegates to `ZabbixService::getInterfaceTraffic()`;
returns `{ out: [...], in: [...] }` with 30-second cache.

**`app/Http/Controllers/Api/MultiLatestValueController.php`** — takes
`item_ids[]` array; calls `ZabbixService::getLatestValues()`; returns flat
keyed array `{ itemid: value }` with 20-second cache. Catches `Throwable`
and returns 500 with `{ error, message }`.

**`app/Services/ZabbixService.php:67`** — `getItemIdByKey()` has unsafe array
access: `return $response['result'][0]['itemid'] ?? null;` — no `isset($response['result'])`. This was flagged as finding #4 in the 2024-07-17 audit.

**`tests/Feature/`** — no test files for either controller.

Repo conventions: Pest, `RefreshDatabase`, use `Http::fake()` for external
HTTP calls, structural assertion pattern from `TodoApiTest.php`.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Tests | `php artisan test` | exit 0, all pass |
| Lint | `vendor/bin/pint` | exit 0 |

## Scope

**In scope**:
- `tests/Feature/TrafficApiTest.php` — create
- `tests/Feature/MultiLatestValueApiTest.php` — create
- `tests/Feature/ZabbixServiceTest.php` — create

**Out of scope**:
- Any change to controllers or services
- Integration with real Zabbix instance

## Git workflow

- Branch: `test/it-monitoring-api`
- Commit style: `test: add IT monitoring API tests`
- Do NOT push or open a PR unless the operator instructed it

## Steps

### Step 1: Create TrafficApiTest

Create `tests/Feature/TrafficApiTest.php` using `TodoApiTest.php` as the
structural pattern. Use `Http::fake()` to stub the Zabbix API responses.

Test cases:
- `test_unauthenticated_returns_401`
- `test_valid_request_returns_out_and_in_arrays` — authenticate, POST
  `/api/zabbix/traffic` with valid item IDs → 200, response has `out` and
  `in` keys, each an array
- `test_missing_out_item_id_returns_validation_error`
- `test_missing_in_item_id_returns_validation_error`
- `test_duration_parameter_accepted` — pass `duration: 7200` → accepted
- `test_duration_out_of_range_returns_validation_error` — `duration: 999999`

**Mock shape for `history.get` Zabbix response**:
```php
Http::fake([
    '*' => Http::response([
        'jsonrpc' => '2.0', 'id' => 1, 'result' => [
            ['clock' => '1730000000', 'value' => '1000000'],
            ['clock' => '1730000030', 'value' => '2000000'],
        ]
    ], 200),
]);
```

**Verify**: `php artisan test tests/Feature/TrafficApiTest.php`

### Step 2: Create MultiLatestValueApiTest

Create `tests/Feature/MultiLatestValueApiTest.php`. Same pattern.

Test cases:
- `test_unauthenticated_returns_401`
- `test_valid_item_ids_returns_keyed_values` — `item_ids: ['12345', '67890']`
  → 200, response has both keys
- `test_empty_item_ids_returns_validation_error`
- `test_zabbix_error_returns_500_with_error_shape` — mock Zabbix to throw
  via `Http::fake()` returning a 500; response should be `{ error, message }`
  with status 500
- `test_sorted_item_ids_produces_same_cache_key` — same ids in different
  order should hit the same cache (verify by checking Cache facade calls;
  simplest: sort(ids) is already in the controller, so just verify the
  response is keyed correctly)

**Mock shape for `item.get` Zabbix response**:
```php
Http::fake([
    '*' => Http::response([
        'jsonrpc' => '2.0', 'id' => 1, 'result' => [
            ['itemid' => '12345', 'lastvalue' => '45.6'],
            ['itemid' => '67890', 'lastvalue' => '78.9'],
        ]
    ], 200),
]);
```

**Verify**: `php artisan test tests/Feature/MultiLatestValueApiTest.php`

### Step 3: Create ZabbixServiceTest

Create `tests/Feature/ZabbixServiceTest.php` for service-level unit tests
(also in Feature dir per repo convention).

Test cases:
- `test_get_latest_values_returns_null_for_missing_item` — mock response
  with fewer items than requested; all requested ids should appear in
  result (missing ones → `null` per the service's fill logic at lines 112-116)
- `test_get_latest_values_empty_input_returns_empty_array`
- `test_get_item_id_by_key_returns_itemid` — mock `item.get` response
- `test_get_item_id_by_key_returns_null_when_not_found`
- `test_get_interface_traffic_returns_points_array` — mock `history.get`

**Verify**: `php artisan test tests/Feature/ZabbixServiceTest.php`

## Test plan

All test cases listed above. No existing test for these files — all new.
Use `tests/Feature/TodoApiTest.php` as structural reference (same HTTP
mocking + authentication pattern).

## Done criteria

- [ ] `php artisan test` exits 0
- [ ] `vendor/bin/pint` exits 0
- [ ] `tests/Feature/TrafficApiTest.php` exists with ≥6 tests, all passing
- [ ] `tests/Feature/MultiLatestValueApiTest.php` exists with ≥5 tests, all passing
- [ ] `tests/Feature/ZabbixServiceTest.php` exists with ≥5 tests, all passing
- [ ] No files outside the in-scope list are modified (`git status`)

## STOP conditions

Stop and report back if:
- `Http::fake()` pattern used in existing tests differs from what this plan
  assumes (check `tests/Feature/TodoApiTest.php` for how HTTP is mocked — if
  it's not used there, use `Http::fake()` as shown).
- The mock responses don't match the actual Zabbix service's parsing (read
  `ZabbixService.php` again before writing mocks).

## Maintenance notes

- If ZabbixService gains new methods, add tests in the same file.
- The `ZabbixService` constructor reads `config('services.zabbix.*')` — in
  tests this is fine because `Http::fake()` intercepts before the config
  is used in the request URL.