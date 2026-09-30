<?php

namespace App\Services\Zabbix;

/**
 * Typed outcome of one Zabbix call (#741).
 *
 * The controllers used to catch \Throwable around raw service calls, so a
 * connection problem was an exception travelling through HTTP code. This
 * result object makes the failure an ordinary value: `failed()` + a failure
 * kind the controller maps to its (unchanged) 503 contract.
 *
 * Failure kinds: `timeout`, `connection`, `invalid_response`, `error`.
 */
final class ZabbixResult
{
    /**
     * @param  mixed  $data  payload when successful
     * @param  string|null  $failure  one of timeout|connection|invalid_response|error
     */
    private function __construct(
        private readonly bool $ok,
        private readonly mixed $data,
        private readonly ?string $failure,
        private readonly ?string $message,
    ) {}

    public static function success(mixed $data): self
    {
        return new self(true, $data, null, null);
    }

    public static function fail(string $failure, string $message): self
    {
        return new self(false, null, $failure, $message);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}
