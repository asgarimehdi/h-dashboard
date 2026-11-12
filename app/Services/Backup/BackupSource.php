<?php

namespace App\Services\Backup;

/**
 * A set of backup tables whose rows can be streamed (issue #1418).
 *
 * The validator and the restore used to receive the whole archive as one
 * nested PHP array, so every table was materialised before the first check.
 * A source hands the same data over table by table, and `rows()` may be
 * called more than once (the validator reads a table, the writer reads it
 * again) without holding anything in memory.
 */
interface BackupSource
{
    /**
     * The tables this source carries.
     *
     * @return list<string>
     */
    public function tables(): array;

    /**
     * The rows of one table, one at a time.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(string $table): iterable;
}
