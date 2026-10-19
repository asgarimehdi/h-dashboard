# perf: the orphan-attachment prune scans the table once per file

> **Plan 015** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P3` · formerly GitHub issue #1151 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Console/Commands/PruneOrphanAttachments.php database/migrations`

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The orphan-attachment prune command checks each file on disk with its own `exists()` query, and `attachments.file_path` has **no index** — so every file is a sequential scan of the `attachments` table. With 10,000 files that is 10,000 table scans, and the whole thing runs in the hourly-scheduled `cache:prune-stale`… actually it is run manually (`php artisan attachments:prune`); regardless, the scan cost scales as O(files × rows). Loading the referenced set once and adding a `file_path` index turns it into a handful of queries.

## Current state

- `app/Console/Commands/PruneOrphanAttachments.php:43-53`:

```php
foreach ($disk->allFiles(self::DIRECTORY) as $file) {
    $path = self::path($file);

    if (Attachment::query()->where('file_path', $path)->exists()) {   // one query per file
        $referenced++;
        continue;
    }

    $orphans[] = $path;
}
```

- `:80-84` — deletion is already chunked (`array_chunk($orphans, self::CHUNK)`), so only the lookup phase is unbounded.
- `database/migrations/2026_01_02_000001_create_attachments_table.php:15` — `$table->string('file_path');` with **no index** (only `ticket_id` and `activity_id` are indexed).
- Repo conventions: migrations named `YYYY_MM_DD_000001_description.php`; the command already supports `--dry-run` and prints every orphan (that output is the deliverable under dry-run — preserve it).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/PruneOrphanAttachmentsTest.php` (find: `grep -rl "PruneOrphan" tests/`) | all pass |
| Run the command | `php artisan attachments:prune --dry-run` (confirm the real signature with `php artisan list \| grep -i attach`) | lists orphans, no writes |
| Migration | `php artisan migrate --no-interaction` | applies |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `app/Console/Commands/PruneOrphanAttachments.php`
- A new migration adding an index to `attachments.file_path`
- The prune test file

**Out of scope** (do NOT touch):
- The deletion logic and its chunking, the `--dry-run` output format, and the `DIRECTORY` constant.
- `Attachment` model relationships.
- Any other command.

## Git workflow

- Branch: `perf/prune-orphan-attachments-lookup`
- Commit style: `perf: look up attachment references in bulk and index file_path (#015)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Add the index

Create a migration:

```php
Schema::table('attachments', function (Blueprint $table) {
    $table->index('file_path');   // not unique unless the app guarantees uniqueness — check first
});
```

Do **not** make it unique unless the code guarantees one row per path; `grep -rn "file_path" app/` and confirm. `down()` drops it.

**Verify**: `php artisan migrate --no-interaction` → applies; `migrate:rollback --step=1` → drops; re-apply.

### Step 2: Load references once, in chunks

Replace the per-file `exists()` with a set lookup:

```php
$referencedPaths = Attachment::query()
    ->select('file_path')
    ->pluck('file_path')
    ->flip();          // O(1) membership
```

If the referenced set can be large, chunk it and `array_merge` the chunks into one PHP set — but a single `pluck` of paths is far cheaper than N queries and is the right first step. Keep the `$referenced` count and the orphan list exactly as before.

**Verify**: `php artisan <prune command> --dry-run` → same orphan list as before the change (compare against a run on the pre-change commit if a fixture exists); focused test passes.

### Step 3: (Optional) avoid materializing the whole listing

`$disk->allFiles()` returns an array of every file. If the directory can hold very many files, iterate per-directory instead (`$disk->directories()` + `$disk->files($dir)`). This is optional — only do it if the focused test/benchmark shows memory pressure; otherwise leave `allFiles()` and note it.

**Verify**: if changed, dry-run output is identical.

## Test plan

- Keep/extend the prune test: seeded attachments (some referenced, some orphaned) → the command lists exactly the orphans and deletes them when not `--dry-run`.
- Add an assertion that the lookup issues a bounded number of queries (use the shared trait's `assertQueryCount()` with a ceiling) — this is the regression guard for the per-file loop.
- Structural pattern: existing prune test; `Storage::fake('public')` for file fixtures.
- Verification: `XDEBUG_MODE=off php artisan test <prune test>` → all pass.

## Done criteria

- [ ] `attachments.file_path` is indexed (migration present; `migrate`/`rollback`/`migrate` succeed)
- [ ] The per-file `exists()` loop is gone; references are loaded in bulk
- [ ] Orphan detection results are identical for the same fixture (test)
- [ ] Query-count ceiling test passes
- [ ] `XDEBUG_MODE=off php artisan test <prune test>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- `file_path` turns out to be non-unique and used as a lookup key elsewhere in a way an index would change — report (an index does not change semantics, but the uniqueness question matters for the migration's shape).
- The command's real name/signature differs from `attachments:prune` — use the actual one and note it.
- The dry-run output changes for the same input.

## Maintenance notes

- If attachments later move to object storage with a different path scheme, the `file_path` index and the `DIRECTORY` constant must be revisited together.
- Reviewer should scrutinize: that the command still prints every orphan under `--dry-run`, and that the index is non-unique unless uniqueness is actually guaranteed.
