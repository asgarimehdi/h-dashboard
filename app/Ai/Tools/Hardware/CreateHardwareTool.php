<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Ai\Traits\AiAccessScope;
use App\Models\Hardware;
use App\Models\Person;

class CreateHardwareTool extends Tool
{
    use AiAccessScope;

    public function name(): string
    {
        return 'create_hardware';
    }

    public function description(): string
    {
        return 'Create a new hardware record. Required: n_code and pc_name. The person must be within your organizational scope.';
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

        // Check if the person exists and is within the user's organizational scope
        $person = Person::where('n_code', $arguments['n_code'])
            ->whereHas('unit', function ($query) {
                $unitIds = app(\App\Services\AccessService::class)->accessibleUnitIds();
                $query->whereIn('units.id', $unitIds);
            })
            ->first();

        if (!$person) {
            return "Person with n_code {$arguments['n_code']} not found or not within your organizational scope.";
        }

        $hardware = Hardware::create($arguments);

        return "Successfully created hardware: {$hardware->pc_name} for n_code {$hardware->n_code}. ID: {$hardware->id}";
    }
}