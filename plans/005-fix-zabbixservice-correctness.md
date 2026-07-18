# Plan 005: Fix ZabbixService correctness issues

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat <planned-at SHA>..HEAD -- app/Services/ZabbixService.php`
> If ZabbixService.php changed since this plan was written, compare the
> "Current state" against the live code before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `b585f88`, 2024-07-17
- **Issue**: N/A

## Why this matters

Two correctness bugs in ZabbixService cause:
1. **Null reference crash**: `getItemIdByKey()` accesses `$response['result'][0]` without checking if `result` exists or has items — throws error when Zabbix API returns an error shape or empty result.
2. **Wrong metric calculation**: `getInterfaceTraffic()` labels the output as `bps` (bits per second) but actually uses the raw counter value without computing the delta between samples — the chart displays meaningless data.

These are low-risk fixes that improve reliability and data accuracy.

## Current state

**File:** `app/Services/ZabbixService.php`

**Buggy line 67** (getItemIdByKey):
```php
return $response['result'][0]['itemid'] ?? null;
```
This assumes `$response['result']` exists and is an array. If the Zabbix API returns an error object or empty result, this throws "undefined array key" error.

**Buggy line 47** (getInterfaceTraffic):
```php
$bps = $timeDiff > 0 ? ($curr['value']) : 0;
```
This uses `$curr['value']` (raw counter) instead of computing the delta between current and previous values. True bits-per-second should be `($curr['value'] - $prev['value']) / $timeDiff`.

**Repo conventions**: Service classes follow standard PHP with type hints. See `app/Services/NotificationService.php` for patterns. Error handling uses try-catch at controller level, not in services.

## Commands you will need

| Purpose   | Command                  | Expected on success |
|-----------|--------------------------|---------------------|
| Syntax    | `php -l app/Services/ZabbixService.php` | `No syntax errors detected` |
| Lint      | `vendor/bin/pint app/Services/ZabbixService.php --test` | `All files pass` |
| Test      | `php artisan test --compact` | all pass (or no NEW failures) |
| Build     | `npm run build`          | exit 0              |

## Scope

**In scope** (the only files you should modify):
- `app/Services/ZabbixService.php`

**Out of scope** (do NOT touch, even though they look related):
- `app/Http/Controllers/Api/TrafficController.php` — uses ZabbixService but doesn't need changes
- `app/Http/Controllers/Api/MultiLatestValueController.php` — uses ZabbixService but doesn't need changes
- Zabbix config values in `.env` or `config/services.php`

## Git workflow

- Branch: `advisor/005-fix-zabbixservice-correctness` (or the repo's branch-naming convention if one is evident)
- Commit per step or per logical unit; message style: `fix: handle null result in ZabbixService::getItemIdByKey`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Fix null reference in getItemIdByKey

Replace the current implementation:
```php
public function getItemIdByKey($key)
{
    $response = $this->request("item.get", [
        "output" => ["itemid"],
        "filter" => [
            "key_" => $key
        ]
    ]);

    return $response['result'][0]['itemid'] ?? null;
}
```

With:
```php
public function getItemIdByKey(string $key): ?string
{
    $response = $this->request("item.get", [
        "output" => ["itemid"],
        "filter" => [
            "key_" => $key
        ]
    ]);

    return isset($response['result'][0]['itemid']) ? $response['result'][0]['itemid'] : null;
}
```

**Verify**: `php -l app/Services/ZabbixService.php` → `No syntax errors detected`

### Step 2: Fix bps calculation in getInterfaceTraffic

Replace the current implementation:
```php
$bps = $timeDiff > 0 ? ($curr['value']) : 0;
```

With:
```php
$delta = $timeDiff > 0 ? ($curr['value'] - $prev['value']) / $timeDiff : 0;
```

Update the variable name in the return to match:
```php
$points[] = [
    'x' => $curr['clock'] * 1000,
    'y' => round($delta / 1000000, 2)
];
```

**Verify**: `php -l app/Services/ZabbixService.php` → `No syntax errors detected`

### Step 3: Run lint and tests

`vendor/bin/pint app/Services/ZabbixService.php --test`

**Verify**: `All files pass`

`php artisan test --compact`

**Verify**: No NEW failures (pre-existing failures are acceptable)

## Test plan

- No new test files needed — the fixes are correctness improvements.
- Validation: Verify the service doesn't crash on null/empty results (manual testing with Zabbix API or mock).
- After changes, run `php artisan test --compact` to confirm no regressions.

## Done criteria

ALL must hold:

- [ ] `php -l app/Services/ZabbixService.php` exits 0
- [ ] `vendor/bin/pint app/Services/ZabbixService.php --test` exits 0
- [ ] `php artisan test --compact` exits with no NEW failures
- [ ] `getItemIdByKey` has `?string` return type and uses `isset()` check
- [ ] `getInterfaceTraffic` uses delta calculation `($curr['value'] - $prev['value']) / $timeDiff`
- [ ] No files outside `app/Services/ZabbixService.php` are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at the locations in "Current state" doesn't match the excerpts (the codebase has drifted since this plan was written).
- A step's verification fails twice after a reasonable fix attempt.
- The fix appears to require touching an out-of-scope file.
- You discover the assumption "Zabbix API returns result array" is false (check actual API response format).

## Maintenance notes

- If Zabbix API response format changes, the `isset()` check in Step 1 will still protect against crashes.
- The delta calculation assumes Zabbix counters are monotonically increasing (standard for network traffic). If counters can reset (e.g., device reboot), the delta might be negative — consider adding a min(0, delta) check.
- Future: Add unit tests for ZabbixService with mocked HTTP responses.