<?php

namespace App\Services\Backup;

use App\Exceptions\BackupValidationException;
use App\Support\Backup\BackupProblem;
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
 */
class BackupImportValidator
{
    /**
     * How many values are compared with the database per query.
     */
    private const CHUNK_SIZE = 500;

    /**
     * How many blocking rows of one child table are reported.
     */
    private const BLOCKING_ROW_LIMIT = 50;

    public function __construct(private readonly TableRegistry $registry) {}

    /**
     * Validate the rows of ONE table against the live database.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws BackupValidationException
     */
    public function validateTable(string $table, array $rows): void
    {
        if (! $this->registry->isRegistered($table)) {
            throw new BackupValidationException("جدول «{$table}» در رجیستری بک‌آپ نیست.");
        }

        $problems = $this->columnProblems($table, $rows);
        $problems = array_merge($problems, $this->duplicateProblems($table, $rows));
        $problems = array_merge($problems, $this->missingReferenceProblems($table, $rows));
        $problems = array_merge($problems, $this->liveChildProblems($table, $rows));

        if ($problems !== []) {
            throw BackupValidationException::fromProblems($problems);
        }
    }

    /**
     * Validate the tables of a FULL backup against each other.
     *
     * A full restore replaces every registered table, so the file has to hold
     * the parents its children point at — the target database is irrelevant
     * here, it is about to be replaced.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     *
     * @throws BackupValidationException
     */
    public function validateFullBackup(array $tables): void
    {
        $problems = [];

        foreach ($tables as $table => $rows) {
            $problems = array_merge($problems, $this->columnProblems((string) $table, $rows));
            $problems = array_merge($problems, $this->duplicateProblems((string) $table, $rows));
        }

        foreach ($tables as $table => $rows) {
            $problems = array_merge($problems, $this->fileReferenceProblems((string) $table, $rows, $tables));
        }

        if ($problems !== []) {
            throw BackupValidationException::fromProblems($problems);
        }
    }

    /**
     * The file has to carry exactly the columns of the table.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<BackupProblem>
     */
    private function columnProblems(string $table, array $rows): array
    {
        $expected = $this->registry->columns($table);
        $problems = [];

        foreach ($rows as $row) {
            $given = array_keys($row);

            // A column the table does not have comes first: it is the actual
            // mistake, while the missing columns are often its consequence.
            foreach (array_diff($given, $expected) as $column) {
                $problems[] = new BackupProblem(
                    kind: BackupProblem::KIND_UNKNOWN_COLUMN,
                    table: $table,
                    rowId: $this->rowId($row),
                    column: (string) $column,
                    value: null,
                    relatedTable: $table,
                );
            }

            foreach (array_diff($expected, $given) as $column) {
                $problems[] = new BackupProblem(
                    kind: BackupProblem::KIND_MISSING_COLUMN,
                    table: $table,
                    rowId: $this->rowId($row),
                    column: (string) $column,
                    value: null,
                    relatedTable: $table,
                );
            }
        }

        return $problems;
    }

    /**
     * The file must not carry the same primary key twice — which of the two
     * rows would win is undefined, so the file is refused instead of
     * silently losing one of them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<BackupProblem>
     */
    private function duplicateProblems(string $table, array $rows): array
    {
        $seen = [];
        $problems = [];

        foreach ($rows as $row) {
            $id = $this->rowId($row);

            if ($id === null) {
                continue;
            }

            $key = (string) $id;

            if (isset($seen[$key])) {
                $problems[] = new BackupProblem(
                    kind: BackupProblem::KIND_DUPLICATE_ROW,
                    table: $table,
                    rowId: $id,
                    column: 'id',
                    value: $id,
                    relatedTable: $table,
                );

                continue;
            }

            $seen[$key] = true;
        }

        return $problems;
    }

    /**
     * Every foreign-key value of the file has to exist in the target
     * database.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<BackupProblem>
     */
    private function missingReferenceProblems(string $table, array $rows): array
    {
        $problems = [];

        foreach ($this->registry->foreignKeysOf($table) as $edge) {
            $values = $this->distinctValues($rows, $edge->childColumn);

            if ($values === []) {
                continue;
            }

            $existing = $this->existingValues($edge->parentTable, $edge->parentColumn, $values);

            foreach ($values as $key => $value) {
                if (isset($existing[$key])) {
                    continue;
                }

                $problems[] = new BackupProblem(
                    kind: BackupProblem::KIND_MISSING_REFERENCE,
                    table: $table,
                    rowId: $this->firstRowId($rows, $edge->childColumn, $value),
                    column: $edge->childColumn,
                    value: $value,
                    relatedTable: $edge->parentTable,
                );
            }
        }

        return $problems;
    }

    /**
     * The LIVE children of the target database have to stay valid against
     * the parent set the file brings (owner decision Q1-(b)).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<BackupProblem>
     */
    private function liveChildProblems(string $table, array $rows): array
    {
        $problems = [];

        foreach ($this->registry->childForeignKeysOf($table) as $edge) {
            if (! $this->registry->isRegistered($edge->childTable)) {
                continue;
            }

            $parentValues = array_keys($this->distinctValues($rows, $edge->parentColumn));

            $blocking = DB::table($edge->childTable)
                ->whereNotNull($edge->childColumn)
                ->whereNotIn($edge->childColumn, $parentValues)
                ->limit(self::BLOCKING_ROW_LIMIT)
                ->get(['id', $edge->childColumn]);

            foreach ($blocking as $row) {
                $problems[] = new BackupProblem(
                    kind: BackupProblem::KIND_ORPHAN_CHILD,
                    table: $edge->childTable,
                    rowId: (int) $row->id,
                    column: $edge->childColumn,
                    value: $row->{$edge->childColumn},
                    relatedTable: $table,
                );
            }
        }

        return $problems;
    }

    /**
     * Every foreign-key value of the file has to exist in the file itself.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return list<BackupProblem>
     */
    private function fileReferenceProblems(string $table, array $rows, array $tables): array
    {
        $problems = [];

        foreach ($this->registry->foreignKeysOf($table) as $edge) {
            if (! array_key_exists($edge->parentTable, $tables)) {
                $problems[] = new BackupProblem(
                    kind: BackupProblem::KIND_MISSING_TABLE,
                    table: $table,
                    rowId: null,
                    column: $edge->childColumn,
                    value: null,
                    relatedTable: $edge->parentTable,
                );

                continue;
            }

            $parentValues = $this->distinctValues($tables[$edge->parentTable], $edge->parentColumn);

            foreach ($rows as $row) {
                $value = $row[$edge->childColumn] ?? null;

                if ($value === null) {
                    continue;
                }

                if (isset($parentValues[(string) $value])) {
                    continue;
                }

                $problems[] = new BackupProblem(
                    kind: BackupProblem::KIND_MISSING_REFERENCE,
                    table: $table,
                    rowId: $this->rowId($row),
                    column: $edge->childColumn,
                    value: $value,
                    relatedTable: $edge->parentTable,
                );
            }
        }

        return $problems;
    }

    /**
     * The distinct, non-null values of a column.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed> value => value
     */
    private function distinctValues(array $rows, string $column): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $row[$column] ?? null;

            if ($value === null) {
                continue;
            }

            $values[(string) $value] = $value;
        }

        return $values;
    }

    /**
     * The values of the parent table that exist in the target database.
     *
     * @param  array<string, mixed>  $values
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
     * The id of the first row that carries a value.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function firstRowId(array $rows, string $column, mixed $value): int|string|null
    {
        foreach ($rows as $row) {
            if (($row[$column] ?? null) === $value) {
                return $this->rowId($row);
            }
        }

        return null;
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
