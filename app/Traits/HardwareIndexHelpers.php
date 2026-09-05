<?php

namespace App\Traits;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Observers\HardwareAuditObserver;

trait HardwareIndexHelpers
{
    // ── History modal properties ─────────────────────────────────────

    public bool $showHistoryModal = false;

    public bool $showTrashModal = false;

    public array $deletedHardware = [];

    public ?int $historyHardwareId = null;

    public array $history = [];

    public int $historyCurrentPage = 1;

    public int $historyPerPage = 15;

    public int $historyTotal = 0;

    public ?string $historyActionFilter = null;

    // ── History methods ──────────────────────────────────────────────

    public function loadHistory(int $hardwareId): void
    {
        $this->historyHardwareId = $hardwareId;
        $this->historyCurrentPage = 1;
        $this->historyActionFilter = null;
        $this->fetchHistory();
        $this->showHistoryModal = true;
    }

    /**
     * Fetch history from DB (scoped to accessible units).
     */
    private function fetchHistory(): void
    {
        if (! $this->historyHardwareId) {
            return;
        }

        $unitId = Hardware::where('hardwares.id', $this->historyHardwareId)
            ->join('persons', 'hardwares.n_code', '=', 'persons.n_code')
            ->value('persons.u_id');

        if (! $unitId) {
            $this->history = [];
            $this->historyTotal = 0;

            return;
        }

        $accessibleIds = $this->accessibleUnitIds();
        if (! in_array($unitId, $accessibleIds)) {
            $this->history = [];
            $this->historyTotal = 0;

            return;
        }

        // The User model has no `name` column — `name` is an accessor derived
        // from the related Person (f_name . ' ' . l_name). Eager-load the
        // person relation instead of selecting a nonexistent column. Mirrors
        // the API controller (HardwareAuditController::index).
        $query = HardwareAudit::with('user.person:id,n_code,f_name,l_name')
            ->where('hardware_id', $this->historyHardwareId);

        if ($this->historyActionFilter) {
            $query->where('action', $this->historyActionFilter);
        }

        $this->historyTotal = $query->count();

        $items = $query
            ->orderByDesc('created_at')
            ->forPage($this->historyCurrentPage, $this->historyPerPage)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'action' => $h->action,
                'source' => $h->source,
                'changes' => $h->changes,
                'ip_address' => $h->ip_address,
                'user_agent' => $h->user_agent,
                'created_at' => $h->created_at?->toIso8601String(),
                'user' => $h->user ? [
                    'id' => $h->user->id,
                    'n_code' => $h->user->n_code,
                    'name' => $h->user->name,
                ] : null,
            ])
            ->all();

        $this->history = $items;
    }

    /**
     * Pagination for history.
     */
    public function historyPage(int $page): void
    {
        $this->historyCurrentPage = $page;
        $this->fetchHistory();
    }

    /**
     * Filter history by action.
     */
    public function filterHistory(?string $action): void
    {
        $this->historyActionFilter = $action;
        $this->historyCurrentPage = 1;
        $this->fetchHistory();
    }

    /**
     * Rollback a single field to its previous value (Issue #246).
     */
    public function rollbackHistoryField(int $auditId, string $field): void
    {
        if (! auth()->user()->can('manage_hardware')) {
            $this->error('شما مجوز manage_hardware ندارید.', position: 'toast-bottom');

            return;
        }

        $audit = HardwareAudit::find($auditId);

        if (! $audit || $audit->hardware_id !== $this->historyHardwareId) {
            $this->error('رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        $changes = $audit->changes ?? [];
        $fieldChange = collect($changes)->firstWhere('field', $field);

        if (! $fieldChange) {
            $this->error('فیلد در رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        $hw = Hardware::find($this->historyHardwareId);
        if (! $hw) {
            $this->error('سخت افزار یافت نشد.', position: 'toast-bottom');

            return;
        }

        // Parse old value and update
        $restoredValue = $this->restoreAuditValue($fieldChange['old'] ?? '—', $field);
        $hw->update([$field => $restoredValue]);

        // Log rollback
        app(HardwareAuditObserver::class)->recordRollbackAudit(
            $hw,
            [[
                'field' => $field,
                'old' => $fieldChange['new'] ?? '—',
                'new' => $fieldChange['old'] ?? '—',
            ]],
            auth()->id()
        );

        $this->success("فیلد {$field} به مقدار قبلی بازگردانده شد.", position: 'toast-bottom');
        $this->fetchHistory();
    }

    /**
     * Parse a stored display value back for restore.
     */
    private function restoreAuditValue(string $displayValue, string $field): mixed
    {
        if ($displayValue === '—') {
            return null;
        }
        if ($displayValue === 'بله') {
            return true;
        }
        if ($displayValue === 'خیر') {
            return false;
        }

        return $displayValue;
    }

    // ── Deleted-hardware restore ─────────────────────────────────────

    /**
     * Fetch all deleted hardware IDs from audit trail and open trash modal.
     */
    public function loadDeletedHardware(): void
    {
        // Single efficient query: find created audits for deleted hardware,
        // plus their delete timestamp via subquery.
        $deletedHardwareIds = HardwareAudit::where('action', 'deleted')
            ->pluck('hardware_id')
            ->unique()
            ->values()
            ->all();

        if (empty($deletedHardwareIds)) {
            $this->deletedHardware = [];
            $this->showTrashModal = true;

            return;
        }

        $createdAudits = HardwareAudit::whereIn('hardware_id', $deletedHardwareIds)
            ->where('action', 'created')
            ->with('user:id,n_code')
            ->get();

        // Batch-load all delete timestamps in one query to avoid N+1
        $deleteTimestamps = HardwareAudit::whereIn('hardware_id', $deletedHardwareIds)
            ->where('action', 'deleted')
            ->select('hardware_id', 'created_at')
            ->get()
            ->mapWithKeys(fn ($d) => [$d->hardware_id => $d->created_at]);

        $this->deletedHardware = $createdAudits
            ->map(function (HardwareAudit $audit) use ($deleteTimestamps) {
                $audit->deleted_at = $deleteTimestamps->get($audit->hardware_id);

                return $audit;
            })
            ->values()
            ->all();

        $this->showTrashModal = true;
    }

    /**
     * Restore a fully-deleted hardware record from its 'created' audit entry.
     */
    public function restoreRecord(int $auditId): void
    {
        if (! auth()->user()->can('manage_hardware')) {
            $this->error('شما مجوز manage_hardware ندارید.', position: 'toast-bottom');

            return;
        }

        $audit = HardwareAudit::find($auditId);
        if (! $audit || $audit->action !== 'created') {
            $this->error('رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        // Check it was actually deleted
        $exists = Hardware::where('id', $audit->hardware_id)->exists();
        if ($exists) {
            $this->error('این سخت‌افزار هنوز وجود دارد — از بازگردانی فیلد استفاده کنید.', position: 'toast-bottom');

            return;
        }

        // Build restore data from audit changes
        $restoreData = [];
        $pcName = $audit->hardware_id;
        $nCode = null;
        foreach ($audit->changes as $change) {
            if (! isset($change['field'], $change['new'])) {
                continue;
            }
            $restoreData[$change['field']] = $this->restoreAuditValue($change['new'], $change['field']);
            if ($change['field'] === 'pc_name') {
                $pcName = $change['new'];
            }
            if ($change['field'] === 'n_code') {
                $nCode = $change['new'];
            }
        }

        // If n_code not in audit (old records created before observer fix),
        // the hardware→person link was lost on delete.
        if ($nCode === null) {
            $this->error('این رکورد قبل از ثبت n_code در تاریخچه ایجاد شده و لینک شخص حذف شده است — لطفاً به صورت دستی ایجاد کنید.', position: 'toast-bottom');

            return;
        }

        if (empty($restoreData) || $nCode === null) {
            $this->error('داده‌ای برای بازگردانی وجود ندارد (n_code یافت نشد).', position: 'toast-bottom');

            return;
        }

        $restoreData['n_code'] = $nCode;
        $restoredHardware = Hardware::create($restoreData);

        // Log the restore
        app(HardwareAuditObserver::class)->recordRollbackAudit(
            $restoredHardware,
            array_map(
                fn ($c) => ['field' => $c['field'], 'old' => 'حذف شده', 'new' => $c['new'] ?? '—'],
                $audit->changes
            ),
            auth()->id()
        );

        $this->success("سخت‌افزار {$pcName} با موفقیت بازگردانده شد.", position: 'toast-bottom');
        $this->loadDeletedHardware();
    }
}
