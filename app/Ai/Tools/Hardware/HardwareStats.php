<?php

namespace App\Ai\Tools\Hardware;

use App\Models\Hardware;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class HardwareStats implements Tool
{
    /**
     * Describe what this tool does for the LLM.
     */
    public function description(): Stringable|string
    {
        return 'Get hardware inventory statistics and summaries. Shows counts by type, OS, and other breakdowns. Use this to answer questions like "how many PCs do we have?" or "what OS distribution is used?".';
    }

    /**
     * Execute the stats query.
     */
    public function handle(Request $request): Stringable|string
    {
        $category = $request['category'] ?? 'overview';

        return match ($category) {
            'type' => $this->byType(),
            'os' => $this->byOs(),
            'network' => $this->networkStats(),
            'storage' => $this->storageStats(),
            default => $this->overview(),
        };
    }

    private function overview(): string
    {
        $total = Hardware::count();
        $byType = Hardware::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');
        $shutdownCount = Hardware::where('shutdown', true)->count();
        $markedCount = Hardware::where('mark', true)->count();

        return json_encode([
            'total_devices' => $total,
            'by_type' => $byType,
            'shutdown_devices' => $shutdownCount,
            'marked_devices' => $markedCount,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function byType(): string
    {
        $stats = Hardware::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->orderByDesc('count')
            ->pluck('count', 'type');

        return $stats->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function byOs(): string
    {
        $stats = Hardware::selectRaw('os, count(*) as count')
            ->groupBy('os')
            ->orderByDesc('count')
            ->pluck('count', 'os');

        return $stats->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function networkStats(): string
    {
        $withValidIp = Hardware::whereNotNull('ip_valid')->count();
        $withLocalIp = Hardware::whereNotNull('ip_local')->count();
        $withMac = Hardware::whereNotNull('mac')->count();
        $withVlan = Hardware::whereNotNull('vlan')->count();

        $byNetType = Hardware::selectRaw('net_type, count(*) as count')
            ->whereNotNull('net_type')
            ->groupBy('net_type')
            ->orderByDesc('count')
            ->pluck('count', 'net_type');

        return json_encode([
            'with_valid_ip' => $withValidIp,
            'with_local_ip' => $withLocalIp,
            'with_mac' => $withMac,
            'with_vlan' => $withVlan,
            'by_network_type' => $byNetType,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function storageStats(): string
    {
        $byCpu = Hardware::selectRaw('cpu, count(*) as count')
            ->whereNotNull('cpu')
            ->groupBy('cpu')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'cpu');

        $byRam = Hardware::selectRaw('ram, count(*) as count')
            ->whereNotNull('ram')
            ->groupBy('ram')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'ram');

        $byHdd = Hardware::selectRaw('hdd, count(*) as count')
            ->whereNotNull('hdd')
            ->groupBy('hdd')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'hdd');

        return json_encode([
            'top_cpus' => $byCpu,
            'top_ram_configs' => $byRam,
            'top_storage_configs' => $byHdd,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Schema definition for the tool's input parameters.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->enum([
                'overview', 'type', 'os', 'network', 'storage',
            ]),
        ];
    }
}
