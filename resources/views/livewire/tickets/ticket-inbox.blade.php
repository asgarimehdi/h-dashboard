<div class="p-4 space-y-4">
    <x-header title="صندوق تیکت‌های پشتیبانی" separator progress-indicator>
        <x-slot:actions>
            <x-input
                placeholder="جستجوی کد یا موضوع..."
                wire:model.live.debounce="search"
                icon="o-magnifying-glass"
                class="input-sm shadow-sm"
                clearable />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow class="bg-base-100 border-none shadow-sm rounded-3xl">
        <div class="flex flex-col md:flex-row gap-4 justify-between items-center mb-4">
            {{-- Tab View Selection --}}
            <div class="join shadow-sm border border-base-200 p-1 bg-base-200/50 rounded-2xl">
                <button
                    wire:click="updateFilter('received', 'pending')"
                    class="join-item btn btn-sm {{ $viewMode === 'received' ? 'btn-primary shadow-md' : 'btn-ghost' }} rounded-xl px-6">
                    ورودی‌ها و اقدامات من
                </button>

                <button
                    wire:click="updateFilter('sent', 'pending')"
                    class="join-item btn btn-sm {{ $viewMode === 'sent' ? 'btn-primary shadow-md' : 'btn-ghost' }} rounded-xl px-6">
                    ارسالی‌ها و اقدامات من
                </button>
            </div>

            {{-- Date Pickers (Integration with your existing script) --}}
            <div class="flex items-center gap-2 bg-base-200/50 p-1.5 rounded-2xl border border-base-200" wire:ignore>
                <input data-jdp id="filter_date_from" placeholder="از تاریخ"
                    class="input input-ghost input-xs w-24 text-center cursor-pointer focus:bg-white transition-all rounded-lg" readonly>
                <span class="text-base-content/40 text-xs font-bold">تا</span>
                <input data-jdp id="filter_date_to" placeholder="تا تاریخ"
                    class="input input-ghost input-xs w-24 text-center cursor-pointer focus:bg-white transition-all rounded-lg" readonly>
            </div>
        </div>

        {{-- Status Filter Buttons --}}
        <div class="flex flex-wrap gap-2 pt-4 border-t border-base-200">
            <x-button
                wire:click="$set('statusFilter', 'all')"
                label="همه تیکت‌ها"
                class="btn-xs {{ $statusFilter === 'all' ? 'btn-neutral' : 'btn-outline border-base-300' }} rounded-lg" />

            @if($viewMode === 'received')
            <x-button wire:click="$set('statusFilter', 'pending')" label="در انتظار بررسی" class="btn-xs {{ $statusFilter === 'pending' ? 'btn-warning text-white' : 'btn-ghost border-base-200' }} rounded-lg" />
            <x-button wire:click="$set('statusFilter', 'accepted')" label="در حال انجام" class="btn-xs {{ $statusFilter === 'accepted' ? 'btn-info text-white' : 'btn-ghost border-base-200' }} rounded-lg" />
            <x-button wire:click="$set('statusFilter', 'rejected')" label="رد شده توسط ما" class="btn-xs {{ $statusFilter === 'rejected' ? 'btn-error text-white' : 'btn-ghost border-base-200' }} rounded-lg" />
            <x-button wire:click="$set('statusFilter', 'completed')" label="انجام شده" class="btn-xs {{ $statusFilter === 'completed' ? 'btn-success text-white' : 'btn-ghost border-base-200' }} rounded-lg" />
            @else
            <x-button wire:click="$set('statusFilter', 'pending')" label="منتظر تایید " class="btn-xs {{ $statusFilter === 'pending' ? 'btn-warning text-white' : 'btn-ghost border-base-200' }} rounded-lg" />
            <x-button wire:click="$set('statusFilter', 'accepted')" label="تایید شده مقصد" class="btn-xs {{ $statusFilter === 'accepted' ? 'btn-info text-white' : 'btn-ghost border-base-200' }} rounded-lg" />
            <x-button wire:click="$set('statusFilter', 'rejected')" label="رد شده مقصد" class="btn-xs {{ $statusFilter === 'rejected' ? 'btn-error text-white' : 'btn-ghost border-base-200' }} rounded-lg" />
            <x-button wire:click="$set('statusFilter', 'completed')" label="نهایی شده" class="btn-xs {{ $statusFilter === 'completed' ? 'btn-success text-white' : 'btn-ghost border-base-200' }} rounded-lg" />
            @endif
        </div>
    </x-card>

    <x-card class="bg-base-100 border-none shadow-sm rounded-3xl overflow-hidden mt-4">
        <x-table :headers="[
        ['key' => 'user.person.f_name', 'label' => 'ایجاد کننده', 'class' => 'w-48'],
        ['key' => 'priority', 'label' => 'اولویت', 'class' => 'hidden md:table-cell text-center'],
        ['key' => 'status_name', 'label' => 'وضعیت', 'class' => 'hidden md:table-cell text-center'],
        ['key' => 'subject', 'label' => 'موضوع'],
        ['key' => 'unit.name', 'label' => 'نزد واحد', 'class' => 'hidden md:table-cell text-center'],
        ['key' => 'actions', 'label' => 'عملیات', 'sortable' => false, 'class' => 'text-left'],
    ]"
            :rows="$tickets" with-pagination dir="rtl">
            @scope('cell_user.person.f_name', $ticket)
            <div class="flex items-center gap-3">

                <div class="flex flex-col">
                    <span class="font-bold text-sm">{{ $ticket->user->person?->f_name }} {{ $ticket->user->person?->l_name }}</span>
                    <span class="text-[10px] opacity-50 font-mono">#{{ $ticket->ticket_code }}</span>
                </div>
            </div>
            @endscope

            @scope('cell_priority', $ticket)
            @php
            $pClasses = ['urgent' => 'badge-error', 'normal' => 'badge-info', 'low' => 'badge-ghost'];
            $pLabels = ['urgent' => 'فوری', 'normal' => 'معمولی', 'low' => 'کم‌اهمیت'];
            @endphp
            <span class="badge badge-sm font-bold py-3 px-4 {{ $pClasses[$ticket->priority] ?? 'badge-ghost' }} text-white">
                {{ $pLabels[$ticket->priority] ?? '---' }}
            </span>
            @endscope

            @scope('cell_status_name', $ticket)
            <div class="badge badge-outline badge-sm font-bold opacity-70">{{ $ticket->status_name }}</div>
            @endscope

            @scope('cell_subject', $ticket)
            <span class="text-sm font-medium line-clamp-1 max-w-[150px]" title="{{ $ticket->subject }}">
                {{Str::limit( $ticket->subject, 15, '...') }}
            </span>
            @endscope
            @scope('cell_unit.name', $ticket)
            <span class="text-xs font-medium" title="{{ $ticket->unit->name }}">
                {{ Str::limit($ticket->unit->name, 15, '...') }}
            </span>
            @endscope
            @scope('actions', $ticket)
            <div class="flex justify-end gap-1">
                @if($ticket->status !== 'accepted' && $ticket->status !== 'rejected' && $ticket->status !== 'completed' && $ticket->unit_id == auth()->user()->person?->u_id)
                <x-button icon="o-check" wire:click="acceptTicket({{ $ticket->id }})" class="btn-ghost btn-sm text-success" tooltip="تایید" spinner />
                <x-button icon="o-x-mark" wire:click="rejectTicket({{ $ticket->id }})"
                    wire:confirm="آیا مطمئن هستید؟" class="btn-ghost btn-sm text-error" tooltip="رد" spinner />
                @endif

                <x-button icon="o-eye" wire:click="showTicket({{ $ticket->id }})" class="btn-ghost btn-sm text-info" tooltip="مشاهده" spinner />

                @if($ticket->status !== 'completed' && $ticket->status !== 'rejected' && $ticket->unit_id == auth()->user()->person?->u_id)
                <x-button icon="o-arrow-path" wire:click="openCompletionModal({{ $ticket->id }})" class="btn-ghost btn-sm text-primary" tooltip="عملیات / ارجاع" spinner />
                @endif
            </div>
            @endscope
        </x-table>
    </x-card>

    <x-modal wire:model="showModal" title="جزئیات تیکت" separator class="backdrop-blur-md" box-class="max-w-4xl rounded-[2.5rem]">
        @if($showingTicket)
        <div class="space-y-6 text-right" dir="rtl">
            {{-- Main Request --}}
            <div class="relative bg-base-200/50 p-6 rounded-3xl border border-base-300 shadow-inner">
                <span class="absolute -top-3 right-6 badge badge-primary py-3 px-4 font-bold">شرح درخواست</span>
                <p class="text-sm leading-8 pt-2">{{ $showingTicket->content }}</p>

                @php $initialFiles = $showingTicket->attachments->where('activity_id', null); @endphp
                @if($initialFiles->count() > 0)
                <div class="mt-4 flex flex-wrap gap-2 border-t border-base-300 pt-4">
                    @foreach($initialFiles as $file)
                    <x-button :label="$file->file_name" icon="o-paper-clip" link="{{ asset('storage/' . $file->file_path) }}"
                        class="btn-xs btn-outline rounded-xl" external target="_blank" />
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Timeline --}}
            <div class="space-y-4 pt-4">
                <h4 class="text-xs font-black opacity-50 pr-2 border-r-4 border-primary uppercase tracking-widest">گردش فعالیت‌ها</h4>
                <div class="space-y-4">
                    @foreach($showingTicket->activities->sortByDesc('created_at') as $activity)
                    <div class="flex gap-4 group">
                        <div class="flex flex-col items-center">
                            <div class="w-3 h-3 rounded-full bg-primary mt-2"></div>
                            <div class="w-0.5 h-full bg-base-200"></div>
                        </div>
                        <div class="bg-base-100 p-4 rounded-2xl border border-base-200 w-full group-hover:shadow-md transition-all">
                            <div class="flex justify-between items-center mb-2">
                                <span class="font-bold text-xs">{{ $activity->user->person?->f_name }} {{ $activity->user->person?->l_name }}</span>
                                <span class="text-[9px] opacity-40 font-mono">{{ jdate($activity->created_at)->format('H:i - Y/m/d') }}</span>
                            </div>
                            <p class="text-xs opacity-70 leading-6">{{ $activity->description }}</p>
                            @if($activity->attachments->count() > 0)
                            <div class="flex gap-1 mt-3">
                                @foreach($activity->attachments as $actFile)
                                <x-button icon="o-arrow-down-tray" link="{{ asset('storage/' . $actFile->file_path) }}"
                                    class="btn-xs btn-square btn-ghost text-primary" external target="_blank" />
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
        <x-slot:actions>
            <x-button label="متوجه شدم" class="btn-primary rounded-xl px-10" @click="$wire.showModal = false" />
        </x-slot:actions>
    </x-modal>

   <x-modal wire:model="isCompletionModalOpen" class="backdrop-blur-md" box-class="rounded-[2.5rem] p-0">
    <div class="p-6 {{ ($targetUnitId || $selectedAssigneeId) ? 'bg-info/10' : 'bg-success/10' }} border-b border-base-200 text-center">
        <h3 class="text-lg font-black {{ ($targetUnitId || $selectedAssigneeId) ? 'text-info' : 'text-success' }}">
            {{ ($targetUnitId || $selectedAssigneeId) ? '🚀 عملیات ارجاع تیکت' : '✅ اعلام اتمام فعالیت' }}
        </h3>
    </div>

    <x-form wire:submit.prevent="submitAction({{ $showingTicketId }})" class="p-6 space-y-4" dir="rtl">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- ارجاع به واحد (Unit Search) --}}
            <div class="space-y-2">
                <x-input label="ارجاع به واحد مقصد:"
                    wire:model.live="unitSearch"
                    placeholder="جستجوی واحد..."
                    icon="o-building-office"
                    class="rounded-2xl"
                    :disabled="$selectedAssigneeId" /> {{-- اگر شخص انتخاب شود، این قفل می‌شود --}}

                @if(!empty($units) && !$selectedAssigneeId)
                <div class="menu bg-base-100 rounded-2xl border border-base-200 shadow-xl p-2 max-h-40 overflow-y-auto">
                    @foreach($units as $u)
                    <button type="button" wire:click="selectTargetUnit({{ $u->id }}, '{{ $u->name }}')" class="btn btn-ghost btn-sm justify-start text-xs font-bold">
                        {{ $u->name }}
                    </button>
                    @endforeach
                </div>
                @endif

                @if($targetUnitId)
                <div class="alert alert-info py-2 rounded-2xl shadow-sm">
                    <span class="text-xs font-bold">مقصد: {{ $targetUnitName }}</span>
                    <x-button label="لغو" wire:click="$set('targetUnitId', null)" class="btn-xs btn-ghost text-white underline" />
                </div>
                @endif
            </div>

            {{-- ارجاع به کارشناس هم‌واحدی --}}
            <div class="space-y-2">
                <x-select label="ارجاع به همکار (داخلی):"
                    wire:model.live="selectedAssigneeId"
                    :options="$this->myTeam" {{-- لیستی که در مرحله قبل توضیح دادم --}}
                    option-label="full_name"
                    placeholder="انتخاب همکار..."
                    icon="o-user"
                    class="rounded-2xl"
                    :disabled="$targetUnitId" /> {{-- اگر واحد انتخاب شود، این قفل می‌شود --}}
                
                @if($selectedAssigneeId)
                <div class="alert alert-info py-2 rounded-2xl shadow-sm">
                    <span class="text-xs font-bold">کارشناس انتخاب شد</span>
                    <x-button label="لغو" wire:click="$set('selectedAssigneeId', null)" class="btn-xs btn-ghost text-white underline" />
                </div>
                @endif
            </div>
        </div>

        <x-textarea
            label="توضیحات یا گزارش نهایی:"
            wire:model="completionNote"
            rows="4"
            class="rounded-2xl" />

        <x-file wire:model="completionFiles" label="پیوست مستندات" multiple icon="o-cloud-arrow-up" class="rounded-2xl" />

        <div class="flex gap-2 pt-4">
            <x-button label="انصراف" @click="$wire.isCompletionModalOpen = false" class="btn-ghost flex-1 rounded-2xl" />
            <x-button
                type="submit"
                label="{{ ($targetUnitId || $selectedAssigneeId) ? 'تایید و ارجاع نهایی' : 'تکمیل و بستن تیکت' }}"
                class="flex-[2] rounded-2xl {{ ($targetUnitId || $selectedAssigneeId) ? 'btn-info text-white' : 'btn-success text-white' }}"
                spinner />
        </div>
    </x-form>
</x-modal>
</div>
@script
<script>
    // استفاده از ایونت خود لایووایر برای اطمینان از لود شدن DOM
    $wire.on('init-picker', () => {
        jalaliDatepicker.startWatch();
    });

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

    // اجرا در حالت عادی
    initJdp();

    // اجرا برای جابجایی بین صفحات با wire:navigate
    document.addEventListener('livewire:navigated', initJdp);
</script>
@endscript