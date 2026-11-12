<?php

namespace App\Services\Backup;

use App\Exceptions\BackupValidationException;
use App\Support\Backup\BackupProblem;
use App\Support\Backup\ForeignKey;
use App\Support\Backup\TableRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Checks a backup file before anything is restored (issue #1375).
 *
 * Three gates, all of them BEFORE the first write:
 *
 * 1. Structure — the file has to carry the columns of the table it claims
 *    to be.
 * 2. References — every foreign-key value has to resolve. A per-table backup
 *    is checked against the TARGET database; a full backup is checked against
 *    the file itself, because that is what a full restore replaces.
 * 3. Live children — the owner decision Q1-(b): restoring a parent also
 *    validates the LIVE children of the target database against the new
 *    parent set. A child that points at a row the file does not carry would
 *    be orphaned, so the whole import is refused.
 *
 * Every problem that was found is collected and reported as ONE Persian
 * error — a restore that stops at the first problem would send the user
 * through the same file again and again.
 *
 * ## Streaming (issue #1418)
 *
 * The checks used to receive the whole table as an array, which meant the
 * archive was materialised (and re-scanned) in PHP memory. Each check now
 * consumes an `iterable` once. The dedicated full-set structures that remain
 * are O(distinct ids / distinct foreign-key values), never O(rows):
 *
 * - `$seen` — the duplicate-id set,
 * - `$referenceValues` / `$referenceFirstId` — distinct child FK values and
 *   the first row carrying each,
 * - `$parentValues` — distinct parent values of a full backup,
 * - `$liveParentValues` — distinct parent values of a per-table restore.
 *
 * The problem list itself is capped by {@see ValidationProblems}.
 */
class BackupImportValidator
{
    /**
     * How many values are compared with the database per query.
     */
    private const CHUNK_SIZE = 500;

    /**
     * How many single-column ids are staged per temp-table insert.
     */
    private const ID_CHUNK_SIZE = 5000;

    /**
     * How many blocking rows of one child table are reported.
     */
    private const BLOCKING_ROW_LIMIT = 50;

    public function __construct(private readonly TableRegistry $registry) {}

    /**
     * Validate the rows of ONE table against the live database.
     *
     * @param  BackupSource|list<array<string, mixed>>  $rows
     *
     * @throws BackupValidationException
     */
    public function validateTable(string $table, BackupSource|array $rows): void
    {
        if (! $this->registry->isRegistered($table)) {
            throw new BackupValidationException("جدول «{$table}» در رجیستری بک‌آپ نیست.");
        }

        $source = $rows instanceof BackupSource ? $rows : new ArrayBackupSource([$table => $rows]);

        $problems = new ValidationProblems;

        $seen = [];
        /** @var array<int, array<string, mixed>> $referenceValues edge => (key => value) */
        $referenceValues = [];
        /** @var array<int, array<string, int|string|null>> $referenceFirstId edge => (key => row id) */
        $referenceFirstId = [];
        /** @var array<int, array<string, mixed>> $liveParentValues edge => (key => value) */
        $liveParentValues = [];

        $edges = $this->registry->foreignKeysOf($table);
        $liveEdges = array_values(array_filter(
            $this->registry->childForeignKeysOf($table),
            fn (ForeignKey $edge): bool => $this->registry->isRegistered($edge->childTable)
        ));

        // One pass: columns, duplicates, the child values this table points
        // at, and the parent values its live children must resolve against.
        foreach ($source->rows($table) as $row) {
            $this->columnProblems($table, $row, $problems);
            $this->accumulateDuplicate($table, $row, $seen, $problems);

            foreach ($edges as $index => $edge) {
                $value = $row[$edge->childColumn] ?? null;

                if ($value === null) {
                    continue;
                }

                $key = (string) $value;

                if (array_key_exists($key, $referenceValues[$index] ?? [])) {
                    continue;
                }

                $referenceValues[$index][$key] = $value;
                $referenceFirstId[$index][$key] = $this->rowId($row);
            }

            foreach ($liveEdges as $index => $edge) {
                $value = $row[$edge->parentColumn] ?? null;

                if ($value === null) {
                    continue;
                }

                $liveParentValues[$index][(string) $value] = $value;
            }
        }

        // The database checks run after the one pass: every whereIn chunk is
        // additive (500 values at a time), and the live-child check is an
        // anti-join against a staged temp table.
        foreach ($edges as $index => $edge) {
            $values = $referenceValues[$index] ?? [];

            if ($values === []) {
                continue;
            }

            $existing = $this->existingValues($edge->parentTable, $edge->parentColumn, $values);

            foreach ($values as $key => $value) {
                if (isset($existing[$key])) {
                    continue;
                }

                $problems->add(new BackupProblem(
                    kind: BackupProblem::KIND_MISSING_REFERENCE,
                    table: $table,
                    rowId: $referenceFirstId[$index][$key] ?? null,
                    column: $edge->childColumn,
                    value: $value,
                    relatedTable: $edge->parentTable,
                ));
            }
        }

        foreach ($liveEdges as $index => $edge) {
            $this->liveChildProblems(
                $table,
                $edge,
                array_values($liveParentValues[$index] ?? []),
                $problems
            );
        }

        $problems->throwIfAny();
    }

    /**
     * Validate the tables of a FULL backup against each other.
     *
     * A full restore replaces every registered table, so the file has to hold
     * the parents its children point at — the target database is irrelevant
     * here, it is about to be replaced.
     *
     * Pass 1 per table: columns, duplicates, and the parent value sets.
     * Pass 2 per child table: the reference checks against those sets.
     *
     * @param  BackupSource|array<string, list<array<string, mixed>>>  $tables
     *
     * @throws BackupValidationException
     */
    public function validateFullBackup(BackupSource|array $tables): void
    {
        $source = $tables instanceof BackupSource ? $tables : new ArrayBackupSource($tables);

        $problems = new ValidationProblems;
        $available = $source->tables();
        $availableSet = array_flip($available);

        /** @var array<string, array<string, true>> $parentColumns table => (column => true) */
        $parentColumns = [];

        foreach ($this->registry->foreignKeys() as $edge) {
            $parentColumns[$edge->parentTable][$edge->parentColumn] = true;
        }

        /** @var array<string, array<string, true>> $seen table => id set */
        $seen = [];
        /** @var array<string, array<string, array<string, mixed>>> $parentValues table => column => (key => value) */
        $parentValues = [];

        foreach ($available as $table) {
            $table = (string) $table;
            $seen[$table] = [];

            foreach ($source->rows($table) as $row) {
                $this->columnProblems($table, $row, $problems);
                $this->accumulateDuplicate($table, $row, $seen[$table], $problems);

                foreach (array_keys($parentColumns[$table] ?? []) as $column) {
                    $value = $row[$column] ?? null;

                    if ($value === null) {
                        continue;
                    }

                    $parentValues[$table][$column][(string) $value] = $value;
                }
            }
        }

        foreach ($available as $table) {
            $table = (string) $table;

            foreach ($this->registry->foreignKeysOf($table) as $edge) {
                if (! isset($availableSet[$edge->parentTable])) {
                    $problems->add(new BackupProblem(
                        kind: BackupProblem::KIND_MISSING_TABLE,
                        table: $table,
                        rowId: null,
                        column: $edge->childColumn,
                        value: null,
                        relatedTable: $edge->parentTable,
                    ));

                    continue;
                }

                $parents = $parentValues[$edge->parentTable][$edge->parentColumn] ?? [];

                foreach ($source->rows($table) as $row) {
                    $value = $row[$edge->childColumn] ?? null;

                    if ($value === null) {
                        continue;
                    }

                    if (isset($parents[(string) $value])) {
                        continue;
                    }

                    $problems->add(new BackupProblem(
                        kind: BackupProblem::KIND_MISSING_REFERENCE,
                        table: $table,
                        rowId: $this->rowId($row),
                        column: $edge->childColumn,
                        value: $value,
                        relatedTable: $edge->parentTable,
                    ));
                }
            }
        }

        $problems->throwIfAny();
    }

    /**
     * The file has to carry exactly the columns of the table.
     *
     * @param  array<string, mixed>  $row
     */
    private function columnProblems(string $table, array $row, ValidationProblems $problems): void
    {
        $expected = $this->registry->columns($table);
        $given = array_keys($row);

        // A column the table does not have comes first: it is the actual
        // mistake, while the missing columns are often its consequence.
        foreach (array_diff($given, $expected) as $column) {
            $problems->add(new BackupProblem(
                kind: BackupProblem::KIND_UNKNOWN_COLUMN,
                table: $table,
                rowId: $this->rowId($row),
                column: (string) $column,
                value: null,
                relatedTable: $table,
            ));
        }

        foreach (array_diff($expected, $given) as $column) {
            $problems->add(new BackupProblem(
                kind: BackupProblem::KIND_MISSING_COLUMN,
                table: $table,
                rowId: $this->rowId($row),
                column: (string) $column,
                value: null,
                relatedTable: $table,
            ));
        }
    }

    /**
     * The file must not carry the same primary key twice — which of the two
     * rows would win is undefined, so the file is refused instead of
     * silently losing one of them.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $seen
     */
    private function accumulateDuplicate(string $table, array $row, array &$seen, ValidationProblems $problems): void
    {
        $id = $this->rowId($row);

        if ($id === null) {
            return;
        }

        $key = (string) $id;

        if (isset($seen[$key])) {
            $problems->add(new BackupProblem(
                kind: BackupProblem::KIND_DUPLICATE_ROW,
                table: $table,
                rowId: $id,
                column: 'id',
                value: $id,
                relatedTable: $table,
            ));

            return;
        }

        $seen[$key] = true;
    }

    /**
     * The LIVE children of the target database have to stay valid against
     * the parent set the file brings (owner decision Q1-(b)).
     *
     * The parent values are staged in a transaction-local temp table and the
     * check is an anti-join. A chunked `whereNotIn` would be WRONG: the first
     * chunk deletes/decides without the values of the later ones.
     *
     * @param  list<mixed>  $parentValues
     */
    private function liveChildProblems(
        string $parentTable,
        ForeignKey $edge,
        array $parentValues,
        ValidationProblems $problems
    ): void {
        $temp = '_backup_parents_'.bin2hex(random_bytes(6));

        DB::statement('CREATE TEMP TABLE "'.$temp.'" (value text NOT NULL)');

        try {
            foreach (array_chunk($parentValues, self::ID_CHUNK_SIZE) as $chunk) {
                DB::table($temp)->insert(
                    array_map(
                        fn (mixed $value): array => ['value' => (string) $value],
                        $chunk
                    )
                );
            }

            $blocking = DB::table($edge->childTable)
                ->whereNotNull($edge->childColumn)
                ->whereNotExists(function ($query) use ($temp, $edge): void {
                    $query->selectRaw('1')
                        ->from($temp)
                        ->whereRaw(
                            '"'.$temp.'".value = CAST("'.$edge->childTable.'"."'.$edge->childColumn.'" AS text)'
                        );
                })
                ->limit(self::BLOCKING_ROW_LIMIT)
                ->get(['id', $edge->childColumn]);

            foreach ($blocking as $row) {
                $problems->add(new BackupProblem(
                    kind: BackupProblem::KIND_ORPHAN_CHILD,
                    table: $edge->childTable,
                    rowId: (int) $row->id,
                    column: $edge->childColumn,
                    value: $row->{$edge->childColumn},
                    relatedTable: $parentTable,
                ));
            }
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
     * The values of the parent table that exist in the target database.
     *
     * @param  array<string, mixed>  $values  key => value
     * @return array<string, true>
     */
    private function existingValues(string $parentTable, string $parentColumn, array $values): array
    {
        $existing = [];

        foreach (array_chunk(array_values($values), self::CHUNK_SIZE) as $chunk) {
            $found = DB::table($parentTable)
                ->whereIn($parentColumn, $chunk)
                ->pluck($parentColumn);

            foreach ($found as $value) {
                $existing[(string) $value] = true;
            }
        }

        return $existing;
    }

    /**
     * The id of a row, when it has one.
     *
     * @param  array<string, mixed>  $row
     */
    private function rowId(array $row): int|string|null
    {
        $id = $row['id'] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }
}
