<?php

namespace App\Traits;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Observers\HardwareAuditObserver;
use App\Services\HardwareAuditScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

trait HardwareIndexHelpers
{
    // ── History modal properties ─────────────────────────────────────

    public bool $showHistoryModal = false;

    public bool $showTrashModal = false;

    public array $deletedHardware = [];

    /**
     * Defence in depth (issue #816): the value is only ever written
     * server-side by loadHistory()/fetchHistory(), so the client has no
     * business changing it. The scope checks live inside the write methods
     * regardless — a locked property is not the security boundary.
     */
    #[Locked]
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
     *
     * Scope (issue #816) is resolved from the audit itself, inside this
     * method and ahead of every existence/shape guard — never from
     * historyHardwareId, which the caller can prime through loadHistory().
     */
    public function rollbackHistoryField(int $auditId, string $field): void
    {
        if (! auth()->user()->can('manage_hardware')) {
            $this->error('شما مجوز manage_hardware ندارید.', position: 'toast-bottom');

            return;
        }

        $audit = HardwareAudit::find($auditId);

        if (! $audit || ! $this->auditInScope($audit)) {
            $this->error('رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        // Field allowlist — mirrors HardwareAuditController's Rule::in(getFillable()).
        if (! in_array($field, (new Hardware)->getFillable(), true)) {
            $this->error('فیلد درخواستی مجاز نیست.', position: 'toast-bottom');

            return;
        }

        // Consistency only: the audit must belong to the history on screen.
        // Scope was already proven above, so this is no longer the boundary.
        if ($audit->hardware_id !== $this->historyHardwareId) {
            $this->error('رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        $changes = $audit->changes ?? [];
        $fieldChange = collect($changes)->firstWhere('field', $field);

        if (! $fieldChange) {
            $this->error('فیلد در رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        $hw = Hardware::find($audit->hardware_id);
        if (! $hw) {
            $this->error('سخت افزار یافت نشد.', position: 'toast-bottom');

            return;
        }

        // Parse old value and update
        $restoredValue = $this->restoreAuditValue($fieldChange['old'] ?? '—', $field);

        // The parsed value must survive the column's cast (boolean, date) —
        // otherwise the write fails mid-request or stores a mis-typed value.
        if (! $this->valueRoundTrips($field, $restoredValue)) {
            $this->error('مقدار بازگردانی‌شده با نوع ستون سازگار نیست.', position: 'toast-bottom');

            return;
        }

        // Re-pointing hardware at another person must stay inside the caller's
        // own scope — mirrors HardwareAuditController::rollback().
        if ($field === 'n_code' && $restoredValue !== null && ! $this->nCodeInScope((string) $restoredValue)) {
            $this->error('امکان انتقال سخت‌افزار به واحد سازمانی خارج از دسترس شما نیست.', position: 'toast-bottom');

            return;
        }

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
     * Is the audit's hardware inside the caller's organizational scope?
     * Deny by default — HardwareAuditScope returns null when unprovable.
     */
    private function auditInScope(HardwareAudit $audit): bool
    {
        return app(HardwareAuditScope::class)->isAccessible($audit, $this->accessibleUnitIds());
    }

    /**
     * Does the national code resolve to a person in the caller's scope?
     */
    private function nCodeInScope(string $nCode): bool
    {
        $unitId = DB::table('persons')->where('n_code', $nCode)->value('u_id');

        return $unitId !== null && in_array((int) $unitId, $this->accessibleUnitIds(), true);
    }

    /**
     * Can the parsed restore value be written to the column's cast?
     * Booleans must be boolean (or numeric), date columns must parse.
     */
    private function valueRoundTrips(string $field, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        $cast = (new Hardware)->getCasts()[$field] ?? null;

        if ($cast === 'boolean') {
            return is_bool($value) || is_numeric($value);
        }

        if (in_array($cast, ['date', 'datetime', 'immutable_date', 'immutable_datetime'], true)) {
            if (! is_string($value) && ! is_numeric($value)) {
                return false;
            }

            try {
                Carbon::parse((string) $value);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        return is_scalar($value);
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
     * Fetch deleted hardware visible to the caller from the audit trail and
     * open the trash modal.
     *
     * Scoped (issue #816): a row is listed only when its owning unit resolves
     * into the caller's accessible units — scope that cannot be proven is
     * denied, and a row whose hardware is live again (already restored) is
     * dropped from the list.
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

        // Hardware that is live again was already restored: it is no longer
        // trash, so neither the row nor its restore button may survive.
        $liveIds = Hardware::query()->whereIn('id', $deletedHardwareIds)->pluck('id')->all();

        $accessibleIds = $this->accessibleUnitIds();
        $unitIds = app(HardwareAuditScope::class)->unitIdsForAudits($createdAudits);

        $this->deletedHardware = $createdAudits
            ->filter(function (HardwareAudit $audit) use ($liveIds, $unitIds, $accessibleIds) {
                if (in_array((int) $audit->hardware_id, $liveIds, true)) {
                    return false;
                }

                $unitId = $unitIds[$audit->id] ?? null;

                return $unitId !== null && in_array($unitId, $accessibleIds, true);
            })
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
     *
     * Issue #816: organizational scope is asserted first, before the action /
     * existence / shape guards, and it is resolved from the audit itself.
     * The restored row reuses the original primary key, so a second restore
     * of the same audit stops at the "still exists" guard instead of creating
     * a duplicate — and the row drops out of the trash list.
     */
    public function restoreRecord(int $auditId): void
    {
        if (! auth()->user()->can('manage_hardware')) {
            $this->error('شما مجوز manage_hardware ندارید.', position: 'toast-bottom');

            return;
        }

        $audit = HardwareAudit::find($auditId);

        // Scope first: an out-of-scope caller gets the same answer as a
        // missing audit, so neither existence nor shape of the record leaks.
        if (! $audit || ! $this->auditInScope($audit)) {
            $this->error('رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        if ($audit->action !== 'created') {
            $this->error('رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        // Check it was actually deleted
        $exists = Hardware::where('id', $audit->hardware_id)->exists();
        if ($exists) {
            $this->error('این سخت‌افزار هنوز وجود دارد — از بازگردانی فیلد استفاده کنید.', position: 'toast-bottom');

            return;
        }

        if (! is_array($audit->changes)) {
            $this->error('داده‌ای برای بازگردانی وجود ندارد.', position: 'toast-bottom');

            return;
        }

        // Build restore data from audit changes
        $allowed = (new Hardware)->getFillable();
        $restoreData = [];
        $pcName = $audit->hardware_id;
        $nCode = null;
        foreach ($audit->changes as $change) {
            if (! isset($change['field'], $change['new'])) {
                continue;
            }

            // Keys outside the allowlist would be dropped silently by mass
            // assignment anyway — skip them explicitly instead of pretending.
            if (! in_array($change['field'], $allowed, true)) {
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

        if (empty($restoreData)) {
            $this->error('داده‌ای برای بازگردانی وجود ندارد (n_code یافت نشد).', position: 'toast-bottom');

            return;
        }

        // The record must be recreated inside the caller's own scope — the
        // audit's n_code and the snapshot's n_code are not always the same.
        if (! $this->nCodeInScope((string) $nCode)) {
            $this->error('رکورد تاریخچه یافت نشد.', position: 'toast-bottom');

            return;
        }

        $restoreData['n_code'] = $nCode;
        $restoreData['id'] = $audit->hardware_id;

        $restoredHardware = new Hardware;
        $restoredHardware->forceFill($restoreData)->save();

        $this->advanceHardwareSequence();

        // Hardware::create()/save() already fires HardwareAuditObserver::created(),
        // which writes the 'created' audit — so only the explicit rollback
        // entry is logged here (mirrors HardwareAuditController::restoreRecord()).
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

    /**
     * Advance the Postgres sequence past a restored id so the next
     * auto-increment cannot collide (duplicate key on hardwares_pkey).
     * Mirrors HardwareAuditController::restoreRecord().
     */
    private function advanceHardwareSequence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $seq = DB::selectOne("SELECT pg_get_serial_sequence('hardwares','id') as seq");
        if ($seq && $seq->seq) {
            DB::statement('SELECT setval(?, (SELECT COALESCE(MAX(id), 0) FROM hardwares))', [$seq->seq]);
        }
    }
}
