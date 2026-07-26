<?php

namespace App\Ai\Tools\Hardware;

use App\Models\Hardware;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchHardware implements Tool
{
    /**
     * Describe what this tool does for the LLM.
     */
    public function description(): Stringable|string
    {
        return 'Search hardware inventory by PC name, IP address, MAC address, owner national code (n_code), or any keyword. Returns matching hardware records with specs and owner info.';
    }

    /**
     * Execute the search query.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = $request['query'];

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
            ->orWhere('comments', 'like', "%{$query}%")
            ->limit(20)
            ->get();

        if ($results->isEmpty()) {
            return "No hardware records found matching \"{$query}\".";
        }

        $formatted = $results->map(fn (Hardware $h) => [
            'id' => $h->id,
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
            'mark' => $h->mark,
            'clean_at' => $h->clean_at?->toDateString(),
            'comments' => $h->comments,
            'owner' => $h->person
                ? trim($h->person->f_name . ' ' . $h->person->l_name)
                : null,
            'owner_n_code' => $h->n_code,
        ]);

        return $formatted->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Schema definition for the tool's input parameters.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }
}
