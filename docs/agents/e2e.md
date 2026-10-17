# E2E Testing (Playwright)

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-16; that file keeps the one-line rules and
| the map of where each topic lives.

## E2E Testing (Playwright)

**202 tests** across **42 spec files** in `tests/e2e/` (`npx playwright test --list` count, verified 2026-10-08; static `test(` grep undercounts loop-generated suites). Covers auth, navigation, RBAC, CRUD for users/tickets/personnel/units/hardware, reports, maps, dashboard, settings, search, activity log, tools, org chart, and the IT/Zabbix pages.

> CI runs the suite in the e2e job of `.github/workflows/test.yml` (non-blocking ramp, `continue-on-error: true` until two consecutive green runs — see #867).

Newest suites since the 2026-09-25 review: `reports/daily-window`, `dashboard/ticket-trend-window`, `it/zabbix-unavailable`, `it/monitoring`, `organization/units-tree`, `organization/unit-map-boundary`, `hr/org-chart`, `personnel/list`.

### Setup (one-time, per machine)
```bash
npm install                     # includes @playwright/test + dotenv
npx playwright install chromium # one-time browser install (Chromium + headless shell)
bash scripts/build-env-e2e.sh   # builds .env.e2e from .env.e2e.example, copying secret VALUES without printing them
```

### `.env.e2e` — gitignored, must be created locally

It is in `.gitignore`, so it never ships with the repo. **`scripts/build-env-e2e.sh`** (issue #703) generates it from `.env.e2e.example` by copying `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD` out of `.env` — it edits the file **without printing the values**, because the terminal masks secrets in output and a read-and-rewrite would write a literal `***` into `.env.e2e`. Manual equivalent:

```bash
cp .env.e2e.example .env.e2e
# copy from .env: APP_KEY, DB_USERNAME, DB_PASSWORD, REDIS_PASSWORD
# then create the isolated database (NEVER point e2e at `h_dashboard` — it is wiped every run):
psql -h 127.0.0.1 -U h_dashboard -d postgres \
  -c "CREATE DATABASE h_dashboard_e2e WITH OWNER=h_dashboard;"
```

> **⚠️ `APP_LOCALE=fa` is MANDATORY in `.env.e2e`.**
> **`.env.e2e.example` now DOES include it** (fixed in `7485043`) — the earlier failure mode was the example file omitting it while `config/app.php` defaults to `en`. Without it the app renders English pagination (`Showing 1 to 20 of 318 results`, `Next »`) and English validation messages instead of the Persian strings every spec asserts → **11 tests fail** across `auth/password-change`, `hardware/list-filters`, `organization/units`, `personnel/list`, `users/list`.
> If you hand-write `.env.e2e` from an older copy of the example, re-add:
> ```
> APP_LOCALE=fa
> APP_FALLBACK_LOCALE=en
> APP_FAKER_LOCALE=en_US
> ```
> Playwright's `locale: 'fa-IR'` is browser-level only (affects `Intl`, not Laravel translations) — it does **not** replace this.

### Credentials
Test credentials live in `.env.e2e` (gitignored), read by `playwright.config.ts` via `dotenv` and sourced by the shell script for the app itself. **No fallbacks** — `tests/e2e/shared/fixtures.ts` throws if any is missing: `TEST_PASSWORD`, `TEST_N_CODE`, `TEST_UNIT_MANAGER_N_CODE`, `TEST_EXPERT_N_CODE`, `TEST_REGULAR_USER_N_CODE`.

### Run
```bash
bash scripts/e2e-test.sh               # RECOMMENDED: swap .env → config/route:clear → migrate:fresh --seed → pwd user → serve :8001 → test → restore
bash scripts/e2e-test.sh tests/e2e/auth    # single suite (fast loop)
npx playwright test --reporter=list    # only if .env is already swapped and the server is already running
```

> **Cleanup:** teardown in `scripts/e2e-test.sh` runs from a `trap` (`trap teardown EXIT`, plus INT/TERM traps), so even a failing Playwright run restores `.env` and removes run-state (verified on `beta`; the old no-`trap` warning was stale). Only if a run is hard-killed before the trap fires, recover manually:
> ```bash
> [ -f .env.dev.bak ] && cp .env.dev.bak .env && rm -f .env.dev.bak
> pgrep -f 'artisan serve --port=800[1]' | xargs -r kill
> ```
> (never `pkill -f 'artisan serve'` — it kills the shared dev server too)

### Typecheck (issue #1069)
```bash
npm run typecheck    # tsc --noEmit over tests/e2e/** + playwright.config.ts
```
Playwright only transpiles the specs — it never type-checks them — so a type error used to surface at runtime, and with `retries: 2` in CI it could hide as a flake. `tsconfig.json` scopes the check to exactly the TypeScript that exists in this repo (`tests/e2e/**/*` + `playwright.config.ts`, `strict`, `noEmit`). **No ratchet/ceiling is in place** — the surface is clean; if it ever needs one, raise a documented max here instead of dropping `@ts-nocheck` into a spec. Wired **non-blocking** on purpose: the CI `typecheck` job is `continue-on-error: true` (same shape as `coverage-report` and `e2e`), so it never gates and never touches the blocking `lint`/`test`/`phpstan` jobs. `npm ci` in that job doubles as the lock-file consistency check.

### Common failure → cause
| Symptom | Cause | Fix |
|---|---|---|
| 11 tests fail on `نمایش…` / `باید مطابقت داشته باشند` — DOM shows `Showing…` / English validation text | `.env.e2e` has no `APP_LOCALE=fa` (a hand-written or stale example copy) | add the three `APP_*LOCALE*` lines above |
| `fixtures.ts` throws `<VAR> env var is required` or `.run-state.json not found` | `.env.e2e` missing / bare `npx playwright test` without global setup | create `.env.e2e`; run through `scripts/e2e-test.sh` |
| `No tests found` even from `npx playwright test --list` | fixtures read `.run-state.json`, which only `scripts/e2e-test.sh` writes | run through the script; a bare invocation cannot even enumerate |
| `Executable doesn't exist … chromium` | browser not installed | `npx playwright install chromium` |
| `database "h_dashboard_e2e" does not exist` | database never created | `CREATE DATABASE h_dashboard_e2e WITH OWNER=h_dashboard` (plain — migrations enable PostGIS) |
| `.env` still the e2e one after a failed run | script aborted before restore | restore from `.env.dev.bak` manually (see cleanup above) |

### Key helpers (in `tests/e2e/shared/fixtures.ts`)
- `login(page, nCode?, password?)` — fills login form, waits for redirect
- `logout(page)` — submits the real `<form action="/logout">`
- `waitForLivewire(page)` — waits for `.wire-loading` to disappear
- `waitForSearchResults(page, selector)` — waits for Livewire debounced results
- `waitForToast(page, text?)` — waits for toast notification
- `TEST_USER` / `ROLE_ACCOUNTS` — credentials from env vars

### Config highlights
- `baseURL`: `process.env.BASE_URL || 'http://localhost:8000'`
- `locale`: `fa-IR`, `timezoneId`: `Asia/Tehran`
- Retries: 2 in CI, 0 locally
- Trace/screenshot/video on failure

## Gotchas (transplanted from the former `## Gotchas Quick Reference`)

| Gotcha | Details |
|---|---|
| MaryUI `<x-menu-sub>` collapses in E2E | It renders as a `<details>`, open only when a child is active. A collapsed `<details>` still gives children a non-empty box in Chromium, so `isVisible()` is not an "is it open" test — read `details.open`, click the `<summary>`, then `scrollIntoViewIfNeeded()` before clicking (see `tests/e2e/maps/spa-navigation.spec.ts`) |
