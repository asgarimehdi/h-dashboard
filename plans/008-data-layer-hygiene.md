# Plan 008: Data-Layer Hygiene (indexes, migrations, jalali, debugbar, seeds)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- database/migrations/ database/seeders/ composer.json app/Exports/ config/ public/js/other/` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P2 (double GIN write-amplification on every hardware write; sqlite-CI breakage; date-core pin)
- **Effort**: M
- **Risk**: MED (index drops take locks — off-peak; jalali pin relax needs edge-case tests)
- **Depends on**: 001
- **Category**: migration
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

Five `hardwares` columns carry TWO GIN trgm indexes each under different names. The newest trgm migration has no pgsql guard (breaks sqlite CI) and no `CONCURRENTLY`. Person per-column indexes don't serve the actual `CONCAT` search. Extension creation is scattered across 5 migrations. The Jalali date core is exact-pinned to a single maintainer's release while frontend/backend implementations can drift.

## Current state (all verified)

- `2026_08_26_000001_add_trgm_indexes...:28-45` creates `hardwares_{pc_name,comments,type,ip_valid,ip_local,mac}_trgm_idx`; `2026_09_06_000003_add_trigram...:11-14` creates `idx_hardware_{pc_name,n_code,ip_valid,ip_local,mac,comments}_trgm` → 5 columns double-indexed.
- `2026_09_06_000003:8-21`: no `DB::getDriverName()` guard (siblings `2026_08_22_000001:17`, `2026_08_26_000001:20` have it); plain `CREATE INDEX` (no `CONCURRENTLY`).
- `2026_08_22_000001:26-31`: GIN on `(f_name||' '||l_name)` both orders (matches `CONCAT` search); `2026_09_06_000003:17-20`: bare `f_name`/`l_name` GIN (doesn't serve it).
- Extensions in 5 places: `2026_08_22_000001:21`, `2026_08_26_000001:24`, `2026_09_06_000002:10`, `2025_03_20_000009_create_boundaries:17`, `2026_08_03_004321_add_geometry:13`. `2026_09_06_000002_enable_pg_trgm:13-16` down is a no-op.
- `composer.json:19`: `"morilog/jalali": "3.5"` (exact; everything else caret). `jdate()` has no in-repo definition (provider magic). Vendored `public/js/other/jalalidatepicker.min.js` + css. `morilog` used in `app/Exports/*.php` + API controllers (`Jalalian::fromCarbon`).
- `composer.json:26`: `fruitcake/laravel-debugbar ^4.0` beside `laravel/boost ^2.7` + `laravel/pail ^1.2.5`. `composer audit` / `npm audit --omit=dev` clean (DEP-09 — no version-lag work).
- `DatabaseSeeder:51-91` central `resetPostgresSequences()`; `UnitSeeder:7927-7931` duplicates `setval('units_id_seq', max)` unquoted.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Index verify | `psql $DATABASE_URL -c '\di hardwares*trgm*'` (or via Boost `db-schema`) | one index per column after fix |
| Tests | `composer test` | pass |

## Scope

**In scope**: `database/migrations/` (ONE new migration + guard patch), `database/seeders/UnitSeeder.php` (delete dup block), `composer.json` (jalali pin only), date round-trip tests, Debugbar gating audit.
**Out of scope**: query rewrites (plans 005/007), export concern switch (plan 006), any controller logic.

## Steps

### Step 1: Dedupe trgm indexes (one migration)

New migration: `DROP INDEX IF EXISTS` the older `hardwares_*_trgm_idx` set (keep `idx_hardware_*` convention), drop `idx_persons_{f,l}_name_trgm` (keep expression indexes), add pgsql guard + `CONCURRENTLY` (own transaction-free migration). Verify with `\di` + `EXPLAIN` on a `%term%` CONCAT search before/after.
**Verify**: one GIN per column; search uses index; suites pass.

### Step 2: Migration hygiene

Patch `2026_09_06_000003` guard? NO — migrations already ran in prod; never edit. Instead: new migration is pgsql-guarded; consolidate future extension docs (comment in the new migration pointing at the single canonical extension migration); make `enable_pg_trgm` down explicitly irreversible (throw/comment + policy note). Delete `UnitSeeder:7927-7935` dup block (rely on `DatabaseSeeder`).
**Verify**: `migrate:fresh --seed` on pgsql works; rollback notes accurate.

### Step 3: Jalali + Debugbar

`morilog/jalali` `3.5` → `^3.5`; pin picker version in a comment; add Jalali↔Carbon round-trip tests around `todo.blade.php:240 convertToMiladi` + report `by_day` (already covered in plan 001's merged suite — extend, don't duplicate). Audit Debugbar gating to local-only; if clean, note-and-keep (do NOT remove in this plan — removal is a separate decision).
**Verify**: round-trip tests green; `grep -rn 'debugbar' config/ app/Providers/` shows local gate.

## Test plan

- Migration up/down on pgsql; EXPLAIN evidence; round-trip date tests; full suite green.

## Done criteria

- [ ] One trgm index per column; EXPLAIN shows usage
- [ ] New migration guarded + concurrent; down-policy explicit
- [ ] Seeder dup block gone
- [ ] Jalali `^3.5` + round-trip tests; Debugbar gate verified
- [ ] No out-of-scope changes

## STOP conditions

- Prod already depends on the OLD index names (custom queries) → stop, report; drop the NEW set instead.
- `CONCURRENTLY` can't run inside migration transaction on this setup → split migration, no transaction.
- Verification fails twice → stop, report.
