<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Models\Hardware;

class CreateHardwareTool extends Tool
{
    public function name(): string
    {
        return 'create_hardware';
    }

    public function description(): string
    {
        return 'Create a new hardware record. Required: n_code and pc_name.';
    }

    public function parameters(): array
    {
        return [
            'n_code' => [
                'type' => 'string',
                'description' => 'National code of the owner',
                'required' => true,
            ],
            'pc_name' => [
                'type' => 'string',
                'description' => 'Name of the hardware/PC',
                'required' => true,
            ],
            'type' => ['type' => 'string', 'description' => 'Hardware type (e.g., laptop, pc)'],
            'os' => ['type' => 'string', 'description' => 'Operating system'],
            'ip_local' => ['type' => 'string', 'description' => 'Local IP'],
            'cpu' => ['type' => 'string', 'description' => 'CPU info'],
            'ram' => ['type' => 'string', 'description' => 'RAM amount'],
            'hdd' => ['type' => 'string', 'description' => 'Storage/HDD info'],
            'comments' => ['type' => 'string', 'description' => 'Additional notes'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        if (empty($arguments['n_code']) || empty($arguments['pc_name'])) {
            return "Error: n_code and pc_name are required to create a hardware record.";
        }

        $hardware = Hardware::create($arguments);

        return "Successfully created hardware: {$hardware->pc_name} for n_code {$hardware->n_code}. ID: {$hardware->id}";
    }
}