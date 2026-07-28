<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Ai\Traits\AiAccessScope;
use App\Models\Hardware;

class PersonHardwareTool extends Tool
{
    use AiAccessScope;

    public function name(): string
    {
        return 'person_hardware';
    }

    public function description(): string
    {
        return 'Get all hardware for a person by n_code. Respects organizational access scope.';
    }

    public function parameters(): array
    {
        return [
            'n_code' => [
                'type' => 'string',
                'description' => 'National code',
                'required' => true,
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $nCode = $arguments['n_code'] ?? '';

        $devices = $this->scopedHardwareQuery()
            ->where('n_code', $nCode)
            ->get();

        if ($devices->isEmpty()) {
            return "No hardware found for n_code {$nCode} within your access scope.";
        }

        $owner = $devices->first()->person
            ? trim($devices->first()->person->f_name . ' ' . $devices->first()->person->l_name)
            : 'Unknown';

        return [
            'owner' => $owner,
            'devices' => $devices->map(fn (Hardware $h) => [
                'pc_name' => $h->pc_name,
                'type' => $h->type,
                'os' => $h->os,
                'ip_valid' => $h->ip_valid,
                'ip_local' => $h->ip_local,
                'cpu' => $h->cpu,
                'ram' => $h->ram,
                'hdd' => $h->hdd,
            ])->values(),
        ];
    }
}