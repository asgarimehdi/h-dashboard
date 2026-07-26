<?php

namespace App\Ai\Tools;

use App\Models\Todo;

class GetTodosTool extends Tool
{
    public function name(): string
    {
        return 'get_todos';
    }

    public function description(): string
    {
        return 'Get todo tasks. Filter by completion status. Shows title, dates, and associated unit.';
    }

    public function parameters(): array
    {
        return [
            'completed' => [
                'type' => 'boolean',
                'description' => 'Filter by completion: true for completed, false for pending, omit for all',
                'required' => false,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of results (default 10, max 50)',
                'required' => false,
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $query = Todo::query()
            ->with(['unit'])
            ->orderByDesc('created_at');

        if (array_key_exists('completed', $arguments)) {
            $query->where('is_completed', $arguments['completed']);
        }

        $limit = min((int) ($arguments['limit'] ?? 10), 50);

        return $query->limit($limit)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'is_completed' => $t->is_completed,
                'start_at' => $t->start_at?->toIso8601String(),
                'end_at' => $t->end_at?->toIso8601String(),
                'unit' => $t->unit?->name,
            ]);
    }
}
