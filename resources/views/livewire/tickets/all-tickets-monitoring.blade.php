<div class="p-4 sm:p-6 bg-base-200/50 min-h-screen" dir="rtl">
    <div class="max-w-7xl mx-auto space-y-6">

        {{-- هدر صفحه --}}
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <h2 class="text-xl font-bold text-gray-800 italic border-r-4 border-indigo-600 pr-3">مانیتورینگ کل تیکت‌های سیستم</h2>
            <div class="flex flex-wrap gap-2 w-full md:w-auto">
                <x-input wire:model.live="search" placeholder="جستجوی کد یا موضوع..." icon="o-magnifying-glass" class="w-full md:w-64" />
                <x-theme-selector />
            </div>
        </div>

        {{-- بخش فیلترها: واحد، تاریخ و وضعیت (در یک ردیف) --}}
        <x-card class="bg-gradient-to-br from-slate-900 to-indigo-950 text-white shadow-2xl border-none overflow-visible">
            <div class="flex flex-col lg:flex-row items-end gap-4 p-2">

                {{-- جستجوی واحد --}}
                <div class="w-full lg:w-1/3 relative text-gray-800">
                    <label class="block text-[10px] uppercase text-white opacity-60 mb-2 font-bold mr-1">واحد عملیاتی مقصد:</label>
                    <x-input
                        wire:model.live="unitSearch"
                        placeholder="جستجوی واحد..."
                        class="bg-white/10 border-white/20 text-white placeholder:text-white/40 focus:bg-white focus:text-gray-900 rounded-2xl h-12" />
                    @if(!empty($unitSearch) && !empty($filterUnits))
                    <div class="absolute z-[100] w-full bg-base-100 shadow-2xl rounded-2xl mt-1 overflow-hidden border border-base-300">
                        @foreach($filterUnits as $u)
                        <button wire:click="selectUnitForFilter({{ $u->id }})" class="w-full text-right px-4 py-3 hover:bg-primary hover:text-white text-sm transition-colors border-b last:border-0 font-bold">
                            {{ $u->name }}
                        </button>
                        @endforeach
                    </div>
                    @endif
                </div>

                {{-- فیلتر تاریخ (شمسی) --}}
                <div class="w-full lg:w-1/3 grid grid-cols-2 gap-2" wire:ignore>
                    <div>
                        <label class="block text-[10px] text-white opacity-60 mb-2 font-bold mr-1">از تاریخ</label>
                        <input data-jdp id="filter_date_from" placeholder="----/--/--" class="input input-bordered w-full bg-white/10 border-white/20 text-white text-center text-xs rounded-2xl h-12 cursor-pointer" readonly>
                    </div>
                    <div>
                        <label class="block text-[10px] text-white opacity-60 mb-2 font-bold mr-1">تا تاریخ</label>
                        <input data-jdp id="filter_date_to" placeholder="----/--/--" class="input input-bordered w-full bg-white/10 border-white/20 text-white text-center text-xs rounded-2xl h-12 cursor-pointer" readonly>
                    </div>
                </div>

                {{-- وضعیت‌ها --}}
                <div class="w-full lg:w-1/3 flex flex-wrap gap-1 justify-end pb-1">
                    @foreach(['all' => 'همه', 'pending' => 'انتظار', 'accepted' => 'انجام', 'completed' => 'تکمیل'] as $key => $label)
                    <x-button
                        label="{{ $label }}"
                        wire:click="$set('statusFilter', '{{ $key }}')"
                        class="btn-sm rounded-xl {{ $statusFilter === $key ? 'btn-primary shadow-lg shadow-primary/30' : 'bg-white/5 border-white/10 text-white' }}" />
                    @endforeach
                </div>
            </div>

            @if($selectedUnitId)
            <div class="mt-4 px-2 flex items-center gap-2">
                <x-badge value="فیلتر فعال: {{ $currentUnit->name }}" class="badge-warning font-black p-3 rounded-lg" icon-right="o-x-mark" wire:click="$set('selectedUnitId', null)" />
            </div>
            @endif
        </x-card>

        {{-- جدول لیست تیکت‌ها --}}
        <x-card shadow class="rounded-[2rem] overflow-hidden border-none shadow-indigo-100">
            <x-table :headers="[
                ['key' => 'ticket_code', 'label' => 'شناسه / فرستنده'],
                ['key' => 'unit.name', 'label' => 'واحد مقصد' ,'class' => 'hidden md:table-cell text-center'],
                ['key' => 'status', 'label' => 'وضعیت'  ,'class' => 'hidden md:table-cell text-center'],
                ['key' => 'duration', 'label' => 'انتظار' ,'class' => 'hidden md:table-cell text-center'],
                ['key' => 'subject', 'label' => 'موضوع'],
                ['key' => 'actions', 'label' => 'جزئیات']
            ]" :rows="$tickets" with-pagination>

                @scope('cell_ticket_code', $ticket)
                <div class="flex flex-col">
                    <span class="font-mono text-primary text-[10px] font-bold">#{{ $ticket->ticket_code }}</span>
                    <span class="font-bold text-sm">{{ $ticket->user->person?->f_name }} {{ $ticket->user->person?->l_name }}</span>
                </div>
                @endscope
                @scope('cell_unit.name', $ticket)
                <span class="text-xs font-medium" title="{{ $ticket->unit->name }}">
                    {{ Str::limit($ticket->unit->name, 15, '...') }}
                </span>
                @endscope
                @scope('cell_status', $ticket)
                <x-badge :value="$ticket->status_name" class="font-black text-[9px] py-3 px-4 {{ $ticket->status === 'accepted' ? 'badge-info text-white' : 'badge-ghost' }}" />
                @endscope

                @scope('cell_duration', $ticket)
                <span class="px-2 py-1 rounded-lg text-[10px] font-black {{ $ticket->waiting_duration['class'] }} border shadow-sm">
                    {{ $ticket->waiting_duration['text'] }}
                </span>
                @endscope
                @scope('cell_subject', $ticket)
                <span class="text-sm font-medium line-clamp-1 max-w-[150px]" title="{{ $ticket->subject }}">
                    {{Str::limit( $ticket->subject, 15, '...') }}
                </span>
                @endscope
                @scope('actions', $ticket)
                <x-button icon="o-eye" wire:click="showTicket({{ $ticket->id }})" class="btn-circle btn-ghost btn-sm text-primary" spinner />
                @endscope
            </x-table>
        </x-card>
    </div>

    {{-- مودال نمایش جزئیات تیکت --}}
    <x-modal wire:model="showModal" class="backdrop-blur-lg" box-class="max-w-3xl rounded-[2.5rem]">
        @if($showingTicket)
        <div class="text-right p-1" dir="rtl">
            {{-- هدر داخلی مودال --}}
            <div class="flex justify-between items-start mb-6 border-b border-base-300 pb-4">
                <div>
                    <h3 class="text-xl font-black text-primary">{{ $showingTicket->subject }}</h3>
                    <div class="flex gap-2 mt-2">
                        <x-badge :value="'#' . $showingTicket->ticket_code" class="badge-primary badge-outline font-mono text-[10px]" />
                        <span class="text-xs text-base-content/50 font-bold">فرستنده: {{ $showingTicket->user?->full_name }}</span>
                    </div>
                </div>
                {{-- دکمه بستن در RTL توسط خود Mary UI در سمت چپ قرار میگیرد --}}
            </div>

            <div class="space-y-8 max-h-[60vh] overflow-y-auto px-2 custom-scrollbar">
                {{-- شرح اصلی تیکت --}}
                <div class="relative mt-4 p-6 bg-base-200/60 rounded-[2rem] border border-base-300 transition-all">
                    <span class="absolute -top-3 right-8 px-5 py-1.5 bg-indigo-600 text-white text-[11px] font-black rounded-full shadow-md z-10">
                        شرح درخواست
                    </span>
                    <p class="text-sm leading-8 text-base-content/80 pt-2 italic">{{ $showingTicket->content }}</p>

                    {{-- پیوست‌های اصلی تیکت --}}
                    @php $initialFiles = $showingTicket->attachments->where('activity_id', null); @endphp
                    @if($initialFiles->count() > 0)
                    <div class="mt-6 flex flex-wrap gap-2 pt-4 border-t border-base-300/50">
                        @foreach($initialFiles as $file)
                        <x-button
                            :label="$file->file_name"
                            icon="o-arrow-down-tray"
                            link="{{ Storage::url($file->file_path) }}"
                            external
                            class="btn-xs btn-outline btn-primary rounded-xl font-bold" />
                        @endforeach
                    </div>
                    @endif
                </div>

                {{-- تایم‌لاین فعالیت‌ها --}}
                <div class="space-y-4">
                    <h4 class="text-[10px] font-black opacity-40 pr-2 uppercase tracking-[0.2em] border-r-4 border-warning mr-1">سیر اقدامات و پاسخ‌ها</h4>
                    <div class="space-y-4 border-r-2 border-primary/20 pr-4 mr-2">
                        @foreach($showingTicket->activities->sortByDesc('created_at') as $activity)
                        <div class="relative bg-base-100 p-4 rounded-2xl border border-base-200 shadow-sm hover:shadow-md transition-all">
                            <div class="absolute -right-[25px] top-5 w-4 h-4 rounded-full bg-primary border-4 border-base-100 shadow-sm"></div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-[11px] font-black text-indigo-600">{{ $activity->user->full_name }}</span>
                                <span class="text-[10px] opacity-40 font-mono">{{ jdate($activity->created_at)->format('H:i - Y/m/d') }}</span>
                            </div>
                            <p class="text-xs opacity-80 leading-6">{{ $activity->description }}</p>

                            {{-- پیوست‌های هر اقدام --}}
                            @if($activity->attachments->count() > 0)
                            <div class="mt-3 flex gap-2">
                                @foreach($activity->attachments as $actFile)
                                <x-button
                                    icon="o-paper-clip"
                                    link="{{ Storage::url($actFile->file_path) }}"
                                    external
                                    class="btn-xs btn-circle btn-warning text-white" />
                                @endforeach
                            </div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif

        <x-slot:actions>
            <x-button label="بستن" wire:click="closeDetail" class="btn-ghost" />
        </x-slot:actions>
    </x-modal>

    {{-- جاوااسکریپت تقویم و رویدادها --}}
    @script
    <script>
        const initMonitoringJdp = () => {
            if (typeof jalaliDatepicker !== 'undefined') {
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
            }
        };

        initMonitoringJdp();
        // اطمینان از اجرای مجدد پس از جابجایی در صفحات Livewire
        document.addEventListener('livewire:navigated', initMonitoringJdp);
    </script>
    @endscript
</div>