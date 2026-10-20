<?php

namespace App\Traits;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\User;
use App\Observers\HardwareAuditObserver;
use App\Services\HardwareAuditScope;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

trait HardwareIndexHelpers
{
    // ── History modal properties ─────────────────────────────────────

    public bool $showHistoryModal = false;

    public bool $showTrashModal = false;

    /**
     * #1170: the trash modal's public payload is the CURRENT PAGE only —
     * never the whole deletion history. The chunked scan, the scope filter
     * and the paginator live in `fetchTrashPage()`; what reaches the
     * snapshot is at most `TRASH_PER_PAGE` rows of tiny scalars (#1041).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $trashRows = [];

    public int $trashTotal = 0;

    public int $trashCurrentPage = 1;

    /**
     * #1170: the page size is fixed, not client-settable — a tampering
     * client could otherwise inflate the public payload it receives.
     */
    private const TRASH_PER_PAGE = 20;

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
     *
     * #1170 (option B): the candidate set is scanned in chunks of 500 with
     * the scope resolved per chunk through `HardwareAuditScope::unitIdsForAudits()`
     * (same deny-by-default predicate the PHP filter below always applied),
     * and only allowed rows reach the `LengthAwarePaginator` the modal
     * displays — same rows, same users, newest first, delivered one page at
     * a time so the public payload never carries the whole history.
     */
    public function loadDeletedHardware(): void
    {
        $this->trashCurrentPage = 1;
        $this->fetchTrashPage();
        $this->showTrashModal = true;
    }

    /**
     * #1170: jump to a page of the filtered trash set. The bounds are
     * clamped inside `fetchTrashPage()` because the last page depends on the
     * caller's scope, which only the scan knows.
     */
    public function trashPage(int $page): void
    {
        $this->trashCurrentPage = max(1, $page);
        $this->fetchTrashPage();
    }

    /**
     * The trash scan + scope filter + pagination for the CURRENT page.
     */
    private function fetchTrashPage(): void
    {
        // Single efficient query: find created audits for deleted hardware
        // whose live row is really gone — #1026: "deleted" is every action
        // that means the device is gone — the observer's `deleted` and the
        // bulk path's `bulk_delete` — from one shared list. #1170: both the
        // has-deletion predicate and the not-live predicate are SQL now,
        // so the scanned set is already the final candidate set.
        //
        // The scan ends in `pluck('id')`: it yields candidate IDS only, so
        // the eager load and the column list below belong to the per-page
        // model fetch, not here.
        $scan = HardwareAudit::query()
            ->where('action', 'created')
            ->whereIn('hardware_id', function ($q) {
                $q->select('hardware_id')
                    ->from('hardware_audits')
                    ->whereIn('action', HardwareAudit::DELETING_ACTIONS);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hardwares')
                    ->whereColumn('hardwares.id', 'hardware_audits.hardware_id');
            });

        // #1170: the scope is resolved from the audit's `changes` JSON in
        // PHP (HardwareAuditScope contract, shared with the API) — so it
        // cannot move into SQL. Deny-by-default: the accessible set may be
        // legitimately EMPTY (`[]` = in scope of nothing), and the filter
        // below must never be guarded around it.
        $accessibleIds = $this->accessibleUnitIds();

        /** @var Collection<int, HardwareAudit> $allowed */
        $allowed = collect();

        // The scan itself is bounded to chunks of 500 audits (option B): the
        // id page is resolved first, then the models for exactly that page
        // are fetched with the eager-loaded `user`. `orderBy('id')` keeps
        // the offset pagination deterministic.
        $page = 0;

        do {
            $pageIds = (clone $scan)->orderBy('id')->forPage(++$page, 500)->pluck('id');

            if ($pageIds->isEmpty()) {
                break;
            }

            // #1041: select only what the trash list turns into a row below.
            // `*` would pull the whole audit, and rows live in a public
            // Livewire property re-serialized on every round-trip; `user_id`
            // is needed for the eager-loaded `user` to match; `changes` for
            // the badges and the restore guard.
            $chunk = HardwareAudit::query()
                ->with('user:id,n_code')
                ->whereKey($pageIds->all())
                ->get(['id', 'hardware_id', 'user_id', 'changes', 'created_at']);

            $unitIds = app(HardwareAuditScope::class)->unitIdsForAudits($chunk);

            $chunk
                ->filter(function (HardwareAudit $audit) use ($unitIds, $accessibleIds) {
                    $unitId = $unitIds[$audit->id] ?? null;

                    return $unitId !== null && in_array($unitId, $accessibleIds, true);
                })
                ->each(function (HardwareAudit $audit) use ($allowed) {
                    $allowed->push($audit);
                });
        } while ($pageIds->count() === 500);

        if ($allowed->isEmpty()) {
            $this->trashCurrentPage = 1;
            $this->trashTotal = 0;
            $this->trashRows = [];

            return;
        }

        // Batch-load the delete timestamps for ALLOWED ids only, so no
        // out-of-scope id ever grows a `whereIn`; the ids are chunked in the
        // same groups of 500. #1026: the same action list keeps a
        // `bulk_delete` row's «حذف شده در» timestamp; the latest delete wins
        // when a hardware was deleted more than once.
        /** @var Collection<int, Carbon> $deleteTimestamps */
        $deleteTimestamps = collect();
        $idChunks = array_chunk($allowed->pluck('hardware_id')->unique()->values()->all(), 500);

        foreach ($idChunks as $ids) {
            HardwareAudit::query()
                ->whereIn('hardware_id', $ids)
                ->whereIn('action', HardwareAudit::DELETING_ACTIONS)
                ->groupBy('hardware_id')
                ->select('hardware_id', DB::raw('max(created_at) as latest_deleted_at'))
                ->pluck('latest_deleted_at', 'hardware_id')
                ->each(function ($latestDeletedAt, $hardwareId) use ($deleteTimestamps) {
                    $at = $latestDeletedAt === null ? null : Carbon::parse($latestDeletedAt);

                    if ($at === null) {
                        return;
                    }

                    $previous = $deleteTimestamps->get((int) $hardwareId);

                    if ($previous === null || $at->gt($previous)) {
                        $deleteTimestamps->put((int) $hardwareId, $at);
                    }
                });
        }

        $rows = $allowed
            ->map(function (HardwareAudit $audit) use ($deleteTimestamps) {
                // #1041: hand the modal plain scalars, not the model. The
                // public property is re-serialized on every Livewire
                // round-trip, so an Eloquent row — with its `changes` JSON and
                // eager-loaded `user` — is the real cost here, not the query.
                //
                // The eager-loaded relation is read directly (not
                // `$audit->user?->n_code`): the model's PHPDoc declares `user`
                // non-nullable, so the nullsafe spelling is reported as
                // unnecessary even though a `user_id = null` row is real.
                $user = $audit->getRelation('user');

                return [
                    'id' => (int) $audit->id,
                    'hardware_id' => (int) $audit->hardware_id,
                    'changes' => $audit->changes,
                    'created_at' => $audit->created_at,
                    'deleted_at' => $deleteTimestamps->get($audit->hardware_id),
                    'user_n_code' => $user instanceof User ? $user->n_code : null,
                ];
            })
            // #1170: newest first — the row the modal shows is keyed on the
            // delete timestamp, with the created audit's own timestamps as
            // deterministic tie-breakers.
            ->sort(function (array $a, array $b): int {
                return [$b['deleted_at'], $b['created_at'], $b['id']]
                    <=> [$a['deleted_at'], $a['created_at'], $a['id']];
            })
            ->values();

        $this->trashTotal = $rows->count();

        $lastPage = max(1, (int) ceil($this->trashTotal / self::TRASH_PER_PAGE));
        $this->trashCurrentPage = min($this->trashCurrentPage, $lastPage);

        $pageRows = $rows->forPage($this->trashCurrentPage, self::TRASH_PER_PAGE)->values()->all();

        // The paginator is the single source of the page's shape and count —
        // the modal's «تعداد کل» counter reads the total from here.
        $paginator = new LengthAwarePaginator(
            $pageRows,
            $this->trashTotal,
            self::TRASH_PER_PAGE,
            $this->trashCurrentPage
        );

        $this->trashRows = $paginator->items();
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

        // #1170: only the current page reloads after a restore — the restored
        // row drops out, the total decrements, and the operator stays where
        // they were (fetchTrashPage() clamps if the last page shrank).
        $this->trashPage($this->trashCurrentPage);
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
