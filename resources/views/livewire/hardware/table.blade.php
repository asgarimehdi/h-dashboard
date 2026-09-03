{{-- Hardware Table: Mobile Cards + Desktop Table --}}
{{-- Included as Blade partial — shares parent Livewire component scope --}}

{{-- Mobile Card Layout --}}
<div class="grid grid-cols-1 gap-4 md:hidden">
    @foreach($hardwares as $hw)
        <div class="p-4 bg-base-100 border rounded-xl shadow-sm {{ $hw['mark'] ? 'border-r-4 border-r-warning bg-warning/10' : '' }}">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <div class="font-bold text-lg">{{ $hw['pc_name'] }}</div>
                    <div class="text-xs text-base-content/60">{{ $hw['person_name'] }}</div>
                    <div class="text-xs text-base-content/40">{{ $hw['unit_name'] }}</div>
                </div>
                <div class="flex gap-2">
                     @if($hw['status'] === 'mark')
                        <x-badge value="علامت" class="badge-warning" />
                    @elseif($hw['status'] === 'off')
                        <x-badge value="خاموش" class="badge-neutral" />
                    @else
                        <x-badge value="فعال" class="badge-success" />
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
                <x-button icon="o-clock" wire:click="loadHistory({{ $hw['id'] }})" class="btn-ghost btn-xs text-info flex-1" label="تاریخچه" />
                <x-button icon="o-trash" wire:click="delete({{ $hw['id'] }})" wire:confirm="آیا مطمئن هستید؟" spinner class="btn-ghost btn-xs text-error flex-1" label="حذف" />
            </div>
        </div>
    @endforeach
</div>

{{-- Desktop Table --}}
<div class="hidden md:block">
    <x-table :headers="$headers" :rows="$hardwares" :sort-by="$sortBy" with-pagination per-page="perPage"
            :per-page-values="[10, 20, 50, 100]" :row-decoration="['bg-warning/20 border-r-4 border-r-warning' => fn($row) => $row['mark']]">
        @scope('cell_checkbox', $hw)
            <input type="checkbox" wire:model.live="selected" value="{{ $hw['id'] }}" class="checkbox checkbox-sm" />
        @endscope
        @scope('cell_status', $hw)
            @if($hw['status'] === 'mark')
                <x-badge value="علامت" class="badge-warning" />
            @elseif($hw['status'] === 'off')
                <x-badge value="خاموش" class="badge-neutral" />
            @else
                <x-badge value="فعال" class="badge-success" />
            @endif
        @endscope
        @scope('actions', $hw)
            <div class="flex gap-1">
                <x-button icon="o-pencil" wire:click="editHardware({{ $hw['id'] }})" class="btn-ghost btn-sm text-primary" />
                <x-button icon="o-clock" wire:click="loadHistory({{ $hw['id'] }})" class="btn-ghost btn-sm text-info" />
                <x-button icon="o-trash" wire:click="delete({{ $hw['id'] }})" wire:confirm="آیا مطمئن هستید؟" spinner class="btn-ghost btn-sm text-error" />
            </div>
        @endscope
    </x-table>
</div>
