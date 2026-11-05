<?php

namespace App\Support\Backup;

/**
 * One foreign-key edge of the backup registry (issue #1375).
 *
 * The REFERENCED column is part of the edge on purpose (expert correction
 * C7): `users.n_code` points at `persons.n_code`, not at `persons.id`. A graph
 * that only knows the parent TABLE would validate every `users` row against
 * the wrong key and reject valid backups.
 */
final readonly class ForeignKey
{
    public function __construct(
        public string $childTable,
        public string $childColumn,
        public string $parentTable,
        public string $parentColumn,
        public string $onDelete,
    ) {}
}
