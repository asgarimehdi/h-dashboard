<?php

namespace App\Services;

use App\Models\HardwareAudit;
use App\Support\ExcelCell;
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
     *
     * #1167: a queued import has no request and no authenticated user, but its
     * audit trail must still name the actor who dispatched it. Passing a
     * non-null `$source` selects that explicit-context path: `$userId`,
     * `$ip` and `$userAgent` are written as given (null is a legitimate
     * value), and the request/Auth fallback is skipped entirely. Omitting
     * `$source` keeps the original web/API behaviour — `source = 'bulk'`,
     * `Auth::user()` and the current request — unchanged.
     */
    public function batchInsertAudits(
        $hardwares,
        string $action,
        ?array $staticChanges,
        ?Closure $changesPerItem = null,
        ?int $userId = null,
        ?string $source = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $explicit = $source !== null;
        $user = $explicit ? null : \Auth::user();
        $request = $explicit ? null : request(); // actual request, not Request::capture()
        $resolvedUserAgent = $explicit ? $userAgent : $request?->userAgent();

        $rows = $hardwares->map(function ($hardware) use ($action, $staticChanges, $changesPerItem, $user, $request, $explicit, $userId, $source, $ip, $resolvedUserAgent) {
            $changes = HardwareAuditChange::toDiffList(
                $changesPerItem ? $changesPerItem($hardware) : $staticChanges
            );

            // #1194: mirror HardwareAuditObserver::sanitizeChanges so the bulk
            // path does not persist raw formula payloads in audit rows (#886).
            if (is_array($changes)) {
                foreach ($changes as $key => $entry) {
                    if (is_array($entry)) {
                        foreach (['old', 'new'] as $field) {
                            if (isset($entry[$field]) && is_string($entry[$field])) {
                                $changes[$key][$field] = ExcelCell::escape($entry[$field]);
                            }
                        }
                    }
                }
            }

            return [
                'hardware_id' => $hardware->id,
                'user_id' => $explicit ? $userId : $user?->id,
                'action' => $action,
                'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'source' => $source ?? 'bulk',
                'ip_address' => $explicit ? $ip : $request?->ip(),
                // #886 (CWE-1236): user_agent is attacker-controlled. The
                // observer escapes it; this writer is now another entry point
                // for the import path, so it must escape it too.
                'user_agent' => ExcelCell::escape($resolvedUserAgent),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        if (! empty($rows)) {
            HardwareAudit::insert($rows);
        }
    }
}
