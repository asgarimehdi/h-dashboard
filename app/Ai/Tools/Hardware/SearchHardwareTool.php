<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Models\Hardware;

class SearchHardwareTool extends Tool
{
    public function name(): string
    {
        return 'search_hardware';
    }

    public function description(): string
    {
        return 'Search hardware by pc_name, ip, mac, n_code, cpu, ram, hdd, os, or type.';
    }

    public function parameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Search term',
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $query = $arguments['query'] ?? '';

        $results = Hardware::query()
            ->with(['person'])
            ->where('pc_name', 'like', "%{$query}%")
            ->orWhere('ip_valid', 'like', "%{$query}%")
            ->orWhere('ip_local', 'like', "%{$query}%")
            ->orWhere('mac', 'like', "%{$query}%")
            ->orWhere('n_code', 'like', "%{$query}%")
            ->orWhere('cpu', 'like', "%{$query}%")
            ->orWhere('ram', 'like', "%{$query}%")
            ->orWhere('hdd', 'like', "%{$query}%")
            ->orWhere('os', 'like', "%{$query}%")
            ->orWhere('type', 'like', "%{$query}%")
            ->limit(20)
            ->get();

        if ($results->isEmpty()) {
            return "No results for \"{$query}\".";
        }

        return $results->map(fn (Hardware $h) => [
            'id' => $h->id,
            'pc_name' => $h->pc_name,
            'type' => $h->type,
            'os' => $h->os,
            'ip_valid' => $h->ip_valid,
            'ip_local' => $h->ip_local,
            'mac' => $h->mac,
            'cpu' => $h->cpu,
            'ram' => $h->ram,
            'hdd' => $h->hdd,
            'shutdown' => $h->shutdown,
            'owner' => $h->person ? trim($h->person->f_name . ' ' . $h->person->l_name) : null,
        ]);
    }
}
