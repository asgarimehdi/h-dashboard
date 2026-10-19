# perf: one chart page loads Highcharts from a public CDN

> **Plan 020** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P3` · formerly GitHub issue #1156 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/livewire/it/network-traffic-chart.blade.php public/js/chart`

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

One chart page loads Highcharts from `https://code.highcharts.com/highcharts.js` — unversioned, no SRI, off-network — while every other chart page in the app uses the copy vendored at `public/js/chart/highcharts.js`. That means an extra ~272 KB third-party download plus DNS/TLS on an internal-network app, an API version that can change under the app without warning, and two Highcharts versions in the same deployment with potentially different behaviour. Six other pages already prove the local copy works.

## Current state

- `resources/views/livewire/it/network-traffic-chart.blade.php:52` (inside an `@assets` block):

```blade
<script src="https://code.highcharts.com/highcharts.js"></script>
```

- The vendored copy and its consumers:

```text
public/js/chart/highcharts.js  (272 KB, v12.1.2)
resources/views/livewire/reports/units.blade.php:252   <script src="{{ asset('js/chart/highcharts.js') }}"></script>
resources/views/livewire/reports/persons.blade.php:319
resources/views/livewire/reports/advanced.blade.php:470
resources/views/livewire/reports/todos.blade.php:313
resources/views/livewire/dashboard.blade.php:620
resources/views/livewire/hr/dashboard.blade.php:159
```

- The page's own fetch loop is at `:116`; the chart is destroyed/rebuilt on updates (`Highcharts.charts.forEach(chart => … .destroy())`).
- Repo convention: page-specific scripts are pushed into `@stack('scripts')` (issue #984/#1018) or emitted via `@assets`; there is no CDN dependency elsewhere for a library that is vendored.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/ItMonitoringTest.php` (find: `ls tests/Feature \| grep -i "it\|zabbix"`) | all pass |
| E2E | `bash scripts/e2e-test.sh tests/e2e/it` (slow; needs `.env.e2e`) | specs pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `resources/views/livewire/it/network-traffic-chart.blade.php`

**Out of scope** (do NOT touch):
- `public/js/chart/highcharts.js` itself (do not upgrade or replace it in this plan).
- The page's fetch/poll logic and the Zabbix 503 handling (`docs/agents/zabbix.md` — a failed `ZabbixResult` maps to 503; the friendly «دسترسی به سرور مقدور نمی باشد» message is client-side and must stay).
- The other six consumers.

## Git workflow

- Branch: `perf/highcharts-local-asset`
- Commit style: `perf: use the vendored Highcharts build on the Zabbix chart page (#020)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Confirm the local build has the modules this page needs

Compare the page's Highcharts usage (chart types, any tooltip/export features) against the vendored build's options. If the page uses a module the vendored file lacks (e.g. an indicator, exporting, accessibility), **STOP** and report which one — the fix is then to add that module locally (a deliberate decision), not to keep the CDN.

**Verify**: the page's chart config uses only core features, or you have identified the exact missing module.

### Step 2: Swap the source

Replace the CDN script with the same tag the six other pages use:

```blade
<script src="{{ asset('js/chart/highcharts.js') }}"></script>
```

Keep it wherever it currently is (`@assets`) so load ordering relative to the page's `@script` is unchanged; if the `@assets` block runs on every update, consider the `@push('scripts')` pattern instead — but only if it does not change ordering (state which you chose).

**Verify**: `grep -rn "code.highcharts.com" resources/` → no matches.

### Step 3: Prove the chart still renders

Run the IT E2E spec (it loads this page) and, if it does not assert chart rendering, add a minimal check (the Zabbix canvas/container exists and no `Highcharts is not defined` console error).

**Verify**: `bash scripts/e2e-test.sh tests/e2e/it` → specs pass.

## Test plan

- Update/extend the IT monitoring Feature test (or E2E) to assert the page HTML references `js/chart/highcharts.js` and does **not** reference `code.highcharts.com`.
- Keep the existing Zabbix-unavailable behaviour test green (`tests/e2e/it/zabbix-unavailable.spec.ts` or the Feature equivalent).
- Verification: focused Feature test + the IT E2E suite.

## Done criteria

- [ ] `grep -rn "code.highcharts.com" resources/` returns no matches
- [ ] The page HTML references `js/chart/highcharts.js` (new/updated test)
- [ ] The chart renders and the friendly Zabbix-unavailable message still works
- [ ] `XDEBUG_MODE=off php artisan test <IT test>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] No files outside the in-scope list are modified

## STOP conditions

- The vendored build lacks a module the page uses (step 1) — report which, do not silently downgrade the chart.
- The `@assets` block's ordering relative to the page script cannot be preserved with a stack push — keep the `@assets` placement.
- The E2E spec fails for a reason unrelated to the chart source (e.g. Zabbix flakiness) — report rather than editing the spec.

## Maintenance notes

- If Highcharts is ever upgraded, upgrade the vendored file once and all seven pages move together — that is the point of this change.
- Reviewer should scrutinize: that no CDN reference remains anywhere in `resources/`, and that the chart's tooltips/legend look identical to before.
