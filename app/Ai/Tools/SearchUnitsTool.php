<?php

namespace App\Ai\Tools;

use App\Models\Unit;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;

class SearchUnitsTool extends Tool
{
    public function name(): string
    {
        return 'search_units';
    }

    public function description(): string
    {
        return 'Search for organizational units by name. Returns unit hierarchy info including type, region, and parent unit. Respects organizational access scope.';
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
        $query = PersianNormalizer::normalizeForSearch($query);

        $user = auth()->user();
        $unitIds = app(AccessService::class)->accessibleUnitIds($user);

        if (empty($unitIds)) {
            return 'No accessible units found for your account.';
        }

        $results = Unit::query()
            ->with(['unitType', 'region', 'parent'])
            ->withCount('person')
            ->whereIn('id', $unitIds)
            ->where('name', 'like', "%{$query}%")
            ->limit(20)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'type' => $u->unitType?->name,
                'region' => $u->region?->name,
                'parent' => $u->parent?->name,
                'persons_count' => $u->person_count,
            ]);

        if ($results->isEmpty()) {
            return "No results for \"{$query}\" within your access scope.";
        }

        return $results->toArray();
    }
}