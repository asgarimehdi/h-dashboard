    <x-header title="شناسنامه سخت افزار" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="hardware" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />
        {{-- #1175: the row checkbox is bound DEFERRED (see table.blade.php), so the
             server does not know about the ticked rows yet. The count and the
             disabled state therefore live on the client — with a server-rendered
             count the bulk buttons would stay disabled and the user could never
             send the first request at all.
             `sel` is seeded from the server so rows picked on another page are
             counted too; `selection-changed` refreshes it the moment a box is
             ticked; the effect keeps it truthful when the server changes
             `$selected` (a bulk delete clears it), because Livewire never
             re-initialises `x-data` on a re-render. --}}
        <div class="flex flex-wrap gap-2 items-center mb-4"
             x-data="{ sel: @js(count($selected)) }"
             x-on:selection-changed.window="sel = $wire.selected.length"
             x-effect="sel = $wire.selected.length">
            <x-ui.icon-button name="افزودن سخت‌افزار" label="افزودن" class="btn-success" wire:click="startCreate" icon="o-plus" responsive />
            <div class="flex-1 min-w-[180px]">
                <x-input
                    placeholder="جستجو در تمام فیلدها..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
            <x-ui.icon-button name="نمایش فیلترها" icon="o-funnel"
                :class="$showFilters ? 'btn-primary' : 'btn-ghost'"
                wire:click="$toggle('showFilters')"
                />
            <div class="flex flex-wrap gap-1">
                <x-button icon="o-arrow-down-tray" class="btn-ghost btn-sm" label="خروجی اکسل" wire:click="exportExcel" spinner />
                <x-button icon="o-archive-box" class="btn-ghost btn-sm" label="ستون‌ها" wire:click="toggleColPanel" />
                <x-button icon="o-trash" class="btn-error btn-ghost btn-sm" label="حذف" wire:click="bulkDelete" spinner :disabled="empty($selected)" x-bind:disabled="sel === 0" wire:confirm="آیا مطمئن هستید؟" />
                <x-button icon="o-check-circle" class="btn-success btn-ghost btn-sm" label="علامت" wire:click="bulkMark(true)" spinner :disabled="empty($selected)" x-bind:disabled="sel === 0" />
                <x-button icon="o-x-circle" class="btn-ghost btn-sm" label="برداشتن" wire:click="bulkMark(false)" spinner :disabled="empty($selected)" x-bind:disabled="sel === 0" />
                {{-- The count is client-side, so the accessible name is bound to it as
                     well — a screen reader must read the same text a sighted user
                     reads ("۳ انتخاب"), and an icon-only button needs a name. --}}
                <x-button icon="o-x-mark" class="btn-ghost btn-sm text-base-content/50" wire:click="clearSelection" title="پاک کردن انتخاب" x-bind:aria-label="sel + ' انتخاب'" x-show="sel > 0">
                    <span x-text="sel + ' انتخاب'"></span>
                </x-button>
            </div>
                </div>

                {{-- Quick Presets + Filters (Blade partial) --}}
        @include('livewire.hardware.filters')
