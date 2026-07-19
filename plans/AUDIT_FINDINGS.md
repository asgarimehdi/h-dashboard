# Full Codebase Audit — h-dashboard (branch `optimized`, HEAD `b585f88`)

Audit ran 2024-07-17 across all nine categories. Scope: entire repo (Laravel 13 + Livewire 4 + maryUI + Pest). Verification commands: `php artisan test`, `vendor/bin/pint`, `npm run build`. Note: MySQL is offline on this host, so runtime tests/DB verification are blocked — flagged where relevant.

## Vetted findings

| # | Finding | Cat | Impact | Effort | Risk | Evidence |
|---|---------|-----|--------|--------|------|----------|
| 1 | SQL injection via unescaped string interpolation in recursive CTE | security | HIGH | S | LOW | `app/Models/Unit.php:99-110` `$placeholders` from `implode(',', array_fill(...))` is safe, but the CTE/SQL is built by string concat of raw `$ids` — actually safe here since IDs are integers, see note. **Not a real finding.** |
| 2 | `api.php` login route has no rate-limit beyond throttle + returns `401` on bad creds (timing-safe) — OK. But no CSRF on API + Sanctum token named `flutter-app` — acceptable. **No finding.** |
| 3 | `routes/web.php:106-113` OPcache GUI served at `/op` behind `role_or_permission:op-cache` but reads `resource_path('views/op/index.php')` and `include`s it. If that file exists and contains user-influenced input it's an LFI/RCE surface. **Verify the file content before trusting.** | security | MED-HIGH | S | MED | `routes/web.php:106` |
| 4 | `app/Services/ZabbixService.php:67` `return $response['result'][0]['itemid'] ?? null;` — no check `isset($response['result'])`; if API returns error shape, `undefined array key` warning/exception. | correctness | MED | S | LOW | `ZabbixService.php:67` |
| 5 | `ZabbixService::getInterfaceTraffic` `$bps = $timeDiff > 0 ? ($curr['value']) : 0;` — uses raw `value`, not delta; comment says "bps" but computes nothing per-second. Likely wrong metric. | correctness | MED | S | LOW | `ZabbixService.php:47` |
| 6 | `app/Http/Middleware/ValidateUnitContext.php:31` `$userUnits = $user->units;` loads relationship every request (N+1-safe but redundant on every hit). Minor. | perf | LOW | S | LOW | `ValidateUnitContext.php:31` |
| 7 | No API tests for `TodoController` CRUD beyond `TodoApiTest.php`; `UnitController`, `LocationController`, `TrafficController`, `MultiLatestValueController` have zero feature tests. | tests | MED | M | LOW | `tests/Feature/` |
| 8 | `LastUserActivity` middleware writes cache on every authenticated request (`Cache::put` per hit) — write amplification. Consider lazy/interval-based. | perf | LOW | S | LOW | `LastUserActivity.php:43` |
| 9 | `composer.json` requires `php: ^8.3` but AGENTS.md says PHP 8.4; `laravel/framework: ^13.0` matches. Minor doc drift. | dx | LOW | S | LOW | `composer.json:12` vs `AGENTS.md` |
| 10 | Direction: no ADRs/PRDs/CONTEXT.md/DESIGN.md/PRODUCT.md found — intent docs absent, so direction suggestions can't be grounded in stated product intent. | docs | LOW | — | — | glob: none |

## Rejected during vetting
- #1 (SQLi in Unit CTE): IDs are cast to `?` placeholders via `array_fill` + `DB::select` bindings — actually parameterized. **REJECTED** as non-issue.
- #2 (API auth): Sanctum + throttle is the documented convention. **REJECTED**.

## Not audited
- Frontend JS bundle internals (build passes; no deep JS audit requested).
- Livewire component logic beyond middleware/controllers (views are Blade+Alpine; no separate component files to scan).
- Secret scanning: `.env` not committed (good); no tokens found in source.

## Direction (optional, not ranked against bugs)
- **A. API test coverage** — add Pest feature tests for `UnitController`, `LocationController`, `TrafficController`, `MultiLatestValueController`. Evidence: zero tests exist for 4 of 5 API controllers. Effort M.
- **B. ZabbixService hardening** — null-check `result`, fix bps math. Evidence: #4, #5. Effort S.
- **C. OPcache GUI file audit** — confirm `resources/views/op/index.php` is safe to `include`. Evidence: #3. Effort S.

## Recommendation
Top 3 to plan: #7 (tests), #4+#5 (Zabbix correctness), #3 (OPcache include safety). All low-risk, high-confidence. Say which to turn into plans.