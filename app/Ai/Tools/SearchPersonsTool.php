<?php

namespace App\Ai\Tools;

use App\Models\Person;
use App\Traits\PersianNormalizer;

class SearchPersonsTool extends Tool
{
    public function name(): string
    {
        return 'search_persons';
    }

    public function description(): string
    {
        return 'Search for persons in the organization by name or national code (n_code). Returns a list of matching persons with their unit and position.';
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

        return Person::query()
            ->with(['unit', 'semat', 'tahsil', 'radif'])
            ->where('f_name', 'like', "%{$query}%")
            ->orWhere('l_name', 'like', "%{$query}%")
            ->orWhere('n_code', 'like', "%{$query}%")
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
    }
}
