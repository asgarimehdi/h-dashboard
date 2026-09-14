# Plan 011: Toolchain & Docs Repair (hooks, env, README, API docs)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- scripts/pre-commit composer.json .pint.json phpstan.neon phpstan-baseline.neon .env.example .env.example.pgsql .env.testing .github/workflows/test.yml .github/workflows/deploy.yml README.md references/api-endpoints.md install-guid.md AGENTS.md routes/api.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P3 (onboarding + doc drift; no prod impact)
- **Effort**: M
- **Risk**: LOW (docs/config; Pint scope change is the only spicy bit — handled with opt-in step)
- **Depends on**: 001 (README test-setup must match the new `test:ci`)
- **Category**: dx
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

The pre-commit hook never installs on fresh clones and silently rewrites staged code when it does run. `.env.example` contradicts itself (`SESSION_DRIVER` twice, legacy `CACHE_DRIVER` on Laravel 13) while 5 env templates compete. README quick-start cannot produce a working app. API docs describe a deleted route and omit a destructive one. PHPStan's 1115-entry baseline excludes tests while most component logic lives in Blade SFCs neither PHPStan nor Pint inspects.

## Current state (audit-verified; spot-check live)

- `scripts/pre-commit:1-17` exists; `.git/hooks/pre-commit` absent on fresh clone; `composer.json:59-61` symlinks only on `post-install-cmd` with relative `../../scripts/pre-commit` (breaks worktrees); hook runs `pint $STAGED` unquoted then `git add`s with no fail path.
- `.env.example:20` vs `:53` double `SESSION_DRIVER`; legacy `CACHE_DRIVER` (Laravel 13 reads `CACHE_STORE`, `config/cache.php:18`); missing `ZABBIX_IN/OUT_ITEM_ID` (`config/services.php:40-41`), `APP_TIMEZONE`, `CACHE_STORE`, `DB_DEFAULT_EMAIL/PASSWORD`.
- `README:34-62` lacks `key:generate`, `--seed`/admin creds, PostGIS step, `CACHE_STORE=array`; `composer test -- --filter=` doesn't forward filters.
- `references/api-endpoints.md:51` documents `GET /api/hardware/{hardware}/history` (zero hits in `routes/api.php`); `POST /audits/{audit}/restore-record` (`routes/api.php:87-88`) undocumented. `api-endpoints.md:382` claims mysql testing vs committed pgsql `.env.testing`.
- `deploy.yml:133,136`: apache2 reload + `/health` curl; `install-guid.md` specifies nginx, no `/health` route exists; check masked by `continue-on-error`.
- `phpstan.neon:12-17` excludes `tests`; baseline 6691 lines / 1115 ignores; `.pint.json` `not-name: *blade.php` while AGENTS.md mandates single-file Livewire.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Hooks | `ls -la .git/hooks/pre-commit` | present after fix |
| Env audit | `grep -rhoP 'env\(\K[A-Z_]+' config/ app/ database/ | sort -u` | canonical key list |
| Docs | `grep -rn 'history' routes/api.php` | empty (confirm before doc edit) |

## Scope

**In scope**: files in drift check + new `composer setup-hooks` script + optional `.env.ci` fixture.
**Out of scope**: SFC logic extraction to classes (plan 010's direction — don't start it here); OpenAPI/Scribe generation (deferred note only); E2E harness (plan 012).

## Steps

### Step 1: Hooks that install + fail loud

Add `post-update-cmd` + `composer setup-hooks` (absolute-path symlink, `-f` guard); hook runs `pint --test` on staged files and FAILS the commit (no silent `git add` rewrite); document in AGENTS.md.
**Verify**: fresh-clone simulation installs hook; staged violation blocks commit.

### Step 2: One canonical env + testing fixture

Regenerate `.env.example` from `env(` grep (single `SESSION_DRIVER`, `CACHE_STORE`, all Zabbix/timezone/seed keys); both workflows build `.env.testing` from `.env.example.pgsql` (or committed `.env.ci`); deduplicate via shared snippet.
**Verify**: diff of required keys vs example → empty; CI green.

### Step 3: README + references + deploy alignment

README quick-start mirrors AGENTS.md verified setup (`key:generate`, `--seed` + admin source, PostGIS, `CACHE_STORE=array`, correct filter command). Fix `api-endpoints.md` (`/history` row deleted or alias restored + tested; `restore-record` documented; testing-env section rewritten with "last verified" date). Align deploy (nginx + real health route or drop check + `continue-on-error`).
**Verify**: follow README on a clean checkout mentally (or for real if cheap); doc claims grep-clean.

### Step 4: Static analysis ratchet (no big-bang)

CI-gate baseline count (`grep -c` must not grow); include `tests/` at lower level OR document why not; Blade SFC lint as report-only step first (extract `<?php` blocks, `php -l` them) — enforce only after one green release.
**Verify**: baseline count recorded; SFC lint runs non-blocking.

## Test plan

- Hook simulation, env-key diff, doc grep-freshness, full `composer test` green.

## Done criteria

- [ ] Hook installs + blocks (not rewrites)
- [ ] Single canonical env; CI fixture unified
- [ ] README works; API docs match routes; deploy matches install guide
- [ ] Baseline ratchet live; SFC lint report-only
- [ ] Scope clean

## STOP conditions

- README full fresh-clone verification needs infra absent here → mark steps "unverified-live", report.
- Pint-on-Blade explodes diff → keep report-only, defer enforcement.
- Verification fails twice → stop, report.
