<div class="min-h-screen bg-base-200/50 p-2 sm:p-4">
    <div class="max-w-5xl mx-auto">

        <x-header title="ثبت تیکت جدید" subtitle="لطفاً تمامی فیلدها را با دقت تکمیل کنید" separator progress-indicator>
            <x-slot:actions>
                <x-theme-selector />
            </x-slot:actions>
        </x-header>

        <x-card shadow class="bg-base-100">



            <x-form wire:submit="saveTicket">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-8">

                    {{-- ستون راست: اطلاعات اصلی --}}
                    <div class="md:col-span-7 space-y-6">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {{-- واحد گیرنده با استفاده از سیستم جستجوی داخلی mary-ui --}}
                            <div class="relative">
                                <x-input
                                    label="واحد گیرنده"
                                    placeholder="جستجوی واحد..."
                                    wire:model.live.debounce.300ms="search"
                                    icon="o-magnifying-glass"
                                    hint="واحد مربوطه را انتخاب کنید" />

                                @if($showDropdown && !empty($units))
                                <div class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-xl shadow-2xl max-h-52 overflow-auto">
                                    @foreach($units as $unit)
                                    <div
                                        wire:click="selectUnit({{ $unit->id }}, '{{ $unit->name }}')"
                                        class="p-3 text-sm hover:bg-primary hover:text-white cursor-pointer transition-colors border-b border-base-200 last:border-0">
                                        {{ $unit->name }}
                                    </div>
                                    @endforeach
                                </div>
                                @endif
                            </div>

                            {{-- اولویت (Priority) --}}
                            <x-select
                                label="سطح فوریت"
                                icon="o-bolt"
                                :options="[
                                    ['id' => 'low', 'name' => 'عادی'],
                                    ['id' => 'normal', 'name' => 'متوسط'],
                                    ['id' => 'urgent', 'name' => 'فوری']
                                ]"
                                wire:model="priority" />
                        </div>

                        {{-- موضوع --}}
                        <x-input
                            label="موضوع تیکت"
                            placeholder="خلاصه‌ای از درخواست شما..."
                            wire:model="subject"
                            icon="o-pencil-square" />

                        {{-- متن پیام --}}
                        <x-textarea
                            label="شرح درخواست"
                            wire:model="content"
                            placeholder="جزئیات مشکل خود را اینجا بنویسید..."
                            rows="5"
                            inline />
                    </div>

                    {{-- ستون چپ: آپلود و تایید --}}
                    <div class="md:col-span-5 flex flex-col justify-between space-y-6">

                        <div class="bg-base-200/50 p-4 rounded-2xl border-2 border-dashed border-base-300">
                            <x-file
                                wire:model="files"
                                label="پیوست مستندات"
                                multiple
                                hint="حداکثر ۵ فایل (مجموع ۱۰ مگ)"
                                accept="image/*,application/pdf">
                                <div class="flex flex-col items-center justify-center py-4">
                                    <x-icon name="o-cloud-arrow-up" class="w-10 h-10 text-primary mb-2" />
                                    <span class="text-xs font-bold">برای آپلود کلیک کنید یا فایل را بکشید</span>
                                </div>
                            </x-file>

                            {{-- نمایش لیست فایل‌های انتخاب شده با استایل Mary --}}
                            @if(count($files) > 0)
                            <div class="mt-4 space-y-2">
                                @foreach($files as $index => $file)
                                <div class="flex items-center justify-between bg-base-100 p-2 rounded-lg border border-base-300">
                                    <div class="flex items-center gap-2 overflow-hidden">
                                        <x-icon name="o-paper-clip" class="w-4 h-4 text-gray-400" />
                                        <span class="text-[10px] truncate">{{ $file->getClientOriginalName() }}</span>
                                    </div>
                                    <x-button icon="o-x-mark" wire:click="removeFile({{ $index }})" class="btn-ghost btn-xs text-error" />
                                </div>
                                @endforeach
                            </div>
                            @endif
                        </div>

                        {{-- دکمه نهایی --}}
                        <div class="space-y-3">
                            <x-button
                                type="submit"
                                label="ارسال نهایی و دریافت کد"
                                icon="o-paper-airplane"
                                class="btn-primary w-full h-16 shadow-lg shadow-primary/20"
                                spinner="saveTicket" />

                            <div class="flex items-center gap-2 justify-center text-gray-400">
                                <x-icon name="o-information-circle" class="w-4 h-4" />
                                <span class="text-[10px]">تیکت شما مستقیماً به واحد مربوطه ارجاع می‌شود.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </x-form>
        </x-card>
    </div>
</div>