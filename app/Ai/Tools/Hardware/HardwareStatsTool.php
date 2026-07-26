<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Models\Hardware;

class HardwareStatsTool extends Tool
{
    public function name(): string
    {
        return 'hardware_stats';
    }

    public function description(): string
    {
        return 'Get hardware stats: total count, by type, by OS, shutdown count.';
    }

    public function parameters(): array
    {
        return [
            'category' => [
                'type' => 'string',
                'description' => 'overview, type, or os',
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $category = $arguments['category'] ?? 'overview';

        if ($category === 'type') {
            return Hardware::selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->orderByDesc('count')
                ->pluck('count', 'type')
                ->toArray();
        }

        if ($category === 'os') {
            return Hardware::selectRaw('os, count(*) as count')
                ->groupBy('os')
                ->orderByDesc('count')
                ->pluck('count', 'os')
                ->toArray();
        }

        return [
            'total' => Hardware::count(),
            'by_type' => Hardware::selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'shutdown' => Hardware::where('shutdown', true)->count(),
        ];
    }
}
