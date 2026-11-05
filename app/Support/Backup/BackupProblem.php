<?php

namespace App\Support\Backup;

/**
 * One problem found while validating a backup file (issue #1375).
 *
 * Every problem names the table it was found in, the row it belongs to, the
 * column that carries the value and — for a reference problem — the table
 * that has to be restored first. The restore refuses the whole file when it
 * finds even one of them.
 */
final readonly class BackupProblem
{
    /**
     * The file carries a column the table does not have.
     */
    public const KIND_UNKNOWN_COLUMN = 'unknown_column';

    /**
     * The file misses a column the table has.
     */
    public const KIND_MISSING_COLUMN = 'missing_column';

    /**
     * The file carries the same primary key twice.
     */
    public const KIND_DUPLICATE_ROW = 'duplicate_row';

    /**
     * The file points at a parent row that does not exist.
     */
    public const KIND_MISSING_REFERENCE = 'missing_reference';

    /**
     * The file needs a parent table that the archive does not carry.
     */
    public const KIND_MISSING_TABLE = 'missing_table';

    /**
     * A LIVE row of the target database points at a parent row the file does
     * not carry — restoring the file would orphan it.
     */
    public const KIND_ORPHAN_CHILD = 'orphan_child';

    public function __construct(
        public string $kind,
        public string $table,
        public int|string|null $rowId,
        public string $column,
        public mixed $value,
        public string $relatedTable,
    ) {}
}
