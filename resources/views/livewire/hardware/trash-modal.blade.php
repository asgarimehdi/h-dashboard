{{-- Hardware Trash Modal: Deleted Hardware List + Restore --}}
{{-- Included as Blade partial — shares parent Livewire component scope --}}
{{--
    #1170: the payload is the CURRENT PAGE of the scoped filtered set
    (`trashRows`), paginated server-side like the main units/hardware
    tables (MaryUI `x-table`), with the «تعداد کل» counter taken from the
    paginator's total. The in-modal prev/next controls mirror the history
    modal: the component's `WithPagination` query-string paginator belongs
    to the outer hardware list, so a second paginator here would share `?page`.
--}}

@if($showTrashModal)
    <x-modal wire:model="showTrashModal" title="سخت‌افزارهای حذف شده" close-on-backdrop>
        @if(count($trashRows) === 0)
            <div class="text-center py-8 text-base-content/50">هیچ سخت‌افزار حذف شده‌ای در دسترس شما نیست.</div>
        @else
            <x-table
                :rows="$trashRows"
                :headers="[
                    ['key' => 'pc_name', 'label' => 'نام دستگاه', 'sortable' => false],
                    ['key' => 'deleted_at', 'label' => 'حذف شده در', 'class' => 'hidden md:table-cell whitespace-nowrap', 'sortable' => false],
                    ['key' => 'user_n_code', 'label' => 'ایجاد توسط', 'class' => 'hidden md:table-cell', 'sortable' => false],
                    ['key' => 'changes', 'label' => 'تغییرات', 'sortable' => false],
                    ['key' => 'restore', 'label' => '', 'class' => 'w-1', 'sortable' => false],
                ]"
            >
                @scope('cell_pc_name', $row)
                    @php
                        $pcNameField = collect($row['changes'])->firstWhere('field', 'pc_name');
                    @endphp
                    <div class="font-bold">{{ $pcNameField['new'] ?? ('سخت‌افزار #' . $row['hardware_id']) }}</div>
                @endscope

                @scope('cell_deleted_at', $row)
                    @php
                        $deletedAt = $row['deleted_at'] ?? null;
                    @endphp
                    <span class="text-xs opacity-70">
                        {{ $deletedAt ? \Morilog\Jalali\Jalalian::fromDateTime($deletedAt)->format('Y/m/d H:i') : 'نامشخص' }}
                    </span>
                @endscope

                @scope('cell_user_n_code', $row)
                    <span class="text-xs opacity-70">{{ $row['user_n_code'] ?? 'سیستم' }}</span>
                @endscope

                @scope('cell_changes', $row)
                    <div class="flex flex-wrap gap-1">
                        @foreach($row['changes'] as $change)
                            @if(isset($change['field']) && $change['field'] !== 'pc_name' && $change['field'] !== 'n_code')
                                <span class="badge badge-outline badge-sm" title="{{ $change['old'] ?? '' }} ← {{ $change['new'] ?? '' }}">
                                    {{ $change['field'] }}: {{ $change['new'] ?? '—' }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                @endscope

                @scope('cell_restore', $row)
                    @php
                        $canRestore = collect($row['changes'])->contains('field', 'n_code');
                    @endphp
                    @if($canRestore)
                        <x-ui.icon-button name="بازگردانی سخت‌افزار" icon="o-arrow-uturn-left"
                            class="btn-ghost btn-xs text-warning"
                            wire:click="restoreRecord({{ $row['id'] }})"
                            spinner
                            title="بازگردانی این سخت‌افزار" />
                    @else
                        <span class="text-[10px] text-error opacity-70" title="این رکورد n_code ندارد - قابل بازگردانی نیست">
                            ⚠ قابل بازگردانی نیست
                        </span>
                    @endif
                @endscope
            </x-table>

            <div class="flex justify-between items-center mt-2 gap-2">
                <span class="text-sm opacity-70">تعداد کل: <span class="font-bold">{{ $trashTotal }}</span></span>
                @if($trashTotal > count($trashRows))
                    <div class="flex justify-center items-center gap-2">
                        <x-ui.icon-button name="صفحه قبل" icon="o-chevron-right" class="btn-circle btn-sm"
                            :disabled="$trashCurrentPage <= 1"
                            wire:click="trashPage({{ $trashCurrentPage - 1 }})" />
                        <span class="text-sm">صفحه {{ $trashCurrentPage }} از {{ max(1, ceil($trashTotal / 20)) }}</span>
                        <x-ui.icon-button name="صفحه بعد" icon="o-chevron-left" class="btn-circle btn-sm"
                            :disabled="$trashCurrentPage >= ceil($trashTotal / 20)"
                            wire:click="trashPage({{ $trashCurrentPage + 1 }})" />
                    </div>
                @endif
            </div>
        @endif
    </x-modal>
@endif
