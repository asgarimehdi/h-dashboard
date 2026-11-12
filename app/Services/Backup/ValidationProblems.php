<?php

namespace App\Services\Backup;

use App\Exceptions\BackupValidationException;
use App\Support\Backup\BackupProblem;

/**
 * Collects validation problems while keeping a hard cap (issue #1418).
 *
 * A malformed file can produce one problem per row — 20 million rows would
 * mean 20 million problem objects held in memory even though the rows
 * themselves are streamed. The first `MAX_PROBLEMS` problems are kept for the
 * message and the caller, while `count()` stays the true total so the
 * exception can still say "and N more".
 */
final class ValidationProblems
{
    /**
     * How many problem objects are retained.
     */
    public const MAX_PROBLEMS = 100;

    /**
     * @var list<BackupProblem>
     */
    private array $problems = [];

    private int $total = 0;

    public function add(BackupProblem $problem): void
    {
        $this->total++;

        if (count($this->problems) < self::MAX_PROBLEMS) {
            $this->problems[] = $problem;
        }
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * @return list<BackupProblem>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /**
     * @throws BackupValidationException
     */
    public function throwIfAny(): void
    {
        if ($this->isEmpty()) {
            return;
        }

        throw BackupValidationException::fromProblems($this->problems, $this->total);
    }
}
