<div class="p-6 " dir="rtl">
    <div class="max-w-7xl mx-auto">
        {{-- هدر و ابزارهای جستجو --}}
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <h2 class="text-xl font-bold text-gray-800 italic border-r-4 border-indigo-600 pr-3">مانیتورینگ کل تیکت‌های سیستم</h2>

            <div class="flex flex-wrap gap-2 w-full md:w-auto">
                <input type="text" wire:model.live="search" placeholder="جستجوی کد یا موضوع..." class="border-gray-200 rounded-xl text-sm w-full md:w-64 focus:ring-indigo-500">
                
                <div class="flex items-center gap-2" wire:ignore>
                    <input data-jdp id="filter_date_from" placeholder="از تاریخ" class="border-gray-200 rounded-xl text-[10px] w-28 p-2 cursor-pointer" readonly>
                    <input data-jdp id="filter_date_to" placeholder="تا تاریخ" class="border-gray-200 rounded-xl text-[10px] w-28 p-2 cursor-pointer" readonly>
                </div>
            </div>
        </div>

        {{-- فیلتر واحد --}}
        <div class="bg-indigo-900 rounded-3xl p-6 mb-6 text-white shadow-xl shadow-indigo-100 transition-all">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                <div>
                    <label class="block text-[10px] uppercase tracking-widest opacity-60 mb-2 font-bold">واحد عملیاتی مقصد:</label>
                    <div class="relative">
                        <input type="text" wire:model.live="unitSearch"
                            class="w-full bg-white/10 border-white/20 rounded-2xl p-3 text-sm placeholder-white/30 text-white focus:bg-white focus:text-gray-900 transition-all outline-none"
                            placeholder="برای فیلتر، نام واحد را جستجو کنید...">

                        @if(!empty($unitSearch) && !empty($filterUnits))
                        <div class="absolute z-50 w-full bg-white shadow-2xl rounded-2xl mt-2 overflow-hidden border border-gray-100">
                            @foreach($filterUnits as $u)
                            <button wire:click="selectUnitForFilter({{ $u->id }})"
                                class="w-full text-right px-5 py-3 hover:bg-indigo-50 text-gray-700 text-xs font-bold border-b last:border-0 transition-colors">
                                {{ $u->name }}
                            </button>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button wire:click="$set('statusFilter', 'all')" class="px-5 py-2.5 rounded-xl text-xs font-black transition-all {{ $statusFilter === 'all' ? 'bg-white text-indigo-900' : 'bg-white/10 text-white hover:bg-white/20' }}">همه</button>
                    <button wire:click="$set('statusFilter', 'pending')" class="px-5 py-2.5 rounded-xl text-xs font-black transition-all {{ $statusFilter === 'pending' ? 'bg-amber-400 text-indigo-900 shadow-lg shadow-amber-400/20' : 'bg-white/10 text-white hover:bg-white/20' }}">در انتظار</button>
                    <button wire:click="$set('statusFilter', 'accepted')" class="px-5 py-2.5 rounded-xl text-xs font-black transition-all {{ $statusFilter === 'accepted' ? 'bg-blue-400 text-indigo-900 shadow-lg shadow-blue-400/20' : 'bg-white/10 text-white hover:bg-white/20' }}">در حال انجام</button>
                    <button wire:click="$set('statusFilter', 'completed')" class="px-5 py-2.5 rounded-xl text-xs font-black transition-all {{ $statusFilter === 'completed' ? 'bg-emerald-400 text-indigo-900 shadow-lg shadow-emerald-400/20' : 'bg-white/10 text-white hover:bg-white/20' }}">تکمیل شده</button>
                </div>
            </div>

            @if($selectedUnitId)
            <div class="mt-4 flex items-center gap-3 animate-fade-in">
                <span class="text-[10px] bg-amber-400/20 text-amber-400 px-3 py-1 rounded-full border border-amber-400/30">فیلتر فعال: {{ $currentUnit->name }}</span>
                <button wire:click="$set('selectedUnitId', null)" class="text-white/50 hover:text-white text-[10px] flex items-center gap-1 transition-colors">
                    <span class="text-lg">&times;</span> حذف فیلتر
                </button>
            </div>
            @endif
        </div>

        {{-- لیست تیکت‌ها --}}
        <div class="bg-white shadow-sm border border-gray-100 rounded-3xl overflow-hidden">
            <table class="w-full text-right border-collapse">
                <thead class="bg-gray-50/50 text-gray-400 text-[11px] uppercase tracking-tighter">
                    <tr>
                        <th class="p-5 font-black text-gray-500">شناسه / فرستنده</th>
                        <th class="p-5 text-center font-black text-gray-500">واحد مقصد</th>
                        <th class="p-5 text-center font-black text-gray-500">وضعیت فعلی</th>
                        <th class="p-5 text-center font-black text-gray-500">مدت انتظار</th>
                        <th class="p-5 font-black text-gray-500">موضوع درخواست</th>
                        <th class="p-5 text-left font-black text-gray-500">جزئیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($tickets as $ticket)
                    <tr class="group hover:bg-indigo-50/30 transition-all cursor-default">
                        <td class="p-5">
                            <span class="font-mono text-indigo-500 block text-[10px] mb-1">#{{ $ticket->ticket_code }}</span>
                            <span class="font-extrabold text-gray-800 text-sm group-hover:text-indigo-700 transition-colors">{{ $ticket->user?->full_name }}</span>
                        </td>
                        <td class="p-5 text-center">
                            <span class="text-[10px] bg-gray-100 px-3 py-1.5 rounded-xl text-gray-600 font-bold border border-gray-200">{{ $ticket->unit?->name }}</span>
                        </td>
                        <td class="p-5 text-center">
                            <span class="px-3 py-1.5 rounded-full text-[10px] font-black border {{ $ticket->status === 'accepted' ? 'bg-blue-50 text-blue-600 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200' }}">
                                {{ $ticket->status_name }}
                            </span>
                        </td>
                        <td class="p-5 text-center">
                            <span class="px-3 py-1.5 rounded-xl text-[10px] font-black {{ $ticket->waiting_duration['class'] }} border shadow-sm">
                                {{ $ticket->waiting_duration['text'] }}
                            </span>
                        </td>
                        <td class="p-5 text-sm font-semibold text-gray-700">{{ $ticket->subject }}</td>
                        <td class="p-5 text-left">
                            <button wire:click="showTicket({{ $ticket->id }})" class="p-2 bg-gray-100 hover:bg-indigo-600 hover:text-white text-gray-400 rounded-xl transition-all shadow-sm">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="p-20 text-center">
                            <div class="text-gray-300 italic text-sm flex flex-col items-center gap-3">
                                <svg class="w-12 h-12 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                هیچ تیکتی برای نمایش یافت نشد.
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-6 border-t border-gray-50">{{ $tickets->links() }}</div>
        </div>
    </div>

    {{-- مودال جزئیات کاملاً منطبق با ساختار جدید --}}
    @if($showingTicket)
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden border border-white/20 animate-modal-up">
            
            {{-- هدر مودال --}}
            <div class="p-7 border-b flex justify-between items-center bg-gray-50/50">
                <div class="text-right" dir="rtl">
                    <h3 class="text-xl font-black text-gray-800 tracking-tight">{{ $showingTicket->subject }}</h3>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-[10px] text-indigo-600 font-mono bg-indigo-50 px-2 py-0.5 rounded-full border border-indigo-100">#{{ $showingTicket->ticket_code }}</span>
                        <span class="text-[10px] text-gray-400 font-bold">فرستنده: {{ $showingTicket->user?->full_name }}</span>
                    </div>
                </div>
                <button wire:click="closeDetail" class="w-11 h-11 flex items-center justify-center rounded-full text-gray-400 hover:bg-red-50 hover:text-red-500 transition-all text-2xl font-light">&times;</button>
            </div>

            <div class="p-8 overflow-y-auto space-y-8 text-right custom-scrollbar" dir="rtl">
                {{-- شرح اولیه --}}
                <div class="relative p-6 bg-gradient-to-br from-indigo-50/50 to-white rounded-3xl border border-indigo-100/50 shadow-sm">
                    <div class="absolute -top-3 right-6 px-4 py-1 bg-indigo-600 text-white text-[10px] font-bold rounded-full shadow-lg shadow-indigo-200 uppercase tracking-widest">شرح درخواست</div>
                    <p class="text-gray-700 leading-relaxed text-sm pt-2">{{ $showingTicket->content }}</p>
                    
                    {{-- فایل‌های اولیه (بدون activity_id) --}}
                    @php $initialFiles = $showingTicket->attachments->where('activity_id', null); @endphp
                    @if($initialFiles->count() > 0)
                        <div class="mt-5 flex flex-wrap gap-2 pt-4 border-t border-indigo-100/50">
                            @foreach($initialFiles as $file)
                                <a href="{{ asset('storage/' . $file->file_path) }}" target="_blank" class="flex items-center gap-2 text-[10px] bg-white text-indigo-600 px-3 py-2 rounded-xl border border-indigo-100 hover:shadow-md transition-all font-bold">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    {{ $file->file_name }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- تایم‌لاین فعالیت‌ها --}}
                <div class="relative">
                    <h4 class="text-xs font-black text-gray-400 mb-8 uppercase tracking-widest flex items-center gap-2 border-r-4 border-amber-400 pr-3">تاریخچه و مسیر تیکت</h4>
                    
                    <div class="relative space-y-6 before:absolute before:right-[15px] before:top-2 before:bottom-2 before:w-0.5 before:bg-gray-100">
                        @foreach($showingTicket->activities->sortByDesc('created_at') as $activity)
                        <div class="relative pr-10">
                            <div class="absolute right-0 top-1 w-8 h-8 rounded-full bg-white border-4 border-gray-50 flex items-center justify-center z-10 shadow-sm">
                                <div class="w-2.5 h-2.5 rounded-full {{ $activity->action === 'completed' ? 'bg-emerald-500' : 'bg-indigo-500' }}"></div>
                            </div>
                            
                            <div class="bg-gray-50/50 p-5 rounded-[2rem] border border-gray-100 hover:bg-white hover:shadow-xl hover:shadow-indigo-50/50 transition-all group">
                                <div class="flex justify-between items-center mb-3">
                                    <div class="flex items-center gap-3">
                                        <span class="text-[11px] font-black text-gray-800 bg-white px-3 py-1.5 rounded-xl shadow-sm border border-gray-100">{{ $activity->user->full_name }}</span>
                                        
                                        {{-- نمایش فایل‌های مخصوص این اقدام --}}
                                        @if($activity->attachments->count() > 0)
                                        <div class="flex items-center gap-1.5 bg-amber-50 px-2.5 py-1.5 rounded-full border border-amber-100">
                                            @foreach($activity->attachments as $actFile)
                                                <a href="{{ asset('storage/' . $actFile->file_path) }}" target="_blank" title="{{ $actFile->file_name }}" class="text-amber-600 hover:scale-110 transition-transform">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                </a>
                                            @endforeach
                                            <span class="text-[9px] font-black text-amber-500 mr-1">{{ $activity->attachments->count() }} فایل</span>
                                        </div>
                                        @endif
                                    </div>
                                    <span class="text-[10px] text-gray-400 font-mono">{{ jdate($activity->created_at)->format('H:i - Y/m/d') }}</span>
                                </div>
                                <p class="text-xs text-gray-600 leading-relaxed">{{ $activity->description }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="p-6 border-t bg-white flex justify-end">
                <button wire:click="closeDetail" class="bg-gray-900 text-white px-10 py-3 rounded-2xl text-sm font-bold hover:bg-black transition-all shadow-lg shadow-gray-200">بستن پنجره</button>
            </div>
        </div>
    </div>
    @endif

    {{-- جاوااسکریپت تقویم --}}
    @script
    <script>
        const initJdp = () => {
            jalaliDatepicker.startWatch();
            const fromInput = document.getElementById('filter_date_from');
            const toInput = document.getElementById('filter_date_to');

            if (fromInput) {
                fromInput.addEventListener('jdp:change', e => { $wire.set('dateFrom', e.target.value); });
            }
            if (toInput) {
                toInput.addEventListener('jdp:change', e => { $wire.set('dateTo', e.target.value); });
            }
        };
        initJdp();
        document.addEventListener('livewire:navigated', initJdp);
    </script>
    @endscript
</div>