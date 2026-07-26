<?php

namespace App\Ai\Tools\Hardware;

use App\Models\Hardware;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PersonHardware implements Tool
{
    /**
     * Describe what this tool does for the LLM.
     */
    public function description(): Stringable|string
    {
        return 'Get all hardware devices assigned to a specific person by their national code (n_code). Returns full hardware specs for each device owned by that person.';
    }

    /**
     * Execute the query.
     */
    public function handle(Request $request): Stringable|string
    {
        $nCode = $request['n_code'];

        $devices = Hardware::query()
            ->with(['person'])
            ->where('n_code', $nCode)
            ->get();

        if ($devices->isEmpty()) {
            return "No hardware records found for national code \"{$nCode}\".";
        }

        $owner = $devices->first()->person
            ? trim($devices->first()->person->f_name . ' ' . $devices->first()->person->l_name)
            : 'Unknown';

        $formatted = $devices->map(fn (Hardware $h, int $index) => [
            'device' => $index + 1,
            'pc_name' => $h->pc_name,
            'type' => $h->type,
            'os' => $h->os,
            'ip_valid' => $h->ip_valid,
            'ip_local' => $h->ip_local,
            'mac' => $h->mac,
            'net_type' => $h->net_type,
            'switch' => $h->switch,
            'port' => $h->port,
            'vlan' => $h->vlan,
            'motherboard' => $h->motherboard,
            'cpu' => $h->cpu,
            'ram' => $h->ram,
            'hdd' => $h->hdd,
            'shutdown' => $h->shutdown,
            'clean_at' => $h->clean_at?->toDateString(),
            'comments' => $h->comments,
        ]);

        return json_encode([
            'owner_name' => $owner,
            'n_code' => $nCode,
            'total_devices' => $devices->count(),
            'devices' => $formatted->values(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Schema definition for the tool's input parameters.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'n_code' => $schema->string()->required(),
        ];
    }
}
