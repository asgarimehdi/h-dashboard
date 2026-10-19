# perf: folded Persian ticket search cannot use the trgm indexes

> **Plan 003** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P1` · formerly GitHub issue #1139 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Traits/TicketsQueryScopes.php database/migrations`

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW-MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

Ticket search folds Persian character variants by wrapping each searched **column** in a chain of `regexp_replace`/`translate` (`PersianNormalizer::foldSeparatorsSql()`). The predicate is therefore on an *expression*, and the trigram GIN indexes added for exactly this search (`tickets_subject_trgm_idx`, `tickets_content_trgm_idx`, added 2026-10-08) can never be used. The query runs on every debounced keystroke in the inbox and monitoring pages, and the paginator's `COUNT` repeats the same expression — so the fastest-growing table is fully scanned with per-row regex CPU, twice per keystroke. Adding expression GIN trigram indexes that mirror the fold restores index use without changing any search semantics.

## Current state

- `app/Traits/TicketsQueryScopes.php:71-84` — the search predicate:

```php
protected function applyTicketSearch(Builder $query, ?string $term, array $columns): void
{
    if (empty($term)) {
        return;
    }

    $folded = PersianNormalizer::foldedTerm($term);

    $query->where(function (Builder $group) use ($folded, $columns): void {
        foreach ($columns as $column) {
            $group->orWhereRaw(PersianNormalizer::foldSeparatorsSql($column).' LIKE ?', ['%'.$folded.'%']);
        }
    });
}
```

- `app/Traits/PersianNormalizer.php:121-149` — `foldSeparatorsSql()` builds the expression: `regexp_replace(<col>, '[ZWNJ ZWJ]', ' ', 'g')`, one `regexp_replace` per `charMap()` entry, one `translate()` applying `digitMap()`, then `regexp_replace(<expr>, ' +', ' ', 'g')`. Its own docblock states the consequence: *"not index-friendly — though the term is always a leading `%`, so the LIKE was never index-seekable anyway."* That reasoning is now stale: a trigram GIN index **does** serve `LIKE '%term%'`, and the repo ships three such indexes — it is only the folded expression that is unindexed.
- `database/migrations/2026_10_08_000001_add_trgm_indexes_to_tickets_and_todos_search.php:38-45` — the bare-column indexes:

```php
DB::statement('CREATE INDEX IF NOT EXISTS tickets_subject_trgm_idx ON tickets USING GIN (subject gin_trgm_ops)');
DB::statement('CREATE INDEX IF NOT EXISTS tickets_content_trgm_idx ON tickets USING GIN (content gin_trgm_ops)');
DB::statement('CREATE INDEX IF NOT EXISTS todos_title_trgm_idx ON todos USING GIN (title gin_trgm_ops)');
```

- Call sites searching `tickets`: `resources/views/livewire/tickets/⚡inbox.blade.php:130` (`subject`, `ticket_code`, `content`) and `resources/views/livewire/tickets/⚡monitoring.blade.php:121` (`subject`, `ticket_code`).
- Repo convention for migrations: `database/migrations/YYYY_MM_DD_000001_description.php` (sequential daily counter), run with `--no-interaction`. Migrations enable extensions themselves (`DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm')`), matching `2026_10_08_000001`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Generate an expression index SQL snippet | `php artisan tinker --execute 'echo \App\Traits\PersianNormalizer::foldSeparatorsSql("subject");'` | the folded SQL expression |
| Focused search tests | `XDEBUG_MODE=off php artisan test tests/Feature/TicketSearchTest.php` (find it: `grep -rl "applyTicketSearch\|تخصیص\|search" tests/Feature \| grep -i ticket`) | all pass |
| Migration | `php artisan migrate --no-interaction` | migration applies |
| EXPLAIN check | `php artisan tinker --execute '...'` with `EXPLAIN (ANALYZE, BUFFERS)` on the folded query | plan shows a Bitmap Index Scan on the new index |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `database/migrations/<new>_add_folded_trgm_indexes.php` (new migration)
- `app/Traits/PersianNormalizer.php` — **only** to expose the fold expression builder if needed (e.g. a `foldedColumnExpression()` alias); do not change `foldSeparatorsSql()`
- `tests/Feature/<ticket search test>.php`

**Out of scope** (do NOT touch):
- `applyTicketSearch()` semantics — the term-side folding stays exactly as it is; the user-visible search results must be byte-identical.
- The other folded call sites (`app/Services/UnitTreeService.php:185`, `app/Http/Controllers/Api/PersonsExportController.php:100-101`, `app/Support/HardwareFilters.php:100-102`) and `persons`/`hardwares`/`units` indexes — same pattern, lower traffic, deliberately deferred (see Maintenance notes).
- Any model save hook or normalization of stored data.

## Git workflow

- Branch: `perf/folded-search-trgm-indexes`
- Commit style: `perf: index the folded search expression so trgm indexes apply (#003)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Confirm the expression is indexable (immutability)

PostgreSQL requires index expressions to be `IMMUTABLE`. Verify: `php artisan tinker --execute 'echo \App\Traits\PersianNormalizer::foldSeparatorsSql("subject");'` and then run, against the dev DB, `CREATE INDEX ... USING GIN ((<that expression>) gin_trgm_ops)` inside a transaction you roll back. If PostgreSQL rejects it (e.g. a collation-dependent function), **STOP** and report the error — the fallback design (a normalized shadow column maintained on save) is a different, larger plan.

**Verify**: the `CREATE INDEX` above succeeds, then `ROLLBACK`.

### Step 2: Write the migration

Create `database/migrations/<date>_000001_add_folded_trgm_indexes_to_tickets_search.php` that, in `up()`:

1. `DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm')` (matches the existing migration).
2. Creates GIN trigram indexes on the **exact** expressions used by search, generated from PHP so the two can never diverge:

```php
$subject = PersianNormalizer::foldSeparatorsSql('subject');
$content = PersianNormalizer::foldSeparatorsSql('content');
DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS tickets_subject_folded_trgm_idx ON tickets USING GIN (($subject) gin_trgm_ops)");
DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS tickets_content_folded_trgm_idx ON tickets USING GIN (($content) gin_trgm_ops)");
```

`down()` drops both. Keep the existing bare-column indexes in place (they are still used by non-folded searches elsewhere, e.g. global search).

Note: `CREATE INDEX CONCURRENTLY` cannot run inside a transaction — check the existing migration style for whether this repo wraps migrations; if the framework wraps it, either use plain `CREATE INDEX` (short lock on this table is acceptable at current size and matches the existing migration) or set the migration to run without a transaction. Prefer matching the existing migration's choice and note it in the commit message.

**Verify**: `php artisan migrate --no-interaction` → applies; `php artisan migrate:rollback --step=1 --no-interaction` → drops them; then re-apply.

### Step 3: Prove the index is used

Run the real query with `EXPLAIN (ANALYZE, BUFFERS)`. The simplest reliable path is a Pest test or a tinker snippet that reproduces the inbox search: a folded `LIKE` over `subject`/`content` with a scope `WHERE unit_id IN (...)`. Expected: a `Bitmap Index Scan` on `tickets_subject_folded_trgm_idx` / `..._content_folded_trgm_idx` rather than a `Seq Scan` on `tickets`.

Note this is verification, not an assertion in the suite (plans must not assert on EXPLAIN output — planner choices vary). If the planner still prefers a seq scan on a tiny dev table, seed enough rows (e.g. 5000 tickets) locally and re-check; report the observed plan either way.

**Verify**: `EXPLAIN (ANALYZE, BUFFERS)` output names the new index (or you report the seeded-table result).

### Step 4: Regression-test the search semantics

Find the ticket-search test (`grep -rl "search" tests/Feature | grep -i ticket`). Ensure it covers the ZWNJ/digit cases the fold exists for, e.g. searching `حرفه ای` (space) finding a stored `حرفه‌ای` (ZWNJ) subject, and `۴۵` finding `45`. If coverage is missing, add it. Semantics must not change.

**Verify**: `XDEBUG_MODE=off php artisan test <ticket search test>` → all pass.

## Test plan

- New tests in the ticket-search test file: the ZWNJ→space case and the Persian-digit case, for both `subject` and `content`, asserting the row is found (and that an unrelated row is not). These pin the *behaviour*, which the index must not alter.
- Do **not** write a test asserting EXPLAIN/index usage — it is environment-dependent and would be flaky.
- Structural pattern: existing ticket feature tests using `InteractsWithTestSetup` and `Ticket::factory()` (`TicketFactory` is live and used by `tests/Feature/TicketWorkflowTest.php`); remember `created_at` is not fillable.
- Verification: `XDEBUG_MODE=off php artisan test <file>` → all pass.

## Done criteria

- [ ] A migration exists that creates folded-expression GIN trigram indexes for `tickets.subject` and `tickets.content`, generated from `PersianNormalizer::foldSeparatorsSql()` (not hand-copied SQL)
- [ ] `php artisan migrate` then `migrate:rollback --step=1` then `migrate` all succeed
- [ ] `EXPLAIN` shows the new index used on a seeded table (result pasted in the PR description)
- [ ] Ticket search tests pass unchanged, plus the ZWNJ/digit cases
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] No changes to `applyTicketSearch()` or `foldSeparatorsSql()` behaviour (`git diff` shows none)
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- The expression is not `IMMUTABLE` for PostgreSQL (step 1) — the shadow-column approach is a separate plan.
- The existing migration wraps DDL in a transaction and `CONCURRENTLY` is therefore impossible, and a plain `CREATE INDEX` would lock the table unacceptably — report and let the operator choose.
- Search results change for any existing test — the term-side fold must be untouched.
- `2026_10_08_000001` no longer matches the excerpt (drift).

## Maintenance notes

- **The expression index must stay in lockstep with `foldSeparatorsSql()`.** If `charMap()`/`digitMap()` ever change, the index expression changes and a new migration is required; a stale expression index silently stops being used (it is not wrong, just unused). Add a one-line note in `foldSeparatorsSql()`'s docblock pointing at the migration.
- The same treatment applies to `persons` (via `PersonsExportController`/`kargozini/person`), `hardwares` (`HardwareFilters`), and `units` (`UnitTreeService`) — deferred here because ticket search has the highest frequency and the largest table. Follow this plan's pattern if those become hot.
- Reviewer should scrutinize: that the migration builds SQL from the PHP helper (not a copy), that `down()` drops cleanly, and that no user-visible search behaviour changed.
