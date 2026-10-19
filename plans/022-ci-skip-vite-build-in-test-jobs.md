# ci: skip the Vite build in the PHP test jobs

> **Plan 022** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P2` · formerly GitHub issue #1158 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- .github/workflows/test.yml tests/TestCase.php`

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf (DX/CI)
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The blocking PHP test job installs Node, runs `npm ci`, and builds all Vite assets before running Pest — and the same happens in the non-blocking PHP 8.6 job. No assertion reads the build output: page-rendering tests avoid the Vite manifest with `$this->withoutVite()`, and the pattern is applied per-file in five existing tests. Doing it once in the base `TestCase` lets both jobs drop the whole Node/build phase, cutting the longest blocking job's setup time on every PR.

## Current state

- `.github/workflows/test.yml:124-127` (blocking `test` job) and `:659-662` (`test-php86` job) — both run:

```yaml
      - name: Setup Node
        uses: actions/setup-node@v7
        with:
          node-version: '24'
          cache: 'npm'

      - name: Build frontend assets
        run: |
          npm ci
          npm run build
```

- The per-file workaround already exists — `$this->withoutVite()` in `tests/Feature/AccessibilityInfrastructureTest.php:40`, `SidebarPermissionsTest.php:67`, `SidebarActiveLinkTest.php:54`, `LeafletCssScopingTest.php:44`, `PageScriptSplittingTest.php:48`.
- `tests/TestCase.php:16` — the base `setUp()` (already does `parent::setUp()`, `cache()->flush()`, throttle disabling) — this is the natural single place.
- The e2e job builds assets for real and must keep doing so (it serves the app); only the **PHP test jobs** change.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full suite (slow, ~8 min serial) | `composer test` | all pass, with `public/build` absent |
| YAML sanity | `php -r "var_dump(yaml_parse_file('.github/workflows/test.yml') !== false);"` (or `yq -r '.jobs \| keys' .github/workflows/test.yml`) | parses; job keys unchanged |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `.github/workflows/test.yml` (the `test` and `test-php86` jobs only)
- `tests/TestCase.php`

**Out of scope** (do NOT touch):
- The `e2e` job's Node/build steps — that job serves real assets.
- `coverage-report`, `lint`, `phpstan`, `mutation` jobs' steps unless they also build (verify with `grep -n "npm run build" .github/workflows/test.yml` and report if any other job does; only change the PHP test jobs).
- The `.env.testing` copy step and the Postgres/Redis service definitions.
- `phpunit.xml`.

## Git workflow

- Branch: `ci/skip-vite-in-php-test-jobs`
- Commit style: `ci: stop building Vite assets in the PHP test jobs (#022)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Add a global `withoutVite()`

In `tests/TestCase.php::setUp()`, after `parent::setUp()`:

```php
// #022: no test reads the Vite manifest, and the per-file
// `$this->withoutVite()` calls in five tests proved that. Doing it once here
// lets CI's PHP test jobs stop installing Node and building assets.
$this->withoutVite();
```

Remove the now-redundant per-file calls only if they cause no harm — they are harmless (idempotent), so **leave them** to keep the diff minimal, or remove them in a separate cleanup; state your choice in the PR.

**Verify**: delete/rename `public/build` locally (`mv public/build /tmp/build.bak`) and run `XDEBUG_MODE=off php artisan test tests/Feature/SidebarPermissionsTest.php tests/Feature/AccessibilityInfrastructureTest.php` → passes without the manifest.

### Step 2: Prove the whole suite survives without the build

With `public/build` still absent, run the full suite. This is the critical check — any page-rendering test that relies on the manifest will fail with `ViteManifestNotFoundException`, and that test needs `withoutVite()` too (the base-class call covers it, but verify empirically).

**Verify**: `composer test` → all pass (no `ViteManifestNotFoundException`).

### Step 3: Drop the steps from the two PHP jobs

Remove the `Setup Node` and `Build frontend assets` steps from the `test` job (`:124-127`) and the `test-php86` job (`:659-662`). Do **not** remove the checkout step, the composer install, the `.env.testing` copy, migrations, or anything else.

**Verify**: `grep -n "npm ci\|npm run build\|setup-node" .github/workflows/test.yml` → matches remain **only** in the e2e job.

### Step 4: Restore the local build

Put `public/build` back (`mv /tmp/build.bak public/build`) so your working tree is unchanged.

**Verify**: `git status --porcelain` shows no unexpected build changes.

## Test plan

- No new tests. The verification is the full suite passing with `public/build` absent — record that run in the PR.
- Keep the five existing `withoutVite()` calls (they are now belt-and-braces); if you remove them, the suite run in step 2 must still pass.
- Verification: `composer test` with no `public/build` → all pass.

## Done criteria

- [ ] `tests/TestCase.php` calls `$this->withoutVite()`
- [ ] `composer test` passes with `public/build` **absent** (evidence in PR)
- [ ] `grep -n "npm run build" .github/workflows/test.yml` matches only the e2e job
- [ ] `grep -n "setup-node" .github/workflows/test.yml` matches only the e2e job
- [ ] The `test` and `test-php86` jobs still run migrations and the suite (read the diff)
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- Any test fails with `ViteManifestNotFoundException` even with the base-class `withoutVite()` (e.g. a test asserting real manifest content) — STOP and report the test; it may legitimately need the build, which changes this plan's premise.
- Another CI job besides e2e builds assets and the plan would leave it broken — report before touching it.
- `withoutVite()` is unavailable in this Laravel version's `TestCase` — report (it is used in five tests today, so it should be).

## Maintenance notes

- If a future test genuinely needs the compiled manifest (e.g. asserting a built asset hash), it must opt **back in** locally rather than restoring the CI build step.
- Reviewer should scrutinize: that the removed YAML is exactly the Node/build phase (not the `.env.testing` copy or migrations), and that the e2e job is untouched.
