<?php

namespace App\Ai\Tools;

use App\Models\Unit;

class SearchUnitsTool extends Tool
{
    public function name(): string
    {
        return 'search_units';
    }

    public function description(): string
    {
        return 'Search for organizational units by name. Returns unit hierarchy info including type, region, and parent unit.';
    }

    public function parameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Search term — matches unit name',
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $query = $arguments['query'] ?? '';

        return Unit::query()
            ->with(['unitType', 'region', 'parent'])
            ->where('name', 'like', "%{$query}%")
            ->limit(20)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'type' => $u->unitType?->name,
                'region' => $u->region?->name,
                'parent' => $u->parent?->name,
                'persons_count' => $u->persons()->count(),
            ]);
    }
}
