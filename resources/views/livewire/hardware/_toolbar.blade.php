    <x-header title="شناسنامه سخت افزار" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="hardware" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />
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
