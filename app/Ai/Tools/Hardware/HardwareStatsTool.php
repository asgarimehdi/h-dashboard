<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Ai\Traits\AiAccessScope;
use App\Models\Hardware;

class HardwareStatsTool extends Tool
{
    use AiAccessScope;

    public function name(): string
    {
        return 'hardware_stats';
    }

    public function description(): string
    {
        return 'Get hardware stats: total count, by type, by OS, shutdown count. Respects organizational access scope.';
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

        $baseQuery = $this->scopedHardwareQuery();

        if ($category === 'type') {
            return (clone $baseQuery)->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->orderByDesc('count')
                ->pluck('count', 'type')
                ->toArray();
        }

        if ($category === 'os') {
            return (clone $baseQuery)->selectRaw('os, count(*) as count')
                ->groupBy('os')
                ->orderByDesc('count')
                ->pluck('count', 'os')
                ->toArray();
        }

        return [
            'total' => (clone $baseQuery)->count(),
            'by_type' => (clone $baseQuery)->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'shutdown' => (clone $baseQuery)->where('shutdown', true)->count(),
        ];
    }
}