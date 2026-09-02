{{-- Hardware Filters: Quick Presets + Advanced Filter Panel --}}
{{-- Included as Blade partial — shares parent Livewire component scope --}}

{{-- Quick Presets --}}
<div class="flex flex-wrap gap-2 mb-4">
    <x-button icon="o-cpu-chip" :class="$filterType === 'laptop' ? 'btn-primary btn-xs' : 'btn-outline btn-xs'" label="لپ‌تاپ‌ها" wire:click="toggleFilter('filterType', 'laptop')" />
    <x-button icon="o-server" :class="$filterType === 'server' ? 'btn-primary btn-xs' : 'btn-outline btn-xs'" label="سرورها" wire:click="toggleFilter('filterType', 'server')" />
    <x-button icon="o-server-stack" :class="$filterRam === '16384' ? 'btn-primary btn-xs' : 'btn-outline btn-xs'" label="رم 16GB+" wire:click="toggleFilter('filterRam', '16384')" />
    <x-button icon="o-computer-desktop" :class="$filterHdd === 'SSD' ? 'btn-primary btn-xs' : 'btn-outline btn-xs'" label="فقط SSD" wire:click="toggleFilter('filterHdd', 'SSD')" />
    <x-button icon="o-power" :class="$filterShutdown === '1' ? 'btn-success btn-xs' : 'btn-outline btn-xs'" label="روشن‌ها" wire:click="toggleFilter('filterShutdown', '1')" />
    <x-button icon="o-check-circle" :class="$filterMark === '1' ? 'btn-success btn-xs' : 'btn-outline btn-xs'" label="علامت‌دارها" wire:click="toggleFilter('filterMark', '1')" />
    <x-button icon="o-arrow-uturn-left" class="btn-ghost btn-xs text-warning" label="حذف شده‌ها" wire:click="loadDeletedHardware()" />
    <x-button icon="o-x-mark" class="btn-ghost btn-xs" label="پاکسازی" wire:click="clearFilters" />
</div>

{{-- Filters --}}
@if($showFilters)
    <div class="mb-4 p-4 bg-base-200 rounded-lg">
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                <div class="{{ $filterType ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterType" label="نوع دستگاه" placeholder="pc, laptop..." clearable />
                </div>
                <div class="{{ $filterOs ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterOs" label="سیستم عامل" placeholder="Windows 10..." clearable />
                </div>
                <div class="{{ $filterCpu ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterCpu" label="CPU" placeholder="Intel, AMD..." clearable />
                </div>
                <div class="{{ $filterRam ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterRam" label="RAM" placeholder="4096, 8192..." clearable />
                </div>
                <div class="{{ $filterHdd ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterHdd" label="HDD/SSD" placeholder="SSD, 500GB..." clearable />
                </div>
                <div class="{{ $filterNetType ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterNetType" label="نوع شبکه" placeholder="wired, wireless..." clearable />
                </div>
                <div class="{{ $filterShutdown !== null && $filterShutdown !== '' ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-select wire:model.live="filterShutdown" label="وضعیت روشن/خاموش"
                        :options="collect([['id' => '', 'name' => 'همه'], ['id' => '1', 'name' => 'روشن'], ['id' => '0', 'name' => 'خاموش']])" />
                </div>
                <div class="{{ $filterMark !== null && $filterMark !== '' ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-select wire:model.live="filterMark" label="علامت‌دار"
                        :options="collect([['id' => '', 'name' => 'همه'], ['id' => '1', 'name' => 'علامت‌دار'], ['id' => '0', 'name' => 'بدون علامت']])" />
                </div>
                <div class="{{ $filterPerson ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterPerson" label="پرسنل (نام/کد ملی)" placeholder="جستجو..." clearable />
                </div>
                <div class="{{ $filterUnit ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterUnit" label="مرکز/واحد" placeholder="نام واحد..." clearable />
                </div>
                <div class="{{ $filterSemat ? 'ring-2 ring-primary rounded-lg p-1 -m-1' : '' }}">
                    <x-input wire:model.live.debounce="filterSemat" label="سمت" placeholder="پزشک، ممرض..." clearable />
                </div>
            </div>
        @if($this->hasActiveFilters())
            <div class="mt-3 flex items-center gap-2">
                <x-button icon="o-x-mark" label="پاک کردن فیلترها" class="btn-ghost btn-sm" wire:click="clearFilters" />
                <span class="text-xs text-base-content/50">فیلترهای فعال اعمال شده</span>
            </div>
        @endif
    </div>
@endif
