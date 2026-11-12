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
 * ## Streaming (issue #1418)
 *
 * Rows are read from a {@see BackupSource} twice: once to stage the file's
 * ids, once to write buffered chunks. Nothing here holds a whole table.
 *
 * The per-table delete cannot be `whereNotIn('id', $ids)`: Postgres refuses
 * a statement with more than 65,535 bind parameters, and a chunked
 * `whereNotIn` is a correctness bug (the first chunk would delete rows that
 * belong to a later one). The ids are staged in a transaction-local temp
 * table and the delete is an anti-join.
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
    /**
     * How many ids are staged per temp-table insert.
     */
    private const ID_CHUNK_SIZE = 5000;

    /**
     * How many bind parameters a write statement may carry.
     *
     * Postgres refuses a statement above 65,535 parameters. 60,000 leaves a
     * margin, and the computed per-table cap protects a wide table whose
     * 500-row chunk would exceed it.
     */
    private const PARAMETER_BUDGET = 60000;

    /**
     * The preferred number of rows per insert/upsert statement.
     */
    private const ROW_CHUNK_SIZE = 500;

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
        $archive = $this->reader->inspect($path);
        $source = new ArchiveBackupSource($this->reader, $path, $archive['tables']);

        if ($table !== null) {
            if (! in_array($table, $archive['tables'], true)) {
                throw new BackupValidationException("جدول «{$table}» در فایل بک‌آپ نیست.");
            }

            return $this->restoreTable($table, $source);
        }

        if ($archive['full']) {
            return $this->restoreFull($source);
        }

        return $this->restoreTable($archive['tables'][0], $source);
    }

    /**
     * Replace ONE table with the rows of a file.
     *
     * The rows that are not in the file are deleted, the rows that are in it
     * are inserted or updated — in one transaction.
     *
     * @param  BackupSource|list<array<string, mixed>>  $rows
     *
     * @throws BackupValidationException
     */
    public function restoreTable(string $table, BackupSource|array $rows): RestoreResult
    {
        $source = $rows instanceof BackupSource ? $rows : new ArrayBackupSource([$table => $rows]);

        return DB::transaction(function () use ($table, $source): RestoreResult {
            $this->validator->validateTable($table, $source);

            $rowCount = $this->replaceRows($table, $source);
            $this->resyncSequence($table);

            return new RestoreResult(
                full: false,
                tables: [$table],
                rowCount: $rowCount,
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
     * @param  BackupSource|array<string, list<array<string, mixed>>>  $tables
     *
     * @throws BackupValidationException
     */
    public function restoreFull(BackupSource|array $tables): RestoreResult
    {
        $source = $tables instanceof BackupSource ? $tables : new ArrayBackupSource($tables);

        return DB::transaction(function () use ($source): RestoreResult {
            $this->requireEveryRegisteredTable($source->tables());
            $this->validator->validateFullBackup($source);

            $order = $this->registry->topologicalOrder();
            $available = array_flip($source->tables());

            foreach (array_reverse($order) as $table) {
                DB::table($table)->delete();
            }

            $rowCount = 0;

            foreach ($order as $table) {
                if (! isset($available[$table])) {
                    continue;
                }

                $rowCount += $this->insertRows($table, $source->rows($table));
            }

            foreach ($order as $table) {
                $this->resyncSequence($table);
            }

            return new RestoreResult(
                full: true,
                tables: $source->tables(),
                rowCount: $rowCount,
            );
        });
    }

    /**
     * Make the table hold exactly the rows of the file.
     *
     * @return int The number of rows the file carried.
     *
     * @throws BackupValidationException
     */
    private function replaceRows(string $table, BackupSource $source): int
    {
        $temp = '_restore_ids_'.bin2hex(random_bytes(6));

        // Transaction-local: the single-transaction guarantee is unchanged,
        // and the table is dropped by the commit (or the rollback).
        DB::statement(
            'CREATE TEMP TABLE "'.$temp.'" ON COMMIT DROP '
            .'AS SELECT id FROM "'.$table.'" WITH NO DATA'
        );

        try {
            $this->stageIds($table, $source, $temp);

            // An empty id set compiles to "NOT EXISTS over nothing", which is
            // true for every row — the right answer for a file that carries
            // no rows at all. Chunking this statement is NOT an option: the
            // first chunk would remove rows that the later chunks keep.
            DB::table($table)
                ->whereRaw('NOT EXISTS (SELECT 1 FROM "'.$temp.'" WHERE "'.$temp.'".id = "'.$table.'".id)')
                ->delete();

            return $this->writeRows($table, $source);
        } finally {
            try {
                DB::statement('DROP TABLE IF EXISTS "'.$temp.'"');
            } catch (\Throwable) {
                // The transaction is already aborted; its rollback removes
                // the temp table with it.
            }
        }
    }

    /**
     * Stage the file's ids in the transaction-local temp table.
     *
     * The id is the key of the replace: without it both the delete set and
     * the upsert are undefined. The validator catches a missing `id` column
     * first, but this method is the last gate before the writes and has to
     * fail closed on its own.
     *
     * @throws BackupValidationException
     */
    private function stageIds(string $table, BackupSource $source, string $temp): void
    {
        $ids = [];
        $index = 0;

        foreach ($source->rows($table) as $row) {
            if (! array_key_exists('id', $row) || $row['id'] === null) {
                throw new BackupValidationException(
                    'ساختار فایل بک‌آپ نامعتبر است: ردیف شماره '.($index + 1)." جدول «{$table}» مقدار معتبری برای ستون «id» ندارد."
                );
            }

            $ids[] = ['id' => $row['id']];
            $index++;

            if (count($ids) >= self::ID_CHUNK_SIZE) {
                DB::table($temp)->insert($ids);
                $ids = [];
            }
        }

        if ($ids !== []) {
            DB::table($temp)->insert($ids);
        }
    }

    /**
     * Stream the rows into buffered upsert statements.
     *
     * @return int The number of rows written.
     */
    private function writeRows(string $table, BackupSource $source): int
    {
        $columns = $this->updatableColumns($table);
        $chunkSize = $this->chunkSize($table);
        $buffer = [];
        $count = 0;

        foreach ($source->rows($table) as $row) {
            $buffer[] = $row;
            $count++;

            if (count($buffer) >= $chunkSize) {
                DB::table($table)->upsert($buffer, ['id'], $columns);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table($table)->upsert($buffer, ['id'], $columns);
        }

        return $count;
    }

    /**
     * Insert the rows of one table of a full backup in bind-safe chunks.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return int The number of rows written.
     */
    private function insertRows(string $table, iterable $rows): int
    {
        $chunkSize = $this->chunkSize($table);
        $buffer = [];
        $count = 0;

        foreach ($rows as $row) {
            $buffer[] = $row;
            $count++;

            if (count($buffer) >= $chunkSize) {
                DB::table($table)->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table($table)->insert($buffer);
        }

        return $count;
    }

    /**
     * How many rows of this table fit in one statement.
     */
    private function chunkSize(string $table): int
    {
        $columns = max(1, count($this->registry->columns($table)));

        return max(1, min(self::ROW_CHUNK_SIZE, intdiv(self::PARAMETER_BUDGET, $columns)));
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
     * @param  list<string>  $tables
     *
     * @throws BackupValidationException
     */
    private function requireEveryRegisteredTable(array $tables): void
    {
        $missing = array_values(array_diff($this->registry->tables(), $tables));

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
