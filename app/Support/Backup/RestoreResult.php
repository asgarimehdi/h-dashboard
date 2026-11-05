<?php

namespace App\Support\Backup;

/**
 * What a restore did (issue #1375).
 */
final readonly class RestoreResult
{
    /**
     * @param  bool  $full  Whether this was a full restore.
     * @param  list<string>  $tables  The tables that were restored.
     * @param  int  $rowCount  How many rows the file carried.
     */
    public function __construct(
        public bool $full,
        public array $tables,
        public int $rowCount,
    ) {}
}
