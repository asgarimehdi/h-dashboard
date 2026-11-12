<?php

namespace App\Services\Backup;

/**
 * A backup source backed by an in-memory array (issue #1418).
 *
 * Production restores always use {@see ArchiveBackupSource}; this adapter
 * exists so the validator and the restore keep accepting the array shape the
 * existing tests (and any caller that already holds rows) use.
 */
final readonly class ArrayBackupSource implements BackupSource
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $tables
     */
    public function __construct(private array $tables) {}

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_map('strval', array_keys($this->tables));
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(string $table): iterable
    {
        return $this->tables[$table] ?? [];
    }
}
