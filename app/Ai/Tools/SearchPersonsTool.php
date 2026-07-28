<?php

namespace App\Ai\Tools;

use App\Models\Person;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;

class SearchPersonsTool extends Tool
{
    public function name(): string
    {
        return 'search_persons';
    }

    public function description(): string
    {
        return 'Search for persons in the organization by name or national code (n_code). Returns a list of matching persons with their unit and position. Respects organizational access scope.';
    }

    public function parameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Search term — matches first name, last name, or national code',
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

        $results = Person::query()
            ->with(['unit', 'semat', 'tahsil', 'radif'])
            ->whereIn('u_id', $unitIds)
            ->where(function ($q) use ($query) {
                $q->where('f_name', 'like', "%{$query}%")
                  ->orWhere('l_name', 'like', "%{$query}%")
                  ->orWhere('n_code', 'like', "%{$query}%");
            })
            ->limit(20)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'n_code' => $p->n_code,
                'name' => trim($p->f_name . ' ' . $p->l_name),
                'unit' => $p->unit?->name,
                'semat' => $p->semat?->name,
                'tahsil' => $p->tahsil?->name,
            ]);

        if ($results->isEmpty()) {
            return "No results for \"{$query}\" within your access scope.";
        }

        return $results->toArray();
    }
}