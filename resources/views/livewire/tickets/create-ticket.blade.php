<div class="h-screen bg-gray-100 p-2 sm:p-4 overflow-hidden flex flex-col items-center justify-center "
     x-data="{ isUploading: false, progress: 0, errorMessage: '' }">

    <div class="w-full max-w-5xl">
        {{-- پیام موفقیت بسیار فشرده --}}
        @if (session()->has('success'))
            <div class="mb-3 p-3 bg-green-600 text-white rounded-xl shadow-lg flex justify-between items-center animate-bounce">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                    <span class="text-sm font-bold">{{ session('success') }}</span>
                </div>
                <div class="bg-white/20 px-3 py-1 rounded-lg text-xs">
                    کد پیگیری: <span class="font-black select-all">{{ session('ticket_code') }}</span>
                </div>
            </div>
        @endif

        <div class="bg-white rounded-3xl shadow-2xl overflow-hidden border border-gray-200">
            {{-- Header --}}
            <div class="bg-indigo-700 py-3 px-6 text-white flex justify-between items-center">
                <h2 class="text-lg font-black italic">ثبت تیکت جدید</h2>
                <span class="text-[10px] opacity-70">لطفا تمامی فیلدها را با دقت تکمیل کنید</span>
            </div>

            <form wire:submit.prevent="saveTicket" class="p-4 sm:p-6">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
                    
                    {{-- ستون راست: اطلاعات اصلی --}}
                    <div class="md:col-span-7 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            {{-- واحد گیرنده --}}
                            <div class="relative">
                                <label class="block text-[11px] font-bold text-gray-500 mb-1 mr-1">واحد گیرنده</label>
                                <input type="text" wire:model.live.debounce.300ms="search" placeholder="جستجوی واحد..."
                                       class="w-full p-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:border-indigo-500 outline-none">
                                
                                @if($showDropdown && !empty($units))
                                    <div class="absolute z-50 w-full mt-1 bg-white border rounded-xl shadow-xl max-h-40 overflow-auto">
                                        @foreach($units as $unit)
                                            <button type="button" wire:click="selectUnit({{ $unit->id }}, '{{ $unit->name }}')"
                                                    class="w-full text-right p-2 text-xs hover:bg-indigo-50 transition-colors border-b last:border-0 italic">
                                                {{ $unit->name }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                @error('unit_id') <span class="text-red-500 text-[9px] font-bold">{{ $message }}</span> @enderror
                            </div>

                            {{-- اولویت --}}
                            <div>
                                <label class="block text-[11px] font-bold text-gray-500 mb-1 mr-1">فوریت</label>
                                <div class="flex bg-gray-100 p-1 rounded-xl h-[42px]">
                                    @foreach(['low' => 'عادی', 'normal' => 'متوسط', 'urgent' => 'فوری'] as $key => $label)
                                        <label class="flex-1 cursor-pointer">
                                            <input type="radio" wire:model="priority" value="{{ $key }}" class="hidden peer">
                                            <div class="h-full flex items-center justify-center text-[10px] font-bold rounded-lg transition-all peer-checked:bg-white peer-checked:text-indigo-700 peer-checked:shadow-sm text-gray-400">
                                                {{ $label }}
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- موضوع --}}
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 mb-1 mr-1">موضوع تیکت</label>
                            <input type="text" wire:model="subject" placeholder="عنوان کوتاه..." 
                                   class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2.5 text-sm focus:border-indigo-500 outline-none">
                            @error('subject') <span class="text-red-500 text-[9px] font-bold">{{ $message }}</span> @enderror
                        </div>

                        {{-- متن پیام --}}
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 mb-1 mr-1">شرح درخواست</label>
                            <textarea wire:model="content" rows="4" placeholder="جزئیات مشکل خود را بنویسید..." 
                                      class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2.5 text-sm focus:border-indigo-500 outline-none resize-none"></textarea>
                            @error('content') <span class="text-red-500 text-[9px] font-bold">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    {{-- ستون چپ: آپلود و تایید --}}
                    <div class="md:col-span-5 flex flex-col justify-between space-y-4">
                        <div class="space-y-3">
                            <label class="block text-[11px] font-bold text-gray-500 mr-1">پیوست مستندات</label>
                            <div class="relative border-2 border-dashed border-indigo-100 rounded-2xl p-6 bg-indigo-50/30 text-center hover:bg-indigo-50 transition-all group"
                                 :class="isUploading ? 'opacity-50' : ''">
                                <input type="file" wire:model="files" multiple class="absolute inset-0 opacity-0 cursor-pointer"
                                       x-on:livewire-upload-start="isUploading = true"
                                       x-on:livewire-upload-finish="isUploading = false"
                                       x-on:livewire-upload-progress="progress = $event.detail.progress">
                                
                                <div class="flex flex-col items-center">
                                    <svg class="w-8 h-8 text-indigo-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                                    <span class="text-[10px] text-indigo-600 font-bold">کشیدن یا کلیک برای آپلود</span>
                                    <span class="text-[9px] text-gray-400 mt-1">حداکثر ۵ فایل (مجموع ۱۰ مگ)</span>
                                </div>

                                <div x-show="isUploading" class="absolute inset-0 flex items-center justify-center bg-white/80 rounded-2xl">
                                    <div class="w-2/3 bg-gray-200 h-1.5 rounded-full overflow-hidden">
                                        <div class="bg-indigo-600 h-full transition-all" :style="'width:' + progress + '%'"></div>
                                    </div>
                                </div>
                            </div>

                            {{-- لیست فایل‌ها --}}
                            <div class="flex flex-wrap gap-2 max-h-24 overflow-y-auto p-1">
                                @foreach($files as $index => $file)
                                    <div class="flex items-center gap-1 bg-white border rounded-lg px-2 py-1 shadow-sm animate-in fade-in">
                                        <span class="text-[9px] text-gray-600 truncate max-w-[80px]">{{ $file->getClientOriginalName() }}</span>
                                        <button type="button" wire:click="removeFile({{ $index }})" class="text-red-500 font-bold text-sm">&times;</button>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- دکمه نهایی --}}
                        <div class="pt-4 border-t flex flex-col gap-2">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="w-full bg-indigo-700 hover:bg-indigo-800 text-white py-4 rounded-2xl font-black text-sm shadow-xl shadow-indigo-200 transition-all flex items-center justify-center gap-2">
                                <span wire:loading.remove>ارسال نهایی و دریافت کد</span>
                                <span wire:loading>درحال پردازش...</span>
                                <svg wire:loading.remove class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </button>
                            <p class="text-[9px] text-center text-gray-400">پس از ثبت، تیکت مستقیماً به واحد مربوطه ارجاع می‌شود.</p>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>