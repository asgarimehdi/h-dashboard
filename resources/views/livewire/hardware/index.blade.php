<?php

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Observers\HardwareAuditObserver;
use App\Traits\HardwareIndexHelpers;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use HardwareIndexHelpers;
    use PersianNormalizer;
    use Toast;
    use WithPagination;

    public string $search = '';

    public int $perPage = 20;

    public bool $showHelpModal = false;

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
    <x-card shadow>
    @include('livewire.hardware._toolbar')

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

        @include('livewire.hardware._form-create')

        @include('livewire.hardware._form-edit')

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
