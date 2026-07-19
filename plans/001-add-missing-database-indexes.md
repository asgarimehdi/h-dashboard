# Plan 001: Add Missing Database Indexes

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat <planned-at SHA>..HEAD -- database/migrations/`
> If any migration file in database/migrations/ changed since this plan was written,
> compare the "Current state" before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `7f8544d`, 2024-07-17
- **Issue**: N/A

## Why this matters

Indexes on foreign keys and frequently-queried columns are the single highest-leverage database performance fix. Without them, every join and filter on these columns performs a full table scan — slowing every page load and API response that touches users, persons, units, tickets, todos, or pivot tables. Adding these indexes is a read-only schema change with zero application code risk.

## Current state

The following tables lack indexes on columns used in WHERE clauses, JOINs, and ORDER BY:

| Table | Column(s) | Used in |
|-------|-----------|---------|
| `persons` | `u_id` (FK → units), `n_code` (FK → users) | Person→Unit join, User→Person join |
| `users` | `n_code` (FK → persons) | User→Person join |
| `todos` | `created_at`, `updated_at`, `unit_id` | Listing/sorting, scope filtering |
| `tickets` | `created_at`, `updated_at`, `unit_id`, `user_id` | Listing/sorting, scope filtering |
| `user_units` | `user_id`, `unit_id` | Access control, user-unit assignment |
| `user_unit_todo` | `user_id`, `unit_id`, `todo_id` | User-todo assignment |

The previous performance migration (`2026_06_23_231832_add_performance_indexes`) was deleted in the `new` branch — those indexes are gone and need re-creation. See `git show origin/main:database/migrations/2026_06_23_231832_add_performance_indexes.php` for the original.

**Repo conventions**: All migrations follow Laravel's standard class-anonymous pattern. See `database/migrations/2026_07_03_000001_create_activity_logs_table.php` as a reference.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Create migration | `php artisan make:migration add_missing_performance_indexes` | `Created Migration:` output |
| Migrate | `php artisan migrate` | exit 0 |
| Rollback | `php artisan migrate:rollback --step=1` | exit 0 |
| Test | `php artisan test --compact` | all pass |
| Schema check | `php artisan tinker --execute 'DB::select("SHOW INDEX FROM persons")'` | shows u_id, n_code indexes |

## Scope

**In scope** (the only files you should modify):
- `database/migrations/YYYY_MM_DD_HHMMSS_add_missing_performance_indexes.php` (create new file)

**Out of scope** (do NOT touch, even though they look related):
- Any existing migration file — do not modify, reorder, or rename them
- Model files, controllers, or any application code
- Composer or npm dependencies
- Configuration files

## Git workflow

- Branch: `advisor/001-add-missing-indexes` (or the repo's branch-naming convention if one is evident)
- Commit per step or per logical unit; message style: `perf: add indexes on persons, users, todos, tickets, and pivot tables`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Create the migration file

Run `php artisan make:migration add_missing_performance_indexes` to generate a skeleton.

**Verify**: The output shows `Created Migration:` and the file exists under `database/migrations/`.

### Step 2: Write the `up()` method

Open the generated file and add index definitions inside `up()`. The structure:

```php
public function up(): void
{
    // persons
    Schema::table('persons', function (Blueprint $table) {
        $table->index('u_id');
        $table->index('n_code');
    });

    // users
    Schema::table('users', function (Blueprint $table) {
        $table->index('n_code');
    });

    // todos
    Schema::table('todos', function (Blueprint $table) {
        $table->index('created_at');
        $table->index('updated_at');
        $table->index('unit_id');
    });

    // tickets
    Schema::table('tickets', function (Blueprint $table) {
        $table->index('created_at');
        $table->index('updated_at');
        $table->index('unit_id');
        $table->index('user_id');
    });

    // user_units
    Schema::table('user_units', function (Blueprint $table) {
        $table->index('user_id');
        $table->index('unit_id');
    });

    // user_unit_todo (if table exists)
    if (Schema::hasTable('user_unit_todo')) {
        Schema::table('user_unit_todo', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('unit_id');
            $table->index('todo_id');
        });
    }
}
```

**Verify**: The file parses without syntax errors: `php -l database/migrations/<filename>.php` → `No syntax errors detected`.

### Step 3: Write the `down()` method

Add the corresponding `dropIndex()` calls in the `down()` method:

```php
public function down(): void
{
    Schema::table('persons', function (Blueprint $table) {
        $table->dropIndex(['u_id']);
        $table->dropIndex(['n_code']);
    });
    // ... repeat for each table
}
```

**Verify**: Same syntax check.

### Step 4: Run the migration

`php artisan migrate` — confirm it completes without error.

**Verify**: `php artisan migrate:status` shows the new migration with a `Y` in the Ran column.

### Step 5: Verify indexes were created

`php artisan tinker --execute 'print_r(DB::select("SHOW INDEX FROM persons"));'`

**Verify**: The output includes rows for `persons_u_id_index` and `persons_n_code_index`.

### Step 6: Run the test suite

`php artisan test --compact`

**Verify**: All tests pass (expecting `Tests:  1 failed, 1 passed` — the ExampleTest failure is pre-existing, caused by MySQL not running locally. The important thing is no NEW failures are introduced).

### Step 7: Test rollback

`php artisan migrate:rollback --step=1`

**Verify**: `php artisan migrate:status` shows the migration with `N` in the Ran column.

### Step 8: Re-run the migration

`php artisan migrate` — re-apply for production use.

**Verify**: exit 0, no errors.

## Test plan

- No new test files needed — this is a schema change, tested by the migration itself.
- Validation: `php artisan migrate:fresh --seed` (if MySQL is running) to verify no conflicts with existing seeders.
- After migration, run `php artisan test --compact` to confirm no regressions.

## Done criteria

ALL must hold:

- [ ] `php artisan migrate:status` shows the new migration as Ran
- [ ] `php artisan tinker --execute 'print_r(DB::select("SHOW INDEX FROM persons"));'` shows `persons_u_id_index` and `persons_n_code_index`
- [ ] `php artisan tinker --execute 'print_r(DB::select("SHOW INDEX FROM todos"));'` shows `todos_created_at_index`, `todos_updated_at_index`, `todos_unit_id_index`
- [ ] `php artisan tinker --execute 'print_r(DB::select("SHOW INDEX FROM tickets"));'` shows `tickets_created_at_index`, `tickets_updated_at_index`, `tickets_unit_id_index`, `tickets_user_id_index`
- [ ] `php artisan tinker --execute 'print_r(DB::select("SHOW INDEX FROM user_units"));'` shows `user_units_user_id_index` and `user_units_unit_id_index`
- [ ] `php artisan test --compact` exits with no NEW failures (pre-existing ExampleTest failure is acceptable)
- [ ] `php artisan migrate:rollback --step=1` exits 0 and removes the indexes
- [ ] `php artisan migrate` re-applies cleanly
- [ ] No files outside `database/migrations/` are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The migration file can't be created because `php artisan make:migration` fails.
- `php artisan migrate` produces any SQL error (e.g. "Duplicate key name" — check if the index already exists).
- `php artisan test` shows NEW failures that trace to the migration (not the pre-existing ExampleTest failure).
- The `user_unit_todo` table does not exist in the schema (the plan uses `Schema::hasTable` to guard against this, but if it's absent, report it).
- A step's verification fails twice after a reasonable fix attempt.

## Maintenance notes

- These indexes are forward-compatible with the deleted `2026_06_23_231832_add_performance_indexes.php` — they cover the same columns plus new ones (`users.n_code`, `todos.unit_id`, `user_units.*`).
- If the `user_unit_todo` table is dropped in a future migration, the `down()` method should be updated to remove the `Schema::hasTable` guard.
- Monitor slow query log after deployment to identify any remaining unindexed queries.
- If write performance on pivot tables becomes an issue, consider composite indexes: `['user_id', 'unit_id']` instead of separate single-column indexes.