# Plan 024: E2E Data Independence (خودکفایی تست‌های E2E)

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in the STOP conditions occurs, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat 142b478..HEAD -- tests/e2e/ playwright.config.ts .env.e2e.example app/Console/Commands/ database/seeders/` → expect no output (files untouched since review).
>
> **Scope note**: This is the ONLY remaining plan in `plans/` (all plans 001–023 were deleted by owner decision 2026-09-13). It is self-contained — no dependencies.

## Status

| Field      | Value                          |
|------------|--------------------------------|
| Category   | tests (E2E)                    |
| Effort     | L                              |
| Risk       | MED                            |
| Priority   | P0                             |
| Depends on | none (branch-independent — run from any branch) |
| Verified on | `142b478` (2026-09-13, any branch) |
| Branch     | any (branch-independent)           |

## ⚠️ TL;DR فارسی

**مشکل:** E2E به دیتای dev چسبیده (سرور `:8000` همون DB اصلی رو می‌خونه): جستجوی رکورد واقعی (`هادیلو`، `4411015056`)، assert عدد ثابت (`290/241/49`)، جهش رمز روی اکانت مشترک.

**راه‌حل:** DB اختصاصی `h_dashboard_e2e` + سرور جدا `:8001` + `migrate:fresh --seed` اول هر ران (خودترمیم‌شونده) + پیشوند یکتا `E2E-<runId>` برای collision موازی + assert نسبی.

**ریسک:** 🟡 متوسط (حجم کار بالا ولی مکانیکی؛ ریسک آلودگی dev صفر می‌شود)

---

## 1. Problem (verified 2026-09-13 on `f8dcb21`)

E2E tests currently hit the **dev database** (`playwright.config.ts` → `BASE_URL localhost:8000` → `.env` → `DB_DATABASE=h_dashboard`). Three fragile couplings to real data:

1. **رکورد واقعی** — `tests/e2e/users/list.spec.ts:39` + `users/crud.spec.ts:63` + `search/global.spec.ts:36` search `هادیلو`; `personnel/list.spec.ts:46` searches `4411015056`; `organization/units.spec.ts:36` searches `دانشگاه علوم پزشکی زنجان`. Deleting/renaming that person breaks them.
2. **عدد ثابت** — `personnel/list.spec.ts:34,63,76,85,88` asserts `318`; `users/list.spec.ts:34,74` asserts `317`/`32`; `hardware/list-filters.spec.ts:29,48` asserts `449`; `organization/units.spec.ts:31` asserts `831`; `reports/map-no-boundary.spec.ts:38-40` asserts `290/241/49`; `dashboard/stats.spec.ts:36` matches `/3\d\d|8\d\d/`. Any insert/delete breaks them.
3. **اکانت مشترک + جهش** — `auth/password-change.spec.ts` changes the shared seeded account's password and changes it back (dies mid-way → broken password). Under `fullyParallel: true` it also races with every other spec logging in as the same user.

Supporting facts:
- `tests/e2e/shared/fixtures.ts:5-6` — hardcoded fallbacks `4411015056` / `12345678`; `ROLE_ACCOUNTS` pins 4 seeded n_codes.
- `tests/e2e/users/crud.spec.ts:70` — `fill('12345678')` hardcoded.
- No `.env.test` / `.env.test.example` on disk (an earlier draft claimed this done — it was NOT).
- `playwright.config.ts` — `globalSetup: undefined`, `fullyParallel: true`.
- Seeded credentials live in `UsersTableSeeder` + `PersonUserFromDeviceSeeder` (password `12345678`); the 4 role accounts resolve to real seed rows (`4411015056` in `UsersTableSeeder`/`PersonsTableSeeder` — مهدی عسگری; `6275537615`/`0023548258`/`0041368464` in `seeders/data/person_users_from_devices.php` with roles unit_manager/expert/user).
- `migrate:fresh` is safe on a new DB: PostGIS/pg_trgm are enabled with `CREATE EXTENSION IF NOT EXISTS` in migrations.

## 2. Decision: dedicated E2E database (owner-approved)

Instead of row-level teardown on the dev DB, each run rebuilds its own world:

- **DB جدا:** `h_dashboard_e2e` (same PostGIS server, new database). `migrate:fresh --seed --env=e2e` at the start of every run → a crashed run can never poison the next one. Row-level teardown is therefore NOT required (teardown only deletes the run-state file).
- **سرور جدا:** `php artisan serve --env=e2e --port=8001`, Playwright `BASE_URL=http://localhost:8001`. Dev on `:8000` stays untouched.
- **کش جدا:** `CACHE_PREFIX=h_dashboard_e2e` + `REDIS_DB=1` + `QUEUE_CONNECTION=sync` in `.env.e2e` (never share Spatie permission cache with dev).
- **پیشوند یکتا همچنان لازم است:** `fullyParallel: true` means specs share the one e2e DB concurrently → created records use `E2E-<runId>-...` so parallel workers don't collide with each other. DB isolation removes dev pollution, not intra-run races.

What a dedicated DB does **not** fix (handled in Phase 2): absolute-count asserts, real-record searches, shared-password mutation.

## 3. Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat f8dcb21..HEAD -- tests/e2e/ playwright.config.ts` | No output |
| Create e2e DB | `createdb -h 127.0.0.1 -U <user> h_dashboard_e2e` (or via psql) | DB exists |
| Fresh seed | `php artisan migrate:fresh --seed --env=e2e --force` | All migrations + seeders green |
| Serve e2e | `php artisan serve --env=e2e --port=8001` | Listening on :8001 |
| Run E2E | `BASE_URL=http://localhost:8001 npx playwright test` | Green |
| No hardcoded counts | `grep -rEn "toContainText\((['\"])[0-9]{2,}" tests/e2e/` | 0 results |
| No real-record searches | `grep -rn "هادیلو\|4411015056\|دانشگاه علوم پزشکی زنجان" tests/e2e/` | 0 results |
| No hardcoded password | `grep -rn "12345678" tests/e2e/` | 0 results |
| No hardcoded n_codes | `grep -rn "0023548258\|0041368464\|6275537615" tests/e2e/` | 0 results |

## 4. Steps

### Phase 0 — Isolated environment (no test changes)

**Step 0.1**: Create `.env.e2e.example` (tracked, no real secrets):
```
APP_ENV=local
APP_URL=http://localhost:8001
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=h_dashboard_e2e
DB_USERNAME=<same as dev>
DB_PASSWORD=
CACHE_PREFIX=h_dashboard_e2e
REDIS_DB=1
QUEUE_CONNECTION=sync
TEST_PASSWORD=12345678
BASE_URL=http://localhost:8001
```
(`TEST_PASSWORD` equals the seeded password — it is a throwaway local credential, not a secret. Do NOT touch the seeders: Pest/dev depend on `12345678`.)

**Step 0.2**: Add `.env.e2e` to `.gitignore` (next to the existing `.env.test` line). Copy `.env.e2e.example` → `.env.e2e` locally and fill `DB_USERNAME`/`DB_PASSWORD`.

**Step 0.3**: Point `playwright.config.ts` at the new env file (`dotenv.config({ path: '.env.e2e' })`) and wire setup/teardown (files land in Phase 1):
```ts
globalSetup: './tests/e2e/global-setup.ts',
globalTeardown: './tests/e2e/global-teardown.ts',
```

**Verify**: `php artisan migrate:fresh --seed --env=e2e --force` → green; `BASE_URL=http://localhost:8001 npx playwright test tests/e2e/auth/login.spec.ts` → green (old asserts still pass against fresh seed).

### Phase 1 — Run-scoped fixtures (no test changes)

**Step 1.1**: `tests/e2e/global-setup.ts`
- Generate `runId` (`Date.now().toString(36)`), `execSync('php artisan migrate:fresh --seed --env=e2e --force')`.
- Create ONE dedicated password-mutation user (`E2E-<runId>-pwd`, own Person+Unit+pivot rows) via `artisan tinker`/a tiny `--env=e2e` command call; store `{ runId, pwdNCode }` in `tests/e2e/.run-state.json` (gitignored).
- Fail fast if `TEST_PASSWORD` is unset.

**Step 1.2**: `tests/e2e/global-teardown.ts` — delete `.run-state.json`. (Data cleanup happens via next run's `fresh`; document this explicitly in the file header so nobody "fixes" it into row deletion.)

**Step 1.3**: `tests/e2e/shared/fixtures.ts`
- Remove ALL hardcoded fallbacks (`|| '12345678'`, `|| '4411015056'`, …) → read strictly from env, throw if missing.
- Export `runId`/`pwdNCode` readers from `.run-state.json`.
- `crud.spec.ts:70` `fill('12345678')` → `TEST_USER.password`.

**Verify**: `npx playwright test` → green with old asserts (proves infra parity before touching asserts).

### Phase 2 — Relative asserts (file by file)

Rule: count → before/after or `>0` + presence of the `E2E-<runId>` record; search → the run's own record; never a seeded name/code.

| File | Problem | Fix |
|---|---|---|
| `users/list.spec.ts` | `هادیلو` (line 39), `0023548258` (line 50), `317` (line 34), `32` (line 74) | search `E2E-<runId>` person; count before/after create; relative page count based on filtered rows |
| `users/crud.spec.ts` | `هادیلو` (line 63), `12345678` (line 70) | full lifecycle on `E2E-<runId>` user (create→edit→delete); password from `TEST_USER.password` |
| `personnel/list.spec.ts` | `4411015056` (lines 46,48), `318` (lines 34,63,76,85,88) | `>0` + filter behavior on seeded data; select by value not index; assert count changes relative |
| `organization/units.spec.ts` | `دانشگاه علوم پزشکی زنجان` (line 36), `831` (line 31) | search unit created in setup (`E2E-<runId>`) |
| `hardware/list-filters.spec.ts` | `449` (lines 29,48) | filter on hardware with unique serial created in setup |
| `reports/map-no-boundary.spec.ts` | `290/241/49` (lines 38-40) | create unit without boundary → count increases by exactly 1 |
| `dashboard/stats.spec.ts` | `/3\d\d\|8\d\d/` (line 36) | assert all 7 stat labels (کاربران/پرسنل/...) are present; verify numeric values are > 0; no exact count matching |
| `tickets/new.spec.ts` | subject uses `Date.now()` (already good) | subject `E2E-<runId>-<ts>` (fresh DB per run needs no delete) — verify pattern is consistent |
| `auth/password-change.spec.ts` | mutates shared account (lines 22-28) | run ONLY on the dedicated `pwdNCode` user from run-state |
| `rbac/roles.spec.ts` | 4 seeded accounts | keep seeded logins (fresh DB guarantees them) — assert menus/403s only |
| `search/global.spec.ts` | `هادیلو` (line 36) | search `E2E-<runId>` record |

### Phase 3 — Stability proof

1. `grep -rEn "toContainText\((['\"])[0-9]{2,}" tests/e2e/` → 0
2. `grep -rn "هادیلو\|4411015056\|دانشگاه علوم پزشکی زنجان\|12345678" tests/e2e/` → 0
3. `grep -rn "0023548258\|0041368464\|6275537615" tests/e2e/` → 0
4. Full suite green twice in a row (second run proves `fresh` self-heals).
5. ضربه: add/remove a manual user+person in dev DB → E2E still green (proves dev-independence).
6. `git status --short` → no file with real credentials tracked (`.env.e2e` ignored, `.run-state.json` ignored).

## 5. Test plan

```bash
# Phase 0–1
php artisan migrate:fresh --seed --env=e2e --force
php artisan serve --env=e2e --port=8001 &
BASE_URL=http://localhost:8001 npx playwright test tests/e2e/auth/login.spec.ts

# Phase 2 (per file, then full)
BASE_URL=http://localhost:8001 npx playwright test tests/e2e/users/list.spec.ts
BASE_URL=http://localhost:8001 npx playwright test

# Phase 3
grep -rEn "toContainText\((['\"])[0-9]{2,}" tests/e2e/ | wc -l   # expect 0
```

## 6. Done criteria

- [ ] `h_dashboard_e2e` + `.env.e2e` + `:8001` wired; dev DB never touched by E2E
- [ ] `globalSetup` fresh-seeds every run; `globalTeardown` removes run-state
- [ ] Zero hardcoded absolute counts / real-record searches / hardcoded passwords in `tests/e2e/`
- [ ] Full suite green on two consecutive runs
- [ ] `password-change.spec.ts` touches only the dedicated per-run user
- [ ] No credentials tracked (`git status` clean of `.env.e2e`, `.run-state.json`)

## 7. STOP conditions

- `migrate:fresh --seed --env=e2e` fails (e.g. a seeder assumes dev-only data) → print the error, stop, do not hack seeders without review
- Any file under `tests/e2e/` changed by another PR → re-run drift check, reconcile before continuing
- Fresh seed takes >10 min or bcrypt cost blocks CI → stop, propose `--seeder=E2eSeeder` (minimal subset) instead of full seed
- Parallel workers collide on the same `E2E-<runId>` record → stop, scope creation per-worker (not per-run)

## 8. Maintenance notes

- Seeders intentionally keep password `12345678` — Pest, dev, and E2E all rely on it. Rotating it is a separate ops decision, not part of this plan.
- `.env.e2e` is gitignored; CI must generate it from secrets (same pattern as `test.yml`'s `cp .env.example .env.testing` + `sed`).
- The `!.mimocode/plans/.env.testing` negation in `.gitignore` is unrelated — leave it.
- Future specs: create with `E2E-` prefix, assert relatively, never assert seeded names/codes/counts.
- Old draft sections ("Already Done", absolute counts `317/318/831/449`, branch `celin`) were stale at review time and were dropped in this rewrite — do not reintroduce them.
