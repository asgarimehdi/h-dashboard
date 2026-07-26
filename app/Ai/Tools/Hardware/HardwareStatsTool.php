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
        return 'Get hardware inventory statistics: total devices, breakdown by type/OS, network stats, storage configs, shutdown count. Use for questions like "how many PCs?" or "what OS distribution?".';
    }

    public function parameters(): array
    {
        return [
            'category' => [
                'type' => 'string',
                'description' => 'Stats category: "overview" (default), "type", "os", "network", or "storage"',
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $category = $arguments['category'] ?? 'overview';

        return match ($category) {
            'type' => $this->byType(),
            'os' => $this->byOs(),
            'network' => $this->networkStats(),
            'storage' => $this->storageStats(),
            default => $this->overview(),
        };
    }

    private function overview(): array
    {
        return [
            'total_devices' => Hardware::count(),
            'by_type' => Hardware::selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'shutdown_devices' => Hardware::where('shutdown', true)->count(),
            'marked_devices' => Hardware::where('mark', true)->count(),
        ];
    }

    private function byType(): array
    {
        return Hardware::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->orderByDesc('count')
            ->pluck('count', 'type')
            ->toArray();
    }

    private function byOs(): array
    {
        return Hardware::selectRaw('os, count(*) as count')
            ->groupBy('os')
            ->orderByDesc('count')
            ->pluck('count', 'os')
            ->toArray();
    }

    private function networkStats(): array
    {
        return [
            'with_valid_ip' => Hardware::whereNotNull('ip_valid')->count(),
            'with_local_ip' => Hardware::whereNotNull('ip_local')->count(),
            'with_mac' => Hardware::whereNotNull('mac')->count(),
            'with_vlan' => Hardware::whereNotNull('vlan')->count(),
            'by_network_type' => Hardware::selectRaw('net_type, count(*) as count')
                ->whereNotNull('net_type')
                ->groupBy('net_type')
                ->orderByDesc('count')
                ->pluck('count', 'net_type')
                ->toArray(),
        ];
    }

    private function storageStats(): array
    {
        return [
            'top_cpus' => Hardware::selectRaw('cpu, count(*) as count')
                ->whereNotNull('cpu')
                ->groupBy('cpu')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'cpu')
                ->toArray(),
            'top_ram_configs' => Hardware::selectRaw('ram, count(*) as count')
                ->whereNotNull('ram')
                ->groupBy('ram')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'ram')
                ->toArray(),
            'top_storage_configs' => Hardware::selectRaw('hdd, count(*) as count')
                ->whereNotNull('hdd')
                ->groupBy('hdd')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'hdd')
                ->toArray(),
        ];
    }
}
