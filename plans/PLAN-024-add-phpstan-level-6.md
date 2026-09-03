# Plan 024: Add PHPStan Static Analysis at Level 6

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

The project has no static analysis tool configured. PHPStan catches type errors, dead code, and logic bugs that Pest/unit tests miss. Without it, issues like missing return types, null access, and incorrect argument types slip through.

### Current State

- No `phpstan.neon` file in the project root
- No `phpstan/phpstan` or `phpstan/phpstan-laravel` in `composer.json` require-dev
- No static analysis step in CI (`.github/workflows/test.yml`)
- No IDE integration for PHPStan

---

## Solution

Install PHPStan with Laravel extensions, create config at level 6, generate a baseline for existing errors, and add a CI job.

### Step 1: Install Packages

```bash
composer require --dev phpstan/phpstan phpstan/phpstan-laravel
```

### Step 2: Create Configuration

**File:** `phpstan.neon` (project root)

```neon
includes:
    - vendor/phpstan/phpstan-laravel/extension.neon

parameters:
    level: 6
    paths:
        - app
        - config
        - database
        - routes
        - resources/views/livewire
    excludePaths:
        - vendor
        - node_modules
        - storage
        - bootstrap/cache
        - tests
    ignoreErrors:
        # Livewire anonymous class patterns — PHPStan can't resolve them
        - '#Call to an undefined method .*::(render|mount|updated|hydrate|dehydrate)#'
```

### Step 3: Generate Baseline

```bash
vendor/bin/phpstan analyse --generate-baseline
```

This creates `phpstan-baseline.neon` containing all existing errors. Subsequent runs only fail on **new** errors.

**File:** `phpstan.neon` (updated to include baseline):

```neon
includes:
    - vendor/phpstan/phpstan-laravel/extension.neon
    - phpstan-baseline.neon

parameters:
    level: 6
    paths:
        - app
        - config
        - database
        - routes
        - resources/views/livewire
    excludePaths:
        - vendor
        - node_modules
        - storage
        - bootstrap/cache
        - tests
```

### Step 4: Add CI Job

**File:** `.github/workflows/test.yml`

Add a new job after the existing `test` job:

```yaml
  phpstan:
    name: PHPStan Static Analysis
    runs-on: ubuntu-latest
    timeout-minutes: 10

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pgsql, redis, pcntl, bcmath, zip, curl, mbstring
          tools: composer:latest

      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Run PHPStan
        run: vendor/bin/phpstan analyse --no-progress
```

### Step 5: Add Script to composer.json

```json
{
    "scripts": {
        "phpstan": "phpstan analyse --no-progress",
        "phpstan-baseline": "phpstan analyse --generate-baseline"
    }
}
```

---

## Verification

1. **Run PHPStan locally:**
   ```bash
   vendor/bin/phpstan analyse --no-progress
   ```
   Expected: passes with baseline (0 new errors).

2. **Check baseline was generated:**
   ```bash
   wc -l phpstan-baseline.neon
   ```
   Expected: file exists with error count in header comment.

3. **Verify CI job is valid:**
   ```bash
   cat .github/workflows/test.yml | grep phpstan
   ```
   Expected: phpstan job present.

4. **Intentionally introduce an error:**
   ```php
   // In any file:
   $x = null;
   $x->method(); // Should trigger PHPStan level 6 error
   ```
   Run `vendor/bin phpstan analyse` — should fail. Revert.

---

## STOP Conditions

- If `phpstan/phpstan-laravel` requires a different Laravel version, check compatibility and downgrade if needed.
- If the baseline has >500 errors, consider starting at level 5 and working up.
- If CI job fails due to missing PHP extensions, add them to `shivammathur/setup-php` config.

---

## Out of Scope

- Fixing all PHPStan baseline errors (separate effort).
- Adding PHPStan to pre-commit hook (covered in Plan 025).
- IDE configuration for PHPStan (VS Code / PhpStorm setup).
- Setting `treatPhpDocTypesAsCertain: false` (stricter mode).

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `vendor/bin/phpstan analyse --no-progress` | 0 new errors |
| 2 | `cat phpstan-baseline.neon \| head -3` | Error count comment present |
| 3 | `composer phpstan` | Same result as direct run |
| 4 | CI: push to PR branch | phpstan job runs and passes |

---

## Maintenance Notes

- **Baseline management:** When fixing baseline errors, regenerate with `composer phpstan-baseline`. Review the diff to ensure intentional fixes.
- **Level progression:** Level 6 catches missing type hints and dead code. Levels 7-8 catch more subtle issues. Consider increasing after baseline is clean.
- **Livewire compatibility:** The `phpstan-laravel` extension handles most Laravel patterns. Anonymous Livewire classes may need specific ignores.
- **Performance:** PHPStan level 6 on this codebase should run in ~30s. Level 8+ takes longer.
