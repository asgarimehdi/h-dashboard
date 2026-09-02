{{-- Hardware Trash Modal: Deleted Hardware List + Restore --}}
{{-- Included as Blade partial — shares parent Livewire component scope --}}

@if($showTrashModal)
    <x-modal wire:model="showTrashModal" title="سخت‌افزارهای حذف شده" close-on-backdrop>
        @if(count($deletedHardware) === 0)
            <div class="text-center py-8 text-base-content/50">هیچ سخت‌افزار حذف شده‌ای در دسترس شما نیست.</div>
        @else
            <div class="space-y-3 max-h-96 overflow-y-auto">
                @foreach($deletedHardware as $audit)
                    <div class="border rounded-lg p-3 bg-base-200/50">
                        <div class="flex items-center justify-between mb-2">
                            @php
                                $pcNameField = collect($audit->changes)->firstWhere('field', 'pc_name');
                                $deletedAt = $audit->deleted_at ?? null;
                            @endphp
                            <div>
                                <div class="font-bold">{{ $pcNameField['new'] ?? ('سخت‌افزار #' . $audit->hardware_id) }}</div>
                                <div class="text-xs opacity-60">
                                    حذف شده در {{ $deletedAt ? \Morilog\Jalali\Jalalian::fromDateTime($deletedAt)->format('Y/m/d H:i') : 'نامشخص' }}
                                    توسط {{ $audit->user['n_code'] ?? 'سیستم' }}
                                </div>
                            </div>
                            @php
                                $canRestore = collect($audit->changes)->contains('field', 'n_code');
                            @endphp
                            @if($canRestore)
                                <x-button icon="o-arrow-uturn-left"
                                    class="btn-ghost btn-xs text-warning"
                                    wire:click="restoreRecord({{ $audit->id }})"
                                    spinner
                                    title="بازگردانی این سخت‌افزار" />
                            @else
                                <span class="text-[10px] text-error opacity-70" title="این رکورد n_code ندارد - قابل بازگردانی نیست">
                                    ⚠ قابل بازگردانی نیست
                                </span>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($audit->changes as $change)
                                @if(isset($change['field']) && $change['field'] !== 'pc_name' && $change['field'] !== 'n_code')
                                    <span class="badge badge-outline badge-sm" title="{{ $change['old'] ?? '' }} ← {{ $change['new'] ?? '' }}">
                                        {{ $change['field'] }}: {{ $change['new'] ?? '—' }}
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-modal>
@endif
