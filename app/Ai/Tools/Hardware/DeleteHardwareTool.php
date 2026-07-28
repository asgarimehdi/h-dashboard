<?php

namespace App\Ai\Tools\Hardware;

use App\Ai\Tools\Tool;
use App\Ai\Traits\AiAccessScope;
use App\Models\Hardware;

class DeleteHardwareTool extends Tool
{
    use AiAccessScope;

    public function name(): string
    {
        return 'delete_hardware';
    }

    public function description(): string
    {
        return 'Delete a hardware record by ID. Use with caution. Respects organizational access scope.';
    }

    public function parameters(): array
    {
        return [
            'id' => [
                'type' => 'integer',
                'description' => 'Hardware record ID',
                'required' => true,
            ],
            'confirm' => [
                'type' => 'boolean',
                'description' => 'Must be true to confirm deletion',
                'required' => true,
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $id = $arguments['id'] ?? null;

        if (!$id) {
            return 'Hardware ID is required.';
        }

        if (!($arguments['confirm'] ?? false)) {
            return 'Deletion requires confirmation (confirm=true).';
        }

        $hardware = $this->scopedHardwareQuery()->find($id);

        if (!$hardware) {
            return "Hardware #{$id} not found or access denied.";
        }

        $name = $hardware->pc_name;
        $hardware->delete();

        return "Deleted hardware #{$id} ({$name}).";
    }
}