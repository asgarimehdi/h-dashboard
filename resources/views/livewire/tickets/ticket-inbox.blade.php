<div class="p-6">
    <div class="max-w-7xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800"> صندوق تیکت ها</h2>
            <input type="text" wire:model.live="search" placeholder="جستجوی کد یا موضوع..." class="border-gray-200 rounded-xl text-sm w-64">

            <div class="flex items-center gap-2" wire:ignore>
                <input data-jdp id="filter_date_from"
                    onclick="jalaliDatepicker.show(this)"
                    placeholder="از تاریخ"
                    class="border-gray-200 rounded-xl text-[10px] w-28 p-2 cursor-pointer" readonly>

                <input data-jdp id="filter_date_to"
                    onclick="jalaliDatepicker.show(this)"
                    placeholder="تا تاریخ"
                    class="border-gray-200 rounded-xl text-[10px] w-28 p-2 cursor-pointer" readonly>
            </div>
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
        <div class="bg-white shadow-sm border border-gray-100 rounded-2xl overflow-hidden">
            <div class="p-6 " dir="rtl">
                {{-- سوئیچ اصلی جهت تیکت --}}
                <div class="flex bg-gray-200 p-1 rounded-2xl w-fit mb-8 shadow-inner">
                    <button wire:click="$set('viewMode', 'received'); $set('statusFilter', 'pending')"
                        class="px-8 py-2.5 rounded-xl text-sm font-bold transition-all {{ $viewMode === 'received' ? 'bg-white shadow-lg text-blue-700' : 'text-gray-500 hover:text-gray-700' }}">
                        ورودی‌ها و اقدامات من
                    </button>
                    <button wire:click="$set('viewMode', 'sent'); $set('statusFilter', 'pending')"
                        class="px-8 py-2.5 rounded-xl text-sm font-bold transition-all {{ $viewMode === 'sent' ? 'bg-white shadow-lg text-blue-700' : 'text-gray-500 hover:text-gray-700' }}">
                        ارسالی‌ها و اقدامات من
                    </button>

                </div>

                {{-- تب‌های وضعیت داینامیک --}}
                <div class="flex flex-wrap gap-3 mb-6">
                    <button wire:click="$set('statusFilter', 'all')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'all' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-500 border-gray-200' }}">همه</button>

                    @if($viewMode === 'received')
                    {{-- تب‌های بخش دریافتی --}}
                    <button wire:click="$set('statusFilter', 'pending')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'pending' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-gray-500' }}">در انتظار بررسی</button>
                    <button wire:click="$set('statusFilter', 'accepted')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'accepted' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-500' }}">قبول شده / در حال انجام</button>
                    <button wire:click="$set('statusFilter', 'rejected')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'rejected' ? 'bg-red-600 text-white border-red-600' : 'bg-white text-gray-500' }}">رد شده (توسط واحد ما)</button>
                    <button wire:click="$set('statusFilter', 'completed')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'completed' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-500' }}">انجام شده</button>
                    @else
                    {{-- تب‌های بخش ارسالی --}}
                    <button wire:click="$set('statusFilter', 'pending')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'pending' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-gray-500' }}">منتظر تایید مقصد</button>
                    <button wire:click="$set('statusFilter', 'accepted')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'accepted' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-500' }}">تایید شده توسط مقصد</button>
                    <button wire:click="$set('statusFilter', 'rejected')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'rejected' ? 'bg-red-600 text-white border-red-600' : 'bg-white text-gray-500' }}">رد شده توسط مقصد</button>
                    <button wire:click="$set('statusFilter', 'completed')" class="px-5 py-1.5 rounded-full border text-xs font-bold {{ $statusFilter === 'completed' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-500' }}">تکمیل و نهایی شده</button>
                    @endif
                </div>
                <div class="flex gap-4 mb-4 items-center">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-white border border-gray-200"></span>
                        <span class="text-[10px] text-gray-500 font-bold">تیکت‌های ایجاد شده توسط من</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-green-100 border border-gray-200"></span>
                        <span class="text-[10px] text-gray-500 font-bold">تیکت‌های ارجاعی (اقدام شده)</span>
                    </div>
                </div>
                {{-- ادامه کد جدول تیکت‌ها که قبلا داشتید --}}
            </div>
            <table class="w-full text-right">
                <thead class="bg-gray-50 text-gray-500 text-sm">
                    <tr>
                        <th class="p-4 text-right">کد / ایجاد کننده</th>
                        <th class="p-4 text-center">اولویت</th>
                        <th class="p-4 text-center">وضعیت</th>
                        <th class="p-4 text-center">زمان انتظار</th>
                        <th class="p-4 text-right">موضوع</th>
                        <th class="p-4 text-center">در انتظار تایید...</th>
                        <th class="p-4 text-left">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($tickets as $ticket)

                    @php
                    // تشخیص اینکه آیا کاربر فعلی سازنده این تیکت است یا خیر
                    $isOwner = $ticket->user_id === auth()->id();
                    @endphp

                    <tr class="transition-all {{ $isOwner ? 'bg-white' : 'bg-green-100' }} hover:bg-indigo-50/30">
                        <td class="p-4 relative">
                            {{-- اضافه کردن یک نوار رنگی کوچک در کنار ردیف برای تشخیص سریع‌تر --}}
                            @if($isOwner)
                            <div class="absolute right-0 top-0 bottom-0 w-1 bg-indigo-500 rounded-l-md" title="ایجاد شده توسط شما"></div>
                            @endif

                            <span class="font-mono text-indigo-600 block text-xs">#{{ $ticket->ticket_code }}</span>
                            <span class="text-sm font-bold text-gray-700">{{ ($ticket->user->person?->f_name . ' ' . $ticket->user->person?->l_name) ?: 'کاربر سیستم' }}</span>
                        </td>

                        {{-- اولویت --}}
                        <td class="p-4 text-center">
                            @php
                            $priorityColors = [
                            'urgent' => 'bg-red-100 text-red-700 border-red-200',
                            'normal' => 'bg-blue-100 text-blue-700 border-blue-200',
                            'low' => 'bg-gray-100 text-gray-700 border-gray-200',
                            ];
                            $priorityLabels = ['urgent' => 'فوری', 'normal' => 'معمولی', 'low' => 'کم‌اهمیت'];
                            @endphp
                            <span class="px-2 py-1 rounded-full text-[10px] font-bold border {{ $priorityColors[$ticket->priority] ?? $priorityColors['low'] }}">
                                {{ $priorityLabels[$ticket->priority] ?? 'نامشخص' }}
                            </span>
                        </td>

                        {{-- وضعیت با رنگ‌بندی اختصاصی --}}
                        <td class="p-4 text-center">
                            @php
                            $statusColors = [
                            'created' => 'bg-purple-100 text-purple-700 border-purple-200',
                            'forwarded' => 'bg-amber-100 text-amber-700 border-amber-200',
                            'accepted' => 'bg-blue-100 text-blue-700 border-blue-200',
                            'completed' => 'bg-gray-100 text-gray-700 border-gray-200',
                            'rejected' => 'bg-red-100 text-red-700 border-red-200',
                            ];
                            @endphp
                            <span class="px-2 py-1 rounded-full text-[10px] font-bold border {{ $statusColors[$ticket->status] ?? 'bg-gray-100' }}">
                                {{ $ticket->status_name }}
                            </span>
                        </td>
                        <td class="p-4 text-center">
                            @if(in_array($ticket->status, ['completed']))
                            <span class="text-xs text-gray-400">-----</span>
                            @elseif(in_array($ticket->status, ['rejected']))
                            <span class="text-xs text-gray-400">-----</span>
                            @else
                            <span class="px-2 py-1 rounded-lg text-[10px] font-bold {{ $ticket->waiting_duration['class'] }}">
                                {{ $ticket->waiting_duration['text'] }}
                            </span>
                            @endif
                        </td>
                        <td class="p-4">
                            <div class="text-sm font-bold text-gray-800" title="{{ $ticket->subject }}">
                                {{ str($ticket->subject)->limit(15, '...') }}
                            </div>
                        </td>
                        <td class="p-4 text-center">
                            @if(in_array($ticket->status, ['created', 'forwarded']))
                            <div class="flex flex-col items-center">
                                <span class="text-[9px] text-gray-400 mb-1">نزد واحد:</span>
                                <span class="text-xs font-medium text-amber-700 bg-amber-50 px-2 py-1 rounded-lg border border-amber-200">
                                    {{ $ticket->unit?->name ?? '---' }}
                                </span>
                            </div>
                            @elseif($ticket->status === 'accepted')
                            <span class="text-xs font-medium text-blue-700 bg-blue-50 px-2 py-1 rounded-lg border border-blue-200">
                                تایید شده در {{ $ticket->unit?->name }}
                            </span>
                            @else
                            <span class="text-xs text-gray-400">نهایی شده</span>
                            @endif
                        </td>
                        <td class="p-4 text-left flex items-center justify-end gap-2">
                            <div class="flex items-center justify-center gap-1">
                                {{-- نمایش نام مسئول (تست رابطه assignee) --}}
                                @if($ticket->current_assignee_id)
                                <!-- <span class="text-[10px] bg-gray-50 text-gray-500 px-2 py-1 rounded border">
                                در کارتابل: <b>{{ $ticket->assignee?->full_name ?? 'خطا در رابطه' }}</b>
                            </span> -->
                                @endif



                                {{-- دکمه تایید و رد فقط برای تیکت‌های پذیرفته نشده --}}
                                @if($ticket->status !== 'accepted' &&
                                $ticket->status !== 'rejected'&&
                                $ticket->status !== 'completed'
                                && $ticket->unit_id == auth()->user()->person?->u_id)
                                <button wire:click="acceptTicket({{ $ticket->id }})"
                                    class="group relative p-2 text-emerald-600 hover:bg-emerald-50 rounded-xl transition-all"
                                    title="تایید و شروع کار">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span class="absolute bottom-full mb-2 hidden group-hover:block bg-gray-800 text-white text-[10px] px-2 py-1 rounded">تایید</span>
                                </button>


                                <button onclick="confirm('آیا از رد تیکت اطمینان دارید؟') || event.stopImmediatePropagation()"
                                    wire:click="rejectTicket({{ $ticket->id }})"
                                    class="group relative p-2 text-red-600 hover:bg-red-50 rounded-xl transition-all"
                                    title="عدم تایید / رد">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span class="absolute bottom-full mb-2 hidden group-hover:block bg-gray-800 text-white text-[10px] px-2 py-1 rounded">رد تیکت</span>
                                </button>
                                @endif
                                <button wire:click="showTicket({{ $ticket->id }})"
                                    class="group relative p-2 text-blue-600 hover:bg-blue-50 rounded-xl transition-all"
                                    title="مشاهده جزئیات و تاریخچه">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    <span class="absolute bottom-full mb-2 hidden group-hover:block bg-gray-800 text-white text-[10px] px-2 py-1 rounded">جزئیات</span>
                                </button>

                                @if($ticket->status !== 'completed'
                                && $ticket->status !== 'rejected'
                                && $ticket->unit_id == auth()->user()->person?->u_id)
                                <button wire:click="openCompletionModal({{ $ticket->id }})"
                                    class="group relative p-2 text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                    title="ارجاع یا اتمام کار">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                    </svg>
                                    <span class="absolute bottom-full mb-2 hidden group-hover:block bg-gray-800 text-white text-[10px] px-2 py-1 rounded">عملیات</span>
                                </button>
                                @endif

                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="p-10 text-center text-gray-400">تیکتی یافت نشد.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-4">{{ $tickets->links() }}</div>
        </div>
    </div>
    {{-- مودال ۲: عملیات (ارجاع / بستن) --}}
    {{-- مودال ۲: عملیات (ارجاع / بستن) --}}
    @if($isCompletionModalOpen)
    <div class="fixed inset-0 bg-gray-900/70 backdrop-blur-md z-[70] flex items-center justify-center p-4">
        {{-- اضافه کردن flex flex-col و محدود کردن ارتفاع کل مودال --}}
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden border border-white/50">

            {{-- هدر (ثابت) --}}
            <div class="p-5 text-center border-b relative {{ $targetUnitId ? 'bg-indigo-50' : 'bg-emerald-50' }} shrink-0">
                <h3 class="text-base font-black {{ $targetUnitId ? 'text-indigo-800' : 'text-emerald-800' }}">
                    {{ $targetUnitId ? '🚀 عملیات ارجاع تیکت' : '✅ اعلام اتمام فعالیت' }}
                </h3>
                <button wire:click="closeAllModals" class="absolute top-4 left-4 text-gray-400 hover:text-red-500 text-2xl transition-colors">
                    &times;
                </button>
            </div>

            {{-- بدنه مودال (بخش اسکرول‌شونده) --}}
            <div class="p-6 overflow-y-auto custom-scrollbar space-y-5 flex-1" dir="rtl">

                {{-- فیلد جستجوی واحد --}}
                <div class="space-y-2">
                    <label class="block text-xs font-black text-gray-600 mr-2">ارجاع به واحد دیگر (اختیاری):</label>
                    <div class="relative group">
                        <input type="text" wire:model.live="unitSearch"
                            class="w-full bg-gray-50 border-gray-200 rounded-2xl px-4 py-2.5 text-sm focus:ring-4 focus:ring-indigo-100 transition-all"
                            placeholder="جستجوی واحد مقصد...">

                        @if(!empty($units))
                        <div class="absolute z-[80] w-full bg-white shadow-2xl rounded-2xl mt-1 border border-gray-100 max-h-40 overflow-y-auto p-2">
                            @foreach($units as $u)
                            <button wire:click="selectTargetUnit({{ $u->id }}, '{{ $u->name }}')"
                                class="w-full text-right px-4 py-2 hover:bg-indigo-50 rounded-xl text-xs font-bold text-gray-700">
                                {{ $u->name }}
                            </button>
                            @endforeach
                        </div>
                        @endif
                    </div>

                    @if($targetUnitId)
                    <div class="flex items-center justify-between bg-indigo-600 text-white px-4 py-2 rounded-xl mt-1">
                        <span class="text-[11px] font-bold">مقصد: {{ $targetUnitName }}</span>
                        <button wire:click="$set('targetUnitId', null)" class="text-[10px] bg-white/20 px-2 py-0.5 rounded-lg">لغو</button>
                    </div>
                    @endif
                </div>

                {{-- فیلد توضیحات --}}
                <div class="space-y-2">
                    <label class="block text-xs font-black text-gray-600 mr-2">
                        {{ $targetUnitId ? 'علت یا توضیحات ارجاع (اختیاری):' : 'گزارش نهایی کارشناس (اجباری):' }}
                    </label>
                    <textarea wire:model="completionNote"
                        class="w-full bg-gray-50 border-gray-200 rounded-2xl p-3 text-sm focus:ring-4 focus:ring-emerald-100 transition-all"
                        rows="3" placeholder="توضیحات..."></textarea>
                    @error('completionNote') <span class="text-red-500 text-[10px] font-bold">{{ $message }}</span> @enderror
                </div>

                {{-- بخش آپلود فایل --}}
                <div class="space-y-2">
                    <label class="block text-xs font-black text-gray-600 mr-2">مستندات پیوست:</label>
                    <div class="relative border-2 border-dashed border-gray-200 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-emerald-50/50 transition-all">
                        <input type="file" wire:model="completionFiles" multiple class="absolute inset-0 opacity-0 cursor-pointer">
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                        </svg>
                        <span class="text-[10px] font-bold text-gray-500">کشیدن یا انتخاب فایل</span>
                    </div>

                    {{-- لیست فایل‌ها --}}
                    @if($completionFiles)
                    <div class="space-y-2 mt-2">
                        @foreach($completionFiles as $index => $file)
                        <div class="flex items-center justify-between bg-gray-50 p-2 rounded-xl border border-gray-100">
                            <span class="text-[10px] text-gray-600 truncate max-w-[200px]">{{ $file->getClientOriginalName() }}</span>
                            <button type="button" wire:click="removeFile({{ $index }})" class="text-red-500 hover:bg-red-50 p-1 rounded-md">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>

            {{-- فوتر (ثابت در پایین) --}}
            <div class="p-5 border-t bg-gray-50 shrink-0">
                @php $isAccepted = $showingTicket?->status === 'accepted'; @endphp

                @if(!$isAccepted && !$targetUnitId)
                <div class="text-center bg-amber-100 text-amber-800 p-3 rounded-xl text-[10px] font-bold">
                    ⚠️ تیکت تایید نشده را فقط می‌توانید ارجاع دهید.
                </div>
                @else
                <button wire:click="submitAction({{ $showingTicketId }})"
                    class="w-full py-3.5 rounded-2xl text-sm font-black text-white shadow-lg transition-all 
                        {{ $targetUnitId ? 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-100' : 'bg-emerald-600 hover:bg-emerald-700 shadow-emerald-100' }}">
                    {{ $targetUnitId ? 'تایید و ارجاع تیکت' : 'ثبت نهایی و بستن تیکت' }}
                </button>
                @endif
            </div>
        </div>
    </div>
    @endif
    {{-- مودال جزئیات --}}
   @if($showingTicket)
    <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden border border-white/20">
            {{-- هدر --}}
            <div class="p-6 border-b flex justify-between items-center bg-gray-50/50 sticky top-0 z-10">
                <div class="text-right" dir="rtl">
                    <h3 class="text-lg font-extrabold text-gray-800">{{ $showingTicket->subject }}</h3>
                    <span class="text-xs text-indigo-500 font-mono bg-indigo-50 px-2 py-0.5 rounded-full">#{{ $showingTicket->ticket_code }}</span>
                </div>
                <button wire:click="closeDetail" class="w-10 h-10 flex items-center justify-center rounded-full text-gray-400 hover:bg-red-50 hover:text-red-500 transition-all text-2xl">&times;</button>
            </div>

            {{-- محتوا --}}
            <div class="p-6 overflow-y-auto space-y-8 text-right custom-scrollbar" dir="rtl">
                
                {{-- ۱. متن اصلی تیکت و فایل‌های اولیه --}}
                <div class="relative p-5 bg-gradient-to-br from-gray-50 to-indigo-50/30 rounded-2xl border border-indigo-100/50 shadow-sm">
                    <div class="absolute -top-3 right-4 px-3 py-1 bg-indigo-600 text-white text-[10px] rounded-full shadow-lg">شرح درخواست اصلی</div>
                    <p class="text-gray-700 leading-relaxed text-sm pt-2">{{ $showingTicket->content }}</p>
                    
                    {{-- نمایش فایل‌هایی که در لحظه شروع تیکت آپلود شده‌اند (activity_id ندارند) --}}
                    @php 
                        $initialFiles = $showingTicket->attachments->where('activity_id', null); 
                    @endphp
                    @if($initialFiles->count() > 0)
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-indigo-100 pt-3">
                            @foreach($initialFiles as $file)
                                <a href="{{ asset('storage/' . $file->file_path) }}" target="_blank" 
                                   class="flex items-center gap-2 text-[11px] bg-white text-indigo-700 px-3 py-1.5 rounded-xl border border-indigo-200 hover:bg-indigo-50 transition-all">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                    {{ $file->file_name }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- ۲. تاریخچه و پیگیری‌ها (فعالیت‌ها) --}}
                <div>
                    <h4 class="text-sm font-bold text-gray-800 mb-6 flex items-center gap-2 pr-2 border-r-4 border-amber-500">تاریخچه اقدامات</h4>
                    <div class="relative space-y-6 before:absolute before:right-[11px] before:top-2 before:bottom-2 before:w-0.5 before:bg-gradient-to-b before:from-indigo-500 before:to-gray-100">
                        
                        @foreach($showingTicket->activities->sortByDesc('created_at') as $activity)
                        <div class="relative pr-8">
                            <div class="absolute right-0 top-1 w-6 h-6 rounded-full bg-white border-2 border-indigo-500 flex items-center justify-center z-10 shadow-sm">
                                <div class="w-2 h-2 rounded-full bg-indigo-500"></div>
                            </div>
                            
                            <div class="bg-gray-50/80 backdrop-blur-sm p-4 rounded-2xl border border-gray-100 shadow-sm group hover:bg-white hover:border-indigo-200 transition-all">
                                <div class="flex justify-between items-center mb-3 text-[11px]">
                                    <div class="flex items-center gap-3">
                                        <span class="font-black text-gray-800 bg-white px-2 py-1 rounded-lg shadow-sm border border-gray-100">{{ $activity->user->full_name }}</span>
                                        
                                        {{-- نمایش فایل‌های مخصوص این فعالیت --}}
                                        @if($activity->attachments->count() > 0)
                                            <div class="flex items-center gap-1 bg-amber-50 px-2 py-1 rounded-full border border-amber-100">
                                                @foreach($activity->attachments as $actFile)
                                                    <a href="{{ asset('storage/' . $actFile->file_path) }}" target="_blank" title="{{ $actFile->file_name }}" class="text-amber-600 hover:text-amber-800">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                                    </a>
                                                @endforeach
                                                <span class="text-[9px] font-bold text-amber-500 mr-1">{{ $activity->attachments->count() }} فایل پیوست</span>
                                            </div>
                                        @endif
                                    </div>
                                    <span class="text-gray-400 font-mono">{{ jdate($activity->created_at)->format('H:i - Y/m/d') }}</span>
                                </div>
                                <p class="text-xs text-gray-600 leading-6">{{ $activity->description }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="p-5 border-t bg-white flex justify-end">
                <button wire:click="closeDetail" class="bg-gray-800 text-white px-8 py-2.5 rounded-2xl text-sm font-bold hover:bg-black transition-all shadow-lg shadow-gray-200">متوجه شدم</button>
            </div>
        </div>
    </div>
@endif
</div>