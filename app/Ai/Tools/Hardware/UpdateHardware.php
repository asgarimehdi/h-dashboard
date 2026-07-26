<?php

namespace App\Ai\Tools\Hardware;

use App\Models\Hardware;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateHardware implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Describe what this tool does for the LLM.
     */
    public function description(): Stringable|string
    {
        return 'Update a hardware record by its ID. You can change fields like pc_name, type, os, ip_valid, ip_local, cpu, ram, hdd, comments, shutdown status, and more. Requires human approval before applying changes.';
    }

    /**
     * Execute the update.
     */
    public function handle(Request $request): Stringable|string
    {
        $hardware = Hardware::find($request['id']);

        if (!$hardware) {
            return "Hardware record with ID {$request['id']} not found.";
        }

        $oldData = $hardware->toArray();

        $updatable = [
            'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'shutdown', 'mark', 'clean_at',
        ];

        $changes = [];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $request->all())) {
                $changes[$field] = $request[$field];
            }
        }

        if (empty($changes)) {
            return 'No valid fields provided to update.';
        }

        $hardware->update($changes);

        $changedFields = array_keys($changes);

        return json_encode([
            'success' => true,
            'message' => "Hardware record #{$hardware->id} ({$hardware->pc_name}) updated successfully.",
            'changed_fields' => $changedFields,
            'old_values' => array_intersect_key($oldData, $changes),
            'new_values' => $changes,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): \Laravel\Ai\Approvals\Approval|bool
    {
        // Always require approval for updates
        return \Laravel\Ai\Approvals\Approval::required(
            "Updating hardware record #{$request['id']}. Please confirm this change."
        );
    }

    /**
     * Schema definition for the tool's input parameters.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'pc_name' => $schema->string(),
            'type' => $schema->string(),
            'os' => $schema->string(),
            'ip_valid' => $schema->string(),
            'ip_local' => $schema->string(),
            'mac' => $schema->string(),
            'net_type' => $schema->string(),
            'switch' => $schema->string(),
            'port' => $schema->string(),
            'vlan' => $schema->string(),
            'motherboard' => $schema->string(),
            'cpu' => $schema->string(),
            'ram' => $schema->string(),
            'hdd' => $schema->string(),
            'comments' => $schema->string(),
            'shutdown' => $schema->boolean(),
            'mark' => $schema->boolean(),
        ];
    }
}
