<?php

namespace App\Ai\Tools;

use Closure;

abstract class Tool
{
    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function parameters(): array;

    abstract public function execute(array $arguments): mixed;

    public function toFunction(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $this->parameters(),
                    'required' => array_keys(array_filter($this->parameters(), fn ($p) => $p['required'] ?? false)),
                ],
            ],
        ];
    }
}
