{{-- Hardware Audit History Modal --}}
{{-- Included as Blade partial — shares parent Livewire component scope --}}

@if($showHistoryModal)
    <x-modal wire:model="showHistoryModal" title="تاریخچه تغییرات" close-on-backdrop>
        <div class="mb-3 flex flex-wrap gap-2">
            <x-button :class="$historyActionFilter === null ? 'btn-primary btn-sm' : 'btn-ghost btn-sm'" wire:click="filterHistory(null)" label="همه" />
            <x-button :class="$historyActionFilter === 'created' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm'" wire:click="filterHistory('created')" label="ایجاد" />
            <x-button :class="$historyActionFilter === 'updated' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm'" wire:click="filterHistory('updated')" label="ویرایش" />
            <x-button :class="$historyActionFilter === 'deleted' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm'" wire:click="filterHistory('deleted')" label="حذف" />
            <x-button :class="$historyActionFilter === 'bulk_mark' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm'" wire:click="filterHistory('bulk_mark')" label="علامت گروهی" />
            <x-button :class="$historyActionFilter === 'bulk_delete' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm'" wire:click="filterHistory('bulk_delete')" label="حذف گروهی" />
            <x-button :class="$historyActionFilter === 'rollback' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm'" wire:click="filterHistory('rollback')" label="بازگردانی" />
        </div>

        @if(count($history) === 0)
            <div class="text-center py-8 text-base-content/50">تاریخچه‌ای ثبت نشده است.</div>
        @else
            <div class="space-y-3 max-h-96 overflow-y-auto">
                @foreach($history as $entry)
                    <div class="border rounded-lg p-3 bg-base-200/50">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                @php
                                    $badgeClass = match($entry['action']) {
                                        'created' => 'badge-success',
                                        'updated' => 'badge-info',
                                        'deleted', 'bulk_delete' => 'badge-error',
                                        'bulk_mark' => 'badge-warning',
                                        'rollback' => 'badge-secondary',
                                        default => 'badge-neutral',
                                    };
                                    $actionLabel = match($entry['action']) {
                                        'created' => 'ایجاد',
                                        'updated' => 'ویرایش',
                                        'deleted' => 'حذف',
                                        'bulk_mark' => 'علامت گروهی',
                                        'bulk_delete' => 'حذف گروهی',
                                        'rollback' => 'بازگردانی',
                                        default => $entry['action'],
                                    };
                                    $sourceLabel = match($entry['source'] ?? '') {
                                        'api' => 'API',
                                        'import' => 'ایمپورت',
                                        'bulk' => 'گروهی',
                                        default => 'وب',
                                    };
                                @endphp
                                <x-badge :value="$actionLabel" :class="$badgeClass" />
                                <x-badge value="{{ $sourceLabel }}" class="badge-outline badge-xs" />
                                <span class="text-xs opacity-60">{{ $entry['user']['name'] ?? $entry['user']['n_code'] ?? 'سیستم' }}</span>
                            </div>
                            <span class="text-xs opacity-50">{{ \Morilog\Jalali\Jalalian::fromDateTime($entry['created_at'])->format('Y/m/d H:i') }}</span>
                        </div>
                        @if(!empty($entry['changes']) && is_array($entry['changes']))
                            <div class="space-y-1 mt-1">
                                @foreach($entry['changes'] as $change)
                                    @if(is_array($change) && isset($change['field']))
                                        <div class="flex items-center justify-between gap-2 text-xs bg-base-300/30 rounded px-2 py-1"
                                             title="{{ $change['old'] ?? '' }} ← {{ $change['new'] ?? '' }}">
                                            <span class="font-mono">
                                                <span class="text-error">{{ $change['old'] ?? '—' }}</span>
                                                <span class="opacity-50 mx-1">←</span>
                                                <span class="text-success">{{ $change['new'] ?? '—' }}</span>
                                                <span class="opacity-50 ms-1">({{ $change['field'] }})</span>
                                            </span>
                                            @if(in_array($entry['action'], ['updated', 'rollback']) && ($change['old'] ?? '—') !== '—')
                                                <button
                                                    wire:click="rollbackHistoryField({{ $entry['id'] }}, '{{ $change['field'] }}')"
                                                    wire:confirm="آیا از بازگردانی فیلد {{ $change['field'] }} به مقدار «{{ $change['old'] ?? '' }}» مطمئن هستید؟"
                                                    class="btn btn-ghost btn-xs text-primary hover:bg-primary/10 shrink-0"
                                                    title="بازگردانی این فیلد"
                                                >
                                                    <x-icon name="o-arrow-path" class="w-3 h-3" />
                                                    بازگردانی
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @if($entry['ip_address'])
                            <div class="text-[10px] opacity-40 mt-1">IP: {{ $entry['ip_address'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($historyTotal > $historyPerPage)
                <div class="flex justify-center items-center gap-2 mt-4">
                    <x-button icon="o-chevron-right" class="btn-circle btn-sm"
                        :disabled="$historyCurrentPage <= 1"
                        wire:click="historyPage({{ $historyCurrentPage - 1 }})" />
                    <span class="text-sm">صفحه {{ $historyCurrentPage }} از {{ ceil($historyTotal / $historyPerPage) }}</span>
                    <x-button icon="o-chevron-left" class="btn-circle btn-sm"
                        :disabled="$historyCurrentPage >= ceil($historyTotal / $historyPerPage)"
                        wire:click="historyPage({{ $historyCurrentPage + 1 }})" />
                </div>
            @endif
        @endif
    </x-modal>
@endif
