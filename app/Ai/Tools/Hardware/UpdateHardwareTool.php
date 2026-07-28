<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Ai\Traits\AiAccessScope;
use App\Models\Hardware;

class UpdateHardwareTool extends Tool
{
    use AiAccessScope;

    public function name(): string
    {
        return 'update_hardware';
    }

    public function description(): string
    {
        return 'Update hardware record fields by id. Respects organizational access scope.';
    }

    public function parameters(): array
    {
        return [
            'id' => [
                'type' => 'integer',
                'description' => 'Hardware record ID',
                'required' => true,
            ],
            'pc_name' => ['type' => 'string', 'description' => 'PC name'],
            'os' => ['type' => 'string', 'description' => 'Operating system'],
            'ip_valid' => ['type' => 'string', 'description' => 'Public IP'],
            'ip_local' => ['type' => 'string', 'description' => 'Local IP'],
            'cpu' => ['type' => 'string', 'description' => 'CPU'],
            'ram' => ['type' => 'string', 'description' => 'RAM'],
            'hdd' => ['type' => 'string', 'description' => 'Storage'],
            'comments' => ['type' => 'string', 'description' => 'Notes'],
            'shutdown' => ['type' => 'boolean', 'description' => 'Shutdown status'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $hardware = $this->scopedHardwareQuery()->find($arguments['id'] ?? null);
        if (!$hardware) {
            return "Hardware #{$arguments['id']} not found or access denied.";
        }

        $updatable = ['pc_name', 'os', 'ip_valid', 'ip_local', 'cpu', 'ram', 'hdd', 'comments', 'shutdown'];
        $changes = array_filter($arguments, fn ($k) => in_array($k, $updatable) && $k !== 'id', ARRAY_FILTER_USE_KEY);

        if (empty($changes)) {
            return 'No fields to update.';
        }

        $hardware->update($changes);

        return "Updated {$hardware->pc_name}: " . implode(', ', array_keys($changes));
    }
}