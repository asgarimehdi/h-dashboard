<?php

namespace App\Ai\Tools;

use App\Models\Hardware;
use App\Traits\PersianNormalizer;

class GetHardwareTool extends Tool
{
    public function name(): string
    {
        return 'get_hardware';
    }

    public function description(): string
    {
        return 'Search hardware inventory by PC name, IP address, or owner national code. Returns hardware specs including CPU, RAM, HDD, IP, and OS.';
    }

    public function parameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Search term — matches pc_name, ip_valid, ip_local, mac, or owner n_code',
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $query = $arguments['query'] ?? '';
        $query = PersianNormalizer::normalizeForSearch($query);

        return Hardware::query()
            ->with(['person'])
            ->where('pc_name', 'like', "%{$query}%")
            ->orWhere('ip_valid', 'like', "%{$query}%")
            ->orWhere('ip_local', 'like', "%{$query}%")
            ->orWhere('mac', 'like', "%{$query}%")
            ->orWhere('n_code', 'like', "%{$query}%")
            ->limit(20)
            ->get()
            ->map(fn ($h) => [
                'pc_name' => $h->pc_name,
                'type' => $h->type,
                'os' => $h->os,
                'ip_valid' => $h->ip_valid,
                'ip_local' => $h->ip_local,
                'cpu' => $h->cpu,
                'ram' => $h->ram,
                'hdd' => $h->hdd,
                'owner' => $h->person ? trim($h->person->f_name . ' ' . $h->person->l_name) : null,
            ]);
    }
}
