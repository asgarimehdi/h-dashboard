# Plan 001: Gate Zabbix proxy endpoints behind a permission

> **Executor instructions**: Follow step by step. Run every verification command and confirm the expected result before continuing. If a STOP condition occurs, stop and report.

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: HIGH
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `bc1e38e`, 2026-09-12

## Why this matters
`/api/zabbix/traffic` and `/api/zabbix/multi-latest` are reachable by **any** authenticated
user (they sit under `auth:sanctum` + `throttle` only). Because these controllers forward
arbitrary client-supplied Zabbix item IDs to the internal monitoring host, any low-privilege
user can (a) read monitoring values for arbitrary items and (b) use the app server as an
SSRF relay against internal Zabbix — regardless of role or organizational unit.

Secondarily, `MultiLatestValueController` leaks internal Zabbix error strings to the client
(`INFO-01`), aiding reconnaissance.

## Current state
- `routes/api.php:64-65` maps both routes under only `['auth:sanctum','throttle:api-user']`.
- `app/Http/Controllers/Api/TrafficController.php:12` type-hints plain `Illuminate\Http\Request`.
- `app/Http/Controllers/Api/MultiLatestValueController.php:14` type-hints plain `Illuminate\Http\Request`.
- `app/Http/Controllers/Api/MultiLatestValueController.php:32-36` returns `$e->getMessage()` on `Throwable`.
- The app uses Spatie permission middleware `role_or_permission:<perm>` (see any hardware route).
- Requiring a *new* permission means adding it to `database/seeders/*.php` permission seed and
  to roles; prefer reusing an existing IT/monitoring-scoped permission if one fits (see AGENTS.md
  "Key permissions" list — `bw` or `manage_hardware` may already be the right gate; confirm with
  the maintainers before inventing a new permission).

## Commands you will need
| Purpose | Command | Expected |
|---------|---------|----------|
| Route list | `php artisan route:list --path=zabbix` | show the two routes + current middleware |
| Test | `XDEBUG_MODE=off php artisan test tests/Feature/TrafficApiTest.php` | pass |
| Format | `vendor/bin/pint --dirty` | clean |

## Scope
**In scope**: `routes/api.php`, `app/Http/Controllers/Api/TrafficController.php`,
`app/Http/Controllers/Api/MultiLatestValueController.php`, the relevant permission seeder,
and `tests/Feature/TrafficApiTest.php`.
**Out of scope**: the Zabbix service itself, the Livewire gauge UI, any other route.

## Git workflow
- Branch off `rebecca`; commit messages `fix(security): ...` / `test: ...`.
- Do NOT open a PR (the maintainer does that via the "pr" trigger).

## Steps

### Step 1: Add the permission middleware to both routes
In `routes/api.php`, move both zabbix routes under the chosen guard, e.g.
`->middleware(['auth:sanctum', 'throttle:api-user', 'role_or_permission:manage_hardware'])`
(pick the real permission after confirming with maintainers).
**Verify**: `php artisan route:list --path=zabbix` shows the new middleware on both rows.

### Step 2: Validate item IDs server-side
In both controllers, replace free-form ID reads with validated whitelisting. At minimum,
cast to int and reject a non-numeric/empty list. If the layout knows the finite set of valid
item IDs, harden to an allow-list.
**Verify**: sending a garbage `item_ids` returns 422/400, not a passed-through value.

### Step 3: Stop leaking Zabbix errors
In `MultiLatestValueController`, replace `'message' => $e->getMessage()` with
`report($e);` (log server-side) + a generic `'Zabbix unavailable'` message.
**Verify**: code review — no raw exception text in the response body.

### Step 4: Add/adjust tests
- Extend `tests/Feature/TrafficApiTest.php` to assert: (a) unprivileged user → 403,
(b) privileged user → 200, (c) invalid `item_ids` → 422/400, (d) Zabbix timeout → generic 500.
**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/TrafficApiTest.php` passes.

## Test plan
- New cases: 403 for unprivileged, 422 for bad input, generic error on Zabbix failure.
- Existing happy-path tests must still pass.

## Done criteria
- Both routes carry a `role_or_permission` gate (visible in `route:list`).
- Unprivileged `GET /api/zabbix/multi-latest` → 403.
- No `$e->getMessage()` in the multi-latest error body.
- `TrafficApiTest` green.

## STOP conditions
- The chosen permission name is ambiguous — confirm with maintainers instead of guessing.
- Existing legitimate consumers (mobile app) break because they use these endpoints without
  the new permission — surface this instead of silently shipping.