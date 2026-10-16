# Zabbix Integration

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-16; that file keeps the one-line rules and
| the map of where each topic lives.

### Zabbix transport is a typed value, not an exception (issue #741)

`App\Services\Zabbix\ZabbixClient` (interface) + `ZabbixResult` (final value object) + `ServiceZabbixClient` (the only implementation, bound in `AppServiceProvider`).

- `ZabbixResult::success($data)` / `::fail($failure, $message)`; read with `isOk()`, `failed()`, `data()`, `failure()`, `message()`.
- Failure kinds: `timeout`, `connection`, `invalid_response`, `error`.
- `TrafficController` and `MultiLatestValueController` now **branch on the result** and keep the **unchanged 503** contract. The `catch (\Throwable)` that used to wrap these calls is **no longer what guards them** — do not "restore" it, and do not remove the 503 mapping.
- The point is testability: `tests/Unit/ZabbixAdapterTest.php` proves each classification (timeout, connection refused, HTTP error status, invalid JSON, unexpected) with a **stub client and no Zabbix server**.

> **Do not call `ZabbixService` directly from a controller.** Depend on `ZabbixClient`; that is the boundary that keeps failures as values.

### Zabbix sync observability (issue #740)

`zabbix_sync_logs` — one row per `SyncZabbixJob` run (`2026_09_30_000002_create_zabbix_sync_logs_table.php`), model `App\Models\ZabbixSyncLog`.

- Columns: `success`, `consecutive_failures`, `error`, `ran_at` (indexed). `consecutive_failures` is **precomputed including the current row** (0 on a success row), so the threshold check and the widget are single-row reads.
- `failed()` runs **only after the queue gives up** (tries exhausted) → one failure row per job failure; retries do **not** inflate the streak. `failed()` also calls `report($exception)`, so a dead log channel still records it.
- **The unconfigured-skip is now a recorded failure**, not a silent warning: missing `services.zabbix.out_item_id`/`in_item_id` writes a failure row (previously a misconfigured deployment stayed silently broken for weeks).
- At streak **exactly** `ZabbixSyncLog::ALERT_THRESHOLD` (= 3) it alerts every admin **once** through `NotificationService::send()` — in-app only, no email/webhook. Any success resets the streak, so further failures never re-fire the alert.
- Dashboard banner: `getZabbixSyncStatusProperty()` on `/dashboard` reads the **latest row only** and renders `ok` / `stale` (old) / `failing` (streak), showing «نمایش از کش قدیمی» when the traffic cache outlived the last good sync.

> Gotcha: the streak is computed by reading the previous row (`orderByDesc('id')->first()`), so it is **serial by nature** — do not parallelise or reorder run recording.

### Zabbix connection test is real (issue #713)

`ZabbixService::testApiConnection()` calls `apiinfo.version` and throws unless the response carries a string `result`. `testConnection()` verifies API reachability **before** item checks and surfaces the reported Zabbix version. `tests/Unit/ZabbixServiceTest.php` + `tests/Feature/ZabbixDeviceTest.php` cover it.

> The `/it` widgets' friendly «دسترسی به سرور مقدور نمی باشد» message (#703) is **client-side and only for a connection failure** — a 400 from bad item ids must keep surfacing as an error, or real bugs get hidden.

### Batched Zabbix polling (issue #722 sibling, `f002cfc`)

One **batched** poll per `/it/networks` and `/it/wireless` page, not one request per gauge.

## Gotchas (transplanted from the former `## Gotchas Quick Reference`)

| Gotcha | Details |
|---|---|
| `ZabbixService` errors | `TrafficController` and `MultiLatestValueController` map a **failed `ZabbixResult`** to 503, never 500. Since #741 they do **not** wrap the call in `catch (\Throwable)` — that try/catch is gone, and the 503 mapping is what guards them |
| Controllers must depend on `ZabbixClient` | `App\Services\Zabbix\ZabbixClient` is the transport boundary (#741). Calling `ZabbixService` directly from a controller throws away the failure-as-value design and re-couples you to a live server |
| `ZabbixSyncLog` streak is serial | The failure streak is computed by reading the previous row (`orderByDesc('id')->first()`), and `failed()` runs only after retries are exhausted — do not parallelise run recording or the streak inflates |
