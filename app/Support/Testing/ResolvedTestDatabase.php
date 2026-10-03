<?php

namespace App\Support\Testing;

/**
 * The connection the test suite will actually use, as an immutable value.
 */
class ResolvedTestDatabase
{
    public function __construct(
        public readonly string $driver,
        public readonly string $database,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password = '',
    ) {}

    /**
     * A human-readable DSN for error messages, without the password — a message
     * that has to be copied into a terminal should never leak a secret.
     */
    public function describe(): string
    {
        $target = $this->port > 0 ? "{$this->host}:{$this->port}" : $this->host;

        return "{$this->driver}://{$this->username}@{$target}/{$this->database}";
    }
}
