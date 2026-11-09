# Testing & Verification (Pest, PHPStan, Hooks)

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-16; that file keeps the one-line rules and
| the map of where each topic lives.

### Local Verification & Git Hooks (issue #739)

Feedback comes in three layers. The hook layers stay **light on purpose**: a
commit hook that costs a minute is a hook the team bypasses with `--no-verify`,
which is worse than no hook. CI stays the only authority.

| Layer | What runs | Why there |
|---|---|---|
| `pre-commit` | Pint on staged PHP files | Seconds; catches the most |
| `pre-push` | **Full** PHPStan; tests only if `VERIFY_TESTS` names them | Deterministic with the baseline |
| CI | Everything | The final authority |

- Hooks are **versioned** in `.githooks/` and activated with `core.hooksPath`, not
  symlinked by `composer install`. The old `post-install-cmd` symlink only appeared on
  a *fresh* install, so on an already-provisioned machine the light layer was silently
  absent. `composer hooks:install` fixes an existing clone; `php artisan verify:preflight`
  **warns** when hooks are inactive.
- `pre-push` runs PHPStan over the **whole project**, not the staged subset — a
  staged-only path is an analysis route CI never exercises, so errors surfacing only
  through dependencies would never be seen locally.
- **Test selection is manual, never heuristic.** Livewire components are single-file
  Blade views, so no `app/… → tests/…` mapping exists. A heuristic could report green
  without ever running the failing test — an intermittently broken gate is worse than
  no gate.
- `composer verify` reproduces CI's **test job, not the coverage gate**: CI also runs
  `--parallel --coverage --min=80`, which needs pcov. Coverage is CI-only. It runs the
  suite **serially** like `composer test`.
- The preflight **exits 2** (distinct from 1) so callers can tell "your machine is
  wrong" from "your code is wrong". It resolves the effective test database from
  PHPUnit's own precedence — an exported `DB_DATABASE` (or a `DB_URL`) beats
  `phpunit.xml`, because those `<env>` entries carry no `force="true"`. Never run
  `migrate:fresh` before it: a mis-resolved database destroys real data.

- Branch-sync helper (#744): `scripts/sync-beta.sh` reports `behind X, ahead Y` against the **explicit** `origin/beta` ref and fast-forwards only when safe — never auto-merges (exit 1 on divergence).

## Running Tests (Pest)

Pest is the test runner. Uses **Livewire 4.4**, separate PostgreSQL test database `h_dashboard_test`.

> **✅ Latest full-suite figure: 2478 passed, 2 risky** — from the `composer verify` run reported in PR #1115 (2026-10-17); this stamp is second-hand, so re-derive it before quoting it (rule below). **`composer test`** is the one-command way. It bakes in the three environment gotchas.
>
> Re-derive these numbers with the command this line names — run `composer test` and copy its `Tests:` summary verbatim; never re-stamp a figure you did not just produce. The 2026-10-16 stamp read `2303 passed, 2 risky, 6644 assertions` (~517s serial); the one before it read `1687 passed, 4268 assertions, ~289s serial` (2026-10-01) and had drifted by **+616 tests / +2376 assertions** before anyone noticed (#983).
>
> **`.env.testing` no longer ships a key (#953).** It is git-tracked, so the `APP_KEY` was blanked there like in every other template — and it is the file `APP_ENV=testing` loads, so a clone that has never run the suite has no key. `composer test` now generates one **only when there is none** (`scripts/ensure-test-app-key.php`) and leaves an existing key byte-identical. Nothing to do by hand; the script never prints the key and never rotates one.
>
> `2 risky` = tests with no assertions (reported, non-blocking). If a Pest run fails with `database "h_dashboard_test" does not exist` on a handful of tests while the rest pass, it is a transient Postgres hiccup — re-run the file, then the suite.

### Key test files
Counts come from `php artisan test <file> --list-tests` — the authoritative per-file number. A plain `grep` for `test(`/`it(` **undercounts**, because PHPUnit-style classes declare tests as `public function test…` with neither wrapper.

| File | Tests | Purpose |
|---|---|---|
| `tests/Feature/ApiAbilityTest.php` | 66 | Real Bearer tokens per ability (#690) |
| `tests/Feature/TodoLivewireTest.php` | 31 | Todo Livewire component |
| `tests/Feature/PersonsExportTest.php` | 36 | Personnel export (12 columns, filters, breadcrumb) |
| `tests/Feature/UnitsExportTest.php` | 22 | Units Excel export (8 columns incl. «شهرستان») |
| `tests/Feature/SyncZabbixObservabilityTest.php` | 10 | `zabbix_sync_logs` rows, streak, admin alert (#740) |
| `tests/Unit/PersianNormalizerTest.php` | 11 | `normalizeForSearch`, `escapeLikeWildcards`, `normalizeForQuery` |
| `tests/Feature/SecurityHeadersMiddlewareTest.php` | 12 | Headers incl. `Reporting-Endpoints`; CSP report bodies |
| `tests/Feature/Jobs/JobsTest.php` | 12 | Archive / clean / generate jobs |
| `tests/Feature/MaintenanceLivewireTest.php` | 21 | Maintenance schedule CRUD |
| `tests/Unit/ZabbixAdapterTest.php` | 7 | `ZabbixResult` failure classification with a stub client (#741) |
| `tests/Feature/NotificationApiTest.php` | 6 | Notification API endpoints |

> Per-file counts, not the suite total — use `composer test` output for anything you assert in CI.

### Prerequisites
```bash
docker compose -f docker-compose-pgsql-.yml up -d      # PostGIS on :5432, Redis on :6379
pg_isready -h 127.0.0.1 -p 5432                        # Verify PostGIS healthy
```

**Redis is NOT required for tests** — `phpunit.xml` forces `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`.

### Ensure test database exists
```bash
psql -h 127.0.0.1 -U h_dashboard -d postgres -c \
  "CREATE DATABASE h_dashboard_test WITH OWNER=h_dashboard;"
```

> **No `TEMPLATE=template_postgis`.** PostGIS is enabled by the migrations themselves — `2025_03_20_000009_create_boundaries_table.php` runs `CREATE EXTENSION IF NOT EXISTS postgis` before it creates any geometry column, and four later migrations do the same for `pg_trgm`/geometry. A plain database is enough, and this matches `verify:preflight`, which checks `pg_available_extensions` rather than the existence of a template. Requiring `TEMPLATE=template_postgis` fails outright on a server that has the extension available but not that template — which is exactly the plain `postgis/postgis` image the compose file uses.

### Clear cached config/routes BEFORE running (critical!)
```bash
php artisan config:clear      # must be clear so phpunit.xml can override DB_*
php artisan route:clear       # removes routes-v7.php — fixes Livewire endpoint-hash mismatch
```

### Run
```bash
composer test                 # RECOMMENDED: clears config+routes, runs with XDEBUG_MODE=off
# For a single file:
XDEBUG_MODE=off php artisan test tests/Feature/TodoApiTest.php
```

### Common failure → cause
| Symptom | Cause | Fix |
|---|---|---|
| `NOAUTH`/`WRONGPASS` on Redis | cache still on redis — only `CACHE_DRIVER` set | `config:clear` + use `CACHE_STORE=array` |
| Connection refused (mysql) | config cache from `.env.testing` wins | `config:clear` |
| `404` on `->set()`/`->call()`, mutations don't persist (~75 failures) | Livewire endpoint hash mismatch (stale `routes-v7.php`) | `config:clear && route:clear` |
| HTTP 500 on date validation: `Cannot create dynamic property DateMalformedStringException::$xdebug_message` | Xdebug `develop` mode | `XDEBUG_MODE=off` |
| bare `vendor/bin/pest` → usage text | no path argument | pass `tests/` |
| Parallel: ~35 flaky `PermissionDoesNotExist` | spatie cache shared across workers | Keep `CACHE_STORE=array` in phpunit.xml |

## Gotchas (transplanted from the former `## Gotchas Quick Reference`)

| Gotcha | Details |
|---|---|
| Rebuilding a lost `.env` | `.env` is gitignored. Rebuild from `.env-example-github` (the committed dev template) plus the secrets already resolved in `.env.e2e`, override `APP_URL=http://127.0.0.1:8000` and `DB_DATABASE=h_dashboard`, then drop any line whose value still contains `secrets.` (CI placeholders) or artisan dies with "environment file is invalid". **Then run `php artisan key:generate` — never copy a key.** `.env-example-github` ships `APP_KEY=` blank since #953: it is the master secret, it used to sit committed in that template (and three siblings) since 2026-02-07, and copying it is exactly how it reached developer machines. Confirm with `php artisan about --only=environment` (expect `local`, locale `fa`). `parse_ini_file('.env')` fails here — unquoted parens — so scan lines with a regex instead |
| A tracked env file must never carry a real `APP_KEY` (#953) | `.env.example`, `.env.example.mysql`, `.env.example.pgsql`, `.env-example-github`, `.env.testing` and `.env.e2e.example` all ship `APP_KEY=` **blank**; `php artisan key:generate` produces the value per clone. `scripts/check-app-key-leak.php` fails the build on a non-empty one and runs from **both** `scripts/verify.sh` and the `lint` job in CI (the issue's step 3 said "fail the job" — `verify.sh` alone never runs in CI, whose jobs are `lint`/`test`/`coverage-report`/`typecheck`/`e2e`/`mutation`/`phpstan`/`test-php86`). Two rules make the guard usable: it reports **tracked files only** (your working `.env` is gitignored and MUST have a key), and it **never prints the value** (a CI log that echoes the secret republishes it). It reads the **git index**, not the working tree, so a per-clone key generated locally is not a finding. **`.env.testing` is tracked AND load-bearing**: `LoadEnvironmentVariables` swaps the env file to it when `APP_ENV=testing` and dotenv is immutable, so a blank key there beats a good `.env` key and kills >1000 tests with `MissingAppKeyException`. That is why `composer test` starts with `php scripts/ensure-test-app-key.php`, which generates one **only when there is none** — an unconditional `key:generate` would rewrite the tracked file on every run and put a fresh key one `git add` from a commit. Pinned by `AppKeyLeakGuardTest`, `AppKeyGuardWiringTest` and `TestAppKeyBootstrapTest` |
| Faker names can collide with a `LIKE` filter | `PersonFactory` draws `fa_IR` `firstNameMale()`. "علی" itself (3/3000) and names containing it like "ابوعلی" (13/3000) come up in ~0.4% of draws, so a test filtering "علی" with `assertDontSee` on its own row flakes under `executionOrder="random"`. Pin both names explicitly in the test — do NOT fix it in `PersonFactory`, other tests assert on the raw faker value |
| Cache keys collide across tests | Postgres sequences are non-transactional, so `RefreshDatabase` restarts ids at 1 every test. `AccessService` keys on `accessible_units:v{version}:{user_id}:{session_unit_id}:{md5(baseIds)}` — byte-identical across tests, so a stale answer leaks from one test into the next. Put the flush in the base `TestCase::setUp()`, not in a test class — the seeder bump happens in the child's `setUp()`, which runs after `parent::setUp()`, so one flush there covers every class and needs no ordering rule. Never flush inside a test method |
| Parallel workers get their OWN database | Pest/Laravel creates `h_dashboard_test_test_{1..N}` per worker (`TestDatabases`), so workers do NOT share a database. Verified by listing the databases. If a parallel-only failure appears, suspect shared *in-process* state (cache keys, static properties), not the DB |
| Testing a cache fix | Assert through the component or the service, never by poisoning a key and expecting it to be ignored — that tests your own poison, not the flush. A test that only passes in isolation is asserting `setUp`, not the fix; assert the behaviour a user sees |
| Factories | **15 factories** exist under `database/factories/` — do not hand-roll inserts or claim only `UserFactory` exists. **`Ticket` DOES use `HasFactory`** (`/** @use HasFactory<TicketFactory> */ use HasFactory, HasOrganizationalScope;`), so `Ticket::factory()` works and `TicketFactory` is live — **18** `Ticket::factory()` calls across `tests/Feature/TicketWorkflowTest.php` (14) and `tests/Feature/TicketStatusTransitionTest.php` (4). The row that used to claim otherwise was wrong and told agents to delete a working factory (#983). `Ticket::create([...])` (as in `TicketsInboxLivewireTest`) is still fine where a test wants explicit columns, not because the factory is dead. `created_at` is not fillable, so a back-dated ticket needs `forceFill(['created_at' => ...])->save()` |
| An exported `DB_DATABASE` beats `phpunit.xml` | The `DB_*` `<env>` entries in `phpunit.xml` have no `force="true"`, so PHPUnit skips them when the variable already exists (`PhpHandler.php:140`). `DB_URL` is not in `phpunit.xml` at all and outranks everything. `php artisan verify:preflight` resolves the real value — read `.env` or `phpunit.xml` directly and you will check the wrong database |
| `expectsOutputToContain` matches ONE line per expectation | `PendingCommand`'s buffered mock consumes a written line with the first substring that matches it, so two substrings on the same line can never both be asserted. Put each claim on its own output line |
| `assertStringNotContainsString` returns void | Chaining `->and()` after it dies with "Call to a member function and() on null". Same for `assertMatchesRegularExpression` — use separate statements |
| Eloquent chains vs PHPStan (no larastan) | `Eloquent\Builder` has `@mixin Query\Builder`, so a top-level `whereIn()`/`limit()`/`take()` resolves to the query builder and types the rest of the chain `Collection<int, stdClass>`. Start chains `Model::query()->with([...])` (both declared on Eloquent), put IN-filters inside `where(Closure)`, cap rows with `get()->take(N)` not `->limit(N)->get()`. Do NOT add `@method static whereIn()` to a model to silence this — it re-types every `Model::whereIn()` chain repo-wide and unmasks errors in unrelated files |
| phpstan-baseline is line-keyed | Its entries embed line numbers, so inserting even a comment into a baselined file "unmatches" its entries (`ignore.unmatched` errors). After editing baselined code, run `vendor/bin/phpstan analyse --generate-baseline`, then verify `git diff phpstan-baseline.neon` shows **0 additions** — an addition means a real new error got suppressed |
| `Ticket::create(['created_at' => ...])` is silently ignored | `created_at` is **not fillable** on `Ticket` — the attribute is dropped without error, so every row lands on today and date-window tests pass for the wrong reason. Use `$ticket->forceFill(['created_at' => ...])->saveQuietly()` |
| `Query\Builder::toBase()` does not exist | Only `Eloquent\Builder::toBase()` does. The `@mixin Query\Builder` on `Eloquent\Builder` is one-way, so PHPStan resolves `Ticket::query()` as `Query\Builder` — accept both and branch on `instanceof` rather than adding a `@method` (that re-types the chain repo-wide) |
| `DailySeries` needs explicit generics | `Eloquent\Builder` in a signature without a `TModel` produces `missingType.generics` at level 6. Annotate `@param EloquentBuilder<covariant Model>|QueryBuilder` — otherwise the fix gets buried in `phpstan-baseline.neon` |
| A test must not assert a fixed cell address across a `latest('created_at')` export | `hardware_audits.created_at` is `timestamp(0)` on Postgres, so rows written in the same second tie and their order under `latest('created_at')` is undefined — Postgres decides by physical page layout, so the same test passes on an author machine and fails on CI (issue #886's escape test was red in #920/#923/#924, green in #921/#922, with no commit to blame). In an export test, resolve the column from `headings()` and find the row by its own content; never `getCell('F2')` to mean "the row this test created". Note `paginate()` has the same shape: with a count of 0 it never runs the ORDER BY, so a sort test that seeds nothing passes vacuously |

## CI jobs (`.github/workflows/test.yml` — eight jobs, three blocking, five informational)

| Job key | Name | Gate |
|---|---|---|
| `lint` | Code Style (Pint) — `vendor/bin/pint --test` | **blocking** |
| `test` | Tests & Coverage — PHP 8.5, PostGIS + Redis containers, `./vendor/bin/pest --parallel --coverage --min=80` → Codecov | **blocking** |
| `phpstan` | PHPStan Static Analysis — `vendor/bin/phpstan analyse --no-progress` | **blocking** |
| `coverage-report` | Coverage Report (Blade-aware, informational) | `continue-on-error` |
| `typecheck` | Typecheck (Playwright specs, informational) | `continue-on-error` |
| `e2e` | E2E Tests (Playwright) | `continue-on-error` |
| `mutation` | Mutation Testing (`--covered-only`) — runs on every PR | `continue-on-error` (**informational**) |
| `test-php86` | PHP 8.6 Preview (non-blocking) | `continue-on-error` |

`mutation` runs on every PR — checkout → Composer install → migrations → `pest --mutate --parallel --covered-only`, with no `npm ci` and no Vite build (#1185). It is expensive but informational: `continue-on-error: true`, so it never gates a merge, and it runs in parallel with `e2e`. The job's `timeout-minutes: 45` is a cap, not the expected duration (~30 min typical).

> Derivation, re-run after any workflow change — no hand count:
> ```bash
> yq -r '.jobs | keys | join(", ")' .github/workflows/test.yml              # lint, test, coverage-report, typecheck, e2e, mutation, phpstan, test-php86
> yq -r '.jobs | to_entries | map(select(.value."continue-on-error" != true) | .key) | join(", ")' .github/workflows/test.yml   # lint, test, phpstan
> ```

## Derivable invariants (stamp hygiene, #983)

- `InteractsWithTestSetup` usage is counted by `grep -rlE 'use[^;]*InteractsWithTestSetup[^;]*;' tests/ | wc -l` (110 at the #983 stamp) — a bare `grep -rl 'InteractsWithTestSetup'` also matches the trait file itself and reads 111. Re-derive, never hand-count.
- Every Feature test consumes the shared trait (see `tests/Feature/ApiAbilityTest.php` for the standard `setUp()` — `PermissionSeeder` + `seedLookupTables()`).
- Hand-rolled lookup seeding is **ratcheted at 0** (#1044): `php scripts/find-hand-rolled-lookup-seeding.php`, wired into `composer verify` as step 3c. A test file containing `DB::table('tahsils'|'estekhdams'|'semats'|'radifs')->insert` must also consume `InteractsWithTestSetup` (or call `seedLookupTables()`), otherwise the guard exits 1. A file that legitimately needs an *extra* lookup row may keep an explicit insert as long as it uses the trait too. The trait's own contract is pinned by `tests/Feature/InteractsWithTestSetupTest.php`.
- All **26** Eloquent models under `app/Models/` carry `@property` PHPDoc annotations (#671) — update the annotation when a column/cast changes; PHPStan level 6 + baseline depends on them.
