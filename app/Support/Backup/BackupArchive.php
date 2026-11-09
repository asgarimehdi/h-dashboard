<?php

namespace App\Support\Backup;

/**
 * A backup archive that has been written to disk and is ready to be sent to
 * the browser (issue #1375).
 */
final readonly class BackupArchive
{
    /**
     * @param  string  $path  Absolute path of the `.zip` on disk.
     * @param  string  $filename  The name the browser receives.
     * @param  list<array{table: string, rows: int}>  $tables  The archived tables and their row counts.
     */
    public function __construct(
        public string $path,
        public string $filename,
        public array $tables,
    ) {}

    /**
     * The total number of exported rows.
     */
    public function rowCount(): int
    {
        return array_sum(array_column($this->tables, 'rows'));
    }
}
