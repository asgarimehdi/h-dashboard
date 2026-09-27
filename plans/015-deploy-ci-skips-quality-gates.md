# Plan 015: Deploy CI Skips Pint, PHPStan, and Coverage Gate — Untested Code Can Reach Production

> Written against commit: `3dae4cd` (sydney)
> Category: DX | Effort: S | Impact: HIGH

## Problem

The production deploy workflow (`deploy.yml`) runs on push to `main` but only executes `./vendor/bin/pest --parallel` as its quality gate. It does **not** run:

1. **Pint** (`vendor/bin/pint --test`) — code style / formatting
2. **PHPStan** (`vendor/bin/phpstan analyse`) — static analysis
3. **Coverage threshold** (`--coverage --min=80`) — test coverage

The PR workflow (`test.yml`) enforces all four gates (Pint, tests+80% coverage, mutation, PHPStan) before merge. However, there are two paths to production that bypass PRs:

1. **Direct push to `main`** — if branch protection is off or a force-push occurs
2. **`workflow_dispatch`** — manual trigger with no quality gate

This means code with Pint violations, PHPStan errors, or <80% coverage can reach production without any automated check.

### Evidence

**`.github/workflows/deploy.yml:82-83`** — test job only runs basic tests:
```yaml
- name: Run tests
  run: ./vendor/bin/pest --parallel
```

**`.github/workflows/test.yml:38,129,255-256`** — PR workflow runs all gates:
```yaml
# Lint job
- name: Check code style
  run: vendor/bin/pint --test

# Test job
- name: Run tests with coverage
  run: ./vendor/bin/pest --parallel --coverage --min=80 --coverage-clover=coverage.xml

# PHPStan job
- name: Run PHPStan
  run: vendor/bin/phpstan analyse --no-progress
```

**`.github/workflows/deploy.yml:13-84`** — the `test` job in deploy also lacks Redis service (needed by some tests) and uses `QUEUE_CONNECTION=sync` from `.env.example` instead of explicitly setting `CACHE_STORE=array`.

## Solution

Add Pint, PHPStan, and coverage enforcement as blocking steps in the `deploy.yml` test job, mirroring the PR workflow's quality gates. Add Redis service container for test parity.

### Option A (Recommended): Add dedicated quality jobs in deploy.yml

Add two new jobs (`lint`, `phpstan`) to `deploy.yml` and upgrade the test job's coverage enforcement:

```yaml
jobs:
  lint:
    name: Code Style (Pint)
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          tools: composer:latest
      - uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
      - run: composer install --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/pint --test

  phpstan:
    name: PHPStan Static Analysis
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pgsql, redis
          tools: composer:latest
      - uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
      - run: composer install --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/phpstan analyse --no-progress

  test:
    # ... existing test job, but change the test step:
    - name: Run tests with coverage
      run: ./vendor/bin/pest --parallel --coverage --min=80
    # Add Redis service (same as test.yml)
    # Add CACHE_STORE=array and QUEUE_CONNECTION=sync env setup

  deploy:
    needs: [lint, test, phpstan]  # <-- all three must pass
```

### Option B: Reuse the test workflow via `workflow_call`

Refactor `test.yml` to also accept `workflow_call` trigger, then call it from `deploy.yml`. This is cleaner long-term but has more refactoring scope.

## Files in Scope

- `.github/workflows/deploy.yml`
- `.github/workflows/test.yml` (read-only reference, may need `workflow_call` trigger for Option B)

## Files Out of Scope

- Branch protection rules (GitHub UI setting, not code)
- `composer.json` scripts (already correct: `composer pint:test`, `composer phpstan`, `composer test`)
- `.pre-commit` hook (local-only, already installed via `composer install`)

## Steps

### Step 1: Add `lint` job to deploy.yml
1. Copy the `lint` job from `test.yml` (lines 12-38) into `deploy.yml`
2. Verify with: `yamllint .github/workflows/deploy.yml` or `actionlint .github/workflows/deploy.yml` if available

### Step 2: Add `phpstan` job to deploy.yml
1. Copy the `phpstan` job from `test.yml` (lines 229-256) into `deploy.yml`
2. Ensure it has `pgsql` and `redis` extensions for schema introspection

### Step 3: Upgrade test job in deploy.yml
1. Add `redis` service container (copy from `test.yml` lines 59-65)
2. Change test step from `./vendor/bin/pest --parallel` to `./vendor/bin/pest --parallel --coverage --min=80`
3. Add `coverage: pcov` to PHP setup step (currently missing in deploy.yml)
4. Add explicit `.env.testing` setup matching `test.yml` (add `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `REDIS_PASSWORD=`)

### Step 4: Update deploy job dependencies
1. Change `needs: [test]` to `needs: [lint, test, phpstan]`

### Step 5: Verify
```bash
# Validate YAML syntax
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"

# Or use actionlint if available
actionlint .github/workflows/deploy.yml
```

## Test Plan

1. After making changes, push to a feature branch and open a PR to `beta` — the PR should pass all gates
2. Verify deploy.yml YAML is valid (no syntax errors)
3. Verify the deploy workflow triggers correctly by checking GitHub Actions UI on next merge to `main`
4. Simulate a Pint failure: introduce a formatting error, verify deploy job blocks
5. Simulate a PHPStan error: introduce a type error, verify deploy job blocks

## Maintenance Note

- If `test.yml` is refactored (e.g., adding new quality gates), `deploy.yml` must be updated in parallel — or switch to Option B (`workflow_call`) to keep them DRY.
- The `--min=80` threshold in the deploy test job should match `test.yml`. If the project raises coverage requirements, both files must be updated.
- The deploy job runs on `self-hosted` runner for the actual deployment step; quality gate jobs run on `ubuntu-latest` for isolation and speed. Keep this separation.

## Done Criteria

- [ ] `deploy.yml` has a `lint` job running `vendor/bin/pint --test` as a blocking gate
- [ ] `deploy.yml` has a `phpstan` job running `vendor/bin/phpstan analyse --no-progress` as a blocking gate
- [ ] `deploy.yml` test job runs with `--coverage --min=80` (not bare `--parallel`)
- [ ] `deploy.yml` test job includes Redis service container for test parity
- [ ] `deploy.yml` `deploy` job `needs` includes `[lint, test, phpstan]`
- [ ] YAML syntax is valid: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"`
- [ ] No regressions in `test.yml` — PR workflow unchanged
