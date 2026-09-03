# Plan 029: Fix Duplicate Migration Timestamp

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

Two migration files share the same timestamp prefix `2025_12_26_000002`, which causes undefined ordering during `php artisan migrate`. Laravel runs migrations in filename order when timestamps collide, but the behavior is fragile and can cause issues with fresh installations.

### Current State

```
database/migrations/
  2025_12_26_000001_create_tickets_table.php
  2025_12_26_000002_add_task_id_and_deadline_to_tickets_table.php  ← COLLISION
  2025_12_26_000002_create_task_activities_table.php               ← COLLISION
```

The two files with `000002`:
1. `2025_12_26_000002_add_task_id_and_deadline_to_tickets_table.php` — adds columns to `tickets`
2. `2025_12_26_000002_create_task_activities_table.php` — creates the `task_activities` table

### Why This Matters

- On `php artisan migrate`, the execution order of these two files depends on filesystem sorting (alphabetical after the timestamp). Since both have the same prefix, the order is determined by the filename body.
- The `add_task_id_and_deadline` migration likely references `task_activities` (via FK or column), but `create_task_activities` might run first or second depending on the sort.
- On a fresh install, this can cause migration failures if the FK references a table that hasn't been created yet.
- It also affects `migrate:status` output (shows duplicate timestamps).

---

## Solution

Rename the second migration from `2025_12_26_000002` to `2025_12_26_000003`.

### Changes

**Rename:**
```bash
git mv \
  database/migrations/2025_12_26_000002_create_task_activities_table.php \
  database/migrations/2025_12_26_000003_create_task_activities_table.php
```

**Inside the renamed file**, update the class name if it's named after the file (check):

```php
// Before:
class CreateTaskActivitiesTable extends Migration

// After (if class name needs updating — likely it doesn't since PHP class names don't include timestamps):
// No change needed for the class name
```

### Why `000003` and Not a Later Number

`000003` is the next sequential number after the duplicate. Using a much later number (e.g., `2025_12_27_000001`) would also work but creates a gap in the daily sequence. The AGENTS.md convention says: "New migrations use `YYYY_MM_DD_000001_description.php` (sequential daily counter)."

### Production Consideration

If this migration has already run on production:
- `php artisan migrate:status` will show the old filename as "Yes" and the new filename as "No"
- Running `php artisan migrate` will try to run the "new" migration, which will fail because the table already exists

**For production:** After renaming, check the `migrations` table:

```sql
SELECT * FROM migrations WHERE migration LIKE '%2025_12_26_000002%';
```

If both are present in the database, no action needed (Laravel tracks by the full migration name). If only one is present, you'll need to manually insert the missing record:

```sql
INSERT INTO migrations (migration, batch)
VALUES ('2025_12_26_000003_create_task_activities_table', <same batch as the old one>);
```

**For local/staging:** `php artisan migrate:fresh` will re-run all migrations cleanly with the fixed timestamps.

---

## Verification

1. **Check no duplicate timestamps remain:**
   ```bash
   ls database/migrations/2025_12_26_* | sort
   ```
   Expected:
   ```
   2025_12_26_000001_create_tickets_table.php
   2025_12_26_000002_add_task_id_and_deadline_to_tickets_table.php
   2025_12_26_000003_create_task_activities_table.php
   ```

2. **Run fresh migration:**
   ```bash
   php artisan migrate:fresh --seed
   ```
   Expected: all migrations run without errors.

3. **Check migrate:status:**
   ```bash
   php artisan migrate:status
   ```
   Expected: no duplicate timestamps in the output.

4. **Run tests:**
   ```bash
   composer test
   ```
   Expected: all 928 tests pass.

---

## STOP Conditions

- If the `migrations` table on production already has both `000002_*` entries recorded, the rename won't affect production (Laravel tracks by full name). But verify before deploying.
- If the two migrations have a FK dependency that requires specific ordering, verify the rename fixes the ordering issue.

---

## Out of Scope

- Adding a migration uniqueness check to CI (separate linting concern).
- Modifying the migration content (only renaming the timestamp).
- Checking for other timestamp collisions across the migration directory.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `ls database/migrations/2025_12_26_* \| wc -l` | 3 files, no duplicates |
| 2 | `php artisan migrate:fresh` | All run without errors |
| 3 | `php artisan migrate:status` | No duplicate timestamps |
| 4 | `composer test` | 928 tests pass |
| 5 | `git diff --stat` | Only the renamed file |

---

## Maintenance Notes

- **Git history:** `git mv` preserves the file's git history. This is preferred over delete+create.
- **Convention:** The AGENTS.md convention states: "New migrations use `YYYY_MM_DD_000001_description.php` (sequential daily counter)". After this fix, the sequence is clean: `000001`, `000002`, `000003`.
- **Future prevention:** Consider adding a CI step that checks for duplicate migration timestamps:
  ```bash
  ls database/migrations/ | sed 's/_.*//' | sort | uniq -d | grep . && echo "Duplicate timestamps found!" && exit 1
  ```
- **Production deploy:** This rename should be included in a deploy that runs `migrate:status` and verifies the migration records before proceeding.
