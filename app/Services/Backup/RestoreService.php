<?php

namespace App\Services\Backup;

use App\Exceptions\BackupValidationException;
use App\Support\Backup\RestoreResult;
use App\Support\Backup\TableRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Restores a backup file (issue #1375).
 *
 * The restore is ATOMIC: the validation runs first, and the whole
 * replacement happens inside ONE database transaction. Any error — a broken
 * file, a missing reference, a constraint the database refuses — rolls the
 * transaction back, so the tables are exactly as they were.
 *
 * Foreign-key enforcement is ALWAYS ON. There is no `session_replication_role`,
 * no superuser, no `Schema::disableForeignKeyConstraints()` anywhere in this
 * path: the order of the statements is what makes the restore legal.
 *
 * ## The `users` orphan consequence (owner decision Q2-(a))
 *
 * Replacing the `users` table replaces the ACCOUNTS, while the tables that
 * point at an account are NOT part of the backup:
 *
 * - `model_has_roles` / `model_has_permissions` — the role and permission
 *   assignments of the old accounts (polymorphic, so no FK protects them),
 * - `personal_access_tokens` — the API tokens of the old accounts.
 *
 * After a `users` restore those rows can point at an account that no longer
 * exists: the user is restored WITHOUT its roles and WITHOUT its tokens.
 * This is ACCEPTED for v1 (role re-sync is out of scope); the UI warns about
 * it before a `users` restore, and the report of the change says so too.
 */
class RestoreService
{
    public function __construct(
        private readonly BackupArchiveReader $reader,
        private readonly BackupImportValidator $validator,
        private readonly TableRegistry $registry,
    ) {}

    /**
     * Restore an archive from disk.
     *
     * An archive with one table is a per-table backup; an archive with a
     * manifest (or with more than one table) is a full backup.
     *
     * The `$table` argument FORCES the per-table path: the settings page
     * knows which table the admin picked, and a full archive uploaded by
     * mistake must not replace all 32 tables.
     *
     * @throws BackupValidationException
     */
    public function restoreArchive(string $path, ?string $table = null): RestoreResult
    {
        $archive = $this->reader->read($path);

        if ($table !== null) {
            if (! array_key_exists($table, $archive['tables'])) {
                throw new BackupValidationException("جدول «{$table}» در فایل بک‌آپ نیست.");
            }

            return $this->restoreTable($table, $archive['tables'][$table]);
        }

        if ($archive['full']) {
            return $this->restoreFull($archive['tables']);
        }

        $table = (string) array_key_first($archive['tables']);

        return $this->restoreTable($table, $archive['tables'][$table]);
    }

    /**
     * Replace ONE table with the rows of a file.
     *
     * The rows that are not in the file are deleted, the rows that are in it
     * are inserted or updated — in one transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws BackupValidationException
     */
    public function restoreTable(string $table, array $rows): RestoreResult
    {
        return DB::transaction(function () use ($table, $rows): RestoreResult {
            $this->validator->validateTable($table, $rows);

            $this->replaceRows($table, $rows);
            $this->resyncSequence($table);

            return new RestoreResult(
                full: false,
                tables: [$table],
                rowCount: count($rows),
            );
        });
    }

    /**
     * Replace EVERY registered table with the rows of a full backup.
     *
     * The tables are emptied in reverse-topological order (children first)
     * and filled again in topological order (parents first), so no
     * constraint is ever violated — with the foreign keys switched on.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     *
     * @throws BackupValidationException
     */
    public function restoreFull(array $tables): RestoreResult
    {
        return DB::transaction(function () use ($tables): RestoreResult {
            $this->requireEveryRegisteredTable($tables);
            $this->validator->validateFullBackup($tables);

            $order = $this->registry->topologicalOrder();

            foreach (array_reverse($order) as $table) {
                DB::table($table)->delete();
            }

            foreach ($order as $table) {
                $rows = $tables[$table] ?? [];

                if ($rows === []) {
                    continue;
                }

                DB::table($table)->insert($rows);
            }

            foreach ($order as $table) {
                $this->resyncSequence($table);
            }

            $rowCount = 0;
            foreach ($tables as $rows) {
                $rowCount += count($rows);
            }

            return new RestoreResult(
                full: true,
                tables: array_keys($tables),
                rowCount: $rowCount,
            );
        });
    }

    /**
     * Make the table hold exactly the rows of the file.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceRows(string $table, array $rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['id'];
        }

        // An empty list compiles to `1 = 1`, which deletes every row — the
        // right answer for a file that carries no rows at all.
        DB::table($table)->whereNotIn('id', $ids)->delete();

        if ($rows === []) {
            return;
        }

        DB::table($table)->upsert($rows, ['id'], $this->updatableColumns($table));
    }

    /**
     * The columns an `upsert` may update — every column but the primary key.
     *
     * @return list<string>
     */
    private function updatableColumns(string $table): array
    {
        $columns = array_values(array_diff($this->registry->columns($table), ['id']));

        return $columns === [] ? $this->registry->columns($table) : $columns;
    }

    /**
     * A full backup has to carry EVERY registered table — a partial file is
     * a different operation (a per-table restore) and must not be mistaken
     * for one.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     *
     * @throws BackupValidationException
     */
    private function requireEveryRegisteredTable(array $tables): void
    {
        $missing = array_values(array_diff($this->registry->tables(), array_keys($tables)));

        if ($missing === []) {
            return;
        }

        throw new BackupValidationException(
            'فایل بک‌آپ کامل نیست؛ جدول‌های «'.implode('»، «', $missing).'» در آن وجود ندارند.'
        );
    }

    /**
     * Move the Postgres sequence of a table past its highest id (G1).
     *
     * A restore writes EXPLICIT ids, and Postgres sequences do not follow
     * such an insert: without this the next normal create would try the same
     * id again and fail with a duplicate-key error.
     *
     * The third argument of `setval` matters for an EMPTY table: a Postgres
     * sequence starts at 1, so `setval(seq, 0)` is refused ("value 0 is out
     * of bounds"). The value is therefore `COALESCE(MAX(id), 1)` and the
     * `is_called` flag says whether the sequence is already "used" — with
     * `false` the next `nextval()` returns exactly 1.
     */
    private function resyncSequence(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') as seq", [$table]);

        // A table with a UUID primary key (`notifications`) has no sequence.
        if (! $sequence || ! $sequence->seq) {
            return;
        }

        DB::statement(
            'SELECT setval(?, (SELECT COALESCE(MAX(id), 1) FROM "'.$table.'"), (SELECT COUNT(*) > 0 FROM "'.$table.'"))',
            [$sequence->seq]
        );
    }
}
