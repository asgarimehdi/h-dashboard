# Plan 025: Enforce Pint in CI and Add Pre-Commit Hook

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

The project has `laravel/pint` installed (confirmed in `composer.json`) but:
1. No `.pint.json` configuration file exists
2. No CI job runs Pint to enforce formatting
3. No git pre-commit hook runs Pint automatically
4. Developers can push unformatted code without detection

### Current State

- `composer.json` requires `"laravel/pint": "^1.27"`
- AGENTS.md says: "run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes"
- No `.pint.json` in project root
- `.github/workflows/test.yml` has no Pint step
- `.github/workflows/deploy.yml` has no Pint step
- No `.git/hooks/pre-commit` or husky/laravel-vite config

---

## Solution

### Step 1: Create Pint Configuration

**File:** `.pint.json` (project root)

```json
{
    "preset": "laravel",
    "rules": {
        "array_syntax": ["syntax" => "short"],
        "no_unused_imports": true,
        "trailing_comma_in_multiline": true,
        "single_quote": true,
        "concat_space": ["spacing" => "one"]
    },
    "not-name": [
        "*blade.php"
    ]
}
```

**Rationale:**
- `preset: "laravel"` follows Laravel conventions (default for this project).
- `no_unused_imports: true` catches dead imports.
- Excludes `*blade.php` — Pint doesn't process Blade files.

### Step 2: Add Pint Step to CI

**File:** `.github/workflows/test.yml`

Add a lint job before the test job:

```yaml
  lint:
    name: Code Style (Pint)
    runs-on: ubuntu-latest
    timeout-minutes: 5

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          tools: composer:latest

      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Check code style
        run: vendor/bin/pint --test
```

**Key:** `--test` flag (not `--dirty`) makes Pint check ALL files and fail if any are unformatted. This prevents formatting drift.

### Step 3: Add Pre-Commit Hook

**File:** `scripts/pre-commit` (executable)

```bash
#!/bin/bash
# Pre-commit hook: run Pint on staged PHP files
set -euo pipefail

STAGED_PHP_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' || true)

if [ -z "$STAGED_PHP_FILES" ]; then
    exit 0
fi

echo "Running Pint on staged PHP files..."
vendor/bin/pint $STAGED_PHP_FILES

# Re-stage formatted files
git add $STAGED_PHP_FILES

echo "✅ Code style checked."
```

**Installation:**
```bash
chmod +x scripts/pre-commit
# Developer runs once:
ln -sf ../../scripts/pre-commit .git/hooks/pre-commit
```

### Step 4: Add Composer Script

**File:** `composer.json` (add to scripts section):

```json
{
    "scripts": {
        "pint": "pint --dirty --format agent",
        "pint:test": "pint --test",
        "pint:fix": "pint"
    }
}
```

---

## Verification

1. **Run Pint check:**
   ```bash
   vendor/bin/pint --test
   ```
   Expected: passes or shows specific files needing formatting.

2. **Run Pint fix:**
   ```bash
   vendor/bin/pint --dirty
   ```
   Expected: fixes any unformatted files.

3. **Test pre-commit hook:**
   ```bash
   # Add unformatted PHP code
   echo '<?php class X {}' > /tmp/test.php
   git add /tmp/test.php
   git commit -m "test"
   # Pint should format and re-stage
   ```

4. **Verify CI job works:**
   Push a PR with unformatted code → lint job should fail.

---

## STOP Conditions

- If Pint `--test` fails on existing code, run `vendor/bin/pint` first to fix all files, commit the formatting fix separately, then add the CI job.
- If the pre-commit hook conflicts with another hook, check `.git/hooks/pre-commit` for existing content.

---

## Out of Scope

- Formatting all existing code in this plan (do that in a separate "format codebase" commit).
- Adding Pint to PHPStan (separate tools).
- Using laravel-vite for hook management (keep it simple with shell script).
- Adding eslint/stylelint for JS/CSS formatting.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `vendor/bin/pint --test` | All PHP files pass |
| 2 | `vendor/bin/pint --dirty` | No changes needed (if already clean) |
| 3 | CI: push PR | lint job runs |
| 4 | CI: push PR with bad formatting | lint job fails |
| 5 | Pre-commit hook runs on commit | Formats staged files |

---

## Maintenance Notes

- **Pint version:** `^1.27` is compatible with Laravel 13 / PHP 8.5. Check for updates periodically.
- **Formatting fix commit:** Before enabling CI enforcement, run `vendor/bin/pint` on the entire codebase and commit as a single "format codebase" commit to avoid noise in git blame.
- **`.gitattributes`:** Consider adding `*.php text eol=lf` to enforce consistent line endings.
- **Husky alternative:** If the team prefers npm-based hooks, install `husky` and configure via `package.json`.
