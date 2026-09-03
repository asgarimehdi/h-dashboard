<?php

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Observers\HardwareAuditObserver;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use PersianNormalizer;
    use Toast;
    use WithPagination;

    public string $search = '';

    public int $perPage = 20;

    public bool $showHelpModal = false;

    // History modal
    public bool $showHistoryModal = false;

    public bool $showTrashModal = false;

    public array $deletedHardware = [];

    public ?int $historyHardwareId = null;

    public array $history = [];

    public int $historyCurrentPage = 1;

    public int $historyPerPage = 15;

    public int $historyTotal = 0;

    public ?string $historyActionFilter = null;

    /**
     * Get accessible unit IDs for the current user.
     *
     * @return array<int>
     */
    private function accessibleUnitIds(): array
    {
        return app(AccessService::class)->accessibleUnitIds();
    }

    /**
     * Apply organizational scope to a hardware query.
     */
    private function applyOrgScope($query)
    {
        $accessibleIds = $this->accessibleUnitIds();

        if (empty($accessibleIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('person', fn ($q) => $q->whereIn('u_id', $accessibleIds));
    }

    public bool $showForm = false;

    public bool $showEditModal = false;

    public bool $showFilters = false;

    public bool $showColPanel = false;

    public ?int $editingId = null;

    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    // Filter fields
    public ?string $filterType = null;

    public ?string $filterOs = null;

    public ?string $filterCpu = null;

    public ?string $filterRam = null;

    public ?string $filterHdd = null;

    public ?string $filterShutdown = null;

    public ?string $filterNetType = null;

    public ?string $filterMark = null;

    // Related filters (Person/Unit/Semat)
    public ?string $filterPerson = null;

    public ?string $filterUnit = null;

    public ?string $filterSemat = null;

    // Bulk selection
    public array $selected = [];

    // Column visibility — all DB fields available, default hidden (#507)
    public array $visibleCols = [
        'type' => true,
        'os' => true,
        'unit_name' => true,
        'ip_valid' => false,
        'ip_local' => true,
        'mac' => false,
        'net_type' => false,
        'switch' => false,
        'port' => false,
        'vlan' => false,
        'motherboard' => false,
        'cpu' => true,
        'ram' => true,
        'hdd' => true,
        'shutdown' => false,
        'mark' => false,
        'comments' => false,
        'clean_at' => false,
        'status' => true,
    ];

    // Form fields
    public ?string $n_code = null;

    public ?string $n_code_status = null; // 'valid', 'invalid', or null

    public ?string $n_code_name = null;

    public ?string $n_code_unit = null;

    public ?string $pc_name = null;

    public ?string $type = null;

    public ?string $os = null;

    public ?string $ip_valid = null;

    public ?string $ip_local = null;

    public ?string $mac = null;

    public ?string $net_type = null;

    public ?string $switch = null;

    public ?string $port = null;

    public bool $shutdown = true;

    public ?string $vlan = null;

    public ?string $motherboard = null;

    public ?string $cpu = null;

    public ?string $ram = null;

    public ?string $hdd = null;

    public ?string $comments = null;

    public bool $mark = false;

    public ?string $clean_at = null;

    // Person search
    public string $personSearch = '';

    public array $personResults = [];

    public ?string $selectedPersonName = null;

    public function updatedPersonSearch(): void
    {
        $normalized = self::normalizeForSearch($this->personSearch);
        if (strlen($normalized) < 2) {
            $this->personResults = [];

            return;
        }

        $accessibleIds = $this->accessibleUnitIds();

        $this->personResults = Person::whereIn('u_id', $accessibleIds)
            ->where(function ($q) use ($normalized) {
                $q->where('n_code', 'LIKE', "%{$normalized}%")
                    ->orWhere('f_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('l_name', 'LIKE', "%{$normalized}%")
                    ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$normalized}%"]);
            })
            ->limit(10)
            ->get()
            ->map(fn ($p) => ['n_code' => $p->n_code, 'name' => trim($p->f_name.' '.$p->l_name)])
            ->toArray();
    }

    public function updatedNCode($value): void
    {
        if (strlen($value) < 10) {
            $this->n_code_status = null;
            $this->n_code_name = null;
            $this->n_code_unit = null;

            return;
        }

        $accessibleIds = $this->accessibleUnitIds();

        $person = Person::whereIn('u_id', $accessibleIds)
            ->where('n_code', $value)
            ->first();

        if ($person) {
            $this->n_code_status = 'valid';
            $this->n_code_name = trim($person->f_name.' '.$person->l_name);
            $this->n_code_unit = $person->unit?->name;
        } else {
            $this->n_code_status = 'invalid';
            $this->n_code_name = null;
            $this->n_code_unit = null;
        }
    }

    public function selectPerson(string $nCode, string $name): void
    {
        $this->n_code = $nCode;
        $this->n_code_status = 'valid';
        $this->n_code_name = $name;
        $this->personSearch = '';
        $this->personResults = [];
    }

    private function resetForm(): void
    {
        $this->reset([
            'n_code', 'n_code_status', 'n_code_name', 'n_code_unit',
            'pc_name', 'type', 'os',
            'ip_valid', 'ip_local', 'mac', 'net_type', 'switch', 'port',
            'shutdown', 'vlan', 'motherboard', 'cpu', 'ram', 'hdd',
            'comments', 'mark', 'clean_at', 'personSearch', 'personResults', 'selectedPersonName',
        ]);
    }

    public function clearFilters(): void
    {
        $this->reset([
            'filterType', 'filterOs', 'filterCpu', 'filterRam',
            'filterHdd', 'filterShutdown', 'filterNetType', 'filterMark',
            'filterPerson', 'filterUnit', 'filterSemat',
        ]);
    }

    /**
     * Toggle a quick-preset filter on/off. Clicking an already-active preset
     * clears it (so users can remove a filter by clicking it again).
     */
    public function toggleFilter(string $property, string $value): void
    {
        $this->$property = ($value === $this->$property) ? null : $value;
    }

    public function toggleColPanel(): void
    {
        $this->showColPanel = ! $this->showColPanel;
    }

    public function hasActiveFilters(): bool
    {
        return collect([
            $this->filterType, $this->filterOs, $this->filterCpu,
            $this->filterRam, $this->filterHdd, $this->filterShutdown,
            $this->filterNetType, $this->filterMark,
            $this->filterPerson, $this->filterUnit, $this->filterSemat,
        ])->filter()->isNotEmpty();
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showForm = false;
        $this->showEditModal = false;
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showEditModal = false;
        $this->showForm = true;
    }

    public function createHardware(): void
    {
        $this->validate([
            'n_code' => ['required', 'string', Rule::exists('persons', 'n_code')->where(fn ($q) => $q->whereIn('u_id', $this->accessibleUnitIds()))],
            'pc_name' => 'required|string|max:255',
        ]);

        $person = Person::where('n_code', $this->n_code)->firstOrFail();
        $accessibleIds = $this->accessibleUnitIds();

        if (! in_array($person->u_id, $accessibleIds)) {
            $this->error('شما به این پرسنل دسترسی ندارید.', position: 'toast-bottom');

            return;
        }

        Hardware::create($this->only([
            'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
        ]));

        $this->success("سخت افزار {$this->pc_name} ایجاد شد", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function editHardware($id): void
    {
        $this->resetValidation();
        $hw = Hardware::with('person')->findOrFail($id);

        $accessibleIds = $this->accessibleUnitIds();
        if (! in_array($hw->person?->u_id, $accessibleIds)) {
            $this->error('شما به این سخت‌افزار دسترسی ندارید.', position: 'toast-bottom');

            return;
        }

        $this->editingId = (int) $id;
        $this->fill($hw->toArray());
        $this->clean_at = $hw->clean_at?->format('Y-m-d');
        $this->selectedPersonName = $hw->person ? trim($hw->person->f_name.' '.$hw->person->l_name) : null;
        $this->showForm = false;
        $this->showEditModal = true;
    }

    public function updateHardware(): void
    {
        $this->validate([
            'n_code' => 'required|string|exists:persons,n_code',
            'pc_name' => 'required|string|max:255',
        ]);

        $hw = Hardware::with('person')->findOrFail($this->editingId);

        $accessibleIds = $this->accessibleUnitIds();
        if (! in_array($hw->person?->u_id, $accessibleIds)) {
            $this->error('شما به این سخت‌افزار دسترسی ندارید.', position: 'toast-bottom');

            return;
        }

        $hw->update($this->only([
            'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
        ]));

        $this->success("سخت افزار {$this->pc_name} بروزرسانی شد", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function delete(Hardware $hardware): void
    {
        $hardware->load('person');

        $accessibleIds = $this->accessibleUnitIds();
        if (! in_array($hardware->person?->u_id, $accessibleIds)) {
            $this->error('شما به این سخت‌افزار دسترسی ندارید.', position: 'toast-bottom');

            return;
        }

        try {
            $hardware->delete();
            $this->warning("سخت افزار {$hardware->pc_name} حذف شد", 'با موفقیت', position: 'toast-bottom');
        } catch (Exception $e) {
            $this->error('امکان حذف وجود ندارد.', position: 'toast-bottom');
        }
    }

    public function bulkMark(bool $value): void
    {
        if (empty($this->selected)) {
            $this->error('هیچ ردیفی انتخاب نشده است.', position: 'toast-bottom');

            return;
        }

        $accessibleIds = $this->accessibleUnitIds();
        $scopedIds = Hardware::whereIn('id', $this->selected)
            ->whereHas('person', fn ($q) => $q->whereIn('u_id', $accessibleIds))
            ->pluck('id')
            ->toArray();

        if (empty($scopedIds)) {
            $this->error('هیچ ردیفی در محدوده دسترسی شما نیست.', position: 'toast-bottom');

            return;
        }

        Hardware::whereIn('id', $scopedIds)->update(['mark' => $value]);
        Hardware::flushStatsCache(); // Issue #376: bulk update bypasses Eloquent events
        // Keep the selection so the user can immediately toggle back (e.g. "برداشتن")
        // without having to re-select — do NOT clear $this->selected here.
        $this->success('وضعیت علامت‌گذاری تغییر کرد.', position: 'toast-bottom');
    }

    /**
     * Clear the bulk selection (used by the UI after operations if needed).
     */
    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function exportExcel(): void
    {
        $columns = array_keys(array_filter($this->visibleCols));
        // Always include core columns
        $columns = array_unique(array_merge(['n_code', 'pc_name'], $columns));

        $params = [
            'columns' => implode(',', $columns),
            'search' => $this->search,
            'type' => $this->filterType,
            'os' => $this->filterOs,
            'cpu' => $this->filterCpu,
            'ram' => $this->filterRam,
            'hdd' => $this->filterHdd,
            'shutdown' => $this->filterShutdown,
            'net_type' => $this->filterNetType,
            'mark' => $this->filterMark,
            'person' => $this->filterPerson,
            'unit' => $this->filterUnit,
            'semat' => $this->filterSemat,
        ];

        // Remove empty values
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');

        $this->dispatch('download-export', route('hardware.export', $params));
    }

    public function bulkDelete(): void
    {
        if (empty($this->selected)) {
            return;
        }

        $accessibleIds = $this->accessibleUnitIds();
        $scopedIds = Hardware::whereIn('id', $this->selected)
            ->whereHas('person', fn ($q) => $q->whereIn('u_id', $accessibleIds))
            ->pluck('id')
            ->toArray();

        if (empty($scopedIds)) {
            $this->error('هیچ ردیفی در محدوده دسترسی شما نیست.', position: 'toast-bottom');

            return;
        }

        Hardware::whereIn('id', $scopedIds)->delete();
        Hardware::flushStatsCache(); // Issue #376: bulk delete bypasses Eloquent events
        $this->selected = [];
        $this->warning('دستگاه‌های انتخاب شده حذف شدند.', position: 'toast-bottom');
    }

    /**
     * Load hardware change history.
     */
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

    public function headers(): array
    {
        return [
            ['key' => 'checkbox', 'label' => '', 'class' => 'w-10'],
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'pc_name', 'label' => 'نام دستگاه', 'class' => ''],
            ['key' => 'person_name', 'label' => 'صاحب', 'class' => ''],
            ['key' => 'unit_name', 'label' => 'واحد', 'class' => 'hidden md:table-cell', 'hidden' => ! $this->visibleCols['unit_name']],
            ['key' => 'type', 'label' => 'نوع', 'class' => 'hidden md:table-cell', 'hidden' => ! $this->visibleCols['type']],
            ['key' => 'os', 'label' => 'OS', 'class' => 'hidden lg:table-cell', 'hidden' => ! $this->visibleCols['os']],
            ['key' => 'ip_valid', 'label' => 'IP عمومی', 'class' => 'hidden lg:table-cell', 'hidden' => ! $this->visibleCols['ip_valid']],
            ['key' => 'ip_local', 'label' => 'IP', 'class' => 'hidden lg:table-cell', 'hidden' => ! $this->visibleCols['ip_local']],
            ['key' => 'mac', 'label' => 'MAC', 'class' => 'hidden xl:table-cell', 'hidden' => ! $this->visibleCols['mac']],
            ['key' => 'net_type', 'label' => 'نوع اتصال', 'class' => 'hidden xl:table-cell', 'hidden' => ! $this->visibleCols['net_type']],
            ['key' => 'switch', 'label' => 'سوئیچ', 'class' => 'hidden 2xl:table-cell', 'hidden' => ! $this->visibleCols['switch']],
            ['key' => 'port', 'label' => 'پورت', 'class' => 'hidden 2xl:table-cell', 'hidden' => ! $this->visibleCols['port']],
            ['key' => 'vlan', 'label' => 'VLAN', 'class' => 'hidden 2xl:table-cell', 'hidden' => ! $this->visibleCols['vlan']],
            ['key' => 'motherboard', 'label' => 'مادربورد', 'class' => 'hidden 2xl:table-cell', 'hidden' => ! $this->visibleCols['motherboard']],
            ['key' => 'cpu', 'label' => 'CPU', 'class' => 'hidden xl:table-cell', 'hidden' => ! $this->visibleCols['cpu']],
            ['key' => 'ram', 'label' => 'RAM', 'class' => 'hidden xl:table-cell', 'hidden' => ! $this->visibleCols['ram']],
            ['key' => 'hdd', 'label' => 'HDD', 'class' => 'hidden xl:table-cell', 'hidden' => ! $this->visibleCols['hdd']],
            ['key' => 'shutdown_display', 'label' => 'خاموشی', 'class' => 'hidden 2xl:table-cell', 'hidden' => ! $this->visibleCols['shutdown']],
            ['key' => 'mark_display', 'label' => 'علامت', 'class' => 'hidden 2xl:table-cell', 'hidden' => ! $this->visibleCols['mark']],
            ['key' => 'comments_display', 'label' => 'توضیحات', 'class' => 'hidden 2xl:table-cell', 'hidden' => ! $this->visibleCols['comments']],
            ['key' => 'clean_at_display', 'label' => 'تاریخ نظافت', 'class' => 'hidden 2xl:table-cell', 'hidden' => ! $this->visibleCols['clean_at']],
            ['key' => 'status', 'label' => 'وضعیت', 'class' => 'w-24', 'hidden' => ! $this->visibleCols['status']],
        ];
    }

    private ?LengthAwarePaginator $hardwaresCache = null;

    public function hardwares(): LengthAwarePaginator
    {
        if ($this->hardwaresCache !== null) {
            return $this->hardwaresCache;
        }

        $query = $this->applyOrgScope(Hardware::with('person.unit'));

        // General search
        if (! empty($this->search)) {
            $s = self::normalizeForSearch($this->search);
            $query->where(function ($q) use ($s) {
                $q->where('pc_name', 'LIKE', "%{$s}%")
                    ->orWhere('n_code', 'LIKE', "%{$s}%")
                    ->orWhere('ip_valid', 'LIKE', "%{$s}%")
                    ->orWhere('ip_local', 'LIKE', "%{$s}%")
                    ->orWhere('mac', 'LIKE', "%{$s}%")
                    ->orWhere('comments', 'LIKE', "%{$s}%")
                    ->orWhereHas('person', function ($pq) use ($s) {
                        $pq->where('f_name', 'LIKE', "%{$s}%")
                            ->orWhere('l_name', 'LIKE', "%{$s}%")
                            ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$s}%"]);
                    });
            });
        }

        // Separate filters (AND logic)
        if ($this->filterType) {
            $type = $this->filterType;
            // Map common aliases to actual database values
            $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
            $type = $typeAliases[$type] ?? $type;
            $query->where('type', 'LIKE', "%{$type}%");
        }
        if ($this->filterOs) {
            $query->where('os', 'LIKE', "%{$this->filterOs}%");
        }
        if ($this->filterCpu) {
            $query->where('cpu', 'LIKE', "%{$this->filterCpu}%");
        }
        if ($this->filterRam) {
            $query->where('ram', 'LIKE', "%{$this->filterRam}%");
        }
        if ($this->filterHdd) {
            $query->where('hdd', 'LIKE', "%{$this->filterHdd}%");
        }
        if ($this->filterShutdown !== null && $this->filterShutdown !== '') {
            $query->where('shutdown', $this->filterShutdown === '1');
        }
        if ($this->filterNetType) {
            $query->where('net_type', 'LIKE', "%{$this->filterNetType}%");
        }
        if ($this->filterMark !== null && $this->filterMark !== '') {
            $query->where('mark', $this->filterMark === '1');
        }

        // Related filters (AND logic)
        if ($this->filterPerson) {
            $normalized = self::normalizeForSearch($this->filterPerson);
            $query->whereHas('person', function ($q) use ($normalized) {
                $q->where('f_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('l_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('n_code', 'LIKE', "%{$normalized}%")
                    ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$normalized}%"]);
            });
        }
        if ($this->filterUnit) {
            $normalized = self::normalizeForSearch($this->filterUnit);
            $query->whereHas('person.unit', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }
        if ($this->filterSemat) {
            $normalized = self::normalizeForSearch($this->filterSemat);
            $query->whereHas('person.semat', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }

        $query->orderBy(...array_values($this->sortBy));

        return $this->hardwaresCache = $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'hardwares' => $this->hardwares()->through(fn ($hw) => [
                ...$hw->toArray(),
                'person_name' => $hw->person ? trim($hw->person->f_name.' '.$hw->person->l_name) : '-',
                'unit_name' => $hw->person?->unit?->name ?? '-',
                'shutdown_display' => $hw->shutdown ? 'بله' : 'خیر',
                'mark_display' => $hw->mark ? 'بله' : 'خیر',
                'clean_at_display' => $hw->clean_at?->format('Y/m/d') ?? '—',
                'comments_display' => $hw->comments ?? '—',
                'status' => $hw->mark ? 'mark' : ($hw->shutdown ? 'off' : 'on'),
            ]),
            'headers' => $this->headers(),
        ];
    }
}; ?>

<div>
    <x-header title="شناسنامه سخت افزار" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="hardware" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <x-card shadow>
        <div class="flex flex-wrap gap-2 items-center mb-4">
            <x-button class="btn-success" wire:click="startCreate" label="افزودن" icon="o-plus" responsive />
            <div class="flex-1 min-w-[180px]">
                <x-input
                    placeholder="جستجو در تمام فیلدها..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
            <x-button icon="o-funnel"
                :class="$showFilters ? 'btn-primary' : 'btn-ghost'"
                wire:click="$toggle('showFilters')"
                />
            <div class="flex flex-wrap gap-1">
                <x-button icon="o-arrow-down-tray" class="btn-ghost btn-sm" label="خروجی اکسل" wire:click="exportExcel" spinner />
                <x-button icon="o-archive-box" class="btn-ghost btn-sm" label="ستون‌ها" wire:click="toggleColPanel" />
                <x-button icon="o-trash" class="btn-error btn-ghost btn-sm" label="حذف" wire:click="bulkDelete" spinner :disabled="empty($selected)" wire:confirm="آیا مطمئن هستید؟" />
                <x-button icon="o-check-circle" class="btn-success btn-ghost btn-sm" label="علامت" wire:click="bulkMark(true)" spinner :disabled="empty($selected)" />
                <x-button icon="o-x-circle" class="btn-ghost btn-sm" label="برداشتن" wire:click="bulkMark(false)" spinner :disabled="empty($selected)" />
                @if(!empty($selected))
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm text-base-content/50" label="{{ count($selected) }} انتخاب" wire:click="clearSelection" title="پاک کردن انتخاب" />
                @endif
            </div>
                </div>

                {{-- Quick Presets + Filters (Blade partial) --}}
        @include('livewire.hardware.filters')

        @if($showColPanel)
            <div class="mb-4 p-4 bg-base-200 rounded-lg border-l-4 border-primary">
                <div class="flex items-center gap-2 mb-2 font-bold text-sm">
                    <x-icon name="o-view-columns" class="w-4 h-4" />
                    مدیریت نمایش ستون‌ها
                </div>
                <div class="flex flex-wrap gap-3">
                    @foreach($visibleCols as $key => $visible)
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input type="checkbox" wire:model.live="visibleCols.{{ $key }}" class="checkbox checkbox-xs" />
                            {{ collect($this->headers())->firstWhere('key', $key)['label'] ?? $key }}
                            <span class="text-xs opacity-50 ml-1">({{ $key }})</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Create Form --}}
        @if($showForm && !$editingId)
            <div class="mb-4 p-4 bg-base-200 rounded-lg">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div class="relative">
                        <x-input wire:model.live="n_code" label="کد ملی / نام پرسنل" placeholder="جستجو..." />
                        @if($n_code_status === 'valid')
                            <div class="text-xs text-success mt-1">✓ {{ $n_code_name }} @if($n_code_unit)({{ $n_code_unit }})@endif</div>
                        @elseif($n_code_status === 'invalid')
                            <div class="text-xs text-error mt-1">✗ کد ملی یافت نشد</div>
                        @endif
                        @if(count($personResults) > 0)
                            <div class="absolute z-10 bg-base-100 border rounded-lg shadow-lg w-full mt-1 max-h-48 overflow-auto">
                                @foreach($personResults as $pr)
                                    <div class="px-3 py-2 hover:bg-base-200 cursor-pointer text-sm"
                                         wire:click="selectPerson('{{ $pr['n_code'] }}', '{{ $pr['name'] }}')">
                                        {{ $pr['name'] }} ({{ $pr['n_code'] }})
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if($selectedPersonName)
                            <div class="text-xs text-success mt-1">✓ {{ $selectedPersonName }} ({{ $n_code }})</div>
                        @endif
                        @error('n_code') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>
                    <x-input wire:model="pc_name" label="نام دستگاه" placeholder="PC-NAME" required />
                    <x-input wire:model="type" label="نوع" placeholder="pc, laptop, ..." />
                    <x-input wire:model="os" label="سیستم عامل" placeholder="Windows 10, ..." />
                    <x-input wire:model="ip_valid" label="IP عمومی" />
                    <x-input wire:model="ip_local" label="IP محلی" x-mask="099.099.099.099" />
                    <x-input wire:model="mac" label="MAC Address" x-mask="**:**:**:**:**:**" />
                    <x-input wire:model="net_type" label="نوع شبکه" placeholder="wireless, wired, ..." />
                    <x-input wire:model="switch" label="سوئیچ" />
                    <x-input wire:model="port" label="پورت" />
                    <x-input wire:model="vlan" label="VLAN" />
                    <x-input wire:model="motherboard" label="مادربورد" />
                    <x-input wire:model="cpu" label="CPU" />
                    <x-input wire:model="ram" label="RAM" />
                    <x-input wire:model="hdd" label="HDD/SSD" />
                    <x-input wire:model="clean_at" label="تاریخ نظافت" type="date" />
                    <div class="form-control">
                        <label class="label cursor-pointer gap-2">
                            <input type="checkbox" wire:model="shutdown" class="checkbox checkbox-sm" />
                            <span class="label-text">فعال</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer gap-2">
                            <input type="checkbox" wire:model="mark" class="checkbox checkbox-sm" />
                            <span class="label-text">علامت</span>
                        </label>
                    </div>
                    <div class="md:col-span-2 lg:col-span-3">
                        <x-input wire:model="comments" label="توضیحات" />
                    </div>
                </div>
                <div class="flex gap-2 mt-4">
                    <x-button wire:click="createHardware" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                    <x-button wire:click="cancelEdit" label="لغو" icon="o-x-mark" class="btn-ghost" />
                </div>
            </div>
        @endif

        {{-- Edit Modal --}}
        @if($showEditModal && $editingId)
            <x-modal wire:model="showEditModal" title="ویرایش سخت افزار" close-on-backdrop>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="relative">
                        <x-input wire:model="personSearch" label="کد ملی / نام پرسنل" placeholder="جستجو..." />
                        @if(count($personResults) > 0)
                            <div class="absolute z-10 bg-base-100 border rounded-lg shadow-lg w-full mt-1 max-h-48 overflow-auto">
                                @foreach($personResults as $pr)
                                    <div class="px-3 py-2 hover:bg-base-200 cursor-pointer text-sm"
                                         wire:click="selectPerson('{{ $pr['n_code'] }}', '{{ $pr['name'] }}')">
                                        {{ $pr['name'] }} ({{ $pr['n_code'] }})
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if($selectedPersonName)
                            <div class="text-xs text-success mt-1">✓ {{ $selectedPersonName }} ({{ $n_code }})</div>
                        @endif
                        @error('n_code') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>
                    <x-input wire:model="pc_name" label="نام دستگاه" required />
                    <x-input wire:model="type" label="نوع" />
                    <x-input wire:model="os" label="سیستم عامل" />
                    <x-input wire:model="ip_valid" label="IP عمومی" />
                    <x-input wire:model="ip_local" label="IP محلی" x-mask="099.099.099.099" />
                    <x-input wire:model="mac" label="MAC Address" x-mask="**:**:**:**:**:**" />
                    <x-input wire:model="net_type" label="نوع شبکه" />
                    <x-input wire:model="switch" label="سوئیچ" />
                    <x-input wire:model="port" label="پورت" />
                    <x-input wire:model="vlan" label="VLAN" />
                    <x-input wire:model="motherboard" label="مادربورد" />
                    <x-input wire:model="cpu" label="CPU" />
                    <x-input wire:model="ram" label="RAM" />
                    <x-input wire:model="hdd" label="HDD/SSD" />
                    <x-input wire:model="clean_at" label="تاریخ نظافت" type="date" />
                    <div class="form-control">
                        <label class="label cursor-pointer gap-2">
                            <input type="checkbox" wire:model="shutdown" class="checkbox checkbox-sm" />
                            <span class="label-text">فعال</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer gap-2">
                            <input type="checkbox" wire:model="mark" class="checkbox checkbox-sm" />
                            <span class="label-text">علامت</span>
                        </label>
                    </div>
                    <div class="md:col-span-2">
                        <x-input wire:model="comments" label="توضیحات" />
                    </div>
                </div>
                <x-slot:actions>
                    <x-button wire:click="updateHardware" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                    <x-button @click="$wire.set('showEditModal', false); $wire.cancelEdit()" label="لغو" icon="o-x-mark" class="btn-ghost" />
                </x-slot:actions>
            </x-modal>
        @endif

        {{-- Audit History Modal (Blade partial) --}}
        @include('livewire.hardware.audit-modal')

        {{-- Trash Modal (Blade partial) --}}
        @include('livewire.hardware.trash-modal')

        {{-- Hardware Table: Mobile Cards + Desktop (Blade partial) --}}
        @include('livewire.hardware.table')
    </x-card>
</div>

@script
<script>
    Livewire.on('download-export', (url) => {
        window.location.href = url;
    });
</script>
@endscript
