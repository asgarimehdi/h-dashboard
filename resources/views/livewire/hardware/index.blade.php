<?php

use App\Models\Hardware;
use App\Models\Person;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;
    use PersianNormalizer;

    public string $search = '';
    public int $perPage = 10;
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

        return $query->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds));
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

    // Column visibility
    public array $visibleCols = [
        'type' => true,
        'os' => true,
        'ip_local' => true,
        'cpu' => true,
        'ram' => true,
        'hdd' => true,
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

        $this->personResults = Person::where('n_code', 'LIKE', "%{$normalized}%")
            ->orWhere(function ($q) use ($normalized) {
                $q->where('f_name', 'LIKE', "%{$normalized}%")
                  ->orWhere('l_name', 'LIKE', "%{$normalized}%");
            })
            ->limit(10)
            ->get()
            ->map(fn($p) => ['n_code' => $p->n_code, 'name' => trim($p->f_name . ' ' . $p->l_name)])
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

        $person = Person::where('n_code', $value)->first();

        if ($person) {
            $this->n_code_status = 'valid';
            $this->n_code_name = trim($person->f_name . ' ' . $person->l_name);
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
            'n_code' => 'required|string|exists:persons,n_code',
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
        $this->selectedPersonName = $hw->person ? trim($hw->person->f_name . ' ' . $hw->person->l_name) : null;
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
        } catch (\Exception $e) {
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
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->pluck('id')
            ->toArray();

        if (empty($scopedIds)) {
            $this->error('هیچ ردیفی در محدوده دسترسی شما نیست.', position: 'toast-bottom');
            return;
        }

        Hardware::whereIn('id', $scopedIds)->update(['mark' => $value]);
        $this->selected = [];
        $this->success('وضعیت علامت‌گذاری تغییر کرد.', position: 'toast-bottom');
    }

    public function bulkDelete(): void
    {
        if (empty($this->selected)) return;

        $accessibleIds = $this->accessibleUnitIds();
        $scopedIds = Hardware::whereIn('id', $this->selected)
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->pluck('id')
            ->toArray();

        if (empty($scopedIds)) {
            $this->error('هیچ ردیفی در محدوده دسترسی شما نیست.', position: 'toast-bottom');
            return;
        }

        Hardware::whereIn('id', $scopedIds)->delete();
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
            ['key' => 'type', 'label' => 'نوع', 'class' => 'hidden md:table-cell ' . ($this->visibleCols['type'] ? '' : 'hidden')],
            ['key' => 'os', 'label' => 'OS', 'class' => 'hidden lg:table-cell ' . ($this->visibleCols['os'] ? '' : 'hidden')],
            ['key' => 'ip_local', 'label' => 'IP', 'class' => 'hidden lg:table-cell ' . ($this->visibleCols['ip_local'] ? '' : 'hidden')],
            ['key' => 'cpu', 'label' => 'CPU', 'class' => 'hidden xl:table-cell ' . ($this->visibleCols['cpu'] ? '' : 'hidden')],
            ['key' => 'ram', 'label' => 'RAM', 'class' => 'hidden xl:table-cell ' . ($this->visibleCols['ram'] ? '' : 'hidden')],
            ['key' => 'hdd', 'label' => 'HDD', 'class' => 'hidden xl:table-cell ' . ($this->visibleCols['hdd'] ? '' : 'hidden')],
            ['key' => 'status', 'label' => 'وضعیت', 'class' => 'w-24 ' . ($this->visibleCols['status'] ? '' : 'hidden')],
        ];
    }

    public function hardwares(): LengthAwarePaginator
    {
        $query = $this->applyOrgScope(Hardware::with('person'));

        // General search
        if (!empty($this->search)) {
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
                        ->orWhere('l_name', 'LIKE', "%{$s}%");
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
                  ->orWhere('n_code', 'LIKE', "%{$normalized}%");
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

        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'hardwares' => $this->hardwares()->through(fn($hw) => [
                ...$hw->toArray(),
                'person_name' => $hw->person ? trim($hw->person->f_name . ' ' . $hw->person->l_name) : '-',
                'status' => $hw->mark ? 'mark' : ($hw->shutdown ? 'on' : 'off'),
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
        <div class="flex gap-2 items-center mb-4">
            <x-button class="btn-success" wire:click="startCreate" label="افزودن" icon="o-plus" responsive />
            <div class="flex-1">
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
                    <div class="flex gap-1">
                        <x-button icon="o-archive-box" class="btn-ghost btn-sm" label="ستون‌ها" wire:click="$toggle('showColPanel')" />
                        <x-button icon="o-trash" class="btn-error btn-ghost btn-sm" label="حذف" wire:click="bulkDelete" spinner :disabled="empty($selected)" wire:confirm="آیا مطمئن هستید؟" />
                        <x-button icon="o-check-circle" class="btn-success btn-ghost btn-sm" label="علامت" wire:click="bulkMark(true)" spinner :disabled="empty($selected)" />
                        <x-button icon="o-x-circle" class="btn-ghost btn-sm" label="برداشتن" wire:click="bulkMark(false)" spinner :disabled="empty($selected)" />
                    </div>
                </div>

                {{-- Quick Presets --}}
        <div class="flex flex-wrap gap-2 mb-4">
            <x-button icon="o-cpu-chip" class="btn-outline btn-xs" label="لپ‌تاپ‌ها" wire:click="$set('filterType', 'laptop')" />
            <x-button icon="o-server" class="btn-outline btn-xs" label="سرورها" wire:click="$set('filterType', 'server')" />
            <x-button icon="o-server-stack" class="btn-outline btn-xs" label="رم 16GB+" wire:click="$set('filterRam', '16384')" />
            <x-button icon="o-computer-desktop" class="btn-outline btn-xs" label="فقط SSD" wire:click="$set('filterHdd', 'SSD')" />
            <x-button icon="o-power" class="btn-outline btn-xs text-error" label="خاموش‌ها" wire:click="$set('filterShutdown', '0')" />
            <x-button icon="o-check-circle" class="btn-outline btn-xs text-success" label="علامت‌دارها" wire:click="$set('filterMark', '1')" />
            <x-button icon="o-x-mark" class="btn-ghost btn-xs" label="پاکسازی" wire:click="clearFilters" />
        </div>

        {{-- Filters --}}
        @if($showFilters)
            <div class="mb-4 p-4 bg-base-200 rounded-lg">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <x-input wire:model.live.debounce="filterType" label="نوع دستگاه" placeholder="pc, laptop..." clearable />
                    <x-input wire:model.live.debounce="filterOs" label="سیستم عامل" placeholder="Windows 10..." clearable />
                    <x-input wire:model.live.debounce="filterCpu" label="CPU" placeholder="Intel, AMD..." clearable />
                    <x-input wire:model.live.debounce="filterRam" label="RAM" placeholder="4096, 8192..." clearable />
                    <x-input wire:model.live.debounce="filterHdd" label="HDD/SSD" placeholder="SSD, 500GB..." clearable />
                    <x-input wire:model.live.debounce="filterNetType" label="نوع شبکه" placeholder="wired, wireless..." clearable />
                    <x-select wire:model.live="filterShutdown" label="وضعیت روشن/خاموش"
                        :options="collect([['id' => '', 'name' => 'همه'], ['id' => '1', 'name' => 'روشن'], ['id' => '0', 'name' => 'خاموش']])" />
                    <x-select wire:model.live="filterMark" label="علامت‌دار"
                        :options="collect([['id' => '', 'name' => 'همه'], ['id' => '1', 'name' => 'علامت‌دار'], ['id' => '0', 'name' => 'بدون علامت']])" />
                    <x-input wire:model.live.debounce="filterPerson" label="پرسنل (نام/کد ملی)" placeholder="جستجو..." clearable />
                    <x-input wire:model.live.debounce="filterUnit" label="مرکز/واحد" placeholder="نام واحد..." clearable />
                    <x-input wire:model.live.debounce="filterSemat" label="سمت" placeholder="پزشک، ممرض..." clearable />
                </div>
                @if($this->hasActiveFilters())
                    <div class="mt-3 flex items-center gap-2">
                        <x-button icon="o-x-mark" label="پاک کردن فیلترها" class="btn-ghost btn-sm" wire:click="clearFilters" />
                        <span class="text-xs text-base-content/50">فیلترهای فعال اعمال شده</span>
                    </div>
                @endif
            </div>
        @endif

        @if($showColPanel)
            <div class="mb-4 p-4 bg-base-200 rounded-lg border-l-4 border-primary">
                <div class="flex items-center gap-2 mb-2 font-bold text-sm">
                    <x-icon name="o-columns-3" class="w-4 h-4" />
                    مدیریت نمایش ستون‌ها
                </div>
                <div class="flex flex-wrap gap-3">
                    @foreach($visibleCols as $key => $visible)
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input type="checkbox" wire:model.live="visibleCols.{{ $key }}" class="checkbox checkbox-xs" />
                            {{ $headers()->collect()->firstWhere('key', $key)['label'] ?? $key }}
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

        {{-- Mobile Card Layout --}}
        <div class="grid grid-cols-1 gap-4 md:hidden">
            @foreach($hardwares as $hw)
                <div class="p-4 bg-base-100 border rounded-xl shadow-sm {{ $hw['mark'] ? 'border-r-4 border-r-warning bg-warning/10' : '' }}">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <div class="font-bold text-lg">{{ $hw['pc_name'] }}</div>
                            <div class="text-xs text-base-content/60">{{ $hw['person_name'] }}</div>
                        </div>
                        <div class="flex gap-2">
                             @if($hw['status'] === 'mark')
                                <x-badge value="⚑ علامت" class="badge-warning" />
                            @elseif($hw['status'] === 'off')
                                <x-badge value="⬛ خاموش" class="badge-neutral" />
                            @else
                                <x-badge value="🟢 فعال" class="badge-success" />
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-xs mb-4">
                        <div class="flex justify-between">
                            <span class="opacity-50">نوع:</span> <span>{{ $hw['type'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="opacity-50">OS:</span> <span>{{ $hw['os'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="opacity-50">IP:</span> <span>{{ $hw['ip_local'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="opacity-50">RAM:</span> <span>{{ $hw['ram'] }}</span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <x-button icon="o-pencil" wire:click="editHardware({{ $hw['id'] }})" class="btn-ghost btn-xs text-primary flex-1" label="ویرایش" />
                        <x-button icon="o-trash" wire:click="delete({{ $hw['id'] }})" wire:confirm="آیا مطمئن هستید؟" spinner class="btn-ghost btn-xs text-error flex-1" label="حذف" />
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Desktop Table --}}
        <div class="hidden md:block">
            <x-table :headers="$headers" :rows="$hardwares" :sort-by="$sortBy" with-pagination per-page="perPage"
                    :per-page-values="[5, 10, 25, 50]" :row-decoration="['bg-warning/20 border-r-4 border-r-warning' => fn($row) => $row['mark']]">
                @scope('cell_checkbox', $hw)
                    <input type="checkbox" wire:model="selected" value="{{ $hw['id'] }}" class="checkbox checkbox-sm" />
                @endscope
                @scope('cell_status', $hw)
                    @if($hw['status'] === 'mark')
                        <x-badge value="⚑ علامت" class="badge-warning" />
                    @elseif($hw['status'] === 'off')
                        <x-badge value="⬛ خاموش" class="badge-neutral" />
                    @else
                        <x-badge value="🟢 فعال" class="badge-success" />
                    @endif
                @endscope
                @scope('actions', $hw)
                    <div class="flex gap-1">
                        <x-button icon="o-pencil" wire:click="editHardware({{ $hw['id'] }})" class="btn-ghost btn-sm text-primary" />
                        <x-button icon="o-trash" wire:click="delete({{ $hw['id'] }})" wire:confirm="آیا مطمئن هستید؟" spinner class="btn-ghost btn-sm text-error" />
                    </div>
                @endscope
            </x-table>
        </div>
    </x-card>
</div>
