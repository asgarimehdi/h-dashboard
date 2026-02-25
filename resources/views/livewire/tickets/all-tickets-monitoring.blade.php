<div>

    {{-- هدر --}}
    <x-header title="مانیتورینگ کل تیکت‌های سیستم" separator progress-indicator>
        <x-slot:middle class="!justify-end"></x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow class="mt-6">

        {{-- جستجو + تاریخ --}}
        <div class="breadcrumbs flex flex-col md:flex-row gap-4 items-center">

            <div class="flex-1 w-full">
                <x-input
                    placeholder="جستجوی کد یا موضوع..."
                    wire:model.live.debounce.500ms="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>

            {{-- تاریخ (دست نخورده) --}}
            <div class="flex items-center gap-2" wire:ignore>
                <input data-jdp id="filter_date_from"
                       placeholder="از تاریخ"
                       class="input input-bordered input-sm w-28 cursor-pointer"
                       readonly>

                <input data-jdp id="filter_date_to"
                       placeholder="تا تاریخ"
                       class="input input-bordered input-sm w-28 cursor-pointer"
                       readonly>
            </div>
        </div>

        {{-- فیلترها --}}
        <div class="mt-6 p-6 rounded-2xl bg-base-200">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">

                {{-- فیلتر واحد --}}
                <div>
                    <label class="label">
                        <span class="label-text font-bold text-xs">
                            واحد عملیاتی مقصد:
                        </span>
                    </label>

                    <div class="relative">
                        <input type="text"
                               wire:model.live="unitSearch"
                               class="input input-bordered w-full"
                               placeholder="نام واحد را جستجو کنید...">

                        @if(!empty($unitSearch) && !empty($filterUnits))
                            <div class="absolute z-50 w-full bg-base-100 shadow-xl rounded-xl mt-2 overflow-hidden border">
                                @foreach($filterUnits as $u)
                                    <button type="button"
                                            wire:click="selectUnitForFilter({{ $u->id }})"
                                            class="w-full text-right px-4 py-2 hover:bg-base-200 text-sm">
                                        {{ $u->name }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- فیلتر وضعیت --}}
                <div class="flex flex-wrap gap-2">
                    <x-button type="button"
                              wire:click="$set('statusFilter', 'all')"
                              label="همه"
                              class="{{ $statusFilter === 'all' ? 'btn-primary' : 'btn-ghost' }}" />

                    <x-button type="button"
                              wire:click="$set('statusFilter', 'pending')"
                              label="در انتظار"
                              class="{{ $statusFilter === 'pending' ? 'btn-warning' : 'btn-ghost' }}" />

                    <x-button type="button"
                              wire:click="$set('statusFilter', 'accepted')"
                              label="در حال انجام"
                              class="{{ $statusFilter === 'accepted' ? 'btn-info' : 'btn-ghost' }}" />

                    <x-button type="button"
                              wire:click="$set('statusFilter', 'completed')"
                              label="تکمیل شده"
                              class="{{ $statusFilter === 'completed' ? 'btn-success' : 'btn-ghost' }}" />
                </div>

            </div>

            @if($selectedUnitId)
                <div class="mt-4 flex items-center gap-3">
                    <div class="badge badge-warning gap-2">
                        فیلتر فعال: {{ $currentUnit->name }}
                    </div>

                    <x-button type="button"
                              wire:click="$set('selectedUnitId', null)"
                              label="حذف فیلتر"
                              class="btn-ghost btn-sm" />
                </div>
            @endif

        </div>

        {{-- جدول --}}
        <div class="mt-6 overflow-x-auto">
            <table class="table table-zebra w-full text-sm">
                <thead>
                <tr>
                    <th>شناسه / فرستنده</th>
                    <th class="text-center">واحد مقصد</th>
                    <th class="text-center">وضعیت فعلی</th>
                    <th class="text-center">مدت انتظار</th>
                    <th>موضوع درخواست</th>
                    <th class="text-left">جزئیات</th>
                </tr>
                </thead>

                <tbody>
                @forelse($tickets as $ticket)
                    <tr wire:key="ticket-{{ $ticket->id }}">
                        <td>
                            <div class="font-mono text-xs opacity-60">
                                #{{ $ticket->ticket_code }}
                            </div>
                            <div class="font-bold">
                                {{ $ticket->user?->full_name }}
                            </div>
                        </td>

                        <td class="text-center">
                            <div class="badge badge-outline">
                                {{ $ticket->unit?->name }}
                            </div>
                        </td>

                        <td class="text-center">
                            <div class="badge {{ $ticket->status === 'accepted' ? 'badge-info' : 'badge-ghost' }}">
                                {{ $ticket->status_name }}
                            </div>
                        </td>

                        <td class="text-center">
                            <div class="badge {{ $ticket->waiting_duration['class'] }}">
                                {{ $ticket->waiting_duration['text'] }}
                            </div>
                        </td>

                        <td>
                            {{ $ticket->subject }}
                        </td>

                        <td class="text-left">
                            <x-button
                                type="button"
                                icon="o-eye"
                                wire:click="showTicket({{ $ticket->id }})"
                                class="btn-ghost btn-sm"
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-10 opacity-60">
                            هیچ تیکتی یافت نشد.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $tickets->links() }}
        </div>

    </x-card>


  {{-- Details Modal --}}
<x-modal
    wire:model="showDetailsModal"
    :title="$showingTicket?->subject"
    class="flex items-center justify-center"
    box-class="max-w-4xl w-full rounded-2xl"
    persistent
    separator
>
        @if($showingTicket)
            <div class="space-y-4 text-right">

                <div class="flex gap-3 items-center">
                    <span class="badge badge-info badge-sm font-mono">
                        #{{ $showingTicket->ticket_code }}
                    </span>

                    <span class="text-xs opacity-60">
                        {{ $showingTicket->user?->full_name }}
                    </span>
                </div>

                <div class="bg-base-200 p-4 rounded-xl">
                    {{ $showingTicket->description }}
                </div>

                @if($showingTicket->attachments->count())
                    <div>
                        <h3 class="font-semibold mb-2">پیوست‌ها</h3>
                        <div class="flex flex-wrap gap-2">
                            @foreach($showingTicket->attachments as $file)
                                <a
                                    href="{{ asset('storage/'.$file->file_path) }}"
                                    target="_blank"
                                    class="badge badge-outline"
                                >
                                    {{ $file->file_name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($showingTicket->activities->count())
                    <div>
                        <h3 class="font-semibold mb-2">فعالیت‌ها</h3>

                        <div class="space-y-3">
                            @foreach($showingTicket->activities as $activity)
                                <div class="bg-base-200 p-3 rounded-xl text-sm">
                                    <div class="font-semibold">
                                        {{ $activity->user?->full_name }}
                                    </div>

                                    <div class="opacity-70 text-xs mb-1">
                                        {{ \Morilog\Jalali\Jalalian::fromCarbon($activity->created_at)->format('Y/m/d H:i') }}
                                    </div>

                                    <div>
                                        {{ $activity->description }}
                                    </div>

                                    @if($activity->attachments->count())
                                        <div class="mt-2 flex gap-2 flex-wrap">
                                            @foreach($activity->attachments as $file)
                                                <a
                                                    href="{{ asset('storage/'.$file->file_path) }}"
                                                    target="_blank"
                                                    class="badge badge-outline badge-sm"
                                                >
                                                    {{ $file->file_name }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="text-left pt-4">
                    <x-button
                        label="بستن"
                        class="btn-neutral"
                        wire:click="closeDetail"
                    />
                </div>

            </div>
        @endif
    </x-modal>


    {{-- تقویم (کاملاً دست نخورده) --}}
    @script
    <script>
        const initJdp = () => {
            jalaliDatepicker.startWatch();
            const fromInput = document.getElementById('filter_date_from');
            const toInput = document.getElementById('filter_date_to');

            if (fromInput) {
                fromInput.addEventListener('jdp:change', e => {
                    $wire.set('dateFrom', e.target.value);
                });
            }

            if (toInput) {
                toInput.addEventListener('jdp:change', e => {
                    $wire.set('dateTo', e.target.value);
                });
            }
        };

        initJdp();
        document.addEventListener('livewire:navigated', initJdp);
    </script>
    @endscript

</div>