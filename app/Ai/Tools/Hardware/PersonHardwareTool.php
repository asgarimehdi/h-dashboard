<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Models\Hardware;

class PersonHardwareTool extends Tool
{
    public function name(): string
    {
        return 'person_hardware';
    }

    public function description(): string
    {
        return 'Get all hardware devices assigned to a specific person by their national code (n_code). Returns full hardware specs for each device.';
    }

    public function parameters(): array
    {
        return [
            'n_code' => [
                'type' => 'string',
                'description' => 'Person national code (کد ملی)',
                'required' => true,
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $nCode = $arguments['n_code'] ?? '';

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

        return [
            'owner_name' => $owner,
            'n_code' => $nCode,
            'total_devices' => $devices->count(),
            'devices' => $devices->map(fn (Hardware $h, int $index) => [
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
            ])->values(),
        ];
    }
}
