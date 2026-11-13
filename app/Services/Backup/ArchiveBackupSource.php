<?php

namespace App\Services\Backup;

/**
 * A backup source backed by a `.zip` on disk (issue #1418).
 *
 * Every `rows()` call opens the archive entry again and streams it; nothing
 * is cached, so the validator's second pass and the writer's second pass do
 * not grow memory. Re-reading the uploaded temp file inside the restore's
 * transaction is safe — the transaction covers the writes, not the file.
 */
final readonly class ArchiveBackupSource implements BackupSource
{
    /**
     * @param  list<string>  $tables
     */
    public function __construct(
        private BackupArchiveReader $reader,
        private string $path,
        private array $tables,
    ) {}

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(string $table): iterable
    {
        return $this->reader->rows($this->path, $table);
    }
}
