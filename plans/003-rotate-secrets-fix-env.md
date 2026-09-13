# Plan 003: Rotate committed secrets in .env.testing + fix .env.example

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in the STOP conditions occurs, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat 5f9c24e..HEAD -- .env.testing .env.example .gitignore`

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: HIGH
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `5f9c24e`, 2026-09-12

## ⚠️ TL;DR فارسی

**مشکل:** .env.testing رمز واقعی در repo. .gitignore فقط .env.test رو می‌گیره.

**راه‌حل:** placeholder + gitignore + چرخش رمزها.

**ریسک:** 🟢 صفر


## Why this matters
`.env.testing` is committed to the repository with real credential-like values: a database password, root password, default admin password, Redis password, and an application key. The `.gitignore` only covers `.env.test` (line 40) but not `.env.testing`, so this file is tracked. Anyone with repo read access has these credentials. Additionally, `.env.example` is missing `CACHE_STORE` and `APP_LOCALE`, has a duplicate `SESSION_DRIVER` (lines 20 and 53), and there is only one `.env.example` file (contrary to the task's claim of 4 variants).

## Current state

### .env.testing — committed secrets
`.env.testing:3` — Application key (base64 encoded)
`.env.testing:29` — Database password (non-placeholder value)
`.env.testing:30` — Database root password
`.env.testing:31-32` — Default admin email and password
`.env.testing:57` — Redis password

### .gitignore gap
`.gitignore:40`:
```
.env.test
```
But `.env.testing` (the actual file) is NOT listed. Line 28 has `!.mimocode/plans/.env.testing` which is a negation rule for a different path, not the root `.env.testing`.

### .env.example issues
`.env.example:20` — `SESSION_DRIVER=file`
`.env.example:53` — `SESSION_DRIVER=redis` (duplicate, overwrites line 20)
`.env.example` — Missing `CACHE_STORE` key entirely
`.env.example` — Missing `APP_LOCALE` key entirely (`.env.testing:8` has `APP_LOCALE=fa`)

## Commands you will need
| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 5f9c24e..HEAD -- .env.testing .env.example .gitignore` | No changes |
| Verify .env.testing is tracked | `git ls-files .env.testing` | Returns `.env.testing` |
| Verify .env.testing NOT in gitignore | `git check-ignore .env.testing` | Empty (not ignored) |
| After fix: verify ignored | `git check-ignore -v .env.testing` | Shows the .gitignore line |
| After fix: verify values replaced | `grep -c "example_\|base64:" .env.testing` | 0 |
| After fix: verify CACHE_STORE | `grep CACHE_STORE .env.example` | `CACHE_STORE=redis` |
| After fix: verify APP_LOCALE | `grep APP_LOCALE .env.example` | `APP_LOCALE=fa` |
| After fix: verify SESSION_DRIVER | `grep SESSION_DRIVER .env.example` | Single line: `SESSION_DRIVER=redis` |

## Scope
**In scope**: `.env.testing` (replace credential values with placeholders), `.gitignore` (add `.env.testing`), `.env.example` (add missing keys, fix duplicates).
**Out of scope**: Rotating actual production/staging secrets (that's an ops task, not a code change); creating an `.env.testing.example` template; addressing the `!.mimocode/plans/.env.testing` negation rule in `.gitignore`.

## Git workflow
- Branch: `advisor/003-rotate-secrets-fix-env`

## Steps

### Step 1: Create the feature branch
```bash
git checkout celin
git checkout -b advisor/003-rotate-secrets-fix-env
```
**Verify**: `git branch --show` → `advisor/003-rotate-secrets-fix-env`

### Step 2: Add .env.testing to .gitignore
Add `.env.testing` after the existing `.env.test` line (around line 40):

```gitignore
# E2E test credentials
.env.test
.env.testing
```

**Verify**: `git check-ignore -v .env.testing` → shows the new line from `.gitignore`

### Step 3: Replace secrets in .env.testing with placeholders
Replace all credential-like values with empty placeholders. Do NOT reproduce the original values. The replacements:

| Line | Key | Action |
|------|-----|--------|
| 3 | `APP_KEY` | Replace with `APP_KEY=` (empty) |
| 5 | `APP_URL` | Replace with `APP_URL=http://127.0.0.1:8000` (use localhost) |
| 29 | `DB_PASSWORD` | Replace with `DB_PASSWORD=` |
| 30 | `DB_ROOT_PASSWORD` | Replace with `DB_ROOT_PASSWORD=` |
| 31 | `DB_DEFAULT_EMAIL` | Replace with `DB_DEFAULT_EMAIL=admin@example.com` |
| 32 | `DB_DEFAULT_PASSWORD` | Replace with `DB_DEFAULT_PASSWORD=` |
| 57 | `REDIS_PASSWORD` | Replace with `REDIS_PASSWORD=` |

**Verify**: `grep -c "example_\|base64:" .env.testing` → `0` (no credential-like values remain)
**Verify**: `grep "DB_PASSWORD\|REDIS_PASSWORD\|APP_KEY" .env.testing` → all show empty values after `=`

### Step 4: Fix .env.example — add missing keys
Add `APP_LOCALE=fa` after the `APP_URL` line (near line 8).

Add `CACHE_STORE=redis` near the cache/queue configuration section (around line 52-54).

**Verify**: `grep APP_LOCALE .env.example` → `APP_LOCALE=fa`
**Verify**: `grep CACHE_STORE .env.example` → `CACHE_STORE=redis`

### Step 5: Fix .env.example — remove duplicate SESSION_DRIVER
Remove the first `SESSION_DRIVER=file` at line 20 (the second one at line 53, `SESSION_DRIVER=redis`, is the correct value matching the project's Redis configuration).

**Verify**: `grep -c "SESSION_DRIVER" .env.example` → `1` (single occurrence)

### Step 6: Verify git tracking state
```bash
git status --short .env.testing .env.example .gitignore
```
**Verify**: `.gitignore` shows modified, `.env.example` shows modified, `.env.testing` shows deleted (from tracking, file still on disk)

```bash
git ls-files .env.testing
```
**Verify**: Empty output (no longer tracked)

### Step 7: Format
```bash
vendor/bin/pint --dirty --format agent
```
**Verify**: No errors (this step is mostly for PHP files, but run for consistency).

### Step 8: Commit
```bash
git add .gitignore .env.example .env.testing
git commit -m "fix(security): stop tracking .env.testing + fix .env.example

- Add .env.testing to .gitignore to prevent credential leakage
- Replace committed credential values in .env.testing with empty placeholders
- Add missing CACHE_STORE=redis and APP_LOCALE=fa to .env.example
- Remove duplicate SESSION_DRIVER line in .env.example

NOTE: All previously committed secrets in .env.testing must be rotated
in any environment that used them."
```

## Test plan
1. `git check-ignore -v .env.testing` confirms the file is now gitignored
2. `git ls-files .env.testing` returns empty (no longer tracked)
3. `.env.testing` still exists on disk (for local dev) but has no real secrets
4. `.env.example` has `CACHE_STORE=redis`, `APP_LOCALE=fa`, single `SESSION_DRIVER=redis`
5. CI/test setup scripts that source `.env.testing` still work with empty DB_PASSWORD if the test database doesn't require one, OR the test setup documents how to populate `.env.testing` locally

## Done criteria
- [ ] `git check-ignore .env.testing` returns true
- [ ] `.env.testing` no longer tracked by git
- [ ] No credential-like values in `.env.testing` (no `example_`, no `base64:` keys)
- [ ] `.env.example` has `CACHE_STORE=redis`
- [ ] `.env.example` has `APP_LOCALE=fa`
- [ ] `.env.example` has exactly one `SESSION_DRIVER` line (value: `redis`)

## STOP conditions
- If `.env.testing` removal breaks the CI test suite (CI may depend on committed values) — check CI config before committing
- If other `.env.example` variants are discovered that also need updating — report them
- If rotating secrets requires coordination with ops/DevOps team — stop and list what needs rotating

## Maintenance notes
- **Secrets rotation**: All values previously committed in `.env.testing` should be considered compromised and rotated in any environment that shared them. This includes: APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD, DB_DEFAULT_PASSWORD, REDIS_PASSWORD.
- The `!.mimocode/plans/.env.testing` negation rule at `.gitignore:28` is unrelated to the root `.env.testing` — it enables a nested test env file inside `.mimocode/plans/`. Consider removing it if `.mimocode/` is no longer used.
- Future CI should populate `.env.testing` from CI secrets, not from committed files.
- Consider adding a CI lint step: `! git ls-files --cached -- '*.env*' | grep -v '.env.example'` to catch future secret commits.
