<?php

namespace App\Services;

use App\Models\HardwareAudit;
use App\Support\HardwareAuditChange;
use Closure;

/**
 * The single writer for bulk hardware audit rows (#1026).
 *
 * The API's bulk endpoints and the Livewire hardware index must record the
 * same `hardware_audits` row for a bulk action, or the two paths drift — the
 * web bulk delete used to write no row at all, so those devices were invisible
 * in the trash while the API's `bulk_delete` rows showed up unrestorably.
 * Keeping the writer here means "one audit row per device, in the documented
 * diff shape" holds for both paths.
 */
class HardwareBulkAuditWriter
{
    /**
     * Batch insert audit entries in a single query instead of N+1 loop.
     *
     * #927: `HardwareAudit::insert()` bypasses casts, so the column-shape
     * contract is enforced here — the only place a guard can actually bite. A
     * payload that is not the documented diff list is coerced rather than
     * rejected: a malformed audit row must never be the reason a bulk delete
     * fails.
     */
    public function batchInsertAudits($hardwares, string $action, ?array $staticChanges, ?Closure $changesPerItem = null): void
    {
        $user = \Auth::user();
        $request = request(); // actual request, not Request::capture()

        $rows = $hardwares->map(function ($hardware) use ($action, $staticChanges, $changesPerItem, $user, $request) {
            $changes = HardwareAuditChange::toDiffList(
                $changesPerItem ? $changesPerItem($hardware) : $staticChanges
            );

            return [
                'hardware_id' => $hardware->id,
                'user_id' => $user?->id,
                'action' => $action,
                'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'source' => 'bulk',
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        if (! empty($rows)) {
            HardwareAudit::insert($rows);
        }
    }
}
