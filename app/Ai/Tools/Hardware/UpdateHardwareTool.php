<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Models\Hardware;

class UpdateHardwareTool extends Tool
{
    public function name(): string
    {
        return 'update_hardware';
    }

    public function description(): string
    {
        return 'Update a hardware record by its ID. You can change fields like pc_name, type, os, ip_valid, ip_local, cpu, ram, hdd, comments, shutdown status, and more.';
    }

    public function parameters(): array
    {
        return [
            'id' => [
                'type' => 'integer',
                'description' => 'Hardware record ID',
                'required' => true,
            ],
            'pc_name' => ['type' => 'string', 'description' => 'PC/hostname'],
            'type' => ['type' => 'string', 'description' => 'Device type (desktop, laptop, server)'],
            'os' => ['type' => 'string', 'description' => 'Operating system'],
            'ip_valid' => ['type' => 'string', 'description' => 'Public/valid IP address'],
            'ip_local' => ['type' => 'string', 'description' => 'Local/private IP address'],
            'mac' => ['type' => 'string', 'description' => 'MAC address'],
            'net_type' => ['type' => 'string', 'description' => 'Network type'],
            'switch' => ['type' => 'string', 'description' => 'Network switch name'],
            'port' => ['type' => 'string', 'description' => 'Switch port number'],
            'vlan' => ['type' => 'string', 'description' => 'VLAN identifier'],
            'motherboard' => ['type' => 'string', 'description' => 'Motherboard model'],
            'cpu' => ['type' => 'string', 'description' => 'Processor info'],
            'ram' => ['type' => 'string', 'description' => 'RAM size/info'],
            'hdd' => ['type' => 'string', 'description' => 'Storage info'],
            'comments' => ['type' => 'string', 'description' => 'Free-text notes'],
            'shutdown' => ['type' => 'boolean', 'description' => 'Whether device is shut down'],
            'mark' => ['type' => 'boolean', 'description' => 'Flag/mark'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $id = $arguments['id'] ?? null;

        $hardware = Hardware::find($id);
        if (!$hardware) {
            return "Hardware record with ID {$id} not found.";
        }

        $oldData = $hardware->toArray();

        $updatable = [
            'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'shutdown', 'mark',
        ];

        $changes = [];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null) {
                $changes[$field] = $arguments[$field];
            }
        }

        if (empty($changes)) {
            return 'No valid fields provided to update.';
        }

        $hardware->update($changes);

        return [
            'success' => true,
            'message' => "Hardware record #{$hardware->id} ({$hardware->pc_name}) updated successfully.",
            'changed_fields' => array_keys($changes),
            'old_values' => array_intersect_key($oldData, $changes),
            'new_values' => $changes,
        ];
    }
}
