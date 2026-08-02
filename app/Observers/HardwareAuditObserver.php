<?php

namespace App\Observers;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class HardwareAuditObserver
{
    /**
     * Handle the Hardware "created" event.
     */
    public function created(Hardware $hardware): void
    {
        $fields = [
            'pc_name', 'type', 'os', 'cpu', 'ram', 'hdd', 'net_type',
            'switch', 'port', 'vlan', 'motherboard', 'comments',
            'ip_valid', 'ip_local', 'mac', 'shutdown', 'mark', 'clean_at'
        ];
        $changes = [];
        foreach ($fields as $field) {
            $value = $hardware->getAttribute($field);
            if ($value !== null && $value !== '') {
                $changes[] = [
                    'field' => $field,
                    'old' => '—',
                    'new' => $this->formatValueForDisplay($value),
                ];
            }
        }
        $this->recordAudit($hardware, 'created', $changes ?: null, 'web');
    }

    /**
     * Handle the Hardware "updated" event.
     *
     * We use `updating` (fires BEFORE the model syncs its changes)
     * because in `updated` the dirty attributes are already cleared.
     */
    public function updating(Hardware $hardware): void
    {
        $changes = $this->getChangedFields($hardware);

        if (!empty($changes)) {
            $source = request()->routeIs('api/*') ? 'api' : 'web';
            $this->recordAudit($hardware, 'updated', $changes, $source);
        }
    }

    /**
     * Handle the Hardware "deleted" event.
     */
    public function deleting(Hardware $hardware): void
    {
        $hardwareId = $hardware->id;
        $source = request()->routeIs('api/*') ? 'api' : 'web';
        $this->recordAudit($hardware, 'deleted', null, $source, $hardwareId);
    }

    /**
     * Handle the Hardware "forceDeleted" event.
     */
    public function forceDeleted(Hardware $hardware): void
    {
        $source = request()->routeIs('api/*') ? 'api' : 'web';
        $this->recordAudit($hardware, 'force_deleted', null, $source);
    }

    /**
     * Record an audit entry for the hardware.
     */
    protected function recordAudit(Hardware $hardware, string $action, ?array $changes, string $source, ?int $hardwareId = null): void
    {
        $user = Auth::user();
        $request = Request::capture();

        HardwareAudit::create([
            'hardware_id' => $hardwareId ?? $hardware->id,
            'user_id' => $user?->id,
            'action' => $action,
            'changes' => $changes,
            'source' => $source,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * Get changed fields with old and new values.
     */
    protected function getChangedFields(Hardware $hardware): array
    {
        $dirty = $hardware->getDirty();
        $original = $hardware->getOriginal();

        $changes = [];

        foreach ($dirty as $field => $newValue) {
            // Ignore auto-managed timestamp fields
            if (in_array($field, ['updated_at', 'created_at'], true)) {
                continue;
            }

            // Use array_key_exists so null→value transitions are captured
            $oldValue = array_key_exists($field, $original) ? $original[$field] : null;

            // Normalize values for comparison
            $normalizedOld = $this->normalizeValue($oldValue);
            $normalizedNew = $this->normalizeValue($newValue);

            if ($normalizedOld !== $normalizedNew) {
                $changes[] = [
                    'field' => $field,
                    'old' => $this->formatValueForDisplay($oldValue),
                    'new' => $this->formatValueForDisplay($newValue),
                ];
            }
        }

        return $changes;
    }

    /**
     * Normalize a value for comparison.
     */
    protected function normalizeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (string) $value;
    }

    /**
     * Format a value for display in the changes log.
     */
    protected function formatValueForDisplay(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'بله' : 'خیر';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }
}
