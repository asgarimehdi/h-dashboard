<?php

namespace App\Services;

use App\Models\HardwareAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Organizational scope resolution for the hardware audit trail.
 *
 * Shared by the API (HardwareAuditController) and the Livewire
 * hardware.index component (HardwareIndexHelpers) so both paths resolve
 * scope — and deny — through exactly one implementation:
 *
 *   live hardware n_code → audit n_code snapshot → persons.u_id
 *
 * Deny by default: when the owning unit cannot be proven (no n_code, or a
 * person that no longer exists) the audit is reported as out of scope.
 */
class HardwareAuditScope
{
    /**
     * Unit that owns the hardware an audit refers to, null when unprovable.
     */
    public function unitIdForAudit(HardwareAudit $audit): ?int
    {
        return $this->unitIdsForAudits([$audit])[$audit->id] ?? null;
    }

    /**
     * Is this audit's hardware inside the caller's accessible units?
     *
     * @param  array<int>  $accessibleIds
     */
    public function isAccessible(HardwareAudit $audit, array $accessibleIds): bool
    {
        $unitId = $this->unitIdForAudit($audit);

        return $unitId !== null && in_array($unitId, $accessibleIds, true);
    }

    /**
     * Batch variant — audit id => owning unit id (null when unprovable).
     *
     * Resolves every audit in three queries total, so a long trash list
     * never falls into a per-row N+1.
     *
     * @param  iterable<HardwareAudit>  $audits
     * @return array<int, int|null>
     */
    public function unitIdsForAudits(iterable $audits): array
    {
        $audits = collect($audits)->values();

        if ($audits->isEmpty()) {
            return [];
        }

        // 1. n_code: the live row when it still exists, otherwise the snapshot.
        $hardwareIds = $audits->pluck('hardware_id')->unique()->values()->all();
        /** @var Collection<int, string|null> $liveNCodes */
        $liveNCodes = DB::table('hardwares')->whereIn('id', $hardwareIds)->pluck('n_code', 'id');

        $nCodes = [];
        foreach ($audits as $audit) {
            $nCodes[$audit->id] = $liveNCodes->get($audit->hardware_id)
                ?? $this->snapshotNCode($audit);
        }

        // 2. n_code → person's unit. A missing person means unprovable.
        $distinct = collect($nCodes)->filter()->unique()->values()->all();
        /** @var Collection<string, int|string|null> $unitByNCode */
        $unitByNCode = $distinct === []
            ? collect()
            : DB::table('persons')->whereIn('n_code', $distinct)->pluck('u_id', 'n_code');

        $result = [];
        foreach ($nCodes as $auditId => $nCode) {
            $unitId = $nCode === null ? null : $unitByNCode->get($nCode);
            $result[$auditId] = $unitId === null ? null : (int) $unitId;
        }

        return $result;
    }

    /**
     * The n_code stored in the audit's own change snapshot. Deny by default:
     * no changes at all, or only the em-dash placeholder, proves nothing.
     */
    private function snapshotNCode(HardwareAudit $audit): ?string
    {
        $changes = $audit->changes;

        if (empty($changes)) {
            return null;
        }

        foreach ($changes as $change) {
            if (($change['field'] ?? null) === 'n_code' && isset($change['new'])) {
                return is_string($change['new']) && $change['new'] !== '—' ? $change['new'] : null;
            }
        }

        return null;
    }
}
